<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\BitbucketConnector;
use App\Http\Integrations\Bitbucket\Requests\NextPageRequest;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Support\CredentialStore;
use App\Support\GitContext;
use Illuminate\Contracts\Support\Arrayable;
use LaravelZero\Framework\Commands\Command;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Termwind\parse;
use function Termwind\render;

/**
 * Base command for everything that talks to the Bitbucket API. Provides the
 * connector factory, credential guard, repo parsing, error handling and
 * pagination helpers shared by the concrete commands.
 *
 * All console I/O (tables, prompts, notifications) is routed through
 * Laravel Prompts by overriding the framework's helpers below, so every
 * command gets a consistent look without importing anything itself.
 */
abstract class BitbucketCommand extends Command
{
    /**
     * Render a table with Laravel Prompts, truncating cells so the table
     * always fits the terminal width. Without this, a single long cell (e.g.
     * a URL) overflows and Laravel Prompts draws broken, misaligned borders.
     *
     * @param  Arrayable<int, mixed>|array<int, mixed>  $headers
     * @param  Arrayable<int, mixed>|array<int, mixed>  $rows
     */
    public function table($headers = [], $rows = [], $tableStyle = 'default', array $columnStyles = []): void
    {
        $headers = $headers instanceof Arrayable ? $headers->toArray() : $headers;
        $rows = $rows instanceof Arrayable ? $rows->toArray() : $rows;

        [$headers, $rows] = $this->fitTableToTerminal($headers, $rows);

        table($headers, $rows);
    }

    /**
     * Shrink columns so the whole table fits the terminal width, avoiding the
     * broken borders Laravel Prompts draws when a row overflows.
     *
     * Columns that can't be truncated (they contain Symfony <markup>) keep
     * their full width; the remaining space is shared among the rest in
     * proportion to their natural width.
     *
     * @param  array<int, mixed>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    protected function fitTableToTerminal(array $headers, array $rows): array
    {
        if ($rows === []) {
            return [$headers, $rows];
        }

        $columns = count($headers) ?: count($rows[0]);

        if ($columns === 0) {
            return [$headers, $rows];
        }

        $measure = fn ($value): int => mb_strwidth($this->stripFormatting((string) $value));

        // Prompts renders " │ " between cells: 3 chars per column + the outer
        // border and a leading space. Keep a couple of chars of head-room.
        $terminalWidth = (new Terminal)->getWidth();
        $available = max(20, $terminalWidth - ($columns * 3 + 4));

        // Natural (untruncated) width and truncatability of each column.
        $natural = array_fill(0, $columns, 0);
        $fixed = array_fill(0, $columns, false);
        foreach ($headers as $i => $header) {
            $natural[$i] = $measure($header);
        }
        foreach ($rows as $row) {
            foreach (array_values($row) as $i => $cell) {
                $natural[$i] = max($natural[$i] ?? 0, $measure($cell));
                if (str_contains((string) $cell, '<') || str_contains((string) $cell, "\033")) {
                    $fixed[$i] = true; // contains markup or a hyperlink — never truncate
                }
            }
        }

        if (array_sum($natural) <= $available) {
            return [$headers, $rows];
        }

        // Reserve full width for fixed columns; share the rest proportionally.
        $reserved = 0;
        $flexTotal = 0;
        foreach ($natural as $i => $width) {
            $fixed[$i] ? $reserved += $width : $flexTotal += $width;
        }
        $flexBudget = max(0, $available - $reserved);
        $flexTotal = $flexTotal ?: 1;

        $caps = [];
        foreach ($natural as $i => $width) {
            $caps[$i] = $fixed[$i]
                ? $width
                : max(6, (int) floor($flexBudget * ($width / $flexTotal)));
        }

        $headers = array_map(fn ($h, $i) => $this->truncateCell((string) $h, $caps[$i] ?? 40), $headers, array_keys($headers));
        $rows = array_map(fn (array $row) => array_map(
            fn ($cell, $i) => $this->truncateCell((string) $cell, $caps[$i] ?? 40),
            array_values($row),
            array_keys(array_values($row)),
        ), $rows);

        return [$headers, $rows];
    }

    /**
     * Wrap text in an OSC 8 terminal hyperlink (Ctrl/Cmd+Click opens it).
     * Emits the raw escape sequence — Laravel Prompts renders <fg> markup but
     * ignores Symfony's <href>, whereas the raw sequence passes through and
     * stays aligned (Prompts excludes escapes from its width calculation).
     * Falls back to plain text when no URL is available.
     */
    protected function link(?string $url, string|int $text): string
    {
        $text = (string) $text;

        if (blank($url)) {
            return $text;
        }

        $esc = "\033";

        return "{$esc}]8;;{$url}{$esc}\\{$text}{$esc}]8;;{$esc}\\";
    }

    /**
     * Strip Symfony <tags>, ANSI colours and OSC 8 hyperlinks for width maths.
     */
    protected function stripFormatting(string $value): string
    {
        $value = preg_replace('/\x1b\]8;;.*?\x1b\\\\/', '', $value) ?? $value; // OSC 8 links
        $value = preg_replace('/\x1b\[[0-9;]*m/', '', $value) ?? $value;        // ANSI colours
        $value = preg_replace('/<[^>]+>/', '', $value) ?? $value;              // Symfony tags

        return $value;
    }

    /**
     * The bitbucket.org web URL for a repository.
     */
    protected function repoWebUrl(string $workspace, string $slug): string
    {
        return "https://bitbucket.org/{$workspace}/{$slug}";
    }

    /**
     * Classify a Bitbucket state into a visual kind and a status symbol.
     *
     * @return array{0: string, 1: string} [kind, symbol]
     */
    protected function stateKind(string $state): array
    {
        return match (strtoupper($state)) {
            'SUCCESSFUL', 'MERGED', 'APPROVED', 'RESOLVED', 'COMPLETED' => ['ok', '✓'],
            'FAILED', 'ERROR', 'DECLINED' => ['bad', '✗'],
            'STOPPED' => ['bad', '■'],
            'INPROGRESS', 'IN_PROGRESS', 'RUNNING', 'BUILDING', 'PENDING', 'OPEN', 'NEW' => ['warn', '•'],
            default => ['neutral', ''],
        };
    }

    /**
     * A coloured status pill using Symfony markup — safe inside Prompts
     * tables and $this->line() output.
     */
    protected function badge(string $state): string
    {
        [$kind, $symbol] = $this->stateKind($state);
        $label = trim("{$symbol} {$state}");

        return match ($kind) {
            'ok' => parse('<span class="bg-green font-bold px-1 w-12"> '.e($label).' </span>'),
            'bad' => parse('<span class="bg-red-600 font-bold px-1 w-12"> '.e($label).' </span>'),
            'warn' => parse('<span class="bg-yellow-400 font-bold px-1 w-12"> '.e($label).' </span>'),
            default => parse('<span class="bg-gray-500 font-bold px-1 w-12"> '.e($label).' </span>'),
        };
    }

    /**
     * A coloured status pill as Termwind HTML — for use inside render() blocks.
     */
    protected function badgeHtml(string $state): string
    {
        [$kind, $symbol] = $this->stateKind($state);
        $label = trim("{$symbol} {$state}");

        $class = match ($kind) {
            'ok' => 'bg-green-600 text-white',
            'bad' => 'bg-red-600 text-white',
            'warn' => 'bg-yellow-600 text-black',
            default => 'bg-gray-500 text-white',
        };

        return '<span class="px-1 '.$class.'">'.e($label).'</span>';
    }

    /**
     * Render a key/value detail block with Termwind. Values are treated as raw
     * HTML — wrap plain text with e() and use link()/badgeHtml() for the rest.
     * Empty values are skipped.
     *
     * @param  array<string, string|int|null>  $pairs
     */
    protected function detail(array $pairs): void
    {
        $rows = '';
        foreach ($pairs as $label => $value) {
            if (blank($value)) {
                continue;
            }
            $rows .= '<div>'
                .'<span class="w-28 text-gray-500">'.e($label).'</span>'
                .'<span>'.$value.'</span>'
                .'</div>';
        }

        if ($rows === '') {
            return;
        }

        \Termwind\render('<div class="my-1 ml-1">'.$rows.'</div>');
    }

    /**
     * Truncate a single cell to a visible width, leaving markup cells alone.
     */
    protected function truncateCell(string $value, int $max): string
    {
        // Leave formatted cells (colours / hyperlinks) intact.
        if (str_contains($value, '<') || str_contains($value, "\033") || mb_strwidth($value) <= $max) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, max(1, $max - 1))).'…';
    }

    protected function outputByType(string $message, string $type = 'info'): void
    {
        render(sprintf('<div class="output %s">%s</div>', e($type), e($message)));
    }

    public function info($string, $verbosity = null): void
    {
        $this->outputByType($string, 'info');
    }

    public function warn($string, $verbosity = null): void
    {
        $this->outputByType($string, 'warning');
    }

    public function error($string, $verbosity = null): void
    {
        $this->outputByType($string, 'error');
    }

    public function confirm($question, $default = false): bool
    {
        return confirm(label: $question, default: $default);
    }

    public function ask($question, $default = null): mixed
    {
        return text(label: $question, default: (string) $default);
    }

    public function secret($question, $fallback = true): mixed
    {
        return password(label: $question);
    }

    protected function credentials(): CredentialStore
    {
        return app(CredentialStore::class);
    }

    protected function connector(): BitbucketConnector
    {
        $this->refreshOAuthIfNeeded();

        return new BitbucketConnector($this->credentials());
    }

    /**
     * Transparently refresh an expiring OAuth access token before use.
     */
    protected function refreshOAuthIfNeeded(): void
    {
        $store = $this->credentials();

        if ($store->get('method') !== 'oauth') {
            return;
        }

        $expiresAt = $store->get('oauth.expires_at');
        $refreshToken = $store->get('oauth.refresh_token');

        if (blank($refreshToken) || blank($expiresAt) || time() < ((int) $expiresAt - 60)) {
            return;
        }

        try {
            $authenticator = (new BitbucketConnector($store))->refreshAccessToken((string) $refreshToken);

            $store->fill([
                'oauth.access_token' => $authenticator->getAccessToken(),
                'oauth.refresh_token' => $authenticator->getRefreshToken() ?? $refreshToken,
                'oauth.expires_at' => $authenticator->getExpiresAt()?->getTimestamp(),
            ])->save();
        } catch (\Throwable $e) {
            $this->warn('OAuth token refresh failed ('.$e->getMessage().'). Run "bb auth login" again if requests fail.');
        }
    }

    /**
     * Ensure credentials are present, otherwise nudge the user to configure.
     */
    protected function ensureConfigured(): bool
    {
        if ($this->credentials()->isConfigured()) {
            return true;
        }

        $this->error('bb is not configured yet. Run: bb configure');

        return false;
    }

    /**
     * Resolve the target repository as [workspace, repo_slug], mirroring `gh`:
     *   1. the --repo / -R flag ("workspace/repo" or bare "repo")
     *   2. the git remote of the current directory
     *
     * Commands that call this must declare a {--R|repo=} option.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveRepo(): ?array
    {
        $repo = $this->hasOption('repo') ? $this->option('repo') : null;

        if (filled($repo)) {
            if (str_contains($repo, '/')) {
                [$workspace, $slug] = explode('/', $repo, 2);

                return [$workspace, $slug];
            }

            if (filled($workspace = $this->credentials()->get('workspace'))) {
                return [$workspace, $repo];
            }

            $this->error("No workspace given. Use \"-R workspace/{$repo}\" or set a default with: bb configure");

            return null;
        }

        if ($detected = app(GitContext::class)->currentRepo()) {
            return $detected;
        }

        $this->error('No repository given and none detected from the current git remote. Pass "-R workspace/repo".');

        return null;
    }

    /**
     * Parse an explicit positional repo argument ("workspace/repo" or bare
     * "repo" + default workspace) into [workspace, slug]. Used by commands
     * that always name their target (clone, create, fork, delete).
     *
     * @return array{0: string, 1: string}|null
     */
    protected function parseRepoArgument(string $repo): ?array
    {
        if (str_contains($repo, '/')) {
            [$workspace, $slug] = explode('/', $repo, 2);

            return [$workspace, $slug];
        }

        if (filled($workspace = $this->credentials()->get('workspace'))) {
            return [$workspace, $repo];
        }

        $this->error("No workspace given. Use \"workspace/{$repo}\" or set a default with: bb configure");

        return null;
    }

    /**
     * Resolve a pull request id — either the explicit argument, or the open
     * PR for the current git branch (gh-style "operate on the current PR").
     */
    protected function resolvePrId(string $workspace, string $slug, int|string|null $id): int|string|null
    {
        if (filled($id)) {
            return $id;
        }

        $branch = app(GitContext::class)->currentBranch();

        if (blank($branch)) {
            $this->error('No pull request id given and no current git branch to detect one from.');

            return null;
        }

        // Bitbucket ignores the `state` query param when `q` is present, so the
        // state must be expressed inside `q` — otherwise MERGED/DECLINED PRs
        // for this branch come back too.
        $response = $this->send(new ListPullRequests(
            $workspace,
            $slug,
            'OPEN',
            ['q' => "state=\"OPEN\" AND source.branch.name=\"{$branch}\""],
        ));

        if ($response === null) {
            return null;
        }

        // Verify both the state and source branch client-side — never fall back
        // to an unrelated or already-merged PR.
        $match = collect($response->json('values') ?? [])
            ->first(fn ($pr) => data_get($pr, 'source.branch.name') === $branch
                && data_get($pr, 'state') === 'OPEN');

        if ($match === null) {
            $this->error("No open pull request found for branch \"{$branch}\". Pass a PR id explicitly.");

            return null;
        }

        // Be explicit about which PR was auto-resolved — to STDERR so it never
        // pollutes stdout (JSON output, a piped diff, etc).
        fwrite(STDERR, "Using PR #{$match['id']} (source branch \"{$branch}\")".PHP_EOL);

        return $match['id'];
    }

    /**
     * Whether the command was asked to emit machine-readable JSON.
     */
    protected function wantsJson(): bool
    {
        return $this->hasOption('json') && (bool) $this->option('json');
    }

    /**
     * Print data as JSON, optionally filtered through the `jq` binary.
     */
    protected function outputJson(mixed $data): int
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $filter = $this->hasOption('jq') ? $this->option('jq') : null;

        if (blank($filter)) {
            $this->output->writeln($json);

            return self::SUCCESS;
        }

        $process = new Process(['jq', $filter]);
        $process->setInput($json);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error(trim($process->getErrorOutput()) ?: 'jq failed (is it installed?)');

            return self::FAILURE;
        }

        $this->output->write($process->getOutput());

        return self::SUCCESS;
    }

    /**
     * Colourise a unified diff with Symfony markup (green adds, red removes,
     * cyan hunks, bold headers). Non-diff lines are escaped so they can't be
     * interpreted as markup.
     */
    protected function colorizeDiff(string $diff): string
    {
        return collect(explode("\n", $diff))
            ->map(function (string $line) {
                return match (true) {
                    str_starts_with($line, '+++'), str_starts_with($line, '---') => "<options=bold>{$line}</>",
                    str_starts_with($line, '@@') => "<fg=cyan>{$line}</>",
                    str_starts_with($line, '+') => "<fg=green>{$line}</>",
                    str_starts_with($line, '-') => "<fg=red>{$line}</>",
                    str_starts_with($line, 'diff ') => "<options=bold>{$line}</>",
                    default => OutputFormatter::escape($line),
                };
            })
            ->implode("\n")."\n";
    }

    /**
     * Write content through a pager ($BB_PAGER / $PAGER / less) when attached
     * to a terminal and the content is taller than the window. Controlled by
     * the "pager" setting (auto|off). Falls back to a plain write.
     */
    protected function pager(string $content): void
    {
        $setting = $this->credentials()->get('settings.pager', 'auto');
        $height = (new Terminal)->getHeight() ?: 24;
        $tall = substr_count($content, "\n") >= $height;

        if ($setting === 'off' || ! stream_isatty(STDOUT) || ! $tall) {
            $this->output->write($content);

            return;
        }

        // The pager receives raw bytes, so render Symfony <markup> to ANSI
        // first — otherwise less would print the literal tags.
        $rendered = $this->output->getFormatter()->format($content);

        $pager = getenv('BB_PAGER') ?: getenv('PAGER') ?: 'less -R';

        // less reads the content from its stdin and the keyboard from /dev/tty,
        // while inheriting our real stdout so it can draw to the terminal.
        $process = proc_open($pager, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);

        if (! is_resource($process)) {
            $this->output->write($content);

            return;
        }

        fwrite($pipes[0], (string) $rendered);
        fclose($pipes[0]);
        proc_close($process);
    }

    /**
     * Fire a desktop notification (best effort; silently ignored if the
     * platform tool is unavailable).
     */
    protected function notify(string $title, string $message): void
    {
        $title = str_replace('"', "'", $title);
        $message = str_replace('"', "'", $message);

        try {
            $command = match (PHP_OS_FAMILY) {
                'Darwin' => 'osascript -e '.escapeshellarg("display notification \"{$message}\" with title \"{$title}\""),
                'Linux' => 'notify-send '.escapeshellarg($title).' '.escapeshellarg($message),
                default => null,
            };

            if ($command !== null) {
                Process::fromShellCommandline($command)->run();
            }
        } catch (\Throwable) {
            // Notifications are best-effort.
        }
    }

    /**
     * Open a URL in the user's default browser (respects the --web flag caller).
     */
    protected function openInBrowser(string $url): void
    {
        $opener = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        $this->line("Opening {$url}");

        try {
            Process::fromShellCommandline($opener.' '.escapeshellarg($url))->run();
        } catch (\Throwable $e) {
            $this->warn('Could not open browser: '.$e->getMessage());
        }
    }

    /**
     * Run a git command, streaming its output to the terminal.
     */
    protected function git(array $args, ?string $cwd = null): bool
    {
        $process = new Process(['git', ...$args], $cwd, timeout: null);

        $process->run(function ($type, string $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful();
    }

    /**
     * Run a git command silently and report only whether it succeeded.
     */
    protected function gitQuiet(array $args, ?string $cwd = null): bool
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->run();

        return $process->isSuccessful();
    }

    /**
     * Run a git command and return its trimmed stdout (null on failure).
     */
    protected function gitOutput(array $args, ?string $cwd = null): ?string
    {
        $process = new Process(['git', ...$args], $cwd);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    /**
     * Send a request and surface Bitbucket errors in a friendly way.
     * Returns null when the request failed (message already printed).
     */
    protected function send(Request $request): ?Response
    {
        try {
            $response = $this->dispatch($this->connector(), $request);
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->reportApiError($response);

            return null;
        }

        return $response;
    }

    /**
     * Send a request, transparently backing off and retrying on HTTP 429
     * (rate limited), honouring the Retry-After header when present.
     */
    protected function dispatch(BitbucketConnector $connector, Request $request, int $maxRetries = 3): Response
    {
        $attempt = 0;

        while (true) {
            $response = $connector->send($request);

            if ($response->status() !== 429 || $attempt >= $maxRetries) {
                return $response;
            }

            $attempt++;
            $wait = min(60, max(1, (int) ($response->header('Retry-After') ?: 2 ** $attempt)));
            $this->warn("Rate limited by Bitbucket — retrying in {$wait}s ({$attempt}/{$maxRetries})...");
            sleep($wait);
        }
    }

    /**
     * Follow Bitbucket's cursor pagination, collecting every "values" entry.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function collectAll(Request $request, ?int $limit = null): ?array
    {
        $connector = $this->connector();
        $values = [];

        try {
            $response = $this->dispatch($connector, $request);

            while (true) {
                if ($response->failed()) {
                    $this->reportApiError($response);

                    return null;
                }

                $data = $response->json();
                $values = array_merge($values, $data['values'] ?? []);

                if ($limit !== null && count($values) >= $limit) {
                    return array_slice($values, 0, $limit);
                }

                $next = $data['next'] ?? null;

                if (blank($next)) {
                    return $values;
                }

                $response = $this->dispatch($connector, new NextPageRequest($next));
            }
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The bearer token for the active auth method, if any.
     */
    protected function bearerToken(): ?string
    {
        $store = $this->credentials();

        return match ($store->get('method')) {
            'token' => (string) $store->get('token'),
            'oauth' => (string) $store->get('oauth.access_token'),
            default => null,
        };
    }

    /**
     * The [username, password] pair git should use for HTTPS operations.
     *
     * @return array{0: string, 1: string}|null
     */
    protected function gitCredentials(): ?array
    {
        $store = $this->credentials();

        return match ($store->get('method')) {
            'app_password' => [(string) $store->get('username'), (string) $store->get('app_password')],
            'token' => ['x-token-auth', (string) $store->get('token')],
            'oauth' => ['x-token-auth', (string) $store->get('oauth.access_token')],
            default => null,
        };
    }

    protected function reportApiError(Response $response): void
    {
        $body = json_decode($response->body(), true);
        $message = is_array($body)
            ? data_get($body, 'error.message', $response->body())
            : ($response->body() ?: 'Unknown error');

        $this->error("Bitbucket API [{$response->status()}]: {$message}");

        if ($response->status() === 401) {
            $this->warn('Your credentials were rejected. Run "bb auth login" or "bb configure" to re-authenticate.');
        }
    }
}

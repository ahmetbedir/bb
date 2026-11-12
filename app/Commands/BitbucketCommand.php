<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\BitbucketConnector;
use App\Http\Integrations\Bitbucket\Requests\NextPageRequest;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Support\CredentialStore;
use App\Support\GitContext;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use LaravelZero\Framework\Commands\Command;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\password;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Termwind\parse;
use function Termwind\render;
use function Termwind\renderUsing;

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

        // Piped / redirected: plain tab-separated rows (like gh), no ANSI,
        // no box drawing and no truncation, so scripts and agents can parse it.
        if (! $this->output->isDecorated()) {
            foreach ([$headers, ...$rows] as $row) {
                $cells = array_map(fn ($cell) => str_replace(["\t", "\n"], ' ', $this->stripFormatting((string) $cell)), (array) $row);
                $this->output->writeln(implode("\t", $cells), OutputInterface::OUTPUT_RAW);
            }

            return;
        }

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

        if (blank($url) || ! $this->output->isDecorated()) {
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
     * The most specific state name of a pipeline, step or deployment.
     * Bitbucket nests the interesting bit differently per object: a finished
     * pipeline/step carries state.result, a deployment state.status, and a
     * running pipeline only a stage.
     *
     * @param  array<string, mixed>  $node
     */
    protected function stateOf(array $node): string
    {
        return (string) (data_get($node, 'state.result.name')
            ?? data_get($node, 'state.status.name')
            ?? data_get($node, 'state.stage.name')
            ?? data_get($node, 'state.name')
            ?? '');
    }

    /**
     * A UUID as a safe URL path segment. Bitbucket rejects UUIDs without
     * braces, and braces must be percent-encoded — while numeric references
     * (a pipeline build number) are passed through untouched.
     */
    protected function uuidSegment(int|string|null $value): string
    {
        $value = trim((string) $value);

        if ($value === '' || ctype_digit($value) || str_contains($value, '%7B')) {
            return $value;
        }

        return '%7B'.trim($value, '{}').'%7D';
    }

    /**
     * A UUID reduced to its bare form (no braces, no percent-encoding, lower
     * case) so references from different endpoints can be compared.
     */
    protected function bareUuid(int|string|null $value): string
    {
        return strtolower(trim(urldecode((string) $value), '{} '));
    }

    /**
     * "2m 18s" / "1h 04m 12s" — a duration in seconds, human sized.
     */
    protected function formatDuration(int|float|null $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $seconds = (int) round((float) $seconds);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60).'m '.str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
        }

        return intdiv($seconds, 3600).'h '
            .str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT).'m '
            .str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT).'s';
    }

    /**
     * An API timestamp rendered in the local timezone, optionally with a
     * relative suffix ("2026-08-17 11:05 (3 hours ago)").
     */
    protected function formatTimestamp(?string $timestamp, bool $relative = false, string $format = 'Y-m-d H:i'): string
    {
        if (blank($timestamp)) {
            return '';
        }

        try {
            $moment = Carbon::parse($timestamp)->setTimezone(date_default_timezone_get());
        } catch (\Throwable) {
            return substr($timestamp, 0, 19);
        }

        return $relative
            ? $moment->format($format).' ('.$moment->diffForHumans().')'
            : $moment->format($format);
    }

    /**
     * The wall-clock seconds between two API timestamps (null if unknown).
     */
    protected function elapsedSeconds(?string $from, ?string $to): ?int
    {
        if (blank($from) || blank($to)) {
            return null;
        }

        try {
            return Carbon::parse($from)->diffInSeconds(Carbon::parse($to), absolute: true);
        } catch (\Throwable) {
            return null;
        }
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
            'STOPPED', 'HALTED' => ['bad', '■'],
            'INPROGRESS', 'IN_PROGRESS', 'RUNNING', 'BUILDING', 'PENDING', 'OPEN', 'NEW' => ['warn', '•'],
            'PAUSED', 'READY' => ['warn', '❙❙'],
            'NOT_RUN', 'SKIPPED', 'UNDEPLOYED', 'EXPIRED' => ['neutral', '–'],
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
            'ok' => parse('<span class="bg-green font-bold px-1 w-15"> '.e($label).' </span>'),
            'bad' => parse('<span class="bg-red-600 font-bold px-1 w-15"> '.e($label).' </span>'),
            'warn' => parse('<span class="bg-yellow-400 font-bold px-1 w-15"> '.e($label).' </span>'),
            default => parse('<span class="bg-gray-500 font-bold px-1 w-15"> '.e($label).' </span>'),
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
        $html = sprintf('<div class="output %s">%s</div>', e($type), e($message));

        // Errors and warnings belong on stderr so stdout stays parseable.
        if (in_array($type, ['error', 'warning'], true) && ($err = $this->errorOutput()) !== null) {
            renderUsing($err);
            render($html);
            renderUsing(null);

            return;
        }

        render($html);
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
        // Never silently take the default when nobody can answer: a piped or
        // agent-driven run must opt in explicitly.
        if (! $this->canPrompt()) {
            throw new RuntimeException("Confirmation required (\"{$question}\"). Re-run with --yes to proceed non-interactively.");
        }

        return confirm(label: $question, default: $default);
    }

    public function ask($question, $default = null): mixed
    {
        if (! $this->canPrompt()) {
            throw new RuntimeException("Input required (\"{$question}\"). Re-run with --yes or pass the value as an option.");
        }

        return text(label: $question, default: (string) $default);
    }

    /**
     * Guard for actions people run all the time (no prompt for them) but that
     * should not happen by accident from a script or agent: unattended runs
     * must pass --yes.
     */
    protected function allowUnattended(string $action): bool
    {
        if ($this->canPrompt() || ($this->hasOption('yes') && $this->option('yes'))) {
            return true;
        }

        $this->reportError("{$action} needs --yes when run non-interactively; nothing was sent.");

        return false;
    }

    /**
     * Whether a human can answer prompts (mirrors Laravel's ConfiguresPrompts).
     */
    protected function canPrompt(): bool
    {
        return $this->input->isInteractive()
            && ((defined('STDIN') && stream_isatty(STDIN)) || $this->laravel->runningUnitTests());
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
            // Under the config lock, and re-checked after re-reading: when
            // several bb processes start at once (agents run commands in
            // parallel) only the first refreshes; the rest pick up its token
            // instead of spending a refresh token that may already be rotated.
            $store->locked(function (CredentialStore $store) {
                $refreshToken = $store->get('oauth.refresh_token');

                if (blank($refreshToken) || time() < ((int) $store->get('oauth.expires_at') - 60)) {
                    return;
                }

                $authenticator = (new BitbucketConnector($store))->refreshAccessToken((string) $refreshToken);

                $store->fill([
                    'oauth.access_token' => $authenticator->getAccessToken(),
                    'oauth.refresh_token' => $authenticator->getRefreshToken() ?? $refreshToken,
                    'oauth.expires_at' => $authenticator->getExpiresAt()?->getTimestamp(),
                ])->save();
            });
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

        $this->error($this->notConfiguredMessage());

        return false;
    }

    protected function notConfiguredMessage(): string
    {
        if ($this->credentials()->isUnreadable()) {
            return "Your bb config ({$this->credentials()->path()}) is not valid JSON. Fix or delete it, then run: bb configure";
        }

        return $this->credentials()->usesAppPassword()
            ? 'Bitbucket no longer accepts app passwords. Create an API token and run: bb configure'
            : 'bb is not configured yet. Run: bb configure';
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
     * Validate --jq before handle() runs, so a missing jq binary or a typo in
     * the filter fails the command before any request is sent; otherwise a
     * merge or POST would happen and still exit 1, inviting a retry.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filter = $this->hasOption('jq') ? $this->option('jq') : null;

        if (filled($filter)) {
            $check = new Process(['jq', '-n', $filter]);

            try {
                $check->run();
                $code = $check->getExitCode();
            } catch (\Throwable) {
                $code = 127;
            }

            // jq exits 2 on usage errors, 3 on compile errors; runtime errors
            // on the null input used here (exit 5) are fine.
            if (in_array($code, [2, 3, 127], true)) {
                $this->reportError($code === 127
                    ? '--jq needs the jq binary (https://jqlang.org); nothing was sent.'
                    : 'Invalid --jq filter: '.trim($check->getErrorOutput()).' (nothing was sent)');

                return self::FAILURE;
            }
        }

        return parent::execute($input, $output);
    }

    /**
     * Whether the command was asked to emit machine-readable JSON.
     */
    protected function wantsJson(): bool
    {
        return ($this->hasOption('json') && (bool) $this->option('json'))
            || ($this->hasOption('jq') && filled($this->option('jq')));
    }

    /**
     * Print data as JSON, optionally filtered through the `jq` binary.
     */
    protected function outputJson(mixed $data): int
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $filter = $this->hasOption('jq') ? $this->option('jq') : null;

        if (blank($filter)) {
            $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $process = new Process(['jq', $filter]);
        $process->setInput($json);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->reportError('jq failed on the response: '.(trim($process->getErrorOutput()) ?: 'unknown error'));

            return self::FAILURE;
        }

        $this->output->write($process->getOutput(), false, OutputInterface::OUTPUT_RAW);

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
        if (! $this->canPrompt()) {
            return; // nobody is watching a desktop
        }

        // AppleScript string literal: escape backslashes first, then quotes.
        $quote = fn (string $s) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $s).'"';

        try {
            $command = match (PHP_OS_FAMILY) {
                'Darwin' => 'osascript -e '.escapeshellarg('display notification '.$quote($message).' with title '.$quote($title)),
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
            // start treats the first quoted argument as a window title.
            'Windows' => 'start ""',
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
            $this->reportError('Request failed: '.$e->getMessage());

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
                    if (count($values) > $limit || filled($data['next'] ?? null)) {
                        $this->stderr("Showing the first {$limit} results; more exist (raise --limit to see them).");
                    }

                    return array_slice($values, 0, $limit);
                }

                $next = $data['next'] ?? null;

                if (blank($next)) {
                    return $values;
                }

                // The bearer token goes with this request: only follow links
                // back to the API host we started on.
                if (parse_url($next, PHP_URL_SCHEME) !== 'https'
                    || parse_url($next, PHP_URL_HOST) !== parse_url($connector->resolveBaseUrl(), PHP_URL_HOST)) {
                    throw new \RuntimeException("Refusing to follow a pagination link to another host: {$next}");
                }

                $response = $this->dispatch($connector, new NextPageRequest($next));
            }
        } catch (\Throwable $e) {
            $this->reportError('Request failed: '.$e->getMessage());

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

        $status = $response->status();

        if ($this->wantsJson()) {
            $this->stderr(json_encode(['error' => ['status' => $status, 'message' => $message]], JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->error("Bitbucket API [{$status}]: {$message}");

        $url = $response->getPendingRequest()->getUrl();

        if ($status === 401) {
            $this->warn('Your credentials were rejected. Run "bb auth login" or "bb configure" to re-authenticate.');
        }

        if (in_array($status, [404, 410], true) && str_contains($url, '/issues')) {
            $this->warn('This repository does not have the issue tracker enabled (turn it on in Repository settings → Issue tracker).');
        }
    }

    /**
     * Report a failure: a JSON error object on stderr in --json mode,
     * otherwise the usual styled error line.
     */
    protected function reportError(string $message): void
    {
        if ($this->wantsJson()) {
            $this->stderr(json_encode(['error' => ['message' => $message]], JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->error($message);
    }

    /**
     * Write a raw line to stderr (stdout when there is no separate stream).
     */
    protected function stderr(string $line): void
    {
        ($this->errorOutput() ?? $this->output)->writeln($line, OutputInterface::OUTPUT_RAW);
    }

    /**
     * The real stderr stream, or null when output is a single buffer (tests).
     */
    protected function errorOutput(): ?OutputInterface
    {
        $inner = method_exists($this->output, 'getOutput') ? $this->output->getOutput() : $this->output;

        return $inner instanceof ConsoleOutputInterface ? $inner->getErrorOutput() : null;
    }
}

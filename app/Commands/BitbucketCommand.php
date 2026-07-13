<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\BitbucketConnector;
use App\Http\Integrations\Bitbucket\Requests\NextPageRequest;
use App\Support\CredentialStore;
use App\Support\GitContext;
use LaravelZero\Framework\Commands\Command;
use Saloon\Http\Request;
use Saloon\Http\Response;
use Symfony\Component\Process\Process;

/**
 * Base command for everything that talks to the Bitbucket API. Provides the
 * connector factory, credential guard, repo parsing, error handling and
 * pagination helpers shared by the concrete commands.
 */
abstract class BitbucketCommand extends Command
{
    protected function credentials(): CredentialStore
    {
        return app(CredentialStore::class);
    }

    protected function connector(): BitbucketConnector
    {
        return new BitbucketConnector($this->credentials());
    }

    /**
     * Ensure credentials are present, otherwise nudge the user to configure.
     */
    protected function ensureConfigured(): bool
    {
        if ($this->credentials()->isConfigured()) {
            return true;
        }

        $this->error('bbcli is not configured yet. Run: bbcli configure');

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

            $this->error("No workspace given. Use \"-R workspace/{$repo}\" or set a default with: bbcli configure");

            return null;
        }

        if ($detected = app(GitContext::class)->currentRepo()) {
            return $detected;
        }

        $this->error('No repository given and none detected from the current git remote. Pass "-R workspace/repo".');

        return null;
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

        $process->run(function ($type, $buffer) {
            $this->output->write($buffer);
        });

        return $process->isSuccessful();
    }

    /**
     * Send a request and surface Bitbucket errors in a friendly way.
     * Returns null when the request failed (message already printed).
     */
    protected function send(Request $request): ?Response
    {
        try {
            $response = $this->connector()->send($request);
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
     * Follow Bitbucket's cursor pagination, collecting every "values" entry.
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function collectAll(Request $request, ?int $limit = null): ?array
    {
        $connector = $this->connector();
        $values = [];

        try {
            $response = $connector->send($request);

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

                $response = $connector->send(new NextPageRequest($next));
            }
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return null;
        }
    }

    protected function reportApiError(Response $response): void
    {
        $body = $response->json();
        $message = data_get($body, 'error.message', $response->body() ?: 'Unknown error');

        $this->error("Bitbucket API [{$response->status()}]: {$message}");
    }
}

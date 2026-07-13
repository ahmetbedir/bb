<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\BitbucketConnector;
use App\Http\Integrations\Bitbucket\Requests\NextPageRequest;
use App\Support\CredentialStore;
use LaravelZero\Framework\Commands\Command;
use Saloon\Http\Request;
use Saloon\Http\Response;

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
     * Resolve a "repo" argument into [workspace, repo_slug].
     *
     * Accepts either "workspace/repo" or just "repo" (falling back to the
     * configured default workspace).
     *
     * @return array{0: string, 1: string}|null
     */
    protected function resolveRepo(string $repo): ?array
    {
        if (str_contains($repo, '/')) {
            [$workspace, $slug] = explode('/', $repo, 2);

            return [$workspace, $slug];
        }

        $workspace = $this->credentials()->get('workspace');

        if (blank($workspace)) {
            $this->error("No workspace given. Use \"workspace/{$repo}\" or set a default with: bbcli configure");

            return null;
        }

        return [$workspace, $repo];
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

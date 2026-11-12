<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\ForkRepository;

class RepoForkCommand extends BitbucketCommand
{
    protected $signature = 'repo:fork
        {repo : Repository to fork as "workspace/repo" or just "repo"}
        {--into= : Destination workspace (defaults to your own)}
        {--name= : Name for the fork}
        {--json : Output the API response as JSON}
        {--jq= : Filter the JSON output through a jq expression}';

    protected $description = 'Fork a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->parseRepoArgument($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $response = $this->send(new ForkRepository(
            workspace: $workspace,
            repoSlug: $slug,
            destinationWorkspace: $this->option('into') ?: null,
            name: $this->option('name') ?: null,
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $repo = $response->json();

        if ($this->wantsJson()) {
            return $this->outputJson($response->json());
        }

        $this->info("Forked {$workspace}/{$slug} → {$repo['full_name']}");
        $this->line(' '.($repo['links']['html']['href'] ?? ''));

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\CreateRepository;

class RepoCreateCommand extends BitbucketCommand
{
    protected $signature = 'repo:create
        {repo : New repository as "workspace/repo" or just "repo"}
        {--description= : Repository description}
        {--public : Make the repository public (default: private)}
        {--project= : Project key to place the repository in}
        {--clone : Clone the repository after creating it}';

    protected $description = 'Create a new repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->parseRepoArgument($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $response = $this->send(new CreateRepository(
            workspace: $workspace,
            repoSlug: $slug,
            isPrivate: ! $this->option('public'),
            description: $this->option('description') ?: null,
            projectKey: $this->option('project') ?: null,
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $repo = $response->json();
        $this->info("Created {$repo['full_name']}");
        $this->line(' '.($repo['links']['html']['href'] ?? ''));

        if ($this->option('clone')) {
            $sshUrl = collect($repo['links']['clone'] ?? [])->firstWhere('name', 'ssh')['href'] ?? null;

            if (filled($sshUrl)) {
                $this->newLine();

                return $this->git(['clone', $sshUrl]) ? self::SUCCESS : self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}

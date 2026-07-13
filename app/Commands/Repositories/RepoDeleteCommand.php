<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\DeleteRepository;

class RepoDeleteCommand extends BitbucketCommand
{
    protected $signature = 'repo:delete
        {repo : Repository to delete as "workspace/repo" or just "repo"}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete a repository (irreversible)';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->parseRepoArgument($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;
        $fullName = "{$workspace}/{$slug}";

        if (! $this->option('yes')) {
            $this->warn("This will permanently delete {$fullName}.");
            $typed = $this->ask("Type the repository name to confirm ({$fullName})");

            if ($typed !== $fullName) {
                $this->line('Aborted.');

                return self::SUCCESS;
            }
        }

        $response = $this->send(new DeleteRepository($workspace, $slug));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Deleted {$fullName}.");

        return self::SUCCESS;
    }
}

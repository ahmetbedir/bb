<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Branches\DeleteBranch;

class BranchDeleteCommand extends BitbucketCommand
{
    protected $signature = 'branch:delete
        {name : Branch name to delete}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Delete a branch';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;
        $name = $this->argument('name');

        if (! $this->option('yes') && ! $this->confirm("Delete branch \"{$name}\" in {$workspace}/{$slug}?", false)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $response = $this->send(new DeleteBranch($workspace, $slug, $name));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Deleted branch \"{$name}\".");

        return self::SUCCESS;
    }
}

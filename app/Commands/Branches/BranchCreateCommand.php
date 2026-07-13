<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Branches\CreateBranch;

use function Laravel\Prompts\text;

class BranchCreateCommand extends BitbucketCommand
{
    protected $signature = 'branch:create
        {name? : New branch name}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--from= : Source branch name or commit hash to branch from}';

    protected $description = 'Create a branch';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $name = $this->argument('name') ?: text('New branch name', required: true);
        $from = $this->option('from') ?: text('Branch from (branch name or commit hash)', required: true);

        $response = $this->send(new CreateBranch($workspace, $slug, $name, $from));

        if ($response === null) {
            return self::FAILURE;
        }

        $branch = $response->json();
        $this->info("Created branch \"{$branch['name']}\" at ".substr($branch['target']['hash'] ?? '', 0, 8));

        return self::SUCCESS;
    }
}

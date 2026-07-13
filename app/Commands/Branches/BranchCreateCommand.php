<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Branches\CreateBranch;

use function Laravel\Prompts\text;

class BranchCreateCommand extends BitbucketCommand
{
    protected $signature = 'branch:create
        {repo : Repository as "workspace/repo" or just "repo"}
        {name? : New branch name}
        {--from= : Source branch name or commit hash to branch from}';

    protected $description = 'Create a branch';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo($this->argument('repo'))) {
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

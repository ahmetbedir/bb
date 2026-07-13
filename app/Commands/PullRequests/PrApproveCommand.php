<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ApprovePullRequest;

class PrApproveCommand extends BitbucketCommand
{
    protected $signature = 'pr:approve
        {repo : Repository as "workspace/repo" or just "repo"}
        {id : Pull request id}';

    protected $description = 'Approve a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;
        $id = $this->argument('id');

        $response = $this->send(new ApprovePullRequest($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Approved PR #{$id}.");

        return self::SUCCESS;
    }
}

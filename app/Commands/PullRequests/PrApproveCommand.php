<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ApprovePullRequest;

class PrApproveCommand extends BitbucketCommand
{
    protected $signature = 'pr:approve
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Approve a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
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

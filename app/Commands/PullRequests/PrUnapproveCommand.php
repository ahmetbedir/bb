<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\UnapprovePullRequest;

class PrUnapproveCommand extends BitbucketCommand
{
    protected $signature = 'pr:unapprove
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Remove your approval from a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        if (blank($id = $this->resolvePrId($workspace, $slug, $this->argument('id')))) {
            return self::FAILURE;
        }

        if ($this->send(new UnapprovePullRequest($workspace, $slug, $id)) === null) {
            return self::FAILURE;
        }

        $this->info("Removed approval from PR #{$id}.");

        return self::SUCCESS;
    }
}

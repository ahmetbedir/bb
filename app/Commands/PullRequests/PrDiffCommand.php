<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequestDiff;

class PrDiffCommand extends BitbucketCommand
{
    protected $signature = 'pr:diff
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Show the diff for a pull request';

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

        $response = $this->send(new GetPullRequestDiff($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->pager($this->colorizeDiff($response->body()));

        return self::SUCCESS;
    }
}

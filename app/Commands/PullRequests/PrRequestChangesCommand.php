<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequestComment;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\RequestChangesPullRequest;

class PrRequestChangesCommand extends BitbucketCommand
{
    protected $signature = 'pr:request-changes
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--body= : Optional comment to leave with the review}';

    protected $description = 'Request changes on a pull request';

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

        if (filled($body = $this->option('body'))) {
            if ($this->send(new CreatePullRequestComment($workspace, $slug, $id, $body)) === null) {
                return self::FAILURE;
            }
        }

        $response = $this->send(new RequestChangesPullRequest($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Requested changes on PR #{$id}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequest;

class PrCheckoutCommand extends BitbucketCommand
{
    protected $signature = 'pr:checkout
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--remote=origin : Git remote to fetch from}';

    protected $description = 'Check out a pull request branch locally';

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

        $response = $this->send(new GetPullRequest($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $branch = $response->json('source.branch.name');

        if (blank($branch)) {
            $this->error("Could not determine the source branch for PR #{$id}.");

            return self::FAILURE;
        }

        $remote = (string) $this->option('remote');

        $this->line("Fetching branch \"{$branch}\" for PR #{$id}...");

        if (! $this->git(['fetch', $remote, $branch])) {
            return self::FAILURE;
        }

        return $this->git(['checkout', $branch]) ? self::SUCCESS : self::FAILURE;
    }
}

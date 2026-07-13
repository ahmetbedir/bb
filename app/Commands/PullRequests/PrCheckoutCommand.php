<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequest;

class PrCheckoutCommand extends BitbucketCommand
{
    protected $signature = 'pr:checkout
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--remote=origin : Git remote for the target (destination) repository}';

    protected $description = 'Check out a pull request branch locally (fork-aware)';

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

        $targetFullName = "{$workspace}/{$slug}";
        $sourceFullName = $response->json('source.repository.full_name') ?? $targetFullName;

        return $sourceFullName === $targetFullName
            ? $this->checkoutSameRepo($branch)
            : $this->checkoutFork($id, $branch, $sourceFullName);
    }

    /**
     * PR from a branch in the same repository — fetch and check out directly.
     */
    private function checkoutSameRepo(string $branch): int
    {
        $remote = (string) $this->option('remote');

        $this->line("Fetching \"{$branch}\" from {$remote}...");

        if (! $this->git(['fetch', $remote, $branch])) {
            return self::FAILURE;
        }

        return $this->git(['checkout', $branch]) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * PR from a fork — add the fork as a remote, then check out a local
     * pr-<id> branch tracking the fork's source branch.
     */
    private function checkoutFork(int|string $id, string $branch, string $sourceFullName): int
    {
        $forkOwner = explode('/', $sourceFullName)[0];
        $forkUrl = "git@bitbucket.org:{$sourceFullName}.git";
        $local = "pr-{$id}";

        if (! $this->gitQuiet(['remote', 'get-url', $forkOwner])) {
            $this->line("Adding fork remote \"{$forkOwner}\" → {$forkUrl}");

            if (! $this->git(['remote', 'add', $forkOwner, $forkUrl])) {
                return self::FAILURE;
            }
        }

        $this->line("Fetching \"{$branch}\" from fork \"{$forkOwner}\"...");

        if (! $this->git(['fetch', $forkOwner, $branch])) {
            return self::FAILURE;
        }

        // -B resets the local branch if it already exists, so re-running is safe.
        return $this->git(['checkout', '-B', $local, "{$forkOwner}/{$branch}"])
            ? self::SUCCESS
            : self::FAILURE;
    }
}

<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\MergePullRequest;

class PrMergeCommand extends BitbucketCommand
{
    protected $signature = 'pr:merge
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--strategy=merge_commit : merge_commit, squash or fast_forward}
        {--message= : Custom merge commit message}
        {--close-source-branch : Close the source branch after merge}';

    protected $description = 'Merge a pull request';

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

        if (! $this->confirm("Merge PR #{$id} in {$workspace}/{$slug}?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $response = $this->send(new MergePullRequest(
            workspace: $workspace,
            repoSlug: $slug,
            id: $id,
            mergeStrategy: (string) $this->option('strategy'),
            message: $this->option('message') ?: null,
            closeSourceBranch: (bool) $this->option('close-source-branch'),
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Merged PR #{$id}.");

        return self::SUCCESS;
    }
}

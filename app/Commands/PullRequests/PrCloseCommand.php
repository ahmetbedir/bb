<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\DeclinePullRequest;

class PrCloseCommand extends BitbucketCommand
{
    protected $signature = 'pr:close
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Decline (close) a pull request';

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

        if (! $this->option('yes') && ! $this->confirm("Decline PR #{$id} in {$workspace}/{$slug}?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $response = $this->send(new DeclinePullRequest($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Declined PR #{$id}.");

        return self::SUCCESS;
    }
}

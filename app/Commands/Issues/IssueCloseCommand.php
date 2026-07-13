<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\UpdateIssue;

class IssueCloseCommand extends BitbucketCommand
{
    protected $signature = 'issue:close
        {id : Issue id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--state=resolved : Closing state: resolved, closed, wontfix, invalid or duplicate}';

    protected $description = 'Close an issue';

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
        $state = (string) $this->option('state');

        $response = $this->send(new UpdateIssue($workspace, $slug, $id, ['state' => $state]));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Issue #{$id} set to \"{$state}\".");

        return self::SUCCESS;
    }
}

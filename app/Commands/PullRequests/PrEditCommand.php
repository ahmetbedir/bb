<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\UpdatePullRequest;

class PrEditCommand extends BitbucketCommand
{
    protected $signature = 'pr:edit
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--title= : New title}
        {--description= : New description}
        {--dest= : New destination branch}';

    protected $description = 'Edit a pull request (title, description, destination)';

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

        $changes = [];

        if (filled($this->option('title'))) {
            $changes['title'] = $this->option('title');
        }

        if ($this->option('description') !== null) {
            $changes['description'] = $this->option('description');
        }

        if (filled($this->option('dest'))) {
            $changes['destination'] = ['branch' => ['name' => $this->option('dest')]];
        }

        if ($changes === []) {
            $this->error('Nothing to update. Pass --title, --description or --dest.');

            return self::FAILURE;
        }

        $response = $this->send(new UpdatePullRequest($workspace, $slug, $id, $changes));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Updated PR #{$id}.");

        return self::SUCCESS;
    }
}

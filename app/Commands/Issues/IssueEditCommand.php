<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\UpdateIssue;

class IssueEditCommand extends BitbucketCommand
{
    protected $signature = 'issue:edit
        {id : Issue id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--title= : New title}
        {--content= : New description (raw markdown)}
        {--kind= : bug, enhancement, proposal or task}
        {--priority= : trivial, minor, major, critical or blocker}';

    protected $description = 'Edit an issue';

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

        if ($this->option('content') !== null) {
            $changes['content'] = ['raw' => $this->option('content')];
        }

        if (filled($this->option('kind'))) {
            $changes['kind'] = $this->option('kind');
        }

        if (filled($this->option('priority'))) {
            $changes['priority'] = $this->option('priority');
        }

        if ($changes === []) {
            $this->error('Nothing to update. Pass --title, --content, --kind or --priority.');

            return self::FAILURE;
        }

        $response = $this->send(new UpdateIssue($workspace, $slug, $id, $changes));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Updated issue #{$id}.");

        return self::SUCCESS;
    }
}

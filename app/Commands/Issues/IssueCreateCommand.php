<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\CreateIssue;

use function Laravel\Prompts\text;

class IssueCreateCommand extends BitbucketCommand
{
    protected $signature = 'issue:create
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--title= : Issue title}
        {--content= : Issue description (raw markdown)}
        {--kind=bug : bug, enhancement, proposal or task}
        {--priority=major : trivial, minor, major, critical or blocker}';

    protected $description = 'Create an issue';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $title = $this->option('title') ?: text('Title', required: true);

        $response = $this->send(new CreateIssue(
            workspace: $workspace,
            repoSlug: $slug,
            title: $title,
            content: $this->option('content') ?: null,
            kind: $this->option('kind') ?: null,
            priority: $this->option('priority') ?: null,
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $issue = $response->json();
        $this->info("Created issue #{$issue['id']}: {$issue['title']}");
        $this->line(' '.($issue['links']['html']['href'] ?? ''));

        return self::SUCCESS;
    }
}

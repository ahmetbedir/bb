<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\CreateIssueComment;

use function Laravel\Prompts\text;

class IssueCommentCommand extends BitbucketCommand
{
    protected $signature = 'issue:comment
        {id : Issue id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--body= : Comment body (raw markdown)}';

    protected $description = 'Add a comment to an issue';

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

        $body = $this->option('body') ?: text('Comment', required: true);

        $response = $this->send(new CreateIssueComment($workspace, $slug, $id, $body));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Commented on issue #{$id}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequestComment;

use function Laravel\Prompts\text;

class PrCommentCommand extends BitbucketCommand
{
    protected $signature = 'pr:comment
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--body= : Comment body (raw markdown)}';

    protected $description = 'Add a comment to a pull request';

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

        $response = $this->send(new CreatePullRequestComment($workspace, $slug, $id, $body));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Commented on PR #{$id}.");
        $this->line(' '.($response->json('links.html.href') ?? ''));

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequest;
use App\Support\GitContext;

use function Laravel\Prompts\text;

class PrCreateCommand extends BitbucketCommand
{
    protected $signature = 'pr:create
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--title= : Pull request title}
        {--source= : Source branch name (defaults to the current git branch)}
        {--dest= : Destination branch name (defaults to the main branch)}
        {--description= : Pull request description}
        {--close-source-branch : Close the source branch after merge}
        {--web : Open the created pull request in a browser}';

    protected $description = 'Create a pull request';

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
        $source = $this->option('source')
            ?: app(GitContext::class)->currentBranch()
            ?: text('Source branch', required: true);
        $dest = $this->option('dest') ?: null;
        $description = $this->option('description') ?: null;

        $response = $this->send(new CreatePullRequest(
            workspace: $workspace,
            repoSlug: $slug,
            title: $title,
            sourceBranch: $source,
            destinationBranch: $dest,
            description: $description,
            closeSourceBranch: (bool) $this->option('close-source-branch'),
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $pr = $response->json();
        $this->info("Created PR #{$pr['id']}: {$pr['title']}");
        $url = $pr['links']['html']['href'] ?? '';
        $this->line(' '.$url);

        if ($this->option('web') && filled($url)) {
            $this->openInBrowser($url);
        }

        return self::SUCCESS;
    }
}

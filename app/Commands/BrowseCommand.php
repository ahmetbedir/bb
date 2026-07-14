<?php

namespace App\Commands;

class BrowseCommand extends BitbucketCommand
{
    protected $signature = 'browse
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = "Open the repository's Bitbucket page in your browser";

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $this->openInBrowser($this->repoWebUrl($workspace, $slug));

        return self::SUCCESS;
    }
}

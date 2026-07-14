<?php

namespace App\Commands;

class BrowseShowCommand extends BitbucketCommand
{
    protected $signature = 'browse:show
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Print the repository Bitbucket URL without opening a browser';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $this->output->writeln($this->repoWebUrl($workspace, $slug));

        return self::SUCCESS;
    }
}

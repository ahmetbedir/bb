<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\GetRepository;

class RepoCloneCommand extends BitbucketCommand
{
    protected $signature = 'repo:clone
        {repo : Repository to clone as "workspace/repo" or just "repo"}
        {directory? : Directory to clone into}
        {--https : Clone over HTTPS instead of SSH}';

    protected $description = 'Clone a repository with git';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $repo = $this->argument('repo');

        if (str_contains($repo, '/')) {
            [$workspace, $slug] = explode('/', $repo, 2);
        } elseif (filled($workspace = $this->credentials()->get('workspace'))) {
            $slug = $repo;
        } else {
            $this->error("No workspace given. Use \"workspace/{$repo}\" or set a default with: bbcli configure");

            return self::FAILURE;
        }

        // Fetch the repo so we use the canonical clone URL (handles renames).
        $response = $this->send(new GetRepository($workspace, $slug));

        if ($response === null) {
            return self::FAILURE;
        }

        $wanted = $this->option('https') ? 'https' : 'ssh';
        $cloneUrl = collect($response->json('links.clone') ?? [])
            ->firstWhere('name', $wanted)['href'] ?? null;

        if (blank($cloneUrl)) {
            $this->error("No {$wanted} clone URL available for {$workspace}/{$slug}.");

            return self::FAILURE;
        }

        $args = ['clone', $cloneUrl];
        if (filled($directory = $this->argument('directory'))) {
            $args[] = $directory;
        }

        return $this->git($args) ? self::SUCCESS : self::FAILURE;
    }
}

<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\GetRepository;

class RepoShowCommand extends BitbucketCommand
{
    protected $signature = 'repo:show
        {repo : Repository as "workspace/repo" or just "repo"}';

    protected $description = 'Show details for a single repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $response = $this->send(new GetRepository($workspace, $slug));

        if ($response === null) {
            return self::FAILURE;
        }

        $r = $response->json();

        $cloneHttps = collect($r['links']['clone'] ?? [])->firstWhere('name', 'https')['href'] ?? '';
        $cloneSsh = collect($r['links']['clone'] ?? [])->firstWhere('name', 'ssh')['href'] ?? '';

        $this->table([], [
            ['Full name', $r['full_name'] ?? ''],
            ['Description', $r['description'] ?? ''],
            ['Private', ($r['is_private'] ?? false) ? 'yes' : 'no'],
            ['Language', $r['language'] ?? ''],
            ['Main branch', $r['mainbranch']['name'] ?? ''],
            ['Size', isset($r['size']) ? number_format($r['size'] / 1024, 1).' KB' : ''],
            ['Updated', $r['updated_on'] ?? ''],
            ['Web', $r['links']['html']['href'] ?? ''],
            ['Clone (HTTPS)', $cloneHttps],
            ['Clone (SSH)', $cloneSsh],
        ]);

        return self::SUCCESS;
    }
}

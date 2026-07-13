<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\GetRepository;

class RepoShowCommand extends BitbucketCommand
{
    protected $signature = 'repo:show
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--web : Open the repository in a browser instead of printing details}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show details for a single repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        if ($this->option('web')) {
            $this->openInBrowser("https://bitbucket.org/{$workspace}/{$slug}");

            return self::SUCCESS;
        }

        $response = $this->send(new GetRepository($workspace, $slug));

        if ($response === null) {
            return self::FAILURE;
        }

        $r = $response->json();

        if ($this->wantsJson()) {
            return $this->outputJson($r);
        }

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

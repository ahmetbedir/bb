<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\ListRepositories;

class RepoListCommand extends BitbucketCommand
{
    protected $signature = 'repo:list
        {workspace? : Workspace to list (defaults to configured workspace)}
        {--limit=50 : Maximum number of repositories to show}
        {--q= : Bitbucket query filter, e.g. "name ~ \"api\""}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List repositories in a workspace';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $workspace = $this->argument('workspace') ?: $this->credentials()->get('workspace');

        if (blank($workspace)) {
            $this->error('No workspace given. Pass one as an argument or set a default with: bbcli configure');

            return self::FAILURE;
        }

        $filters = [];
        if (filled($this->option('q'))) {
            $filters['q'] = $this->option('q');
        }

        $repos = $this->collectAll(
            new ListRepositories($workspace, $filters),
            limit: (int) $this->option('limit'),
        );

        if ($repos === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($repos);
        }

        if ($repos === []) {
            $this->info("No repositories found in \"{$workspace}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Slug', 'Private', 'Updated', 'Language'],
            array_map(fn (array $r) => [
                $r['name'] ?? '',
                $r['slug'] ?? '',
                ($r['is_private'] ?? false) ? 'yes' : 'no',
                isset($r['updated_on']) ? substr($r['updated_on'], 0, 10) : '',
                $r['language'] ?? '',
            ], $repos),
        );

        $this->line(' '.count($repos).' repositor'.(count($repos) === 1 ? 'y' : 'ies'));

        return self::SUCCESS;
    }
}

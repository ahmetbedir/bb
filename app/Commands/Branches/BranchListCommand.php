<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Branches\ListBranches;
use Illuminate\Support\Str;

class BranchListCommand extends BitbucketCommand
{
    protected $signature = 'branch:list
        {repo : Repository as "workspace/repo" or just "repo"}
        {--q= : Bitbucket query filter, e.g. "name ~ \"feature\""}
        {--limit=50 : Maximum number of branches to show}';

    protected $description = 'List branches for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $filters = [];
        if (filled($this->option('q'))) {
            $filters['q'] = $this->option('q');
        }

        $branches = $this->collectAll(
            new ListBranches($workspace, $slug, $filters),
            limit: (int) $this->option('limit'),
        );

        if ($branches === null) {
            return self::FAILURE;
        }

        if ($branches === []) {
            $this->info("No branches in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Last commit', 'Author', 'Date'],
            array_map(fn (array $b) => [
                $b['name'] ?? '',
                substr($b['target']['hash'] ?? '', 0, 8),
                Str::limit($b['target']['author']['user']['display_name']
                    ?? $b['target']['author']['raw']
                    ?? '', 30),
                isset($b['target']['date']) ? substr($b['target']['date'], 0, 10) : '',
            ], $branches),
        );

        return self::SUCCESS;
    }
}

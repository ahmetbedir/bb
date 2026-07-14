<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Branches\ListBranches;
use Illuminate\Support\Str;

use function Termwind\terminal;

class BranchListCommand extends BitbucketCommand
{
    protected $signature = 'branch:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--name= : Only branches whose name contains this}
        {--user= : Only branches whose latest commit author name contains this}
        {--q= : Raw Bitbucket query filter, e.g. "name ~ \"feature\""}
        {--limit=50 : Maximum number of branches to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List branches for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        // Filter by name server-side (Bitbucket "contains" operator ~).
        $queries = [];
        if (filled($this->option('q'))) {
            $queries[] = $this->option('q');
        }
        if (filled($name = $this->option('name'))) {
            $queries[] = 'name ~ "'.str_replace('"', '', $name).'"';
        }

        $filters = $queries === [] ? [] : ['q' => implode(' AND ', $queries)];

        $branches = $this->collectAll(
            new ListBranches($workspace, $slug, $filters),
            limit: (int) $this->option('limit'),
        );

        if ($branches === null) {
            return self::FAILURE;
        }

        // Filter by latest-commit author client-side.
        if (filled($user = $this->option('user'))) {
            $needle = mb_strtolower($user);
            $branches = array_values(array_filter($branches, function (array $b) use ($needle) {
                $author = mb_strtolower(
                    data_get($b, 'target.author.user.display_name')
                    ?? data_get($b, 'target.author.raw')
                    ?? ''
                );

                return str_contains($author, $needle);
            }));
        }

        if ($this->wantsJson()) {
            return $this->outputJson($branches);
        }

        if ($branches === []) {
            $this->info("No branches in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Last commit', 'Author', 'Date'],
            array_map(fn (array $b) => [
                $this->link(
                    isset($b['name']) ? $this->repoWebUrl($workspace, $slug).'/branch/'.$b['name'] : null,
                    Str::limit($b['name'] ?? '', terminal()->width() / 2),
                ),
                $this->link(
                    isset($b['target']['hash']) ? $this->repoWebUrl($workspace, $slug).'/commits/'.$b['target']['hash'] : null,
                    substr($b['target']['hash'] ?? '', 0, 8),
                ),
                Str::limit($b['target']['author']['user']['display_name']
                    ?? $b['target']['author']['raw']
                    ?? '', 30),
                isset($b['target']['date']) ? substr($b['target']['date'], 0, 10) : '',
            ], $branches),
        );

        return self::SUCCESS;
    }
}

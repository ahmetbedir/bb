<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\ListIssues;
use Illuminate\Support\Str;

class IssueListCommand extends BitbucketCommand
{
    protected $signature = 'issue:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--state= : Filter by state: new, open, resolved, closed, on hold, invalid, duplicate, wontfix}
        {--limit=50 : Maximum number of issues to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List issues for a repository (issue tracker must be enabled)';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $filters = [];
        if (filled($this->option('state'))) {
            $filters['q'] = 'state="'.$this->option('state').'"';
        }

        $issues = $this->collectAll(
            new ListIssues($workspace, $slug, $filters),
            limit: (int) $this->option('limit'),
        );

        if ($issues === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($issues);
        }

        if ($issues === []) {
            $this->info("No issues in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Title', 'Kind', 'Priority', 'State', 'Assignee', 'Updated'],
            array_map(fn (array $i) => [
                $this->link($i['links']['html']['href'] ?? null, $i['id'] ?? ''),
                Str::limit($i['title'] ?? '', 40),
                $i['kind'] ?? '',
                $i['priority'] ?? '',
                $i['state'] ?? '',
                $i['assignee']['display_name'] ?? '-',
                isset($i['updated_on']) ? substr($i['updated_on'], 0, 10) : '',
            ], $issues),
        );

        return self::SUCCESS;
    }
}

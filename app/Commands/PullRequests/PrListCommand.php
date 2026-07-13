<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use Illuminate\Support\Str;

class PrListCommand extends BitbucketCommand
{
    protected $signature = 'pr:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--state=OPEN : OPEN, MERGED, DECLINED or SUPERSEDED}
        {--limit=50 : Maximum number of pull requests to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List pull requests for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;
        $state = strtoupper((string) $this->option('state'));

        $prs = $this->collectAll(
            new ListPullRequests($workspace, $slug, $state),
            limit: (int) $this->option('limit'),
        );

        if ($prs === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($prs);
        }

        if ($prs === []) {
            $this->info("No {$state} pull requests in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Title', 'State', 'Author', 'Source', 'Dest', 'Updated'],
            array_map(fn (array $pr) => [
                $pr['id'] ?? '',
                Str::limit($pr['title'] ?? '', 40),
                $pr['state'] ?? '',
                $pr['author']['display_name'] ?? '',
                Str::limit($pr['source']['branch']['name'] ?? '', 24),
                Str::limit($pr['destination']['branch']['name'] ?? '', 24),
                isset($pr['updated_on']) ? substr($pr['updated_on'], 0, 10) : '',
            ], $prs),
        );

        return self::SUCCESS;
    }
}

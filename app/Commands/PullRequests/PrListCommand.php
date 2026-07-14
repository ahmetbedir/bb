<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use Illuminate\Support\Str;

class PrListCommand extends BitbucketCommand
{
    protected $signature = 'pr:list
        {branch? : Only list PRs whose source branch is this}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--state=OPEN : OPEN, MERGED, DECLINED or SUPERSEDED}
        {--limit=50 : Maximum number of pull requests to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}
        {--boxed : Display pull requests in a box instead of a table}';

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
        $branch = $this->argument('branch');

        // When a branch is given, put both state and source branch into `q`
        // (Bitbucket ignores the `state` param when `q` is present).
        $filters = filled($branch)
            ? ['q' => "state=\"{$state}\" AND source.branch.name=\"{$branch}\""]
            : [];

        $prs = $this->collectAll(
            new ListPullRequests($workspace, $slug, $state, $filters),
            limit: (int) $this->option('limit'),
        );

        if ($prs === null) {
            return self::FAILURE;
        }

        // Defensively enforce the source-branch filter client-side too.
        if (filled($branch)) {
            $prs = array_values(array_filter($prs, fn ($pr) => data_get($pr, 'source.branch.name') === $branch));
        }

        if ($this->wantsJson()) {
            return $this->outputJson($prs);
        }

        if ($prs === []) {
            $this->info("No {$state} pull requests in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $asBox = (bool) $this->option('boxed');
        if ($asBox) {
            $this->displayBox($prs);
        } else {
            $this->displayTable($prs);
        }

        return self::SUCCESS;
    }

    protected function displayTable(array $prs): void
    {

        $this->table(
            ['#', 'Title', 'State', 'Author', 'Source', 'Dest', 'Updated'],
            array_map(fn (array $pr) => [
                $this->link($pr['links']['html']['href'] ?? null, $pr['id'] ?? ''),
                Str::limit($pr['title'] ?? '', 40),
                $this->badge($pr['state'] ?? ''),
                $pr['author']['display_name'] ?? '',
                $pr['source']['branch']['name'] ?? '',
                $pr['destination']['branch']['name'] ?? '',
                isset($pr['updated_on']) ? substr($pr['updated_on'], 0, 10) : '',
            ], $prs),
        );
    }

    protected function displayBox(array $prs): void
    {
        foreach ($prs as $pr) {
            $this->table([], [
                ['#', $this->link($pr['links']['html']['href'] ?? null, $pr['id'] ?? '')],
                ['Title', $pr['title'] ?? ''],
                ['State', $this->badge($pr['state'] ?? '')],
                ['Author', $pr['author']['display_name'] ?? ''],
                ['Source', $pr['source']['branch']['name'] ?? ''],
                ['Destination', $pr['destination']['branch']['name'] ?? ''],
                ['Reviewers', collect($pr['reviewers'] ?? [])->pluck('display_name')->implode(', ')],
                ['Comments', $pr['comment_count'] ?? 0],
                ['Created', $pr['created_on'] ?? ''],
                ['Updated', $pr['updated_on'] ?? ''],
                ['Web', $pr['links']['html']['href'] ?? ''],
            ]);
        }
    }
}

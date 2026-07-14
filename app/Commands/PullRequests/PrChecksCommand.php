<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestStatuses;

class PrChecksCommand extends BitbucketCommand
{
    protected $signature = 'pr:checks
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--watch : Poll until all checks finish, then notify}
        {--interval=10 : Seconds between polls when watching}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show commit build statuses (CI / pipelines) for a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        if (blank($id = $this->resolvePrId($workspace, $slug, $this->argument('id')))) {
            return self::FAILURE;
        }

        if ($this->option('watch') && ! $this->wantsJson()) {
            $this->waitForChecks($workspace, $slug, $id);
        }

        $statuses = $this->collectAll(new ListPullRequestStatuses($workspace, $slug, $id));

        if ($statuses === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($statuses);
        }

        if ($statuses === []) {
            $this->info("No build statuses reported for PR #{$id}.");

            return self::SUCCESS;
        }

        $this->table(
            ['State', 'Name', 'Key', 'URL'],
            array_map(fn (array $s) => [
                $this->badge($s['state'] ?? ''),
                $s['name'] ?? '',
                $s['key'] ?? '',
                $this->link($s['url'] ?? null, $s['url'] ?? ''),
            ], $statuses),
        );

        $failed = collect($statuses)->contains(fn ($s) => ($s['state'] ?? '') === 'FAILED');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Poll until no check is still INPROGRESS, then fire a notification.
     */
    private function waitForChecks(string $workspace, string $slug, int|string $id): void
    {
        $interval = max(3, (int) $this->option('interval'));

        while (true) {
            $statuses = $this->collectAll(new ListPullRequestStatuses($workspace, $slug, $id));

            if (blank($statuses)) {
                return;
            }

            $pending = collect($statuses)->filter(fn ($s) => ($s['state'] ?? '') === 'INPROGRESS')->count();

            if ($pending === 0) {
                $failed = collect($statuses)->contains(fn ($s) => ($s['state'] ?? '') === 'FAILED');
                $this->notify("PR #{$id} checks", $failed ? 'A check failed' : 'All checks passed');

                return;
            }

            $this->line("  {$pending} check(s) still running for PR #{$id} — polling every {$interval}s...");
            sleep($interval);
        }
    }
}

<?php

namespace App\Commands\Deployments;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithDeployments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListDeployments;

class DeploymentListCommand extends BitbucketCommand
{
    use InteractsWithDeployments;

    protected $signature = 'deployment:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--e|environment= : Only deployments to this environment (name, slug or UUID)}
        {--status= : Only deployments in this state (SUCCESSFUL, FAILED, IN_PROGRESS, UNDEPLOYED, ...)}
        {--limit=20 : Maximum number of deployments to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List deployments, newest first';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $environmentUuid = null;

        if (filled($ref = $this->option('environment'))) {
            if (($environment = $this->resolveEnvironment($workspace, $slug, (string) $ref)) === null) {
                return self::FAILURE;
            }

            $environmentUuid = $environment['uuid'] ?? null;
        }

        $limit = max(1, (int) $this->option('limit'));
        $status = filled($this->option('status')) ? strtoupper((string) $this->option('status')) : null;

        // The API cannot filter by status, so when one is asked for a wider
        // window is fetched and narrowed here.
        $deployments = $this->collectAll(
            new ListDeployments($workspace, $slug, $environmentUuid),
            limit: $status === null ? $limit : min($this->deploymentScanLimit, $limit * 10),
        );

        if ($deployments === null) {
            return self::FAILURE;
        }

        if ($status !== null) {
            $deployments = collect($deployments)
                ->filter(fn (array $d) => $this->stateOf($d) === $status || data_get($d, 'state.name') === $status)
                ->take($limit)
                ->values()
                ->all();
        }

        if ($this->wantsJson()) {
            return $this->outputJson($deployments);
        }

        if ($deployments === []) {
            $this->info($status === null
                ? "No deployments found in \"{$workspace}/{$slug}\"."
                : "No {$status} deployments among the most recent ones — deployments that never ran (UNDEPLOYED) "
                    .'sort last, so raise --limit or filter with --json.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Environment', 'Status', 'Release', 'Commit', 'Started', 'Duration'],
            array_map(fn (array $d) => [
                $this->link(data_get($d, 'state.url'), $d['number'] ?? ''),
                data_get($d, 'environment.name', ''),
                $this->badge($this->stateOf($d)),
                $this->link(data_get($d, 'release.url'), (string) data_get($d, 'release.name', '')),
                substr((string) data_get($d, 'release.commit.hash', ''), 0, 8),
                $this->formatTimestamp(data_get($d, 'state.started_on')),
                $this->formatDuration($this->elapsedSeconds(
                    data_get($d, 'state.started_on'),
                    data_get($d, 'state.completed_on'),
                )),
            ], $deployments),
        );

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipeline;

class PipelineViewCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:view
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, use the latest one on this branch}
        {--web : Open the pipeline in a browser instead}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show a pipeline, its steps and their timings';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $ref = $this->resolvePipelineRef($workspace, $slug, $this->argument('pipeline'));

        if ($ref === null) {
            return self::FAILURE;
        }

        $response = $this->send(new GetPipeline($workspace, $slug, $ref));

        if ($response === null) {
            return self::FAILURE;
        }

        $p = $response->json();
        $url = $this->repoWebUrl($workspace, $slug).'/pipelines/results/'.($p['build_number'] ?? '');

        if ($this->option('web')) {
            $this->openInBrowser($url);

            return self::SUCCESS;
        }

        $steps = $this->pipelineSteps($workspace, $slug, $ref);

        if ($steps === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson(['pipeline' => $p, 'steps' => $steps]);
        }

        $this->renderPipeline($p, $url);
        $this->renderSteps($steps, (string) ($p['build_number'] ?? ''));

        return $this->stateOf($p) === 'FAILED' ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function renderPipeline(array $p, string $url): void
    {
        $selector = data_get($p, 'target.selector.pattern')
            ?? data_get($p, 'target.selector.type');

        $this->detail([
            'Build #' => $this->link($url, $p['build_number'] ?? ''),
            'Status' => $this->badgeHtml($this->stateOf($p)),
            'Ref' => e(trim(strtolower((string) data_get($p, 'target.ref_type', '')).' '.data_get($p, 'target.ref_name', ''))),
            'Commit' => e(substr((string) data_get($p, 'target.commit.hash', ''), 0, 12)),
            'Pipeline' => e((string) $selector),
            'Trigger' => e((string) data_get($p, 'trigger.name', '')),
            'Creator' => e((string) data_get($p, 'creator.display_name', '')),
            'Created' => e($this->formatTimestamp($p['created_on'] ?? null, relative: true)),
            'Completed' => e($this->formatTimestamp($p['completed_on'] ?? null)),
            'Duration' => e($this->formatDuration($p['duration_in_seconds'] ?? null)),
            'Build time' => isset($p['build_seconds_used']) && $p['build_seconds_used'] > 0
                ? e($this->formatDuration($p['build_seconds_used']).' of build minutes')
                : '',
            'Run' => ($p['run_number'] ?? 1) > 1 ? e('#'.$p['run_number']) : '',
            'Logs' => ($p['expired'] ?? false) ? e('expired — Bitbucket no longer keeps them') : '',
            'UUID' => e((string) ($p['uuid'] ?? '')),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function renderSteps(array $steps, string $buildNumber): void
    {
        if ($steps === []) {
            return;
        }

        $this->line(' <options=bold>Steps</>');

        $this->table(
            ['#', 'Status', 'Name', 'Duration', 'Started', 'Image'],
            array_map(fn (array $step) => [
                $step['_index'],
                $this->badge($this->stateOf($step)),
                $step['name'] ?? '(unnamed)',
                $this->formatDuration($step['duration_in_seconds'] ?? null),
                $this->formatTimestamp($step['started_on'] ?? null, format: 'H:i'),
                data_get($step, 'image.name', 'default'),
            ], $steps),
        );

        $this->line(" Step detail: <options=bold>bb pipeline:step {$buildNumber} 1</>"
            ." · logs: <options=bold>bb pipeline:logs {$buildNumber} 1</>");
    }
}

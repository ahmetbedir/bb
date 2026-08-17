<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipeline;

class PipelineWatchCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:watch
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, watch the latest one on this branch}
        {--interval=5 : Seconds between polls}';

    protected $description = 'Watch a pipeline step by step until it finishes, then notify';

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

        $interval = max(2, (int) $this->option('interval'));
        $buildNumber = $this->resolvedBuildNumber;
        $reported = [];

        while (true) {
            $response = $this->send(new GetPipeline($workspace, $slug, $ref));

            if ($response === null) {
                return self::FAILURE;
            }

            $p = $response->json();
            $buildNumber ??= $p['build_number'] ?? '?';

            $this->reportSteps($workspace, $slug, $ref, $reported);

            $stateName = data_get($p, 'state.name', '');
            $result = data_get($p, 'state.result.name');

            if ($stateName === 'COMPLETED' || $result !== null) {
                $result = (string) ($result ?: 'COMPLETED');

                $this->line('  '.$this->badge($result)." pipeline #{$buildNumber} in "
                    .$this->formatDuration($p['duration_in_seconds'] ?? null));
                $this->notify("Pipeline #{$buildNumber}", "Finished: {$result}");

                if ($result !== 'SUCCESSFUL') {
                    $this->line("  Logs: <options=bold>bb pipeline:logs {$buildNumber}</>");
                }

                return $result === 'SUCCESSFUL' ? self::SUCCESS : self::FAILURE;
            }

            $stage = data_get($p, 'state.stage.name');
            $this->line('  '.$this->badge($stage ?: $stateName)." pipeline #{$buildNumber} — polling every {$interval}s...");

            sleep($interval);
        }
    }

    /**
     * Print each step the moment it reaches a new state, so watching reads
     * like a progress log rather than a repeated status line.
     *
     * @param  array<string, string>  $reported  step uuid => last printed state
     */
    private function reportSteps(string $workspace, string $slug, string $ref, array &$reported): void
    {
        $steps = $this->pipelineSteps($workspace, $slug, $ref);

        if ($steps === null || $steps === []) {
            return;
        }

        foreach ($steps as $step) {
            $uuid = (string) ($step['uuid'] ?? '');
            $state = $this->stateOf($step);

            if ($state === '' || ($reported[$uuid] ?? null) === $state) {
                continue;
            }

            $reported[$uuid] = $state;

            $duration = $this->formatDuration($step['duration_in_seconds'] ?? null);

            $this->line('  '.$this->badge($state).' '.$step['_index'].'. '.($step['name'] ?? '(unnamed)')
                .($duration !== '' ? " <fg=gray>({$duration})</>" : ''));
        }
    }
}

<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipeline;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;

class PipelineWatchCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:watch
        {uuid? : Pipeline UUID (defaults to the most recent pipeline)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--interval=5 : Seconds between polls}';

    protected $description = 'Watch a pipeline until it finishes, then notify';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $uuid = $this->argument('uuid') ?: $this->latestPipelineUuid($workspace, $slug);

        if (blank($uuid)) {
            return self::FAILURE;
        }

        $interval = max(2, (int) $this->option('interval'));
        $buildNumber = null;

        while (true) {
            $response = $this->send(new GetPipeline($workspace, $slug, $uuid));

            if ($response === null) {
                return self::FAILURE;
            }

            $p = $response->json();
            $buildNumber ??= $p['build_number'] ?? '?';
            $stateName = $p['state']['name'] ?? '';
            $result = $p['state']['result']['name'] ?? null;

            if ($stateName === 'COMPLETED' || $result !== null) {
                $this->line('  '.$this->badge($result ?? 'COMPLETED')." pipeline #{$buildNumber}");
                $this->notify("Pipeline #{$buildNumber}", "Finished: {$result}");

                return $result === 'SUCCESSFUL' ? self::SUCCESS : self::FAILURE;
            }

            $this->line('  '.$this->badge($stateName)." pipeline #{$buildNumber} — polling every {$interval}s...");
            sleep($interval);
        }
    }

    private function latestPipelineUuid(string $workspace, string $slug): ?string
    {
        $pipelines = $this->collectAll(new ListPipelines($workspace, $slug), limit: 1);

        if (blank($pipelines)) {
            $this->error("No pipelines found in \"{$workspace}/{$slug}\".");

            return null;
        }

        return $pipelines[0]['uuid'] ?? null;
    }
}

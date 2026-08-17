<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\StopPipeline;

class PipelineStopCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:stop
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, stop the latest one on this branch}';

    protected $description = 'Stop a running pipeline';

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

        if ($this->send(new StopPipeline($workspace, $slug, $ref)) === null) {
            return self::FAILURE;
        }

        $this->info('Stopped pipeline '.($this->resolvedBuildNumber ? '#'.$this->resolvedBuildNumber : $ref).'.');

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\StopPipeline;

class PipelineStopCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:stop
        {uuid : Pipeline UUID}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

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
        $uuid = $this->argument('uuid');

        $response = $this->send(new StopPipeline($workspace, $slug, $uuid));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->info("Stopped pipeline {$uuid}.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\RunPipeline;
use App\Support\GitContext;

class PipelineRunCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:run
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : Branch to run the pipeline on (defaults to the current git branch)}
        {--pattern= : Run a custom pipeline definition by name}';

    protected $description = 'Trigger a pipeline run';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $branch = $this->option('branch') ?: app(GitContext::class)->currentBranch();

        if (blank($branch)) {
            $this->error('No branch given and none detected. Pass --branch.');

            return self::FAILURE;
        }

        $response = $this->send(new RunPipeline($workspace, $slug, $branch, $this->option('pattern') ?: null));

        if ($response === null) {
            return self::FAILURE;
        }

        $p = $response->json();
        $number = $p['build_number'] ?? '?';
        $this->info("Started pipeline #{$number} on \"{$branch}\".");
        $this->line(' '.$this->repoWebUrl($workspace, $slug)."/pipelines/results/{$number}");

        return self::SUCCESS;
    }
}

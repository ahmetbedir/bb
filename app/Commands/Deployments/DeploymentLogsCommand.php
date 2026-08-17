<?php

namespace App\Commands\Deployments;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithDeployments;
use App\Commands\Concerns\InteractsWithPipelines;

class DeploymentLogsCommand extends BitbucketCommand
{
    use InteractsWithDeployments;
    use InteractsWithPipelines;

    protected $signature = 'deployment:logs
        {deployment? : Deployment number or UUID (defaults to the most recent deployment)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--e|environment= : Restrict the default/lookup to this environment (name, slug or UUID)}
        {--f|follow : Keep printing new output until the deploy step finishes}
        {--interval=5 : Seconds between polls when following}
        {--tail= : Only print the last N lines}
        {--grep= : Only print lines containing this text (case-insensitive)}
        {--output= : Write the raw log to a file instead of the terminal}';

    protected $description = 'Print (or follow) the log of the step that ran a deployment';

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

        $deployment = $this->resolveDeployment($workspace, $slug, $this->argument('deployment'), $environmentUuid);

        if ($deployment === null) {
            return self::FAILURE;
        }

        if (($stepRef = $this->deploymentStepRef($deployment)) === null) {
            $this->error('This deployment has no pipeline step to read logs from.');

            return self::FAILURE;
        }

        [$pipeline, $stepUuid] = $stepRef;

        $steps = $this->pipelineSteps($workspace, $slug, $pipeline);

        if ($steps === null) {
            return self::FAILURE;
        }

        $step = collect($steps)->first(fn (array $s) => $this->bareUuid($s['uuid'] ?? '') === $this->bareUuid($stepUuid));

        if ($step === null) {
            $this->error('The deploying step is no longer part of that pipeline.');

            return self::FAILURE;
        }

        if ($this->option('follow')) {
            return $this->followStepLog($workspace, $slug, $pipeline, $step, (int) $this->option('interval'));
        }

        $log = $this->fetchStepLog($workspace, $slug, $pipeline, $step);

        if ($log === null) {
            return self::FAILURE;
        }

        if (trim($log) === '') {
            $this->warn('The log for this deployment step is empty.');

            return self::SUCCESS;
        }

        return $this->emitLog($log);
    }
}

<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;

class PipelineLogsCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:logs
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {step? : Step number, name fragment or UUID (defaults to the failed step, else prompts)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, use the latest one on this branch}
        {--all : Print the log of every step}
        {--f|follow : Keep printing new output until the step finishes}
        {--interval=5 : Seconds between polls when following}
        {--tail= : Only print the last N lines}
        {--grep= : Only print lines containing this text (case-insensitive)}
        {--output= : Write the raw log to a file instead of the terminal}';

    protected $description = 'Print (or follow) the build log of a pipeline step';

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

        $steps = $this->pipelineSteps($workspace, $slug, $ref);

        if ($steps === null) {
            return self::FAILURE;
        }

        if ($this->option('all')) {
            return $this->printAll($workspace, $slug, $ref, $steps);
        }

        $step = $this->pickStep($steps, $this->argument('step'), preferFailed: true);

        if ($step === null) {
            return self::FAILURE;
        }

        if ($this->option('follow')) {
            return $this->followStepLog($workspace, $slug, $ref, $step, (int) $this->option('interval'));
        }

        $log = $this->fetchStepLog($workspace, $slug, $ref, $step);

        if ($log === null) {
            return self::FAILURE;
        }

        if (trim($log) === '') {
            $this->warn('The log for this step is empty.');

            return self::SUCCESS;
        }

        return $this->emitLog($log);
    }

    /**
     * Concatenate every step's log behind a header per step.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    private function printAll(string $workspace, string $slug, string $ref, array $steps): int
    {
        $combined = '';

        foreach ($steps as $step) {
            $log = $this->fetchStepLog($workspace, $slug, $ref, $step);

            if ($log === null) {
                continue;
            }

            $combined .= "===== {$step['_index']}. ".($step['name'] ?? '(unnamed)')
                .' ['.$this->stateOf($step)."] =====\n".$log."\n";
        }

        if ($combined === '') {
            $this->warn('No logs available for this pipeline yet.');

            return self::SUCCESS;
        }

        return $this->emitLog($combined);
    }
}

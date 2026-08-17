<?php

namespace App\Commands\Deployments;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithDeployments;
use App\Commands\Concerns\InteractsWithPipelines;

class DeploymentViewCommand extends BitbucketCommand
{
    use InteractsWithDeployments;
    use InteractsWithPipelines;

    protected $signature = 'deployment:view
        {deployment? : Deployment number or UUID (defaults to the most recent deployment)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--e|environment= : Restrict the default/lookup to this environment (name, slug or UUID)}
        {--web : Open the deployment in a browser instead}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show a deployment in full: release, commit, timings and the step that ran it';

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

        $url = data_get($deployment, 'state.url')
            ?? data_get($deployment, 'state.trigger_url')
            ?? data_get($deployment, 'release.url');

        if ($this->option('web')) {
            if (blank($url)) {
                $this->error('This deployment has no web URL.');

                return self::FAILURE;
            }

            $this->openInBrowser((string) $url);

            return self::SUCCESS;
        }

        // The step that performed the deployment carries the real timings and
        // is what "deployment:logs" prints, so it is shown alongside.
        $step = $this->deploymentStep($workspace, $slug, $deployment);

        if ($this->wantsJson()) {
            return $this->outputJson(['deployment' => $deployment, 'step' => $step]);
        }

        $this->render($deployment, $step, (string) $url);

        return in_array($this->stateOf($deployment), ['FAILED', 'ERROR'], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The pipeline step this deployment ran as, when it can be loaded.
     *
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>|null
     */
    private function deploymentStep(string $workspace, string $slug, array $deployment): ?array
    {
        if (($stepRef = $this->deploymentStepRef($deployment)) === null) {
            return null;
        }

        [$pipeline, $stepUuid] = $stepRef;

        $steps = $this->pipelineSteps($workspace, $slug, $pipeline);

        if ($steps === null) {
            return null;
        }

        return collect($steps)->first(
            fn (array $step) => $this->bareUuid($step['uuid'] ?? '') === $this->bareUuid($stepUuid)
        );
    }

    /**
     * @param  array<string, mixed>  $deployment
     * @param  array<string, mixed>|null  $step
     */
    private function render(array $deployment, ?array $step, string $url): void
    {
        $duration = $this->elapsedSeconds(
            data_get($deployment, 'state.started_on'),
            data_get($deployment, 'state.completed_on'),
        );

        $this->detail([
            'Deployment' => $this->link($url, '#'.($deployment['number'] ?? '')),
            'Status' => $this->badgeHtml($this->stateOf($deployment)),
            'Environment' => e(trim((string) data_get($deployment, 'environment.name', '')
                .' ('.data_get($deployment, 'environment.environment_type.name', '?').')')),
            'Release' => $this->link(
                (string) data_get($deployment, 'release.url', ''),
                (string) data_get($deployment, 'release.name', ''),
            ),
            'Commit' => e(substr((string) data_get($deployment, 'release.commit.hash', ''), 0, 12)),
            'Started' => e($this->formatTimestamp(data_get($deployment, 'state.started_on'), relative: true, format: 'Y-m-d H:i:s')),
            'Completed' => e($this->formatTimestamp(data_get($deployment, 'state.completed_on'), format: 'Y-m-d H:i:s')),
            'Duration' => e($this->formatDuration($duration)),
            'Updated' => e($this->formatTimestamp($deployment['last_update_time'] ?? null)),
            'Step' => $step !== null
                ? e($step['_index'].'. '.($step['name'] ?? '(unnamed)').' — '.$this->stateOf($step)
                    .($step['duration_in_seconds'] ?? null ? ' in '.$this->formatDuration($step['duration_in_seconds']) : ''))
                : e((string) data_get($deployment, 'step.uuid', '')),
            'Trigger' => blank(data_get($deployment, 'state.started_on'))
                ? $this->link((string) data_get($deployment, 'state.trigger_url', ''), 'not deployed yet — start it in Bitbucket')
                : '',
            'Previous' => filled($previous = data_get($deployment, 'state.last_successful_deployment.number'))
                ? e('#'.$previous.' (last successful)')
                : '',
            'UUID' => e((string) ($deployment['uuid'] ?? '')),
        ]);

        if ($this->deploymentStepRef($deployment) !== null) {
            $this->line(' Logs: <options=bold>bb deployment:logs #'.($deployment['number'] ?? '').'</>');
        }
    }
}

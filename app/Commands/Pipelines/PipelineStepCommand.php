<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipelineStep;
use Symfony\Component\Console\Formatter\OutputFormatter;

class PipelineStepCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:step
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {step? : Step number, name fragment or UUID (prompted when omitted)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, use the latest one on this branch}
        {--setup : Also list the setup commands Bitbucket runs before the script}
        {--teardown : Also list the teardown commands}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show one pipeline step in full: state, timings, image and its commands';

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

        $step = $this->pickStep($steps, $this->argument('step'), preferFailed: true);

        if ($step === null) {
            return self::FAILURE;
        }

        // The list endpoint already returns the commands, but the single-step
        // endpoint is the authoritative source (and picks up late updates).
        $response = $this->send(new GetPipelineStep($workspace, $slug, $ref, $this->uuidSegment($step['uuid'] ?? '')));

        if ($response === null) {
            return self::FAILURE;
        }

        $detail = $response->json() + ['_index' => $step['_index']];

        if ($this->wantsJson()) {
            return $this->outputJson($detail);
        }

        $this->renderStep($detail, $workspace, $slug);

        return in_array($this->stateOf($detail), ['FAILED', 'ERROR'], true) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function renderStep(array $step, string $workspace, string $slug): void
    {
        $buildNumber = $this->resolvedBuildNumber;
        $url = filled($buildNumber)
            ? $this->repoWebUrl($workspace, $slug)."/pipelines/results/{$buildNumber}/steps/".urlencode((string) ($step['uuid'] ?? ''))
            : null;

        $this->detail([
            'Step' => $this->link($url, $step['_index'].'. '.($step['name'] ?? '(unnamed)')),
            'Status' => $this->badgeHtml($this->stateOf($step)),
            'Error' => e((string) (data_get($step, 'state.result.error.message') ?? '')),
            'Started' => e($this->formatTimestamp($step['started_on'] ?? null, relative: true, format: 'Y-m-d H:i:s')),
            'Completed' => e($this->formatTimestamp($step['completed_on'] ?? null, format: 'Y-m-d H:i:s')),
            'Duration' => e($this->formatDuration($step['duration_in_seconds'] ?? null)),
            'Build time' => isset($step['build_seconds_used']) && $step['build_seconds_used'] > 0
                ? e($this->formatDuration($step['build_seconds_used']))
                : '',
            'Image' => e((string) data_get($step, 'image.name', 'default (atlassian/default-image)')),
            'Size' => e((string) ($step['size'] ?? '')),
            'Max time' => isset($step['maxTime']) ? e($step['maxTime'].' min') : '',
            'Trigger' => e($this->triggerLabel((string) data_get($step, 'trigger.type', ''))),
            'Runner' => e((string) (data_get($step, 'runner.name') ?? data_get($step, 'runner.uuid') ?? '')),
            'Run' => ($step['run_number'] ?? 1) > 1 ? e('#'.$step['run_number']) : '',
            'UUID' => e((string) ($step['uuid'] ?? '')),
        ]);

        if ($this->option('setup')) {
            $this->renderCommands('Setup', $step['setup_commands'] ?? []);
        }

        $this->renderCommands('Script', $step['script_commands'] ?? []);

        if ($this->option('teardown')) {
            $this->renderCommands('Teardown', $step['teardown_commands'] ?? []);
        }

        $hidden = [];
        $flags = [];

        foreach (['setup', 'teardown'] as $section) {
            if (! $this->option($section) && filled($step[$section.'_commands'] ?? null)) {
                $hidden[] = count($step[$section.'_commands']).' '.$section;
                $flags[] = '--'.$section;
            }
        }

        if ($hidden !== []) {
            $this->line(' <fg=gray>'.implode(' and ', $hidden).' command(s) hidden — add '
                .implode(' / ', $flags).'.</>');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $commands
     */
    private function renderCommands(string $title, array $commands): void
    {
        if ($commands === []) {
            return;
        }

        $this->line('');
        $this->line(' <options=bold>'.$title.'</> <fg=gray>('.count($commands).')</>');

        foreach ($commands as $i => $command) {
            $number = str_pad((string) ($i + 1), 2, ' ', STR_PAD_LEFT);
            $system = ($command['commandType'] ?? 'user') === 'system';
            $body = trim((string) ($command['command'] ?? $command['name'] ?? ''));

            foreach (explode("\n", $body) as $line => $text) {
                $prefix = $line === 0 ? " <fg=gray>{$number}</> " : '    ';
                $this->line($prefix.($system ? '<fg=gray>' : '<fg=cyan>').$this->escapeMarkup($text).'</>');
            }
        }
    }

    private function escapeMarkup(string $value): string
    {
        return OutputFormatter::escape($value);
    }

    private function triggerLabel(string $type): string
    {
        return match ($type) {
            'pipeline_step_trigger_automatic' => 'automatic',
            'pipeline_step_trigger_manual' => 'manual',
            default => str_replace('pipeline_step_trigger_', '', $type),
        };
    }
}

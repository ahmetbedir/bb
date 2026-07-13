<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipeline;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelineSteps;

class PipelineViewCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:view
        {uuid : Pipeline UUID (with braces, as shown by the API)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show a pipeline and its steps';

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

        $pipeline = $this->send(new GetPipeline($workspace, $slug, $uuid));

        if ($pipeline === null) {
            return self::FAILURE;
        }

        $steps = $this->collectAll(new ListPipelineSteps($workspace, $slug, $uuid));

        if ($steps === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson(['pipeline' => $pipeline->json(), 'steps' => $steps]);
        }

        $p = $pipeline->json();

        $this->table([], [
            ['Build #', $p['build_number'] ?? ''],
            ['Status', $this->stateLabel($p['state']['result']['name'] ?? $p['state']['name'] ?? '')],
            ['Ref', $p['target']['ref_name'] ?? ''],
            ['Commit', substr($p['target']['commit']['hash'] ?? '', 0, 8)],
            ['Trigger', $p['trigger']['name'] ?? ''],
            ['Created', $p['created_on'] ?? ''],
            ['Duration', isset($p['duration_in_seconds']) ? $p['duration_in_seconds'].'s' : ''],
        ]);

        $this->newLine();
        $this->line('<options=bold>Steps</>');

        foreach ($steps as $step) {
            $name = $step['name'] ?? '(unnamed)';
            $state = $this->stateLabel($step['state']['result']['name'] ?? $step['state']['name'] ?? '');
            $this->line("  {$state}  {$name}");
        }

        return self::SUCCESS;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'SUCCESSFUL' => '<fg=green>✓ SUCCESSFUL</>',
            'FAILED' => '<fg=red>✗ FAILED  </>',
            'STOPPED' => '<fg=gray>■ STOPPED  </>',
            'IN_PROGRESS', 'RUNNING' => '<fg=yellow>• RUNNING  </>',
            'PENDING' => '<fg=yellow>… PENDING  </>',
            default => $state,
        };
    }
}

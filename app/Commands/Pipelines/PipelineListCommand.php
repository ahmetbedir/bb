<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;
use Illuminate\Support\Str;

class PipelineListCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--limit=25 : Maximum number of pipelines to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List recent pipelines for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $pipelines = $this->collectAll(
            new ListPipelines($workspace, $slug),
            limit: (int) $this->option('limit'),
        );

        if ($pipelines === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($pipelines);
        }

        if ($pipelines === []) {
            $this->info("No pipelines in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Status', 'Ref', 'Trigger', 'Created'],
            array_map(fn (array $p) => [
                $this->link(
                    isset($p['build_number']) ? $this->repoWebUrl($workspace, $slug)."/pipelines/results/{$p['build_number']}" : null,
                    $p['build_number'] ?? '',
                ),
                $this->pipelineStatus($p),
                Str::limit($p['target']['ref_name'] ?? ($p['target']['commit']['hash'] ?? ''), 24),
                $p['trigger']['name'] ?? '',
                isset($p['created_on']) ? substr($p['created_on'], 0, 19) : '',
            ], $pipelines),
        );

        return self::SUCCESS;
    }

    private function pipelineStatus(array $pipeline): string
    {
        $stateName = $pipeline['state']['name'] ?? '';
        $result = $pipeline['state']['result']['name'] ?? $pipeline['state']['stage']['name'] ?? $stateName;

        return match ($result) {
            'SUCCESSFUL' => '<fg=green>✓ SUCCESSFUL</>',
            'FAILED' => '<fg=red>✗ FAILED</>',
            'STOPPED' => '<fg=gray>■ STOPPED</>',
            'IN_PROGRESS', 'RUNNING' => '<fg=yellow>• IN PROGRESS</>',
            'PENDING' => '<fg=yellow>… PENDING</>',
            default => (string) $result,
        };
    }
}

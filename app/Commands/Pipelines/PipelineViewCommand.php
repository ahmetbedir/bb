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
        $url = $this->repoWebUrl($workspace, $slug).'/pipelines/results/'.($p['build_number'] ?? '');

        $this->detail([
            'Build #' => $this->link($url, $p['build_number'] ?? ''),
            'Status' => $this->badgeHtml($p['state']['result']['name'] ?? $p['state']['name'] ?? ''),
            'Ref' => e($p['target']['ref_name'] ?? ''),
            'Commit' => e(substr($p['target']['commit']['hash'] ?? '', 0, 8)),
            'Trigger' => e($p['trigger']['name'] ?? ''),
            'Created' => e(substr((string) ($p['created_on'] ?? ''), 0, 19)),
            'Duration' => isset($p['duration_in_seconds']) ? $p['duration_in_seconds'].'s' : '',
        ]);

        if ($steps !== []) {
            $rows = '';
            foreach ($steps as $step) {
                $rows .= '<div>'
                    .$this->badgeHtml($step['state']['result']['name'] ?? $step['state']['name'] ?? '')
                    .'<span class="ml-1">'.e($step['name'] ?? '(unnamed)').'</span>'
                    .'</div>';
            }
            \Termwind\render('<div class="ml-1"><div class="font-bold">Steps</div>'.$rows.'</div>');
        }

        return self::SUCCESS;
    }
}

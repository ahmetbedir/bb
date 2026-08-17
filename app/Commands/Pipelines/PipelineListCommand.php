<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;
use Illuminate\Support\Str;

class PipelineListCommand extends BitbucketCommand
{
    protected $signature = 'pipeline:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : Only pipelines that ran on this branch}
        {--status= : Only pipelines in this state (PENDING, IN_PROGRESS, SUCCESSFUL, FAILED, STOPPED, ...)}
        {--trigger= : Only pipelines with this trigger (PUSH, MANUAL, SCHEDULE, PULLREQUEST)}
        {--pattern= : Only runs of this custom pipeline definition}
        {--commit= : Only pipelines for this commit hash}
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
            new ListPipelines($workspace, $slug, $this->filters()),
            limit: (int) $this->option('limit'),
        );

        if ($pipelines === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($pipelines);
        }

        if ($pipelines === []) {
            $this->info("No pipelines in \"{$workspace}/{$slug}\"".($this->filters() === [] ? '.' : ' matching those filters.'));

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Status', 'Ref', 'Trigger', 'Duration', 'Created', 'By'],
            array_map(fn (array $p) => [
                $this->link(
                    isset($p['build_number']) ? $this->repoWebUrl($workspace, $slug)."/pipelines/results/{$p['build_number']}" : null,
                    $p['build_number'] ?? '',
                ),
                $this->badge($this->stateOf($p)),
                Str::limit($p['target']['ref_name'] ?? ($p['target']['commit']['hash'] ?? ''), 28),
                $p['trigger']['name'] ?? '',
                $this->formatDuration($p['duration_in_seconds'] ?? null),
                $this->formatTimestamp($p['created_on'] ?? null),
                Str::limit((string) data_get($p, 'creator.display_name', ''), 18),
            ], $pipelines),
        );

        return self::SUCCESS;
    }

    /**
     * The server-side filters built from the command's options.
     *
     * @return array<string, string>
     */
    private function filters(): array
    {
        return array_filter([
            'target.branch' => $this->option('branch'),
            'status' => filled($this->option('status')) ? strtoupper((string) $this->option('status')) : null,
            'trigger_type' => filled($this->option('trigger')) ? strtoupper((string) $this->option('trigger')) : null,
            'target.selector.pattern' => $this->option('pattern'),
            'target.commit.hash' => $this->option('commit'),
        ], fn ($value) => filled($value));
    }
}

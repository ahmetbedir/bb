<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestStatuses;

class PrChecksCommand extends BitbucketCommand
{
    protected $signature = 'pr:checks
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show commit build statuses (CI / pipelines) for a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;
        $id = $this->argument('id');

        $statuses = $this->collectAll(new ListPullRequestStatuses($workspace, $slug, $id));

        if ($statuses === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($statuses);
        }

        if ($statuses === []) {
            $this->info("No build statuses reported for PR #{$id}.");

            return self::SUCCESS;
        }

        $this->table(
            ['State', 'Name', 'Key', 'URL'],
            array_map(fn (array $s) => [
                $this->stateLabel($s['state'] ?? ''),
                $s['name'] ?? '',
                $s['key'] ?? '',
                $s['url'] ?? '',
            ], $statuses),
        );

        $failed = collect($statuses)->contains(fn ($s) => ($s['state'] ?? '') === 'FAILED');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'SUCCESSFUL' => '<fg=green>✓ SUCCESSFUL</>',
            'FAILED' => '<fg=red>✗ FAILED</>',
            'INPROGRESS' => '<fg=yellow>• INPROGRESS</>',
            'STOPPED' => '<fg=gray>■ STOPPED</>',
            default => $state,
        };
    }
}

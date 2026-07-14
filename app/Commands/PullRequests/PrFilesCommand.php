<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestFiles;

class PrFilesCommand extends BitbucketCommand
{
    protected $signature = 'pr:files
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List files changed in a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        if (blank($id = $this->resolvePrId($workspace, $slug, $this->argument('id')))) {
            return self::FAILURE;
        }

        $files = $this->collectAll(new ListPullRequestFiles($workspace, $slug, $id));

        if ($files === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($files);
        }

        if ($files === []) {
            $this->info("No file changes reported for PR #{$id}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Status', 'File', '+', '-'],
            array_map(fn (array $f) => [
                $this->fileStatus($f['status'] ?? ''),
                $f['new']['path'] ?? $f['old']['path'] ?? '',
                '<fg=green>+'.($f['lines_added'] ?? 0).'</>',
                '<fg=red>-'.($f['lines_removed'] ?? 0).'</>',
            ], $files),
        );

        $this->line(' '.count($files).' file(s) changed');

        return self::SUCCESS;
    }

    private function fileStatus(string $status): string
    {
        return match ($status) {
            'added' => '<fg=green>added</>',
            'removed' => '<fg=red>removed</>',
            'modified' => '<fg=yellow>modified</>',
            'renamed' => '<fg=cyan>renamed</>',
            default => $status,
        };
    }
}

<?php

namespace App\Commands\Branches;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\GetDiff;
use App\Support\GitContext;

class BranchDiffCommand extends BitbucketCommand
{
    protected $signature = 'branch:diff
        {target : Branch to diff against}
        {source? : Source branch (defaults to the current git branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Show the diff between two branches';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $target = $this->argument('target');
        $source = $this->argument('source') ?: app(GitContext::class)->currentBranch();

        if (blank($source)) {
            $this->error('No source branch given and no current git branch detected. Pass a source branch.');

            return self::FAILURE;
        }

        if ($source === $target) {
            $this->error("Source and target are both \"{$source}\".");

            return self::FAILURE;
        }

        $response = $this->send(new GetDiff($workspace, $slug, "{$source}..{$target}"));

        if ($response === null) {
            return self::FAILURE;
        }

        $body = $response->body();

        if (trim($body) === '') {
            $this->info("No differences between \"{$source}\" and \"{$target}\".");

            return self::SUCCESS;
        }

        $this->pager($this->colorizeDiff($body));

        return self::SUCCESS;
    }
}

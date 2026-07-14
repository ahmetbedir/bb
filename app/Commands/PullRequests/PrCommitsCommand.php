<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestCommits;
use Illuminate\Support\Str;

class PrCommitsCommand extends BitbucketCommand
{
    protected $signature = 'pr:commits
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List commits in a pull request';

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

        $commits = $this->collectAll(new ListPullRequestCommits($workspace, $slug, $id));

        if ($commits === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($commits);
        }

        if ($commits === []) {
            $this->info("No commits found for PR #{$id}.");

            return self::SUCCESS;
        }

        $this->table(
            ['Hash', 'Message', 'Author', 'Date'],
            array_map(fn (array $c) => [
                $this->link(
                    isset($c['hash']) ? $this->repoWebUrl($workspace, $slug).'/commits/'.$c['hash'] : null,
                    substr($c['hash'] ?? '', 0, 8),
                ),
                Str::limit(strtok((string) ($c['message'] ?? ''), "\n"), 50),
                Str::limit($c['author']['user']['display_name'] ?? $c['author']['raw'] ?? '', 24),
                isset($c['date']) ? substr($c['date'], 0, 10) : '',
            ], $commits),
        );

        return self::SUCCESS;
    }
}

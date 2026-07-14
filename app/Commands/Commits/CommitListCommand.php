<?php

namespace App\Commands\Commits;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Commits\ListCommits;
use Illuminate\Support\Str;

class CommitListCommand extends BitbucketCommand
{
    protected $signature = 'commit:list
        {revision? : Branch, tag or commit to list from (defaults to the main branch)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--limit=30 : Maximum number of commits to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List commits for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $commits = $this->collectAll(
            new ListCommits($workspace, $slug, $this->argument('revision')),
            limit: (int) $this->option('limit'),
        );

        if ($commits === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($commits);
        }

        if ($commits === []) {
            $this->info("No commits in \"{$workspace}/{$slug}\".");

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

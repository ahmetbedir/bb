<?php

namespace App\Commands\Tags;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Tags\ListTags;

class TagListCommand extends BitbucketCommand
{
    protected $signature = 'tag:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--limit=50 : Maximum number of tags to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List tags for a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $tags = $this->collectAll(new ListTags($workspace, $slug), limit: (int) $this->option('limit'));

        if ($tags === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($tags);
        }

        if ($tags === []) {
            $this->info("No tags in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Commit', 'Date'],
            array_map(fn (array $t) => [
                $this->link(
                    isset($t['name']) ? $this->repoWebUrl($workspace, $slug).'/commits/tag/'.$t['name'] : null,
                    $t['name'] ?? '',
                ),
                substr($t['target']['hash'] ?? '', 0, 8),
                isset($t['target']['date']) ? substr($t['target']['date'], 0, 10) : '',
            ], $tags),
        );

        return self::SUCCESS;
    }
}

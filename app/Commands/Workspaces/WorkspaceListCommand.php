<?php

namespace App\Commands\Workspaces;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Workspaces\ListWorkspaces;

class WorkspaceListCommand extends BitbucketCommand
{
    protected $signature = 'workspace:list
        {--limit=50 : Maximum number of workspaces to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List workspaces you belong to';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $workspaces = $this->collectAll(new ListWorkspaces, limit: (int) $this->option('limit'));

        if ($workspaces === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($workspaces);
        }

        if ($workspaces === []) {
            $this->info('You do not belong to any workspaces.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Name', 'Private'],
            array_map(fn (array $w) => [
                $this->link($w['links']['html']['href'] ?? null, $w['slug'] ?? ''),
                $w['name'] ?? '',
                ($w['is_private'] ?? false) ? 'yes' : 'no',
            ], $workspaces),
        );

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands\Projects;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Workspaces\ListProjects;
use Illuminate\Support\Str;

class ProjectListCommand extends BitbucketCommand
{
    protected $signature = 'project:list
        {workspace? : Workspace (defaults to the configured workspace)}
        {--limit=50 : Maximum number of projects to show}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List projects in a workspace';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $workspace = $this->argument('workspace') ?: $this->credentials()->get('workspace');

        if (blank($workspace)) {
            $this->error('No workspace given. Pass one as an argument or set a default with: bb configure');

            return self::FAILURE;
        }

        $projects = $this->collectAll(new ListProjects($workspace), limit: (int) $this->option('limit'));

        if ($projects === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($projects);
        }

        if ($projects === []) {
            $this->info("No projects in \"{$workspace}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Name', 'Private', 'Description'],
            array_map(fn (array $p) => [
                $this->link($p['links']['html']['href'] ?? null, $p['key'] ?? ''),
                $p['name'] ?? '',
                ($p['is_private'] ?? false) ? 'yes' : 'no',
                Str::limit($p['description'] ?? '', 40),
            ], $projects),
        );

        return self::SUCCESS;
    }
}

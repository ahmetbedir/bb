<?php

namespace App\Commands\Repositories;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Repositories\UpdateRepository;

class RepoEditCommand extends BitbucketCommand
{
    protected $signature = 'repo:edit
        {repo : Repository as "workspace/repo" or just "repo"}
        {--description= : New description}
        {--name= : New display name}
        {--project= : Move to a project (key)}
        {--main-branch= : Set the main branch}
        {--private : Make the repository private}
        {--public : Make the repository public}
        {--y|yes : Skip the confirmation for --public}
        {--json : Output the API response as JSON}
        {--jq= : Filter the JSON output through a jq expression}';

    protected $description = 'Edit repository settings';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->parseRepoArgument($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $changes = [];

        if ($this->option('description') !== null) {
            $changes['description'] = $this->option('description');
        }
        if (filled($this->option('name'))) {
            $changes['name'] = $this->option('name');
        }
        if (filled($this->option('project'))) {
            $changes['project'] = ['key' => $this->option('project')];
        }
        if (filled($this->option('main-branch'))) {
            $changes['mainbranch'] = ['name' => $this->option('main-branch')];
        }
        if ($this->option('private') && $this->option('public')) {
            $this->error('Pass either --private or --public, not both.');

            return self::FAILURE;
        }
        if ($this->option('private')) {
            $changes['is_private'] = true;
        }
        if ($this->option('public')) {
            // Exposure can't be undone by flipping it back: always confirm.
            if (! $this->option('yes') && ! $this->confirm("Make {$workspace}/{$slug} public? Anyone will be able to read its code and history.", false)) {
                $this->line('Aborted.');

                return self::FAILURE;
            }

            $changes['is_private'] = false;
        }

        if ($changes === []) {
            $this->error('Nothing to update. Pass --description, --name, --project, --main-branch, --private or --public.');

            return self::FAILURE;
        }

        $response = $this->send(new UpdateRepository($workspace, $slug, $changes));

        if ($response === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($response->json());
        }

        $this->info("Updated {$workspace}/{$slug}.");

        return self::SUCCESS;
    }
}

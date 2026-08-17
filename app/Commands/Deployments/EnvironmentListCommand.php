<?php

namespace App\Commands\Deployments;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListEnvironments;

class EnvironmentListCommand extends BitbucketCommand
{
    protected $signature = 'environment:list
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'List the deployment environments of a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $environments = $this->collectAll(new ListEnvironments($workspace, $slug));

        if ($environments === null) {
            return self::FAILURE;
        }

        if ($this->wantsJson()) {
            return $this->outputJson($environments);
        }

        if ($environments === []) {
            $this->info("No deployment environments configured in \"{$workspace}/{$slug}\".");

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Slug', 'Type', 'Lock', 'Gate', 'Admin only'],
            array_map(fn (array $e) => [
                $e['name'] ?? '',
                $e['slug'] ?? '',
                data_get($e, 'environment_type.name', ''),
                $this->badge((string) data_get($e, 'lock.name', '')),
                ($e['deployment_gate_enabled'] ?? false) ? 'yes' : 'no',
                data_get($e, 'restrictions.admin_only') ? 'yes' : 'no',
            ], $environments),
        );

        return self::SUCCESS;
    }
}

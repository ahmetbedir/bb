<?php

namespace App\Commands\Deployments;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithDeployments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListDeployments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListEnvironmentVariables;

class EnvironmentViewCommand extends BitbucketCommand
{
    use InteractsWithDeployments;

    protected $signature = 'environment:view
        {environment : Environment name, slug or UUID}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--history=5 : How many recent deployments to this environment to list}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show an environment: locks, restrictions, variables and recent deployments';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $environment = $this->resolveEnvironment($workspace, $slug, (string) $this->argument('environment'));

        if ($environment === null) {
            return self::FAILURE;
        }

        $uuid = $this->uuidSegment($environment['uuid'] ?? '');

        // Deployment variables need the "admin:pipeline" scope; a rejection
        // here should not fail the whole command.
        $variablesResponse = $this->dispatch($this->connector(), new ListEnvironmentVariables($workspace, $slug, $uuid));
        $variables = $variablesResponse->successful() ? ($variablesResponse->json('values') ?? []) : null;

        $history = max(0, (int) $this->option('history'));
        $deployments = $history > 0
            ? ($this->collectAll(new ListDeployments($workspace, $slug, $environment['uuid'] ?? null), limit: $history) ?? [])
            : [];

        if ($this->wantsJson()) {
            return $this->outputJson([
                'environment' => $environment,
                'variables' => $variables,
                'deployments' => $deployments,
            ]);
        }

        $this->detail([
            'Environment' => e((string) ($environment['name'] ?? '')),
            'Slug' => e((string) ($environment['slug'] ?? '')),
            'Type' => e((string) data_get($environment, 'environment_type.name', '')),
            'Rank' => e((string) ($environment['rank'] ?? '')),
            'Lock' => $this->badgeHtml((string) data_get($environment, 'lock.name', '')),
            'Locking' => e(($environment['environment_lock_enabled'] ?? false) ? 'enabled' : 'disabled'),
            'Deploy gate' => e(($environment['deployment_gate_enabled'] ?? false) ? 'enabled' : 'disabled'),
            'Admin only' => e(data_get($environment, 'restrictions.admin_only') ? 'yes' : 'no'),
            'Hidden' => ($environment['hidden'] ?? false) ? e('yes') : '',
            'UUID' => e((string) ($environment['uuid'] ?? '')),
        ]);

        $this->renderVariables($variables);
        $this->renderDeployments($deployments);

        return self::SUCCESS;
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $variables
     */
    private function renderVariables(?array $variables): void
    {
        if ($variables === null) {
            $this->line(' <fg=gray>Deployment variables not readable with these credentials.</>');

            return;
        }

        if ($variables === []) {
            return;
        }

        $this->line(' <options=bold>Variables</>');

        $this->table(
            ['Key', 'Value', 'Secured'],
            array_map(fn (array $v) => [
                $v['key'] ?? '',
                ($v['secured'] ?? false) ? '••••••' : ($v['value'] ?? ''),
                ($v['secured'] ?? false) ? 'yes' : 'no',
            ], $variables),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $deployments
     */
    private function renderDeployments(array $deployments): void
    {
        if ($deployments === []) {
            return;
        }

        $this->line(' <options=bold>Recent deployments</>');

        $this->table(
            ['#', 'Status', 'Release', 'Commit', 'Started'],
            array_map(fn (array $d) => [
                $this->link(data_get($d, 'state.url'), $d['number'] ?? ''),
                $this->badge($this->stateOf($d)),
                (string) data_get($d, 'release.name', ''),
                substr((string) data_get($d, 'release.commit.hash', ''), 0, 8),
                $this->formatTimestamp(data_get($d, 'state.started_on')),
            ], $deployments),
        );
    }
}

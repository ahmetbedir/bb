<?php

namespace App\Commands\Concerns;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Deployments\GetDeployment;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListDeployments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListEnvironments;

/**
 * Shared deployment plumbing: resolving environments by name and deployments
 * by their (repository-scoped) number.
 *
 * @mixin BitbucketCommand
 */
trait InteractsWithDeployments
{
    /**
     * How many deployments are scanned when resolving one by number. The API
     * has no lookup-by-number endpoint, so the newest-first list is walked.
     */
    protected int $deploymentScanLimit = 600;

    /**
     * Resolve an environment by UUID, slug or (case-insensitive) name.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveEnvironment(string $workspace, string $slug, string $ref): ?array
    {
        $environments = $this->collectAll(new ListEnvironments($workspace, $slug));

        if ($environments === null) {
            return null;
        }

        $needle = $this->bareUuid($ref);

        $match = collect($environments)->first(function (array $environment) use ($needle) {
            return $this->bareUuid($environment['uuid'] ?? '') === $needle
                || strtolower((string) ($environment['slug'] ?? '')) === $needle
                || strtolower((string) ($environment['name'] ?? '')) === $needle;
        });

        if ($match === null) {
            $names = collect($environments)->pluck('name')->filter()->implode(', ');
            $this->error("No environment matching \"{$ref}\".".($names !== '' ? " Available: {$names}" : ''));

            return null;
        }

        return $match;
    }

    /**
     * Load a deployment from a UUID or a deployment number ("867", "#867").
     * With no reference, the most recent deployment is used — optionally
     * restricted to one environment.
     *
     * @return array<string, mixed>|null
     */
    protected function resolveDeployment(
        string $workspace,
        string $slug,
        int|string|null $ref,
        ?string $environmentUuid = null,
    ): ?array {
        $ref = ltrim(trim((string) $ref), '#');

        if ($ref === '') {
            $latest = $this->collectAll(new ListDeployments($workspace, $slug, $environmentUuid), limit: 1);

            if (blank($latest)) {
                $this->error("No deployments found in \"{$workspace}/{$slug}\".");

                return null;
            }

            fwrite(STDERR, 'Using deployment #'.($latest[0]['number'] ?? '?')
                .' ('.data_get($latest[0], 'environment.name', 'unknown environment').')'.PHP_EOL);

            return $latest[0];
        }

        if (! ctype_digit($ref)) {
            $response = $this->send(new GetDeployment($workspace, $slug, $this->uuidSegment($ref)));

            return $response?->json();
        }

        return $this->findDeploymentByNumber($workspace, $slug, (int) $ref, $environmentUuid);
    }

    /**
     * Walk the newest-first deployment list looking for one number.
     *
     * @return array<string, mixed>|null
     */
    protected function findDeploymentByNumber(
        string $workspace,
        string $slug,
        int $number,
        ?string $environmentUuid = null,
    ): ?array {
        $deployments = $this->collectAll(
            new ListDeployments($workspace, $slug, $environmentUuid),
            limit: $this->deploymentScanLimit,
        );

        if ($deployments === null) {
            return null;
        }

        $match = collect($deployments)->first(fn (array $deployment) => (int) ($deployment['number'] ?? 0) === $number);

        if ($match === null) {
            $this->error("Deployment #{$number} was not among the last ".count($deployments)
                .' deployments. Pass its UUID instead (see "bb deployment:list --json").');

            return null;
        }

        return $match;
    }

    /**
     * The pipeline UUID and step UUID a deployment ran as, if known.
     *
     * @param  array<string, mixed>  $deployment
     * @return array{0: string, 1: string}|null
     */
    protected function deploymentStepRef(array $deployment): ?array
    {
        $pipeline = data_get($deployment, 'step.pipeline.uuid') ?? data_get($deployment, 'release.pipeline.uuid');
        $step = data_get($deployment, 'step.uuid');

        if (blank($pipeline) || blank($step)) {
            return null;
        }

        return [$this->uuidSegment($pipeline), $this->uuidSegment($step)];
    }
}

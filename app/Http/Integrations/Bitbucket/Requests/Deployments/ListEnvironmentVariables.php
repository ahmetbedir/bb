<?php

namespace App\Http\Integrations\Bitbucket\Requests\Deployments;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Deployment variables configured on an environment. Secured variables come
 * back without their value — Bitbucket never exposes those.
 */
class ListEnvironmentVariables extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $environmentUuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/deployments_config/environments/{$this->environmentUuid}/variables";
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

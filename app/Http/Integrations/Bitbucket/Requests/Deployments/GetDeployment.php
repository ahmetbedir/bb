<?php

namespace App\Http\Integrations\Bitbucket\Requests\Deployments;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetDeployment extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $uuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/deployments/{$this->uuid}";
    }
}

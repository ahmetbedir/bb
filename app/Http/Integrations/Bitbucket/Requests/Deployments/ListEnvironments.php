<?php

namespace App\Http\Integrations\Bitbucket\Requests\Deployments;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListEnvironments extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/environments";
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

<?php

namespace App\Http\Integrations\Bitbucket\Requests\Repositories;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetRepository extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}";
    }
}

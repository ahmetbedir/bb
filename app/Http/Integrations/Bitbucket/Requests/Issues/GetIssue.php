<?php

namespace App\Http\Integrations\Bitbucket\Requests\Issues;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetIssue extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/issues/{$this->id}";
    }
}

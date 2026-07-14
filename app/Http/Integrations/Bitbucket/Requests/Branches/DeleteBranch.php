<?php

namespace App\Http\Integrations\Bitbucket\Requests\Branches;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeleteBranch extends Request
{
    protected Method $method = Method::DELETE;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/refs/branches/{$this->name}";
    }
}

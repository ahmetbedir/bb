<?php

namespace App\Http\Integrations\Bitbucket\Requests\Workspaces;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListProjects extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/workspaces/{$this->workspace}/projects";
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 50];
    }
}

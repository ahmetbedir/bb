<?php

namespace App\Http\Integrations\Bitbucket\Requests\Workspaces;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListWorkspaces extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/workspaces';
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 50];
    }
}

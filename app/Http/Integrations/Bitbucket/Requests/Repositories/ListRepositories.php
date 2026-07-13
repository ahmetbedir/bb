<?php

namespace App\Http\Integrations\Bitbucket\Requests\Repositories;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListRepositories extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected array $filters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return '/repositories/'.$this->workspace;
    }

    protected function defaultQuery(): array
    {
        return array_merge([
            'role' => 'member',
            'sort' => '-updated_on',
            'pagelen' => 50,
        ], $this->filters);
    }
}

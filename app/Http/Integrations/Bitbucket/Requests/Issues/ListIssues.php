<?php

namespace App\Http\Integrations\Bitbucket\Requests\Issues;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListIssues extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected array $filters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/issues";
    }

    protected function defaultQuery(): array
    {
        return array_merge([
            'sort' => '-updated_on',
            'pagelen' => 50,
        ], $this->filters);
    }
}

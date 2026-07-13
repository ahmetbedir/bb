<?php

namespace App\Http\Integrations\Bitbucket\Requests\Branches;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListBranches extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected array $filters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/refs/branches";
    }

    protected function defaultQuery(): array
    {
        return array_merge([
            'sort' => '-target.date',
            'pagelen' => 50,
        ], $this->filters);
    }
}

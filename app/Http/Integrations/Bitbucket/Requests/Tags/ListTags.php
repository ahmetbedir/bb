<?php

namespace App\Http\Integrations\Bitbucket\Requests\Tags;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListTags extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/refs/tags";
    }

    protected function defaultQuery(): array
    {
        return ['sort' => '-target.date', 'pagelen' => 50];
    }
}

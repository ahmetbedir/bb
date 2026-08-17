<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListPipelines extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  array<string, string>  $filters  server-side filters, e.g.
     *                                          ["target.branch" => "develop", "status" => "FAILED"]
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected array $filters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines";
    }

    protected function defaultQuery(): array
    {
        return [
            'sort' => '-created_on',
            'pagelen' => 25,
            ...$this->filters,
        ];
    }
}

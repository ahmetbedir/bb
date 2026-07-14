<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListPullRequests extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  string|array<int, string>  $state  e.g. OPEN, MERGED, DECLINED, SUPERSEDED
     * @param  array<string, mixed>  $filters  extra query params (e.g. ['q' => '...'])
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string|array $state = 'OPEN',
        protected array $filters = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests";
    }

    protected function defaultQuery(): array
    {
        return array_merge([
            'state' => $this->state,
            'pagelen' => 50,
        ], $this->filters);
    }
}

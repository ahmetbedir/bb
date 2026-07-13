<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListPullRequests extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  string|array<int, string>  $state  e.g. OPEN, MERGED, DECLINED, SUPERSEDED
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string|array $state = 'OPEN',
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests";
    }

    protected function defaultQuery(): array
    {
        return [
            'state' => $this->state,
            'pagelen' => 50,
        ];
    }
}

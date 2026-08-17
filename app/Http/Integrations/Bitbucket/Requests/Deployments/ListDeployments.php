<?php

namespace App\Http\Integrations\Bitbucket\Requests\Deployments;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListDeployments extends Request
{
    protected Method $method = Method::GET;

    /**
     * @param  string|null  $environmentUuid  restrict to one environment
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected ?string $environmentUuid = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/deployments";
    }

    protected function defaultQuery(): array
    {
        // The deploy service only accepts a handful of sort attributes;
        // "-state.started_on" is the one that yields newest-first.
        $query = [
            'sort' => '-state.started_on',
            'pagelen' => 30,
        ];

        if ($this->environmentUuid !== null) {
            $query['environment'] = $this->environmentUuid;
        }

        return $query;
    }
}

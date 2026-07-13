<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

/**
 * A generic, dynamically-built request — the engine behind `bb api`.
 * The HTTP method, endpoint, query and JSON body are all supplied at runtime.
 */
class ApiRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method;

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $bodyData
     */
    public function __construct(
        string $method,
        protected string $endpoint,
        protected array $queryParams = [],
        protected array $bodyData = [],
    ) {
        $this->method = Method::from(strtoupper($method));
    }

    public function resolveEndpoint(): string
    {
        return $this->endpoint;
    }

    protected function defaultQuery(): array
    {
        return $this->queryParams;
    }

    protected function defaultBody(): array
    {
        return $this->bodyData;
    }
}

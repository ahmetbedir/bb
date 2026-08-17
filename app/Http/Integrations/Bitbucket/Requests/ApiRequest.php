<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * A generic, dynamically-built request — the engine behind `bb api`.
 * The HTTP method, endpoint and query are all supplied at runtime.
 *
 * This variant carries no request body: Bitbucket's edge rejects GET requests
 * that arrive with a JSON body, so writes use ApiRequestWithBody instead.
 */
class ApiRequest extends Request
{
    protected Method $method;

    /**
     * @param  array<string, mixed>  $queryParams
     */
    public function __construct(
        string $method,
        protected string $endpoint,
        protected array $queryParams = [],
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
}

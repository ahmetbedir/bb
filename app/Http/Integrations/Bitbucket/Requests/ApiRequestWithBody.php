<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Traits\Body\HasJsonBody;

/**
 * `bb api` with a JSON body — used for the write methods only.
 */
class ApiRequestWithBody extends ApiRequest implements HasBody
{
    use HasJsonBody;

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $bodyData
     */
    public function __construct(
        string $method,
        string $endpoint,
        array $queryParams = [],
        protected array $bodyData = [],
    ) {
        parent::__construct($method, $endpoint, $queryParams);
    }

    protected function defaultBody(): array
    {
        return $this->bodyData;
    }
}

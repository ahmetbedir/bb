<?php

namespace App\Http\Integrations\Bitbucket;

use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class BitbucketConnector extends Connector
{
    use AcceptsJson;

    /**
     * The Base URL of the API
     */
    public function resolveBaseUrl(): string
    {
        return '';
    }

    /**
     * Default headers for every request
     */
    protected function defaultHeaders(): array
    {
        return [];
    }

    /**
     * Default HTTP client options
     */
    protected function defaultConfig(): array
    {
        return [];
    }
}

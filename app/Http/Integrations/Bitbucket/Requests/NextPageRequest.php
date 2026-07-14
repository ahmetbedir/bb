<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Fetches an absolute Bitbucket "next" pagination URL. Saloon uses the URL
 * as-is when the endpoint is already fully-qualified, so the connector's
 * base URL is ignored here.
 */
class NextPageRequest extends Request
{
    protected Method $method = Method::GET;

    // The "next" URL is a fully-qualified api.bitbucket.org link; allow it to
    // override the connector base URL (Saloon blocks absolute endpoints by
    // default as an SSRF guard).
    public ?bool $allowBaseUrlOverride = true;

    public function __construct(protected string $url) {}

    public function resolveEndpoint(): string
    {
        return $this->url;
    }
}

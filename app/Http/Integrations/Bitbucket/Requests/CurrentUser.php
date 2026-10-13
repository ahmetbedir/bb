<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Returns the authenticated account (GET /user). Requires the
 * "read:user:bitbucket" scope on the API token.
 */
class CurrentUser extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/user';
    }
}

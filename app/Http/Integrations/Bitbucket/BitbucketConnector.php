<?php

namespace App\Http\Integrations\Bitbucket;

use App\Support\CredentialStore;
use Saloon\Contracts\Authenticator;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Traits\Plugins\AcceptsJson;

class BitbucketConnector extends Connector
{
    use AcceptsJson;

    public function __construct(protected CredentialStore $credentials) {}

    /**
     * The Base URL of the Bitbucket Cloud REST API (v2).
     */
    public function resolveBaseUrl(): string
    {
        return rtrim(
            $this->credentials->get('base_url', 'https://api.bitbucket.org/2.0'),
            '/'
        );
    }

    /**
     * Authenticate every request using the configured credentials.
     */
    protected function defaultAuth(): ?Authenticator
    {
        return match ($this->credentials->get('method')) {
            'app_password' => new BasicAuthenticator(
                (string) $this->credentials->get('username'),
                (string) $this->credentials->get('app_password'),
            ),
            'token' => new TokenAuthenticator(
                (string) $this->credentials->get('token'),
            ),
            default => null,
        };
    }

    protected function defaultHeaders(): array
    {
        return [
            'Accept' => 'application/json',
        ];
    }

    protected function defaultConfig(): array
    {
        return [
            'timeout' => 30,
        ];
    }
}

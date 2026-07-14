<?php

namespace App\Http\Integrations\Bitbucket;

use App\Support\CredentialStore;
use Saloon\Contracts\Authenticator;
use Saloon\Helpers\OAuth2\OAuthConfig;
use Saloon\Http\Auth\BasicAuthenticator;
use Saloon\Http\Auth\TokenAuthenticator;
use Saloon\Http\Connector;
use Saloon\Http\PendingRequest;
use Saloon\Http\Response;
use Saloon\Traits\OAuth2\AuthorizationCodeGrant;
use Saloon\Traits\Plugins\AcceptsJson;

class BitbucketConnector extends Connector
{
    use AcceptsJson;
    use AuthorizationCodeGrant;

    public function __construct(protected CredentialStore $credentials)
    {
        // BB_DEBUG=1 traces every request/response to STDERR.
        if (getenv('BB_DEBUG')) {
            $this->middleware()->onRequest(function (PendingRequest $request) {
                fwrite(STDERR, '→ '.$request->getMethod()->value.' '.$request->getUrl().PHP_EOL);
            });
            $this->middleware()->onResponse(function (Response $response) {
                fwrite(STDERR, '← '.$response->status().' '.$response->getPendingRequest()->getUrl().PHP_EOL);

                return $response;
            });
        }
    }

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
            'oauth' => new TokenAuthenticator(
                (string) $this->credentials->get('oauth.access_token'),
            ),
            default => null,
        };
    }

    /**
     * OAuth 2.0 (authorization code grant) settings for `bb auth login`.
     * Endpoints live on bitbucket.org, not the api.* host, so we allow the
     * base URL to be overridden by the absolute endpoints below.
     */
    protected function defaultOauthConfig(): OAuthConfig
    {
        return OAuthConfig::make()
            ->setClientId((string) $this->credentials->get('oauth.client_id'))
            ->setClientSecret((string) $this->credentials->get('oauth.client_secret'))
            ->setRedirectUri((string) $this->credentials->get('oauth.redirect_uri', 'http://localhost:8888/callback'))
            ->setAuthorizeEndpoint('https://bitbucket.org/site/oauth2/authorize')
            ->setTokenEndpoint('https://bitbucket.org/site/oauth2/access_token')
            ->setUserEndpoint('https://api.bitbucket.org/2.0/user')
            ->setAllowBaseUrlOverride();
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

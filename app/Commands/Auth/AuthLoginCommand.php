<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\CurrentUser;
use App\Support\OAuthCallbackServer;
use Throwable;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class AuthLoginCommand extends BitbucketCommand
{
    protected $signature = 'auth:login
        {--client-id= : OAuth consumer key}
        {--client-secret= : OAuth consumer secret}
        {--port=8888 : Local port for the OAuth redirect (must match the consumer callback)}
        {--workspace= : Default workspace to use when none is given}';

    protected $description = 'Log in to Bitbucket via OAuth in the browser';

    /**
     * Scopes requested for the token. Adjust to taste — these cover the
     * repository, pull request and issue commands bb ships with.
     */
    private const SCOPES = ['account', 'repository', 'repository:write', 'pullrequest', 'pullrequest:write', 'issue', 'issue:write'];

    public function handle(): int
    {
        $store = $this->credentials();
        $port = (int) $this->option('port');

        $this->line('Log in to Bitbucket with an OAuth consumer.');
        $this->line('Create one at: <comment>Workspace settings → OAuth consumers</comment>');
        $this->line("Set its callback URL to: <comment>http://localhost:{$port}/callback</comment>");
        $this->newLine();

        $clientId = $this->option('client-id') ?: text('OAuth consumer key (client id)', required: true);
        $clientSecret = $this->option('client-secret') ?: password('OAuth consumer secret', required: true);

        $server = new OAuthCallbackServer(port: $port);

        $store->fill([
            'oauth.client_id' => $clientId,
            'oauth.client_secret' => $clientSecret,
            'oauth.redirect_uri' => $server->redirectUri(),
        ]);

        $connector = $this->connector();
        $authUrl = $connector->getAuthorizationUrl(self::SCOPES);
        $expectedState = $connector->getState();

        $this->newLine();
        $this->line('Opening your browser to authorize. If it does not open, visit:');
        $this->line("<comment>{$authUrl}</comment>");
        $this->openInBrowser($authUrl);

        $this->newLine();
        $this->line("Waiting for the redirect on port {$port}...");

        try {
            $callback = $server->waitForCallback();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! isset($callback['code'])) {
            $this->error('Authorization failed: '.($callback['error_description'] ?? $callback['error'] ?? 'no code returned'));

            return self::FAILURE;
        }

        try {
            $authenticator = $connector->getAccessToken(
                code: $callback['code'],
                state: $callback['state'] ?? null,
                expectedState: $expectedState,
            );
        } catch (Throwable $e) {
            $this->error('Token exchange failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $store->fill([
            'method' => 'oauth',
            'oauth.access_token' => $authenticator->getAccessToken(),
            'oauth.refresh_token' => $authenticator->getRefreshToken(),
            'oauth.expires_at' => $authenticator->getExpiresAt()?->getTimestamp(),
        ]);

        if (filled($workspace = $this->option('workspace'))) {
            $store->set('workspace', $workspace);
        }

        $store->save();

        $response = $this->send(new CurrentUser);
        $who = $response?->json('display_name') ?? $response?->json('username') ?? 'your account';

        $this->newLine();
        $this->info("Logged in as {$who}.");

        return self::SUCCESS;
    }
}

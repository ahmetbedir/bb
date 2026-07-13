<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\CurrentUser;

class AuthStatusCommand extends BitbucketCommand
{
    protected $signature = 'auth:status';

    protected $description = 'Show the current authentication status';

    public function handle(): int
    {
        $store = $this->credentials();

        if (! $store->isConfigured()) {
            $this->warn('Not logged in. Run "bbcli auth login" or "bbcli configure".');

            return self::FAILURE;
        }

        $method = $store->get('method');
        $this->line("Config file: <comment>{$store->path()}</comment>");
        $this->line('Auth method: <comment>'.$this->methodLabel($method).'</comment>');

        if ($method === 'app_password') {
            $this->line('Username: <comment>'.$store->get('username').'</comment>');
        }

        if ($method === 'oauth' && filled($expiresAt = $store->get('oauth.expires_at'))) {
            $expired = time() >= (int) $expiresAt;
            $this->line('Token expires: <comment>'.date('Y-m-d H:i:s', (int) $expiresAt).'</comment>'
                .($expired ? ' <fg=yellow>(expired — will refresh)</>' : ''));
        }

        if (filled($workspace = $store->get('workspace'))) {
            $this->line("Default workspace: <comment>{$workspace}</comment>");
        }

        $response = $this->send(new CurrentUser);

        if ($response === null) {
            $this->newLine();
            $this->warn('Credentials present but the API rejected them.');

            return self::FAILURE;
        }

        $who = $response->json('display_name') ?? $response->json('username') ?? 'unknown';
        $this->newLine();
        $this->info("Authenticated as {$who}.");

        return self::SUCCESS;
    }

    private function methodLabel(?string $method): string
    {
        return match ($method) {
            'app_password' => 'App Password',
            'token' => 'Access / API token',
            'oauth' => 'OAuth',
            default => (string) $method,
        };
    }
}

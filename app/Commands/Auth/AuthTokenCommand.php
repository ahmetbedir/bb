<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;

class AuthTokenCommand extends BitbucketCommand
{
    protected $signature = 'auth:token';

    protected $description = 'Print the current bearer access token (for scripting)';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        // Refresh an expiring OAuth token before printing it.
        $this->connector();

        $token = $this->bearerToken();

        if (blank($token)) {
            $this->error('The current auth method ('.$this->credentials()->get('method').') has no bearer token. It uses HTTP basic auth.');

            return self::FAILURE;
        }

        $this->output->writeln($token);

        return self::SUCCESS;
    }
}

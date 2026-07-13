<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;

class AuthLogoutCommand extends BitbucketCommand
{
    protected $signature = 'auth:logout
        {--keep-workspace : Keep the configured default workspace}';

    protected $description = 'Remove stored Bitbucket credentials';

    public function handle(): int
    {
        $store = $this->credentials();

        if (! $store->exists()) {
            $this->info('Nothing to do — no credentials stored.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Remove stored Bitbucket credentials?', true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        foreach (['method', 'username', 'app_password', 'token', 'oauth'] as $key) {
            $store->forget($key);
        }

        if (! $this->option('keep-workspace')) {
            $store->forget('workspace');
        }

        $store->save();

        $this->info('Logged out.');

        return self::SUCCESS;
    }
}

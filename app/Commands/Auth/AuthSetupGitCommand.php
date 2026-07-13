<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;

class AuthSetupGitCommand extends BitbucketCommand
{
    protected $signature = 'auth:setup-git';

    protected $description = 'Configure git to authenticate bitbucket.org over HTTPS using bbcli';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $binary = realpath($_SERVER['argv'][0] ?? '') ?: 'bbcli';
        $key = 'credential.https://bitbucket.org.helper';

        // Clear any existing helpers for this host, then register ours. The
        // empty value resets the helper chain so other helpers don't also run.
        $this->gitQuiet(['config', '--global', '--unset-all', $key]);

        $ok = $this->gitQuiet(['config', '--global', '--add', $key, ''])
            && $this->gitQuiet(['config', '--global', '--add', $key, "!'{$binary}' auth git-credential"]);

        if (! $ok) {
            $this->error('Failed to update your git configuration.');

            return self::FAILURE;
        }

        $this->info('Configured git to use bbcli for bitbucket.org HTTPS authentication.');
        $this->line(' git will now call: <comment>'.$binary.' auth git-credential</comment>');

        return self::SUCCESS;
    }
}

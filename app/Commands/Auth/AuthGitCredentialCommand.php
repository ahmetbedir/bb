<?php

namespace App\Commands\Auth;

use App\Commands\BitbucketCommand;

/**
 * Implements the git credential helper protocol so `git push/pull` over HTTPS
 * can authenticate with bb's stored credentials. Registered by
 * `bb auth setup-git`; git invokes it as `bb auth git-credential get`.
 *
 * @see https://git-scm.com/docs/gitcredentials
 */
class AuthGitCredentialCommand extends BitbucketCommand
{
    protected $signature = 'auth:git-credential {operation : get, store or erase}';

    protected $description = 'Git credential helper for bitbucket.org (used internally by git)';

    protected $hidden = true;

    public function handle(): int
    {
        // git only expects output from "get"; store/erase are no-ops for us.
        if ($this->argument('operation') !== 'get') {
            return self::SUCCESS;
        }

        $attributes = $this->readAttributes();

        // Only answer for Bitbucket Cloud over https.
        if (($attributes['host'] ?? null) !== 'bitbucket.org') {
            return self::SUCCESS;
        }

        // Refresh an expiring OAuth token before handing it to git.
        $this->refreshOAuthIfNeeded();

        $credentials = $this->gitCredentials();

        if ($credentials === null) {
            return self::SUCCESS;
        }

        [$username, $password] = $credentials;

        $this->output->writeln("username={$username}");
        $this->output->writeln("password={$password}");

        return self::SUCCESS;
    }

    /**
     * Read the "key=value" lines git writes to stdin, up to the blank line.
     *
     * @return array<string, string>
     */
    private function readAttributes(): array
    {
        $attributes = [];
        $stdin = fopen('php://stdin', 'r');

        if ($stdin === false) {
            return $attributes;
        }

        while (($line = fgets($stdin)) !== false) {
            $line = rtrim($line, "\r\n");

            if ($line === '') {
                break;
            }

            [$key, $value] = array_pad(explode('=', $line, 2), 2, '');
            $attributes[$key] = $value;
        }

        fclose($stdin);

        return $attributes;
    }
}

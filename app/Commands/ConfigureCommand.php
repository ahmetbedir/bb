<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\Requests\CurrentUser;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class ConfigureCommand extends BitbucketCommand
{
    protected $signature = 'configure
        {--method= : Authentication method: app_password or token}
        {--username= : Bitbucket username (for app_password)}
        {--workspace= : Default workspace to use when none is given}';

    protected $description = 'Configure Bitbucket credentials for bbcli';

    public function handle(): int
    {
        $store = $this->credentials();

        $this->line('Configure your Bitbucket Cloud credentials.');
        $this->newLine();

        $method = $this->option('method') ?: select(
            label: 'Authentication method',
            options: [
                'app_password' => 'App Password (username + app password)',
                'token' => 'Access / API token (Bearer)',
            ],
            default: $store->get('method', 'app_password'),
        );

        if (! in_array($method, ['app_password', 'token'], true)) {
            $this->error("Invalid method \"{$method}\". Use app_password or token.");

            return self::FAILURE;
        }

        $store->set('method', $method);

        if ($method === 'app_password') {
            $username = $this->option('username') ?: text(
                label: 'Bitbucket username',
                default: (string) $store->get('username', ''),
                required: true,
            );

            $appPassword = password(
                label: 'App password',
                hint: 'Create one at Bitbucket → Personal settings → App passwords',
                required: true,
            );

            $store->fill(['username' => $username, 'app_password' => $appPassword]);
        } else {
            $token = password(
                label: 'Access / API token',
                required: true,
            );

            $store->set('token', $token);
        }

        $workspace = $this->option('workspace') ?: text(
            label: 'Default workspace (optional)',
            placeholder: 'my-team',
            default: (string) $store->get('workspace', ''),
            hint: 'Lets you use "repo" instead of "workspace/repo".',
        );

        if (filled($workspace)) {
            $store->set('workspace', $workspace);
        }

        $store->save();

        $this->newLine();
        $this->task('Verifying credentials', fn () => $this->verify());

        $this->newLine();
        $this->info("Saved to {$store->path()}");

        return self::SUCCESS;
    }

    private function verify(): bool
    {
        $response = $this->connector()->send(new CurrentUser);

        if ($response->failed()) {
            $this->newLine();
            $this->warn('Could not verify credentials (HTTP '.$response->status().'). They were saved anyway.');

            return false;
        }

        $account = $response->json('display_name') ?? $response->json('username') ?? 'unknown';
        $this->line(" Authenticated as <info>{$account}</info>");

        return true;
    }
}

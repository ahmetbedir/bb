<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\Requests\CurrentUser;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class ConfigureCommand extends BitbucketCommand
{
    protected $signature = 'configure
        {--method=token : Authentication method (only "token" is supported)}
        {--workspace= : Default workspace to use when none is given}';

    protected $description = 'Configure Bitbucket credentials for bb';

    public function handle(): int
    {
        $store = $this->credentials();

        if ($this->option('method') !== 'token') {
            $this->error('Invalid method "'.$this->option('method').'". Bitbucket retired app passwords; use an API token.');

            return self::FAILURE;
        }

        $this->line('Configure your Bitbucket Cloud credentials.');
        $this->newLine();

        $token = password(
            label: 'API / access token',
            hint: 'Create an API token with Bitbucket scopes at id.atlassian.com → Security → API tokens',
            required: true,
        );

        // Drop leftovers from the retired app password method.
        $store->forget('username')->forget('app_password');
        $store->fill(['method' => 'token', 'token' => $token]);

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

<?php

use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Support\CredentialStore;
use App\Support\GitContext;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-pr-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token']);

    $this->app->instance(CredentialStore::class, $store);
});

function mockPrs(): void
{
    MockClient::global([
        ListPullRequests::class => MockResponse::make([
            'values' => [
                ['id' => 7, 'title' => 'Add caching', 'state' => 'OPEN', 'author' => ['display_name' => 'Jane'], 'source' => ['branch' => ['name' => 'feature/cache']], 'destination' => ['branch' => ['name' => 'main']], 'updated_on' => '2026-07-10T00:00:00Z'],
            ],
        ], 200),
    ]);
}

it('resolves the repo from the --repo flag', function () {
    mockPrs();

    $this->artisan('pr:list --repo=acme/api')
        ->expectsOutputToContain('Add caching')
        ->assertSuccessful();
});

it('resolves the repo from the current git context when no flag is given', function () {
    mockPrs();

    $this->app->instance(GitContext::class, new class extends GitContext
    {
        public function currentRepo(): ?array
        {
            return ['acme', 'api'];
        }
    });

    $this->artisan('pr:list')
        ->expectsOutputToContain('Add caching')
        ->assertSuccessful();
});

it('fails when no repo can be resolved', function () {
    $this->app->instance(GitContext::class, new class extends GitContext
    {
        public function currentRepo(): ?array
        {
            return null;
        }
    });

    $this->artisan('pr:list')
        ->expectsOutputToContain('No repository given')
        ->assertFailed();
});

it('emits json with the --json flag', function () {
    mockPrs();

    $this->artisan('pr:list --repo=acme/api --json')
        ->expectsOutputToContain('"title": "Add caching"')
        ->assertSuccessful();
});

it('filters json through jq', function () {
    mockPrs();

    $this->artisan('pr:list --repo=acme/api --json --jq=.[].title')
        ->expectsOutputToContain('Add caching')
        ->assertSuccessful();
});

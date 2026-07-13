<?php

use App\Http\Integrations\Bitbucket\Requests\Repositories\ListRepositories;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    // A configured, in-memory credential store so commands pass the guard.
    $store = (new CredentialStore(sys_get_temp_dir().'/bbcli-feature-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token', 'workspace' => 'acme']);

    $this->app->instance(CredentialStore::class, $store);
});

it('lists repositories from the api', function () {
    MockClient::global([
        ListRepositories::class => MockResponse::make([
            'values' => [
                ['name' => 'Api Service', 'slug' => 'api-service', 'is_private' => true, 'updated_on' => '2026-07-01T10:00:00Z', 'language' => 'php'],
                ['name' => 'Web App', 'slug' => 'web-app', 'is_private' => false, 'updated_on' => '2026-06-20T10:00:00Z', 'language' => 'js'],
            ],
        ], 200),
    ]);

    $this->artisan('repo:list acme')
        ->expectsOutputToContain('Api Service')
        ->expectsOutputToContain('web-app')
        ->assertSuccessful();
});

it('warns when not configured', function () {
    $this->app->instance(
        CredentialStore::class,
        new CredentialStore(sys_get_temp_dir().'/bbcli-empty-'.uniqid().'/config.json'),
    );

    $this->artisan('repo:list acme')
        ->expectsOutputToContain('not configured')
        ->assertFailed();
});

it('surfaces api errors', function () {
    MockClient::global([
        ListRepositories::class => MockResponse::make(['error' => ['message' => 'Workspace not found']], 404),
    ]);

    $this->artisan('repo:list acme')
        ->expectsOutputToContain('Workspace not found')
        ->assertFailed();
});

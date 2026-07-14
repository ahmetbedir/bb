<?php

use App\Http\Integrations\Bitbucket\Requests\Branches\DeleteBranch;
use App\Http\Integrations\Bitbucket\Requests\Commits\ListCommits;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\RunPipeline;
use App\Http\Integrations\Bitbucket\Requests\Repositories\UpdateRepository;
use App\Http\Integrations\Bitbucket\Requests\Tags\CreateTag;
use App\Http\Integrations\Bitbucket\Requests\Workspaces\ListWorkspaces;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-extra-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token', 'workspace' => 'acme']);

    $this->app->instance(CredentialStore::class, $store);
});

it('lists commits', function () {
    MockClient::global([
        ListCommits::class => MockResponse::make([
            'values' => [
                ['hash' => 'abcdef1234567890', 'message' => "Fix the thing\n\nbody", 'author' => ['user' => ['display_name' => 'Jane']], 'date' => '2026-07-10T00:00:00Z'],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('commit:list', ['--repo' => 'acme/api']);

    expect($code)->toBe(0);
    expect($output)->toContain('abcdef12')->toContain('Fix the thing');
});

it('deletes a branch with --yes', function () {
    MockClient::global([DeleteBranch::class => MockResponse::make('', 204)]);

    $this->artisan('branch:delete old-feature --repo=acme/api --yes')
        ->expectsOutputToContain('Deleted branch')
        ->assertSuccessful();
});

it('creates a tag', function () {
    MockClient::global([
        CreateTag::class => MockResponse::make(['name' => 'v1.0.0', 'target' => ['hash' => 'abcdef1234']], 201),
    ]);

    $this->artisan('tag:create v1.0.0 --repo=acme/api --target=abcdef1234')
        ->expectsOutputToContain('Created tag')
        ->assertSuccessful();
});

it('triggers a pipeline run', function () {
    MockClient::global([
        RunPipeline::class => MockResponse::make(['build_number' => 42], 201),
    ]);

    $this->artisan('pipeline:run --repo=acme/api --branch=main')
        ->expectsOutputToContain('Started pipeline #42')
        ->assertSuccessful();
});

it('edits repository settings', function () {
    MockClient::global([
        UpdateRepository::class => MockResponse::make(['full_name' => 'acme/api'], 200),
    ]);

    $this->artisan('repo:edit acme/api --description=Updated')
        ->expectsOutputToContain('Updated acme/api')
        ->assertSuccessful();
});

it('lists workspaces', function () {
    MockClient::global([
        ListWorkspaces::class => MockResponse::make([
            'values' => [
                ['slug' => 'acme', 'name' => 'Acme Inc', 'is_private' => true],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('workspace:list');

    expect($code)->toBe(0);
    expect($output)->toContain('acme')->toContain('Acme Inc');
});

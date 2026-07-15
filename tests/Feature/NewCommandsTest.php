<?php

use App\Http\Integrations\Bitbucket\Requests\ApiRequest;
use App\Http\Integrations\Bitbucket\Requests\Issues\UpdateIssue;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\DeclinePullRequest;
use App\Http\Integrations\Bitbucket\Requests\Repositories\CreateRepository;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-new-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token', 'workspace' => 'acme']);

    $this->app->instance(CredentialStore::class, $store);
});

it('runs an api passthrough request and prints json', function () {
    MockClient::global([
        ApiRequest::class => MockResponse::make(['full_name' => 'acme/api', 'is_private' => true], 200),
    ]);

    $this->artisan('api /repositories/acme/api')
        ->expectsOutputToContain('"full_name": "acme/api"')
        ->assertSuccessful();
});

it('declines a pull request with --yes', function () {
    MockClient::global([
        DeclinePullRequest::class => MockResponse::make(['state' => 'DECLINED'], 200),
    ]);

    $this->artisan('pr:close 3 --repo=acme/api --yes')
        ->expectsOutputToContain('Declined PR #3')
        ->assertSuccessful();
});

it('creates a repository', function () {
    MockClient::global([
        CreateRepository::class => MockResponse::make([
            'full_name' => 'acme/newrepo',
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/newrepo']],
        ], 200),
    ]);

    $this->artisan('repo:create acme/newrepo --description=Hi')
        ->expectsOutputToContain('Created acme/newrepo')
        ->assertSuccessful();
});

it('closes an issue with a state', function () {
    MockClient::global([
        UpdateIssue::class => MockResponse::make(['id' => 8, 'state' => 'resolved'], 200),
    ]);

    $this->artisan('issue:close 8 --repo=acme/api --state=resolved')
        ->expectsOutputToContain('resolved')
        ->assertSuccessful();
});

it('lists pipelines', function () {
    MockClient::global([
        ListPipelines::class => MockResponse::make([
            'values' => [
                ['build_number' => 12, 'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'SUCCESSFUL']], 'target' => ['ref_name' => 'main'], 'trigger' => ['name' => 'PUSH'], 'created_on' => '2026-07-10T09:00:00Z'],
            ],
        ], 200),
    ]);

    // Content via --json (table cells carry ANSI/OSC8 the headless renderer
    // may truncate; JSON is deterministic).
    [$code, $output] = runCommand('pipeline:list', ['--repo' => 'acme/api', '--json' => true]);

    expect($code)->toBe(0);
    expect($output)->toContain('SUCCESSFUL');
});

<?php

use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequestComment;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestStatuses;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-checks-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token']);

    $this->app->instance(CredentialStore::class, $store);
});

it('lists build statuses and fails on a failed check', function () {
    MockClient::global([
        ListPullRequestStatuses::class => MockResponse::make([
            'values' => [
                ['state' => 'SUCCESSFUL', 'name' => 'Unit', 'key' => 'unit', 'url' => 'https://ci/1'],
                ['state' => 'FAILED', 'name' => 'Lint', 'key' => 'lint', 'url' => 'https://ci/2'],
            ],
        ], 200),
    ]);

    // Content via --json (table cells carry ANSI/OSC8 that the headless
    // Prompts renderer may truncate; JSON is deterministic).
    [, $json] = runCommand('pr:checks', ['id' => 5, '--repo' => 'acme/api', '--json' => true]);
    expect($json)->toContain('SUCCESSFUL')->toContain('FAILED');

    // The table path returns a non-zero exit when a check has FAILED.
    [$code] = runCommand('pr:checks', ['id' => 5, '--repo' => 'acme/api']);
    expect($code)->toBe(1);
});

it('reports no statuses cleanly', function () {
    MockClient::global([
        ListPullRequestStatuses::class => MockResponse::make(['values' => []], 200),
    ]);

    $this->artisan('pr:checks 5 --repo=acme/api')
        ->expectsOutputToContain('No build statuses')
        ->assertSuccessful();
});

it('posts a pull request comment', function () {
    MockClient::global([
        CreatePullRequestComment::class => MockResponse::make([
            'id' => 99,
            'links' => ['html' => ['href' => 'https://bitbucket.org/acme/api/pull-requests/5/_/diff#comment-99']],
        ], 201),
    ]);

    $this->artisan('pr:comment 5 --repo=acme/api --body=LGTM')
        ->expectsOutputToContain('Commented on PR #5')
        ->assertSuccessful();
});

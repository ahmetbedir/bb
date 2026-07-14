<?php

use App\Http\Integrations\Bitbucket\Requests\Branches\ListBranches;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestCommits;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequestFiles;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\UnapprovePullRequest;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-prx-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 't', 'workspace' => 'acme']);
    $this->app->instance(CredentialStore::class, $store);
});

it('unapproves a pull request', function () {
    MockClient::global([UnapprovePullRequest::class => MockResponse::make('', 204)]);

    $this->artisan('pr:unapprove 5 --repo=acme/api')
        ->expectsOutputToContain('Removed approval from PR #5')
        ->assertSuccessful();
});

it('lists changed files in a pull request', function () {
    MockClient::global([
        ListPullRequestFiles::class => MockResponse::make([
            'values' => [
                ['status' => 'modified', 'new' => ['path' => 'src/App.php'], 'lines_added' => 10, 'lines_removed' => 2],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('pr:files', ['id' => 5, '--repo' => 'acme/api']);

    expect($code)->toBe(0);
    expect($output)->toContain('src/App.php')->toContain('modified');
});

it('lists commits in a pull request', function () {
    MockClient::global([
        ListPullRequestCommits::class => MockResponse::make([
            'values' => [
                ['hash' => 'abcdef1234', 'message' => 'Do the thing', 'author' => ['user' => ['display_name' => 'Jane']], 'date' => '2026-07-10T00:00:00Z'],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('pr:commits', ['id' => 5, '--repo' => 'acme/api']);

    expect($code)->toBe(0);
    expect($output)->toContain('abcdef12')->toContain('Do the thing');
});

it('filters pr:list by source branch', function () {
    MockClient::global([
        ListPullRequests::class => MockResponse::make([
            'values' => [
                ['id' => 1, 'title' => 'From prod', 'state' => 'OPEN', 'author' => ['display_name' => 'A'], 'source' => ['branch' => ['name' => 'production']], 'destination' => ['branch' => ['name' => 'main']]],
                ['id' => 2, 'title' => 'From feat', 'state' => 'OPEN', 'author' => ['display_name' => 'B'], 'source' => ['branch' => ['name' => 'feature/x']], 'destination' => ['branch' => ['name' => 'main']]],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('pr:list', ['branch' => 'production', '--repo' => 'acme/api']);

    expect($code)->toBe(0);
    expect($output)->toContain('From prod')->not->toContain('From feat');
});

it('filters branch:list by latest commit author', function () {
    MockClient::global([
        ListBranches::class => MockResponse::make([
            'values' => [
                ['name' => 'a', 'target' => ['hash' => 'aaaaaaaa', 'author' => ['user' => ['display_name' => 'Semih Kaya']]]],
                ['name' => 'b', 'target' => ['hash' => 'bbbbbbbb', 'author' => ['user' => ['display_name' => 'Jane Doe']]]],
            ],
        ], 200),
    ]);

    [$code, $output] = runCommand('branch:list', ['--repo' => 'acme/api', '--user' => 'semih']);

    expect($code)->toBe(0);
    expect($output)->toContain('a')->not->toContain('bbbbbbbb');
});

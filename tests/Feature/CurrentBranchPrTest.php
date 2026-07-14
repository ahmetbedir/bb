<?php

use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequestDiff;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Support\CredentialStore;
use App\Support\GitContext;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-cb-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 't']);
    $this->app->instance(CredentialStore::class, $store);

    // Pretend we're in a checkout of acme/api on branch "production".
    $this->app->instance(GitContext::class, new class extends GitContext
    {
        public function currentRepo(): ?array
        {
            return ['acme', 'api'];
        }

        public function currentBranch(): ?string
        {
            return 'production';
        }
    });
});

it('does not open an unrelated or merged diff when the branch has no open PR', function () {
    // A different-branch PR and a MERGED production PR — neither should match.
    MockClient::global([
        ListPullRequests::class => MockResponse::make([
            'values' => [
                ['id' => 7, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/x']]],
                ['id' => 3899, 'state' => 'MERGED', 'source' => ['branch' => ['name' => 'production']]],
            ],
        ], 200),
    ]);

    $this->artisan('pr:diff')
        ->expectsOutputToContain('No open pull request found for branch "production"')
        ->assertFailed();
});

it('uses the PR whose source branch matches the current branch', function () {
    MockClient::global([
        ListPullRequests::class => MockResponse::make([
            'values' => [
                ['id' => 7, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'feature/x']]],
                ['id' => 9, 'state' => 'OPEN', 'source' => ['branch' => ['name' => 'production']]],
            ],
        ], 200),
        GetPullRequestDiff::class => MockResponse::make("diff --git a/x b/x\n+added", 200),
    ]);

    $this->artisan('pr:diff')->assertSuccessful();
});

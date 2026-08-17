<?php

use App\Http\Integrations\Bitbucket\Requests\Deployments\ListDeployments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListEnvironments;
use App\Http\Integrations\Bitbucket\Requests\Deployments\ListEnvironmentVariables;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipelineStepLog;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelineSteps;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-deployments-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token']);

    $this->app->instance(CredentialStore::class, $store);
});

/**
 * @return array<int, array<string, mixed>>
 */
function environmentPayloads(): array
{
    return [
        [
            'uuid' => '{5e58fadc-c611-45c9-b21e-7360b5dfcbb6}',
            'name' => 'Staging',
            'slug' => 'staging',
            'rank' => 1,
            'environment_type' => ['name' => 'Staging'],
            'deployment_gate_enabled' => false,
            'environment_lock_enabled' => true,
            'lock' => ['name' => 'OPEN'],
            'restrictions' => ['admin_only' => false],
            'hidden' => false,
        ],
        [
            'uuid' => '{99ed05aa-0d52-46c3-894f-5647c6f6e717}',
            'name' => 'Production',
            'slug' => 'production',
            'rank' => 2,
            'environment_type' => ['name' => 'Production'],
            'deployment_gate_enabled' => true,
            'lock' => ['name' => 'OPEN'],
            'restrictions' => ['admin_only' => true],
        ],
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function deploymentPayloads(): array
{
    return [
        [
            'uuid' => '{6a373500-7e9f-56fd-b67c-1aae47e7c7cb}',
            'number' => 867,
            'environment' => environmentPayloads()[0],
            'release' => [
                'name' => '#1586',
                'url' => 'https://bitbucket.org/acme/api/addon/pipelines/home#!/results/1586',
                'commit' => ['hash' => '631fc21afe00dca1ff18871f3d5d9552f074a6a6'],
            ],
            'step' => [
                'uuid' => '{000c1e44-685e-4f6a-b532-3e6f06671fb2}',
                'pipeline' => ['uuid' => '{5b984b19-2af0-46ff-93ec-28118d02516b}'],
            ],
            'state' => [
                'name' => 'COMPLETED',
                'status' => ['name' => 'SUCCESSFUL'],
                'started_on' => '2026-01-21T16:18:39Z',
                'completed_on' => '2026-01-21T16:21:29Z',
                'url' => 'https://bitbucket.org/acme/api/addon/pipelines/home#!/results/1586',
                'last_successful_deployment' => ['number' => 866],
            ],
            'last_update_time' => '2026-01-21T16:21:29Z',
        ],
        [
            'uuid' => '{88cd1d0c-35a5-5dea-b94e-b690d8e97393}',
            'number' => 866,
            'environment' => environmentPayloads()[1],
            'release' => ['name' => '#1585', 'commit' => ['hash' => '08be8d1752a35f1155af523f116bf19ab1427ce2']],
            'state' => [
                'name' => 'COMPLETED',
                'status' => ['name' => 'FAILED'],
                'started_on' => '2026-01-21T16:11:31Z',
                'completed_on' => '2026-01-21T16:14:20Z',
            ],
        ],
    ];
}

it('lists deployments newest first', function () {
    MockClient::global([
        ListDeployments::class => MockResponse::make(['values' => deploymentPayloads()], 200),
    ]);

    [$code, $output] = runCommand('deployment:list', ['--repo' => 'acme/api']);

    expect($output)
        ->toContain('867')
        ->toContain('Staging')
        ->toContain('#1586')
        ->toContain('631fc21a')
        ->toContain('2m 50s');

    expect($code)->toBe(0);
});

it('restricts the list to one environment by name', function () {
    $mock = MockClient::global([
        ListEnvironments::class => MockResponse::make(['values' => environmentPayloads()], 200),
        ListDeployments::class => MockResponse::make(['values' => [deploymentPayloads()[0]]], 200),
    ]);

    runCommand('deployment:list', ['--repo' => 'acme/api', '--environment' => 'staging']);

    $mock->assertSent(fn ($request) => $request instanceof ListDeployments
        && $request->query()->get('environment') === '{5e58fadc-c611-45c9-b21e-7360b5dfcbb6}');
});

it('filters the list by status', function () {
    MockClient::global([
        ListDeployments::class => MockResponse::make(['values' => deploymentPayloads()], 200),
    ]);

    [, $output] = runCommand('deployment:list', ['--repo' => 'acme/api', '--status' => 'failed']);

    expect($output)->toContain('866')->not->toContain('#1586');
});

it('shows a deployment resolved by its number, with the step that ran it', function () {
    MockClient::global([
        ListDeployments::class => MockResponse::make(['values' => deploymentPayloads()], 200),
        ListPipelineSteps::class => MockResponse::make([
            'values' => [[
                'uuid' => '{000c1e44-685e-4f6a-b532-3e6f06671fb2}',
                'name' => 'Deploy to staging',
                'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'SUCCESSFUL']],
                'duration_in_seconds' => 170,
            ]],
        ], 200),
    ]);

    [$code, $output] = runCommand('deployment:view', ['deployment' => '867', '--repo' => 'acme/api']);

    expect($output)
        ->toContain('#867')
        ->toContain('Staging (Staging)')
        ->toContain('2m 50s')
        ->toContain('Deploy to staging')
        ->toContain('#866 (last successful)');

    expect($code)->toBe(0);
});

it('says so when a deployment number is outside the scanned window', function () {
    MockClient::global([
        ListDeployments::class => MockResponse::make(['values' => deploymentPayloads()], 200),
    ]);

    [$code, $output] = runCommand('deployment:view', ['deployment' => '42', '--repo' => 'acme/api']);

    expect($output)->toContain('Deployment #42');
    expect($code)->toBe(1);
});

it('prints the log of the step that ran a deployment', function () {
    MockClient::global([
        ListDeployments::class => MockResponse::make(['values' => deploymentPayloads()], 200),
        ListPipelineSteps::class => MockResponse::make([
            'values' => [[
                'uuid' => '{000c1e44-685e-4f6a-b532-3e6f06671fb2}',
                'name' => 'Deploy to staging',
                'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'SUCCESSFUL']],
            ]],
        ], 200),
        GetPipelineStepLog::class => MockResponse::make("+ rsync\ndone\n", 200),
    ]);

    [$code, $output] = runCommand('deployment:logs', ['deployment' => '867', '--repo' => 'acme/api']);

    expect($output)->toContain('rsync')->toContain('done');
    expect($code)->toBe(0);
});

it('lists environments', function () {
    MockClient::global([
        ListEnvironments::class => MockResponse::make(['values' => environmentPayloads()], 200),
    ]);

    [$code, $output] = runCommand('environment:list', ['--repo' => 'acme/api']);

    expect($output)->toContain('Staging')->toContain('production')->toContain('OPEN');
    expect($code)->toBe(0);
});

it('shows an environment with its variables and recent deployments', function () {
    MockClient::global([
        ListEnvironments::class => MockResponse::make(['values' => environmentPayloads()], 200),
        ListEnvironmentVariables::class => MockResponse::make([
            'values' => [
                ['key' => 'DEPLOY_HOST', 'value' => 'staging.acme.dev', 'secured' => false],
                ['key' => 'DEPLOY_KEY', 'secured' => true],
            ],
        ], 200),
        ListDeployments::class => MockResponse::make(['values' => [deploymentPayloads()[0]]], 200),
    ]);

    [$code, $output] = runCommand('environment:view', ['environment' => 'Staging', '--repo' => 'acme/api']);

    expect($output)
        ->toContain('DEPLOY_HOST')
        ->toContain('staging.acme.dev')
        ->toContain('••••••')
        ->toContain('Recent deployments')
        ->toContain('867');

    expect($code)->toBe(0);
});

it('rejects an unknown environment name', function () {
    MockClient::global([
        ListEnvironments::class => MockResponse::make(['values' => environmentPayloads()], 200),
    ]);

    [$code, $output] = runCommand('environment:view', ['environment' => 'nope', '--repo' => 'acme/api']);

    expect($output)->toContain('No environment matching')->toContain('Staging');
    expect($code)->toBe(1);
});

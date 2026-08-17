<?php

use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipeline;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipelineStep;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipelineStepLog;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetStepTestReport;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelineSteps;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $store = (new CredentialStore(sys_get_temp_dir().'/bb-pipelines-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token']);

    $this->app->instance(CredentialStore::class, $store);
});

/**
 * @return array<string, mixed>
 */
function pipelinePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'uuid' => '{a5f0a0dc-93d2-4fd4-8004-cda575d68766}',
        'build_number' => 4879,
        'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'FAILED']],
        'target' => [
            'ref_type' => 'branch',
            'ref_name' => 'feature/login',
            'commit' => ['hash' => 'cffc4b7e2d85552289daea550e17ae832cdd4db4'],
            'selector' => ['type' => 'default'],
        ],
        'trigger' => ['name' => 'PUSH'],
        'creator' => ['display_name' => 'Ada Lovelace'],
        'created_on' => '2026-08-17T08:05:07.777Z',
        'completed_on' => '2026-08-17T08:07:44.795Z',
        'duration_in_seconds' => 138,
        'build_seconds_used' => 274,
    ], $overrides);
}

/**
 * @return array<int, array<string, mixed>>
 */
function stepPayloads(): array
{
    return [
        [
            'uuid' => '{c747f59e-592b-479e-9262-560e522cd8f7}',
            'name' => 'Build',
            'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'SUCCESSFUL']],
            'started_on' => '2026-08-17T08:05:26Z',
            'completed_on' => '2026-08-17T08:06:00Z',
            'duration_in_seconds' => 34,
            'image' => ['name' => 'php:8.3-cli'],
        ],
        [
            'uuid' => '{d848f60f-6a3c-58af-a373-671f633de9e8}',
            'name' => 'Deploy to staging',
            'state' => ['name' => 'COMPLETED', 'result' => ['name' => 'FAILED']],
            'started_on' => '2026-08-17T08:06:10Z',
            'completed_on' => '2026-08-17T08:07:44Z',
            'duration_in_seconds' => 94,
            'image' => ['name' => 'node:20'],
            'maxTime' => 30,
            'trigger' => ['type' => 'pipeline_step_trigger_manual'],
            'script_commands' => [
                ['commandType' => 'user', 'command' => 'npm ci'],
                ['commandType' => 'user', 'command' => 'npm run deploy'],
            ],
            'setup_commands' => [
                ['commandType' => 'system', 'command' => 'git clone'],
            ],
        ],
    ];
}

it('shows a pipeline with its steps', function () {
    MockClient::global([
        GetPipeline::class => MockResponse::make(pipelinePayload(), 200),
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
    ]);

    [$code, $output] = runCommand('pipeline:view', ['pipeline' => '4879', '--repo' => 'acme/api']);

    expect($output)
        ->toContain('4879')
        ->toContain('feature/login')
        ->toContain('Ada Lovelace')
        ->toContain('2m 18s')
        ->toContain('Build')
        ->toContain('Deploy to staging');

    // A failed pipeline is a failed command.
    expect($code)->toBe(1);
});

it('defaults to the most recent pipeline, honouring --branch', function () {
    $mock = MockClient::global([
        ListPipelines::class => MockResponse::make(['values' => [pipelinePayload()]], 200),
        GetPipeline::class => MockResponse::make(pipelinePayload(), 200),
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
    ]);

    [, $json] = runCommand('pipeline:view', ['--repo' => 'acme/api', '--branch' => 'develop', '--json' => true]);

    expect($json)->toContain('"build_number": 4879');

    $mock->assertSent(fn ($request) => $request instanceof ListPipelines
        && $request->query()->get('target.branch') === 'develop');
});

it('passes list filters to the API as query parameters', function () {
    $mock = MockClient::global([
        ListPipelines::class => MockResponse::make(['values' => [pipelinePayload()]], 200),
    ]);

    runCommand('pipeline:list', [
        '--repo' => 'acme/api',
        '--branch' => 'develop',
        '--status' => 'failed',
        '--trigger' => 'manual',
    ]);

    $mock->assertSent(function ($request) {
        $query = $request->query();

        return $query->get('target.branch') === 'develop'
            && $query->get('status') === 'FAILED'
            && $query->get('trigger_type') === 'MANUAL';
    });
});

it('shows one step in full, selected by name fragment', function () {
    MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetPipelineStep::class => MockResponse::make(stepPayloads()[1], 200),
    ]);

    [$code, $output] = runCommand('pipeline:step', [
        'pipeline' => '4879',
        'step' => 'staging',
        '--repo' => 'acme/api',
    ]);

    expect($output)
        ->toContain('Deploy to staging')
        ->toContain('node:20')
        ->toContain('30 min')
        ->toContain('manual')
        ->toContain('npm run deploy')
        // Setup commands are hidden unless asked for.
        ->not->toContain('git clone');

    expect($code)->toBe(1);
});

it('encodes braced step UUIDs when requesting a step', function () {
    $mock = MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetPipelineStep::class => MockResponse::make(stepPayloads()[0], 200),
    ]);

    runCommand('pipeline:step', ['pipeline' => '4879', 'step' => '1', '--repo' => 'acme/api']);

    $mock->assertSent(fn ($request) => $request instanceof GetPipelineStep
        && str_contains($request->resolveEndpoint(), '/steps/%7Bc747f59e-592b-479e-9262-560e522cd8f7%7D'));
});

it('prints the log of the failed step by default', function () {
    MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetPipelineStepLog::class => MockResponse::make("+ npm ci\nboom\n", 200),
    ]);

    [, $output] = runCommand('pipeline:logs', ['pipeline' => '4879', '--repo' => 'acme/api']);

    expect($output)->toContain('boom');
});

it('applies --grep and --tail to the log', function () {
    MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetPipelineStepLog::class => MockResponse::make("alpha\nbeta\ngamma\n", 200),
    ]);

    [, $grepped] = runCommand('pipeline:logs', [
        'pipeline' => '4879', 'step' => '1', '--repo' => 'acme/api', '--grep' => 'bet',
    ]);

    expect($grepped)->toContain('beta')->not->toContain('alpha');

    [, $tailed] = runCommand('pipeline:logs', [
        'pipeline' => '4879', 'step' => '1', '--repo' => 'acme/api', '--tail' => '1',
    ]);

    expect($tailed)->toContain('gamma')->not->toContain('alpha');
});

it('explains a missing log instead of failing hard', function () {
    MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetPipelineStepLog::class => MockResponse::make(['error' => ['message' => 'Not found']], 404),
    ]);

    [$code, $output] = runCommand('pipeline:logs', ['pipeline' => '4879', 'step' => '1', '--repo' => 'acme/api']);

    expect($output)->toContain('No log for step 1');
    expect($code)->toBe(1);
});

it('reports a step without a test report as a normal outcome', function () {
    MockClient::global([
        ListPipelineSteps::class => MockResponse::make(['values' => stepPayloads()], 200),
        GetStepTestReport::class => MockResponse::make(['error' => ['message' => 'Not found']], 404),
    ]);

    [$code, $output] = runCommand('pipeline:tests', ['pipeline' => '4879', 'step' => '1', '--repo' => 'acme/api']);

    expect($output)->toContain('no test report');
    expect($code)->toBe(0);
});

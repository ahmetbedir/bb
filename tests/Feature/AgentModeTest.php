<?php

use App\Http\Integrations\Bitbucket\Requests\ApiRequest;
use App\Http\Integrations\Bitbucket\Requests\ApiRequestWithBody;
use App\Http\Integrations\Bitbucket\Requests\CurrentUser;
use App\Http\Integrations\Bitbucket\Requests\NextPageRequest;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\RunPipeline;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequest;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\MergePullRequest;
use App\Http\Integrations\Bitbucket\Requests\Repositories\UpdateRepository;
use App\Providers\AppServiceProvider;
use App\Support\CredentialStore;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function () {
    $this->store = (new CredentialStore(sys_get_temp_dir().'/bb-agent-'.uniqid().'/config.json'))
        ->fill(['method' => 'token', 'token' => 'test-token']);

    $this->app->instance(CredentialStore::class, $this->store);
});

afterEach(function () {
    putenv('BB_TOKEN');
    putenv('BB_WORKSPACE');
});

function onePr(): array
{
    return ['id' => 7, 'title' => 'Add caching', 'state' => 'OPEN', 'author' => ['display_name' => 'Jane'], 'source' => ['branch' => ['name' => 'feature/cache']], 'destination' => ['branch' => ['name' => 'main']], 'updated_on' => '2026-07-10T00:00:00Z', 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/api/pull-requests/7']]];
}

it('authenticates from BB_TOKEN without a config file and never persists it', function () {
    $store = new CredentialStore(sys_get_temp_dir().'/bb-env-'.uniqid().'/config.json');
    $this->app->instance(CredentialStore::class, $store);
    putenv('BB_TOKEN=env-token');

    $mock = MockClient::global([ListPullRequests::class => MockResponse::make(['values' => [onePr()]], 200)]);

    [$code] = runCommand('pr:list', ['--repo' => 'acme/api', '--json' => true]);

    expect($code)->toBe(0)
        ->and($store->isConfigured())->toBeTrue()
        ->and($store->all())->toBe([])
        ->and($store->exists())->toBeFalse();

    $mock->assertSent(fn ($request, $response) => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer env-token');
});

it('refuses to merge without --yes when nobody can confirm', function () {
    $mock = MockClient::global([MergePullRequest::class => MockResponse::make(['id' => 7, 'state' => 'MERGED'], 200)]);

    expect(fn () => runCommand('pr:merge', ['id' => 7, '--repo' => 'acme/api', '--no-interaction' => true]))
        ->toThrow(RuntimeException::class, '--yes');

    $mock->assertNothingSent();
});

it('merges with --yes and returns the result as JSON', function () {
    MockClient::global([MergePullRequest::class => MockResponse::make(['id' => 7, 'state' => 'MERGED'], 200)]);

    [$code, $output] = runCommand('pr:merge', ['id' => 7, '--repo' => 'acme/api', '--yes' => true, '--no-interaction' => true, '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true))->toBe(['id' => 7, 'state' => 'MERGED']);
});

it('prints the created pull request as JSON', function () {
    MockClient::global([CreatePullRequest::class => MockResponse::make(onePr(), 201)]);

    [$code, $output] = runCommand('pr:create', ['--repo' => 'acme/api', '--title' => 'Add caching', '--source' => 'feature/cache', '--dest' => 'main', '--json' => true]);

    expect($code)->toBe(0)
        ->and(json_decode($output, true)['id'])->toBe(7);
});

it('treats --jq on its own as a JSON request', function () {
    MockClient::global([ListPullRequests::class => MockResponse::make(['values' => [onePr()]], 200)]);

    [$code, $output] = runCommand('pr:list', ['--repo' => 'acme/api', '--jq' => '.[0].id']);

    expect($code)->toBe(0)->and(trim($output))->toBe('7');
})->skip(fn () => trim((string) shell_exec('command -v jq')) === '', 'jq is not installed');

it('prints plain tab-separated tables when output is piped', function () {
    MockClient::global([ListPullRequests::class => MockResponse::make(['values' => [onePr()]], 200)]);

    [$code, $output] = runCommand('pr:list', ['--repo' => 'acme/api'], decorated: false);

    expect($code)->toBe(0)
        ->and($output)->not->toContain("\033")
        ->and($output)->toContain("\t")
        ->and($output)->toContain('Add caching');
});

it('reports API errors as a JSON object in --json mode', function () {
    MockClient::global([ListPullRequests::class => MockResponse::make(['error' => ['message' => 'Repository not found']], 404)]);

    [$code, $output] = runCommand('pr:list', ['--repo' => 'acme/nope', '--json' => true]);

    expect($code)->toBe(1)
        ->and(json_decode(trim($output), true))->toBe(['error' => ['status' => 404, 'message' => 'Repository not found']]);
});

it('follows every page with api --paginate', function () {
    MockClient::global([
        ApiRequest::class => MockResponse::make(['values' => [['id' => 1]], 'next' => 'https://api.bitbucket.org/2.0/x?page=2'], 200),
        NextPageRequest::class => MockResponse::make(['values' => [['id' => 2]]], 200),
    ]);

    [$code, $output] = runCommand('api', ['path' => '/x', '--paginate' => true]);

    expect($code)->toBe(0)->and(json_decode($output, true))->toBe([['id' => 1], ['id' => 2]]);
});

it('rejects an unknown HTTP method cleanly', function () {
    [$code, $output] = runCommand('api', ['path' => '/x', '--method' => 'FETCH']);

    expect($code)->toBe(1)->and($output)->toContain('Unsupported HTTP method');
});

it('detects agents from their environment, with BB_AGENT as an override', function () {
    $saved = ['AI_AGENT' => getenv('AI_AGENT'), 'CLAUDECODE' => getenv('CLAUDECODE')];

    try {
        putenv('AI_AGENT=claude-code');
        expect(AppServiceProvider::agentDetected())->toBeTrue();

        putenv('BB_AGENT=0');
        expect(AppServiceProvider::agentDetected())->toBeFalse();

        putenv('BB_AGENT=1');
        putenv('AI_AGENT');
        putenv('CLAUDECODE');
        expect(AppServiceProvider::agentDetected())->toBeTrue();
    } finally {
        putenv('BB_AGENT');
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
    }
});

it('neither lists nor accepts --env', function () {
    [, $help] = runCommand('help', ['command_name' => 'pr:list'], decorated: false);

    expect($help)->not->toContain('--env');
    expect(fn () => runCommand('pr:list', ['--repo' => 'acme/api', '--env' => 'testing']))
        ->toThrow(InvalidArgumentException::class, 'The "--env" option does not exist.');
});

it('rejects an invalid --jq filter before sending anything', function () {
    $mock = MockClient::global([MergePullRequest::class => MockResponse::make(['id' => 7], 200)]);

    [$code, $output] = runCommand('pr:merge', ['id' => 7, '--repo' => 'acme/api', '--yes' => true, '--jq' => '.[ ']);

    expect($code)->toBe(1)->and($output)->toContain('Invalid --jq filter');
    $mock->assertNothingSent();
})->skip(fn () => trim((string) shell_exec('command -v jq')) === '', 'jq is not installed');

it('requires --yes for unattended writes through bb api', function () {
    $mock = MockClient::global([ApiRequestWithBody::class => MockResponse::make(['ok' => true], 200)]);

    [$code, $output] = runCommand('api', ['path' => '/x', '--method' => 'POST', '--field' => ['a=1'], '--no-interaction' => true]);

    expect($code)->toBe(1)->and($output)->toContain('needs --yes');
    $mock->assertNothingSent();

    [$code] = runCommand('api', ['path' => '/x', '--method' => 'POST', '--field' => ['a=1'], '--no-interaction' => true, '--yes' => true]);

    expect($code)->toBe(0);
});

it('requires --yes for unattended pipeline runs', function () {
    $mock = MockClient::global([RunPipeline::class => MockResponse::make(['build_number' => 5], 201)]);

    [$code] = runCommand('pipeline:run', ['--repo' => 'acme/api', '--branch' => 'main', '--no-interaction' => true]);

    expect($code)->toBe(1);
    $mock->assertNothingSent();
});

it('always confirms making a repository public', function () {
    $mock = MockClient::global([UpdateRepository::class => MockResponse::make(['full_name' => 'acme/api'], 200)]);

    expect(fn () => runCommand('repo:edit', ['repo' => 'acme/api', '--public' => true, '--no-interaction' => true]))
        ->toThrow(RuntimeException::class, '--yes');
    $mock->assertNothingSent();

    [$code] = runCommand('repo:edit', ['repo' => 'acme/api', '--public' => true, '--private' => true, '--yes' => true]);
    expect($code)->toBe(1);
});

it('confirms plugin installs and rejects names that escape the plugins directory', function () {
    $home = getenv('BB_HOME');
    $tmp = sys_get_temp_dir().'/bb-plugin-'.uniqid();
    putenv("BB_HOME={$tmp}");
    $src = "{$tmp}/Foo.php";
    @mkdir($tmp, 0700, true);
    file_put_contents($src, '<?php');

    try {
        expect(fn () => runCommand('plugin:install', ['source' => $src, '--no-interaction' => true]))
            ->toThrow(RuntimeException::class, '--yes');

        [$code, $output] = runCommand('plugin:install', ['source' => $src, '--name' => '../../evil.php', '--yes' => true]);

        expect($code)->toBe(1)
            ->and($output)->toContain('Invalid plugin name')
            ->and(file_exists("{$tmp}/.config/evil.php"))->toBeFalse();
    } finally {
        $home === false ? putenv('BB_HOME') : putenv("BB_HOME={$home}");
    }
});

it('fails configure when Bitbucket rejects the token', function () {
    MockClient::global([CurrentUser::class => MockResponse::make(['error' => ['message' => 'nope']], 401)]);

    $this->artisan('configure', ['--workspace' => 'acme'])
        ->expectsQuestion('API / access token', 'bad-token')
        ->assertExitCode(1);

    expect($this->store->get('token'))->toBe('bad-token'); // still saved, as the message says
});

it('refuses request paths with "." or ".." segments', function (string $id) {
    $mock = MockClient::global([MergePullRequest::class => MockResponse::make(['id' => 7], 200)]);

    [$code] = runCommand('pr:merge', ['id' => $id, '--repo' => 'acme/api', '--yes' => true]);

    expect($code)->toBe(1);
    $mock->assertNothingSent();
})->with(['raw' => '7/../../8', 'encoded' => '7/%2e%2e/8']);

it('does not follow pagination links to another host', function () {
    $mock = MockClient::global([
        ApiRequest::class => MockResponse::make(['values' => [['id' => 1]], 'next' => 'https://evil.example/steal'], 200),
        NextPageRequest::class => MockResponse::make(['values' => [['id' => 2]]], 200),
    ]);

    [$code] = runCommand('api', ['path' => '/x', '--paginate' => true]);

    expect($code)->toBe(1);
    $mock->assertNotSent(NextPageRequest::class);
});

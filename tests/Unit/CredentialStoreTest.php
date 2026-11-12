<?php

use App\Support\CredentialStore;
use Symfony\Component\Process\Process;

function tempStore(): CredentialStore
{
    return new CredentialStore(sys_get_temp_dir().'/bb-test-'.uniqid().'/config.json');
}

it('is not configured when empty', function () {
    expect(tempStore()->isConfigured())->toBeFalse();
});

it('treats a retired app password config as not configured', function () {
    $store = tempStore()->fill([
        'method' => 'app_password',
        'username' => 'jane',
        'app_password' => 'secret',
    ]);

    expect($store->isConfigured())->toBeFalse()
        ->and($store->usesAppPassword())->toBeTrue();
});

it('is configured with a token', function () {
    $store = tempStore()->fill(['method' => 'token', 'token' => 'abc']);

    expect($store->isConfigured())->toBeTrue();
});

it('is not configured with a token method but no token', function () {
    expect(tempStore()->set('method', 'token')->isConfigured())->toBeFalse();
});

it('is configured with an oauth access token', function () {
    $store = tempStore()->fill(['method' => 'oauth', 'oauth.access_token' => 'xyz']);

    expect($store->isConfigured())->toBeTrue()
        ->and($store->get('oauth.access_token'))->toBe('xyz');
});

it('stores and forgets nested dot-notation keys', function () {
    $store = tempStore();
    $store->set('oauth.refresh_token', 'r1');

    expect($store->get('oauth.refresh_token'))->toBe('r1');

    $store->forget('oauth');

    expect($store->get('oauth.refresh_token'))->toBeNull();
});

it('persists and reloads values', function () {
    $path = sys_get_temp_dir().'/bb-test-'.uniqid().'/config.json';

    (new CredentialStore($path))->fill(['method' => 'token', 'token' => 'abc'])->save();

    expect((new CredentialStore($path))->get('token'))->toBe('abc');

    @unlink($path);
    @rmdir(dirname($path));
});

it('refuses to overwrite a config file it cannot parse', function () {
    $path = sys_get_temp_dir().'/bb-corrupt-'.uniqid().'/config.json';
    mkdir(dirname($path), 0700, true);
    file_put_contents($path, '{"method":"token","token":"keep-me"');

    $store = new CredentialStore($path);

    expect($store->isConfigured())->toBeFalse()
        ->and($store->isUnreadable())->toBeTrue()
        ->and(fn () => $store->set('skill.hinted', true)->save())->toThrow(RuntimeException::class);

    expect(file_get_contents($path))->toContain('keep-me');
});

it('writes the config privately and atomically', function () {
    $path = sys_get_temp_dir().'/bb-atomic-'.uniqid().'/config.json';

    (new CredentialStore($path))->fill(['method' => 'token', 'token' => 'abc'])->save();

    expect(fileperms($path) & 0777)->toBe(0600)
        ->and(glob(dirname($path).'/.config-*'))->toBe([]);
});

it('never falls back to a shared temp directory for the home dir', function () {
    expect(CredentialStore::homeDir())->not->toStartWith(rtrim(sys_get_temp_dir(), '/').'/.config');
});

it('merges saves from stores that loaded before each other saved', function () {
    $path = sys_get_temp_dir().'/bb-merge-'.uniqid().'/config.json';
    (new CredentialStore($path))->fill(['method' => 'token', 'token' => 'abc', 'old' => 1])->save();

    $a = new CredentialStore($path);
    $b = new CredentialStore($path);
    $a->all();
    $b->all();

    $a->set('skill.hinted', true)->save();
    $b->forget('old')->set('workspace', 'acme')->save();

    expect((new CredentialStore($path))->all())->toBe([
        'method' => 'token', 'token' => 'abc', 'skill' => ['hinted' => true], 'workspace' => 'acme',
    ]);
});

it('loses no writes when several processes save at once', function () {
    $path = sys_get_temp_dir().'/bb-race-'.uniqid().'/config.json';
    $script = dirname(__DIR__).'/Fixtures/concurrent-writer.php';

    $processes = array_map(function (string $prefix) use ($path, $script) {
        $process = new Process([PHP_BINARY, $script, $path, $prefix, '40']);
        $process->start();

        return $process;
    }, ['a', 'b', 'c', 'd']);

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    }

    expect((new CredentialStore($path))->get('race'))->toHaveCount(160);
});

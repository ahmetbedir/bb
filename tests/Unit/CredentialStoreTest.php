<?php

use App\Support\CredentialStore;

function tempStore(): CredentialStore
{
    return new CredentialStore(sys_get_temp_dir().'/bbcli-test-'.uniqid().'/config.json');
}

it('is not configured when empty', function () {
    expect(tempStore()->isConfigured())->toBeFalse();
});

it('is configured with an app password', function () {
    $store = tempStore()->fill([
        'method' => 'app_password',
        'username' => 'jane',
        'app_password' => 'secret',
    ]);

    expect($store->isConfigured())->toBeTrue();
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
    $path = sys_get_temp_dir().'/bbcli-test-'.uniqid().'/config.json';

    (new CredentialStore($path))->fill(['method' => 'token', 'token' => 'abc'])->save();

    expect((new CredentialStore($path))->get('token'))->toBe('abc');

    @unlink($path);
    @rmdir(dirname($path));
});

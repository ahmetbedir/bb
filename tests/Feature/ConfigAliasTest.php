<?php

use App\Support\CredentialStore;

beforeEach(function () {
    $this->store = new CredentialStore(sys_get_temp_dir().'/bb-cfg-'.uniqid().'/config.json');
    $this->app->instance(CredentialStore::class, $this->store);
});

it('sets and reads a setting', function () {
    $this->artisan('config pager off')->assertSuccessful();

    expect($this->store->get('settings.pager'))->toBe('off');

    $this->artisan('config pager')
        ->expectsOutputToContain('off')
        ->assertSuccessful();
});

it('unsets a setting', function () {
    $this->store->set('settings.pager', 'off')->save();

    $this->artisan('config pager --unset')->assertSuccessful();

    expect($this->store->get('settings.pager'))->toBeNull();
});

it('defines and stores an alias', function () {
    $this->artisan('alias co pr:checkout')->assertSuccessful();

    expect($this->store->get('aliases.co'))->toBe('pr:checkout');
});

it('deletes an alias', function () {
    $this->store->set('aliases.co', 'pr:checkout')->save();

    $this->artisan('alias co --delete')->assertSuccessful();

    expect($this->store->get('aliases.co'))->toBeNull();
});

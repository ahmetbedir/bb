<?php

use App\Support\GitContext;

it('parses scp-style ssh remotes', function () {
    expect(GitContext::parseRemote('git@bitbucket.org:acme/api-service.git'))
        ->toBe(['acme', 'api-service']);
});

it('parses ssh:// remotes', function () {
    expect(GitContext::parseRemote('ssh://git@bitbucket.org/acme/api-service.git'))
        ->toBe(['acme', 'api-service']);
});

it('parses https remotes with a user', function () {
    expect(GitContext::parseRemote('https://jane@bitbucket.org/acme/api-service.git'))
        ->toBe(['acme', 'api-service']);
});

it('parses https remotes without .git', function () {
    expect(GitContext::parseRemote('https://bitbucket.org/acme/api-service'))
        ->toBe(['acme', 'api-service']);
});

it('returns null for non-bitbucket remotes', function () {
    expect(GitContext::parseRemote('git@github.com:acme/api-service.git'))->toBeNull();
});

it('returns null for malformed remotes', function () {
    expect(GitContext::parseRemote('https://bitbucket.org/acme'))->toBeNull();
});

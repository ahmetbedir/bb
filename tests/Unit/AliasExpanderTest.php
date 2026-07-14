<?php

use App\Support\AliasExpander;

$aliases = ['co' => 'pr:checkout', 'st' => 'pr:status'];

it('expands an alias to its command', function () use ($aliases) {
    expect(AliasExpander::expand(['bb', 'co', '42'], $aliases))
        ->toBe(['bb', 'pr:checkout', '42']);
});

it('expands multi-token aliases', function () {
    expect(AliasExpander::expand(['bb', 'x'], ['x' => 'pr:list --state=MERGED']))
        ->toBe(['bb', 'pr:list', '--state=MERGED']);
});

it('leaves unknown commands untouched', function () use ($aliases) {
    expect(AliasExpander::expand(['bb', 'pr:list'], $aliases))
        ->toBe(['bb', 'pr:list']);
});

it('skips global options when finding the command', function () use ($aliases) {
    expect(AliasExpander::expand(['bb', '-v', 'co'], $aliases))
        ->toBe(['bb', '-v', 'pr:checkout']);
});

it('is a no-op with no aliases', function () {
    expect(AliasExpander::expand(['bb', 'co'], []))->toBe(['bb', 'co']);
});

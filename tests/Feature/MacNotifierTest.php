<?php

use App\Support\MacNotifier;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    $this->tmp = sys_get_temp_dir().'/bb-macnotifier-'.uniqid();
    $this->bundled = "{$this->tmp}/bundle/".MacNotifier::APP;
    mkdir("{$this->bundled}/Contents/MacOS", 0755, true);
    file_put_contents("{$this->bundled}/Contents/MacOS/bb-notifier", 'v1');
    file_put_contents("{$this->bundled}/Contents/Info.plist", '<plist/>');
    $this->installed = "{$this->tmp}/support/".MacNotifier::APP;
    Process::fake();
});

it('installs the app once, registers it and posts through LaunchServices', function () {
    $notifier = new MacNotifier($this->bundled, "{$this->tmp}/support");

    expect($notifier->send('Pipeline #42', 'Finished'))->toBeTrue()
        ->and(file_get_contents("{$this->installed}/Contents/MacOS/bb-notifier"))->toBe('v1')
        ->and(fileperms("{$this->installed}/Contents/MacOS/bb-notifier") & 0777)->toBe(0755);

    Process::assertRan(fn ($p) => str_ends_with($p->command[0], '/lsregister') && $p->command[2] === $this->installed);
    Process::assertRan(fn ($p) => $p->command === [
        'open', '-g', '-a', $this->installed, '--args', '-title', 'Pipeline #42', '-message', 'Finished',
        '-status', "{$this->tmp}/support/notifier-status",
    ]);

    // Already up to date: no second copy or registration.
    $notifier->send('again', 'x');
    Process::assertRanTimes(fn ($p) => str_ends_with($p->command[0], '/lsregister'), 1);
})->skip(PHP_OS_FAMILY !== 'Darwin', 'macOS only');

it('replaces an outdated install after a bb upgrade', function () {
    $notifier = new MacNotifier($this->bundled, "{$this->tmp}/support");
    $notifier->send('t', 'm');
    file_put_contents("{$this->installed}/Contents/stale.txt", 'from the old build');
    file_put_contents("{$this->bundled}/Contents/MacOS/bb-notifier", 'v2');

    $notifier->send('t', 'm');

    expect(file_get_contents("{$this->installed}/Contents/MacOS/bb-notifier"))->toBe('v2')
        ->and(file_exists("{$this->installed}/Contents/stale.txt"))->toBeFalse();
})->skip(PHP_OS_FAMILY !== 'Darwin', 'macOS only');

it('declines when there is no bundled app, so bb falls back to JoliNotif', function () {
    expect((new MacNotifier('/nonexistent', "{$this->tmp}/support"))->send('t', 'm'))->toBeFalse();
    Process::assertNothingRan();
});

it('reports when the app found notifications turned off', function () {
    $notifier = new MacNotifier($this->bundled, "{$this->tmp}/support");
    expect($notifier->wasDenied())->toBeFalse();

    mkdir("{$this->tmp}/support", 0755, true);
    file_put_contents("{$this->tmp}/support/notifier-status", 'denied');

    expect($notifier->wasDenied())->toBeTrue();
});

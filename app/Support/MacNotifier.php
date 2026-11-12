<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/**
 * Native macOS notifications through "bb Notifier.app" (macos/notifier), so
 * they show "bb" and bb's icon on the left like any other app.
 *
 * The app ships inside bb (resources/notifier, built on macOS at release
 * time) and is copied to ~/Library/Application Support/bb on first use:
 * macOS only lets apps that LaunchServices has registered ask for
 * notification permission, and it never registers apps under a temp dir or
 * inside a PHAR.
 */
class MacNotifier
{
    public const APP = 'bb Notifier.app';

    private const LSREGISTER = '/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister';

    public function __construct(
        private string $bundled,
        private string $installDir,
    ) {}

    public static function default(): self
    {
        return new self(
            base_path('resources/notifier/'.self::APP),
            CredentialStore::userHome().'/Library/Application Support/bb',
        );
    }

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_file($this->bundled.'/Contents/MacOS/bb-notifier');
    }

    /**
     * Post a notification. False means "use another notifier" (not macOS, no
     * bundled app, or it could not be launched).
     */
    public function send(string $title, string $message): bool
    {
        if (! $this->available() || ($app = $this->install()) === null) {
            return false;
        }

        // -g: don't steal focus. Not waiting (-W): the first run blocks on
        // the permission prompt, which must not stall the bb command.
        return Process::run(['open', '-g', '-a', $app, '--args', '-title', $title, '-message', $message])->successful();
    }

    /**
     * Copy the bundled app into place when it is missing or out of date.
     */
    private function install(): ?string
    {
        $target = $this->installDir.'/'.self::APP;
        $binary = '/Contents/MacOS/bb-notifier';

        if (is_file($target.$binary) && md5_file($target.$binary) === md5_file($this->bundled.$binary)) {
            return $target;
        }

        // Start clean so files dropped from a newer build don't linger.
        if (is_dir($target) && ! File::deleteDirectory($target)) {
            return null;
        }

        if (! $this->copyTree($this->bundled, $target)) {
            return null;
        }

        // Files copied out of a PHAR lose their mode bits.
        chmod($target.$binary, 0755);
        Process::run([self::LSREGISTER, '-f', $target]);

        return $target;
    }

    private function copyTree(string $from, string $to): bool
    {
        if (! is_dir($to) && ! mkdir($to, 0755, true) && ! is_dir($to)) {
            return false;
        }

        foreach (scandir($from) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $ok = is_dir("{$from}/{$entry}")
                ? $this->copyTree("{$from}/{$entry}", "{$to}/{$entry}")
                : copy("{$from}/{$entry}", "{$to}/{$entry}");

            if (! $ok) {
                return false;
            }
        }

        return true;
    }
}

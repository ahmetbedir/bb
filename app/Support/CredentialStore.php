<?php

namespace App\Support;

use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Reads and writes bb credentials to a JSON file in the user's home
 * directory (~/.config/bb/config.json by default).
 *
 * Supported auth methods:
 *   - "token": Bearer auth using an Atlassian API token or a
 *              repository/workspace/project access token
 *   - "oauth": Bearer auth using an OAuth access token (`bb auth login`)
 *
 * The old "app_password" method is no longer accepted: Bitbucket retired
 * app passwords in 2026, so such configs count as not configured.
 */
class CredentialStore
{
    private ?array $data = null;

    private bool $unreadable = false;

    /**
     * Changes made since the last save, replayed onto the file's current
     * contents so concurrent bb processes don't erase each other's writes.
     *
     * @var array<int, array{0: 'set'|'forget', 1: string, 2?: mixed}>
     */
    private array $pending = [];

    /** @var resource|null */
    private $lock = null;

    public function __construct(private ?string $path = null)
    {
        $this->path ??= static::defaultPath();
    }

    /**
     * The bb home directory (~/.config/bb), honouring BB_HOME.
     */
    public static function homeDir(): string
    {
        return rtrim(getenv('BB_HOME') ?: static::userHome(), '/').'/.config/bb';
    }

    /**
     * The user's home directory. Never falls back to a shared location like
     * /tmp: another user could pre-create it to read the token or plant a
     * plugin that bb would then run.
     */
    public static function userHome(): string
    {
        $home = getenv('HOME') ?: getenv('USERPROFILE');

        if (! $home && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $home = posix_getpwuid(posix_geteuid())['dir'] ?? null;
        }

        if (! $home) {
            throw new RuntimeException('Cannot find your home directory. Set HOME (or BB_HOME).');
        }

        return rtrim($home, '/');
    }

    public static function defaultPath(): string
    {
        return static::homeDir().'/config.json';
    }

    /**
     * Where user plugins live (~/.config/bb/plugins).
     */
    public static function pluginsPath(): string
    {
        return static::homeDir().'/plugins';
    }

    public function path(): string
    {
        return $this->path;
    }

    public function exists(): bool
    {
        return is_file($this->path);
    }

    /**
     * Whether we have enough information to authenticate a request.
     */
    public function isConfigured(): bool
    {
        return match ($this->get('method')) {
            'token' => filled($this->get('token')),
            'oauth' => filled($this->get('oauth.access_token')),
            default => false,
        };
    }

    /**
     * Whether the stored config still uses a retired app password.
     */
    public function usesAppPassword(): bool
    {
        return $this->get('method') === 'app_password';
    }

    public function all(): array
    {
        if ($this->data === null) {
            $this->data = $this->readFile();
        }

        return $this->data;
    }

    /**
     * Run $callback holding an exclusive lock on the config, with this store
     * re-read from disk first (plus any unsaved changes). Use it for
     * read-check-write sequences such as refreshing an OAuth token; save()
     * inside the callback reuses the lock.
     *
     * @template T
     *
     * @param  callable(static): T  $callback
     * @return T
     */
    public function locked(callable $callback): mixed
    {
        if ($this->lock !== null) {
            return $callback($this);
        }

        $dir = dirname($this->path);

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }

        $handle = fopen($this->path.'.lock', 'c');

        if ($handle === false || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException("Could not lock {$this->path}.");
        }

        $this->lock = $handle;

        try {
            $data = $this->readFile();

            foreach ($this->pending as $change) {
                $change[0] === 'set' ? data_set($data, $change[1], $change[2]) : Arr::forget($data, $change[1]);
            }

            $this->data = $data;

            return $callback($this);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            $this->lock = null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readFile(): array
    {
        $raw = $this->exists() ? (string) file_get_contents($this->path) : '';
        $decoded = json_decode($raw, true);

        // A damaged file must not read as "empty": the next save would
        // silently wipe the stored token.
        $this->unreadable = trim($raw) !== '' && ! is_array($decoded);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Whether the config file exists but is not valid JSON.
     */
    public function isUnreadable(): bool
    {
        $this->all();

        return $this->unreadable;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        // BB_TOKEN / BB_WORKSPACE win over the file but are never merged into
        // all(), so save() can't persist an env-only secret to disk.
        $env = $this->envOverrides();

        if (array_key_exists($key, $env)) {
            return $env[$key];
        }

        return data_get($this->all(), $key, $default);
    }

    /**
     * Whether credentials come from the BB_TOKEN environment variable.
     */
    public function usesEnvToken(): bool
    {
        return filled(getenv('BB_TOKEN') ?: null);
    }

    /**
     * @return array<string, string>
     */
    private function envOverrides(): array
    {
        $env = [];

        if ($this->usesEnvToken()) {
            $env['method'] = 'token';
            $env['token'] = (string) getenv('BB_TOKEN');
        }

        if (filled(getenv('BB_WORKSPACE') ?: null)) {
            $env['workspace'] = (string) getenv('BB_WORKSPACE');
        }

        return $env;
    }

    public function set(string $key, mixed $value): static
    {
        $data = $this->all();
        data_set($data, $key, $value);
        $this->data = $data;
        $this->pending[] = ['set', $key, $value];

        return $this;
    }

    public function forget(string $key): static
    {
        $data = $this->all();
        Arr::forget($data, $key);
        $this->data = $data;
        $this->pending[] = ['forget', $key];

        return $this;
    }

    public function fill(array $values): static
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    public function save(): void
    {
        $this->locked(fn () => $this->write());
    }

    private function write(): void
    {
        $dir = dirname($this->path);

        if ($this->unreadable) {
            throw new RuntimeException("{$this->path} is not valid JSON. Fix or delete it; bb will not overwrite it.");
        }

        // Write a private (0600, from tempnam) temp file and rename it into
        // place: readers never see a half-written file and the token is never
        // world-readable, even for a moment.
        $tmp = tempnam($dir, '.config-');
        $json = json_encode($this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;

        if ($tmp === false || file_put_contents($tmp, $json) === false || ! chmod($tmp, 0600) || ! rename($tmp, $this->path)) {
            if (is_string($tmp)) {
                @unlink($tmp);
            }

            throw new RuntimeException("Could not write {$this->path}.");
        }

        $this->pending = [];
    }
}

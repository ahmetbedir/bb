<?php

namespace App\Support;

use Illuminate\Support\Arr;

/**
 * Reads and writes bb credentials to a JSON file in the user's home
 * directory (~/.config/bb/config.json by default).
 *
 * Supported auth methods:
 *   - "app_password": Basic auth using username + Bitbucket App Password
 *   - "token":        Bearer auth using an Access/API token
 */
class CredentialStore
{
    private ?array $data = null;

    public function __construct(private ?string $path = null)
    {
        $this->path ??= static::defaultPath();
    }

    public static function defaultPath(): string
    {
        $home = getenv('BB_HOME')
            ?: getenv('HOME')
            ?: getenv('USERPROFILE')
            ?: sys_get_temp_dir();

        return rtrim($home, '/').'/.config/bb/config.json';
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
            'app_password' => filled($this->get('username')) && filled($this->get('app_password')),
            'token' => filled($this->get('token')),
            'oauth' => filled($this->get('oauth.access_token')),
            default => false,
        };
    }

    public function all(): array
    {
        if ($this->data === null) {
            $this->data = $this->exists()
                ? (json_decode((string) file_get_contents($this->path), true) ?: [])
                : [];
        }

        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->all(), $key, $default);
    }

    public function set(string $key, mixed $value): static
    {
        $data = $this->all();
        data_set($data, $key, $value);
        $this->data = $data;

        return $this;
    }

    public function forget(string $key): static
    {
        $data = $this->all();
        Arr::forget($data, $key);
        $this->data = $data;

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
        $dir = dirname($this->path);

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        file_put_contents(
            $this->path,
            json_encode($this->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );

        // Credentials are sensitive: keep the file private to the user.
        @chmod($this->path, 0600);
    }
}

<?php

namespace App\Commands;

use App\Support\CredentialStore;
use Symfony\Component\Process\Process;

class PluginInstallCommand extends BitbucketCommand
{
    protected $signature = 'plugin:install
        {source : Git URL, GitHub gist (gist:<id> or a gist URL), or a local .php file / directory}
        {--name= : Install under this name (defaults to the source name)}
        {--y|yes : Skip the confirmation prompt}';

    protected $description = 'Install a plugin into ~/.config/bb/plugins';

    public function handle(): int
    {
        $dir = CredentialStore::pluginsPath();

        $source = $this->argument('source');

        // A plugin is PHP that runs inside every later bb command: never install
        // one without an explicit yes (agents could be talked into it).
        if (! $this->option('yes') && ! $this->confirm("Install plugin from \"{$source}\"? Its code will run on every bb command.", false)) {
            $this->line('Aborted.');

            return self::FAILURE;
        }

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            $this->error("Could not create plugins directory: {$dir}");

            return self::FAILURE;
        }

        if ($gist = $this->gistTarget($source)) {
            return $this->installFromGit($dir, $gist['url'], $gist['name']);
        }

        return $this->isGitUrl($source)
            ? $this->installFromGit($dir, $source)
            : $this->installFromPath($dir, $source);
    }

    /**
     * Recognise a GitHub gist source ("gist:<id>" or a gist.github.com URL)
     * and return its git clone URL + a friendly name. A gist is a git repo.
     *
     * @return array{url: string, name: string}|null
     */
    private function gistTarget(string $source): ?array
    {
        if (str_starts_with($source, 'gist:')) {
            $id = substr($source, 5);
        } elseif (str_contains($source, 'gist.github.com')) {
            $path = parse_url($source, PHP_URL_PATH) ?: '';
            $id = preg_replace('/\.git$/', '', basename($path));
        } else {
            return null;
        }

        $id = trim($id);

        if (! preg_match('/^[A-Za-z0-9]+$/', $id)) {
            return null;
        }

        return [
            'url' => "https://gist.github.com/{$id}.git",
            'name' => 'gist-'.substr($id, 0, 12),
        ];
    }

    private function isGitUrl(string $source): bool
    {
        return str_contains($source, '://')
            || str_starts_with($source, 'git@')
            || str_ends_with($source, '.git');
    }

    private function installFromGit(string $dir, string $source, ?string $name = null): int
    {
        $name = $this->option('name') ?: $name ?: preg_replace('/\.git$/', '', basename($source));

        if (! $this->validName($name)) {
            return self::FAILURE;
        }

        $target = "{$dir}/{$name}";

        if (is_dir($target)) {
            $this->error("A plugin named \"{$name}\" is already installed. Remove it first: bb plugin:remove {$name}");

            return self::FAILURE;
        }

        $this->line("Cloning {$source} into {$name}...");

        // "--" so a source like "--upload-pack=..." can't be read as an option.
        if (! $this->git(['clone', '--depth', '1', '--', $source, $target])) {
            return self::FAILURE;
        }

        return $this->done($name);
    }

    private function installFromPath(string $dir, string $source): int
    {
        if (! file_exists($source)) {
            $this->error("No such file or directory: {$source}");

            return self::FAILURE;
        }

        if (is_file($source)) {
            $name = $this->option('name') ?: basename($source);

            if (! $this->validName($name)) {
                return self::FAILURE;
            }

            if (! copy($source, "{$dir}/{$name}")) {
                $this->error("Could not copy {$source}.");

                return self::FAILURE;
            }

            return $this->done($name);
        }

        // Directory — copy it under the plugins folder.
        $name = $this->option('name') ?: basename(rtrim($source, '/'));

        if (! $this->validName($name)) {
            return self::FAILURE;
        }

        $target = "{$dir}/{$name}";

        if (is_dir($target)) {
            $this->error("A plugin named \"{$name}\" is already installed. Remove it first: bb plugin:remove {$name}");

            return self::FAILURE;
        }

        $process = new Process(['cp', '-R', rtrim($source, '/'), $target]);
        $process->run();

        if (! $process->isSuccessful()) {
            $this->error('Failed to copy the plugin: '.trim($process->getErrorOutput()));

            return self::FAILURE;
        }

        return $this->done($name);
    }

    /**
     * Plugin names become paths under the plugins directory: no separators,
     * no "..", no hidden files.
     */
    private function validName(string $name): bool
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $name) && ! str_contains($name, '..')) {
            return true;
        }

        $this->error("Invalid plugin name \"{$name}\". Use letters, digits, \".\", \"_\" or \"-\" (pass --name).");

        return false;
    }

    private function done(string $name): int
    {
        $this->info("Installed plugin \"{$name}\".");
        $this->line(' Run <comment>bb plugin:list</comment> to see its commands.');

        return self::SUCCESS;
    }
}

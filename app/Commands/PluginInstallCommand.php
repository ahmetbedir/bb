<?php

namespace App\Commands;

use App\Support\CredentialStore;
use Symfony\Component\Process\Process;

class PluginInstallCommand extends BitbucketCommand
{
    protected $signature = 'plugin:install
        {source : Git URL, or a local .php file / directory}
        {--name= : Install under this name (defaults to the source name)}';

    protected $description = 'Install a plugin into ~/.config/bb/plugins';

    public function handle(): int
    {
        $dir = CredentialStore::pluginsPath();

        if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create plugins directory: {$dir}");

            return self::FAILURE;
        }

        $source = $this->argument('source');

        return $this->isGitUrl($source)
            ? $this->installFromGit($dir, $source)
            : $this->installFromPath($dir, $source);
    }

    private function isGitUrl(string $source): bool
    {
        return str_contains($source, '://')
            || str_starts_with($source, 'git@')
            || str_ends_with($source, '.git');
    }

    private function installFromGit(string $dir, string $source): int
    {
        $name = $this->option('name') ?: preg_replace('/\.git$/', '', basename($source));
        $target = "{$dir}/{$name}";

        if (is_dir($target)) {
            $this->error("A plugin named \"{$name}\" is already installed. Remove it first: bb plugin:remove {$name}");

            return self::FAILURE;
        }

        $this->line("Cloning {$source} into {$name}...");

        if (! $this->git(['clone', '--depth', '1', $source, $target])) {
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
            copy($source, "{$dir}/{$name}");

            return $this->done($name);
        }

        // Directory — copy it under the plugins folder.
        $name = $this->option('name') ?: basename(rtrim($source, '/'));
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

    private function done(string $name): int
    {
        $this->info("Installed plugin \"{$name}\".");
        $this->line(' Run <comment>bb plugin:list</comment> to see its commands.');

        return self::SUCCESS;
    }
}

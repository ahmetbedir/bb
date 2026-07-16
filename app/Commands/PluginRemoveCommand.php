<?php

namespace App\Commands;

use App\Support\CredentialStore;
use ReflectionClass;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class PluginRemoveCommand extends BitbucketCommand
{
    protected $signature = 'plugin:remove
        {name? : Plugin command (e.g. branch:new) or file/dir name}
        {--yes : Skip the confirmation prompt}';

    protected $description = 'Remove an installed plugin from ~/.config/bb/plugins';

    public function handle(): int
    {
        $dir = CredentialStore::pluginsPath();
        $entries = $this->installedEntries($dir);

        if ($entries === []) {
            $this->info('No plugins installed.');

            return self::SUCCESS;
        }

        $byCommand = $this->pluginCommandEntries($dir); // "branch:new" => "Foo.php"

        $entry = $this->resolveEntry($this->argument('name'), $entries, $byCommand);

        if ($entry === null) {
            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm("Remove plugin \"{$entry}\"?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $target = "{$dir}/{$entry}";

        // Guard: never delete outside the plugins directory.
        if (! str_starts_with((string) realpath($target), (string) realpath($dir))) {
            $this->error('Refusing to delete a path outside the plugins directory.');

            return self::FAILURE;
        }

        if (is_dir($target)) {
            (new Process(['rm', '-rf', $target]))->run();
        } else {
            @unlink($target);
        }

        $this->info("Removed plugin \"{$entry}\".");

        return self::SUCCESS;
    }

    /**
     * Resolve the argument (a command name, file, or dir) to a top-level entry
     * in the plugins directory, or prompt for one.
     *
     * @param  array<int, string>  $entries
     * @param  array<string, string>  $byCommand
     */
    private function resolveEntry(?string $name, array $entries, array $byCommand): ?string
    {
        if (blank($name)) {
            // Show a friendly list labelled with the command each entry provides.
            $options = [];
            foreach ($entries as $entry) {
                $commands = array_keys(array_filter($byCommand, fn ($e) => $e === $entry));
                $options[$entry] = $commands === [] ? $entry : $entry.'  ('.implode(', ', $commands).')';
            }

            return select('Which plugin to remove?', $options);
        }

        $entry = match (true) {
            in_array($name, $entries, true) => $name,
            in_array("{$name}.php", $entries, true) => "{$name}.php",
            isset($byCommand[$name]) => $byCommand[$name],
            default => null,
        };

        if ($entry === null) {
            $this->error("No installed plugin matches \"{$name}\". See: bb plugin:list");
        }

        return $entry;
    }

    /**
     * Map each loaded plugin command name to its top-level plugins entry
     * (a file like "Foo.php" or a subdirectory like "gist-abc").
     *
     * @return array<string, string>
     */
    private function pluginCommandEntries(string $dir): array
    {
        $realDir = realpath($dir);

        if ($realDir === false) {
            return [];
        }

        $map = [];
        foreach ($this->getApplication()->all() as $name => $command) {
            $file = (new ReflectionClass($command))->getFileName();

            if ($file && str_starts_with($file, $realDir)) {
                $relative = ltrim(substr($file, strlen($realDir)), '/');
                $map[$name] = explode('/', $relative)[0];
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function installedEntries(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        return array_values(array_filter(
            scandir($dir) ?: [],
            fn (string $entry) => $entry !== '.' && $entry !== '..'
                && (is_dir("{$dir}/{$entry}") || str_ends_with($entry, '.php')),
        ));
    }
}

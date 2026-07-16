<?php

namespace App\Commands;

use App\Support\CredentialStore;
use Symfony\Component\Process\Process;

use function Laravel\Prompts\select;

class PluginRemoveCommand extends BitbucketCommand
{
    protected $signature = 'plugin:remove
        {name? : Plugin file or directory name (as shown by plugin:list)}
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

        $name = $this->argument('name') ?: select('Which plugin to remove?', $entries);

        // Accept the entry with or without a trailing ".php".
        $target = match (true) {
            in_array($name, $entries, true) => "{$dir}/{$name}",
            in_array("{$name}.php", $entries, true) => "{$dir}/{$name}.php",
            default => null,
        };

        if ($target === null || ! file_exists($target)) {
            $this->error("No installed plugin named \"{$name}\".");

            return self::FAILURE;
        }

        if (! $this->option('yes') && ! $this->confirm("Remove plugin \"{$name}\"?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

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

        $this->info("Removed plugin \"{$name}\".");

        return self::SUCCESS;
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

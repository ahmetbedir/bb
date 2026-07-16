<?php

namespace App\Commands;

use App\Support\CredentialStore;
use ReflectionClass;

class PluginListCommand extends BitbucketCommand
{
    protected $signature = 'plugin:list';

    protected $description = 'List installed plugins (PHP commands in ~/.config/bb/plugins)';

    public function handle(): int
    {
        $dir = realpath(CredentialStore::pluginsPath());

        if ($dir === false) {
            $this->info('No plugins directory yet. Create '.CredentialStore::pluginsPath().' and drop PHP command classes in it.');

            return self::SUCCESS;
        }

        $rows = [];
        foreach ($this->getApplication()->all() as $name => $command) {
            $file = (new ReflectionClass($command))->getFileName();

            if ($file && str_starts_with($file, $dir)) {
                $rows[] = [$name, $command->getDescription(), basename($file)];
            }
        }

        if ($rows === []) {
            $this->info("No plugins loaded from {$dir}.");

            return self::SUCCESS;
        }

        $this->table(['Command', 'Description', 'File'], $rows);
        $this->line(' '.count($rows).' plugin command(s) from '.$dir);

        return self::SUCCESS;
    }
}

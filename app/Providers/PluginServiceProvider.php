<?php

namespace App\Providers;

use App\Commands\BitbucketCommand;
use App\Support\CredentialStore;
use Illuminate\Console\Application;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Discovers user plugins — PHP command classes dropped into
 * ~/.config/bb/plugins — and registers them so they run with the same
 * connector, helpers and Prompts/Termwind styling as native commands.
 *
 * A plugin is any class extending App\Commands\BitbucketCommand. Because that
 * base class lives inside the (sealed) PHAR, plugin files are required from the
 * real filesystem and simply extend it — that works in both source and PHAR.
 */
class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $dir = CredentialStore::pluginsPath();

        if (! is_dir($dir)) {
            return;
        }

        $before = get_declared_classes();

        // Plugins are plain PHP command classes — a flat file or a file inside
        // a one-level subdirectory:
        //   plugins/Foo.php   plugins/my-plugin/Foo.php
        foreach ([glob($dir.'/*.php'), glob($dir.'/*/*.php')] as $group) {
            foreach ($group ?: [] as $file) {
                try {
                    require_once $file;
                } catch (\Throwable $e) {
                    if (getenv('BB_DEBUG')) {
                        fwrite(STDERR, "plugin load failed [{$file}]: ".$e->getMessage().PHP_EOL);
                    }
                }
            }
        }

        $commands = array_values(array_filter(
            array_diff(get_declared_classes(), $before),
            fn (string $class) => is_subclass_of($class, BitbucketCommand::class)
                && ! (new ReflectionClass($class))->isAbstract(),
        ));

        if ($commands === []) {
            return;
        }

        // Register under a "plugin:" namespace so plugin commands are grouped
        // together and can never shadow a native command.
        Application::starting(function ($artisan) use ($commands) {
            foreach ($commands as $class) {
                $command = $this->app->make($class);
                $command->setName('plugin:'.$command->getName());
                $artisan->add($command);
            }
        });
    }
}

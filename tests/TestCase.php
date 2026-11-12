<?php

namespace Tests;

use App\Support\MacNotifier;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use LaravelZero\Framework\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Pin the "testing" environment here rather than through APP_ENV, so no
     * shell variable can switch a real bb run into test mode (which turns off
     * agent detection and the skill checks).
     */
    public function createApplication()
    {
        $app = require Application::inferBasePath().'/bootstrap/app.php';

        // Runs after environment detection, before service providers boot.
        $app->booting(function (Application $app) {
            $app->instance('env', 'testing');

            // Never install the real macOS notifier app or post real
            // notifications from tests; MacNotifierTest covers it with temp dirs.
            $app->instance(MacNotifier::class, new MacNotifier('/nonexistent', sys_get_temp_dir()));
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}

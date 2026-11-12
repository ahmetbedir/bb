<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;

it('ignores --env on the command line', function (array $argv) {
    $saved = $_SERVER['argv'];
    $_SERVER['argv'] = ['bb', ...$argv, 'pr:list'];

    try {
        $app = require Application::inferBasePath().'/bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        expect($app->environment())->toBe(config('app.env'))
            ->and($app->runningUnitTests())->toBeFalse();
    } finally {
        $_SERVER['argv'] = $saved;
        HandleExceptions::flushState($this); // drop the handlers this app registered
    }
})->with([
    'equals form' => [['--env=testing']],
    'separate value' => [['--env', 'testing']],
]);

<?php

use App\Kernel;
use LaravelZero\Framework\Application;

$app = Application::configure(basePath: dirname(__DIR__))
    ->create();

$app->singleton(Illuminate\Contracts\Console\Kernel::class, Kernel::class);

return $app;

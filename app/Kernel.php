<?php

namespace App;

use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use LaravelZero\Framework\Kernel as BaseKernel;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand;
use Saloon\Laravel\Console\Commands\ListCommand;
use Saloon\Laravel\Console\Commands\MakeAuthenticator;
use Saloon\Laravel\Console\Commands\MakeConnector;
use Saloon\Laravel\Console\Commands\MakePlugin;
use Saloon\Laravel\Console\Commands\MakeRequest;
use Saloon\Laravel\Console\Commands\MakeResponse;

class Kernel extends BaseKernel
{
    protected $developmentOnlyCommands = [
        TestCommand::class,

        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,

        MakeAuthenticator::class,
        MakeConnector::class,
        ListCommand::class,
        MakePlugin::class,
        MakeRequest::class,
        MakeResponse::class,
    ];
}

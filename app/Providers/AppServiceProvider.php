<?php

namespace App\Providers;

use App\Support\CredentialStore;
use Illuminate\Support\ServiceProvider;
use Saloon\Laravel\SaloonServiceProvider;

use function Termwind\style;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        style('output')->apply('mt-1 px-2 text-white font-bold');
        style('error')->apply('bg-red-600');
        style('warning')->apply('bg-yellow-300');
        style('success')->apply('bg-green');
        style('info')->apply('bg-blue-600');
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->register(SaloonServiceProvider::class);

        $this->app->singleton(CredentialStore::class, fn () => new CredentialStore);
    }
}

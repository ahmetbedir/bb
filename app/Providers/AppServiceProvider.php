<?php

namespace App\Providers;

use App\Support\CredentialStore;
use Illuminate\Support\ServiceProvider;
use Saloon\Laravel\SaloonServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
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

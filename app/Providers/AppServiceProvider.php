<?php

namespace App\Providers;

use App\Support\CredentialStore;
use App\Support\SkillUpdater;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Laravel\AgentDetector\AgentDetector;
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

        $this->configureForAgents();

        // bb ignores --env (see register()), so don't advertise it either.
        Artisan::starting(function (Artisan $artisan): void {
            $definition = $artisan->getDefinition();
            $definition->setOptions(array_filter($definition->getOptions(), fn ($option) => $option->getName() !== 'env'));
        });

        if (! $this->app->runningUnitTests()) {
            Event::listen(CommandStarting::class, function (CommandStarting $event): void {
                // Best effort: skill housekeeping must never break the user's command.
                try {
                    $updater = $this->app->make(SkillUpdater::class);
                    $quiet = $event->input->hasParameterOption(['--json', '--jq'], true);
                    $updater->refreshIfOutdated((string) $event->command, $event->output, $quiet);
                    $updater->hintIfMissing((string) $event->command, $event->output, static::agentDetected(), $quiet);
                } catch (\Throwable) {
                }
            });
        }
    }

    /**
     * Some agents (Cursor, Windsurf, Gemini CLI...) run commands inside a
     * pseudo-terminal, so bb would think a human is watching: boxed tables,
     * colours, and prompts that block forever. When an agent is detected, act
     * as if output were piped and stdin closed. BB_AGENT=0 opts out.
     */
    private function configureForAgents(): void
    {
        if ($this->app->runningUnitTests() || ! static::agentDetected()) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $event->input->setInteractive(false);
            $event->output->setDecorated(false);
        });
    }

    public static function agentDetected(): bool
    {
        $override = getenv('BB_AGENT');

        if ($override !== false && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return AgentDetector::detect()->isAgent;
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Laravel lets `--env=...` on the command line switch the environment.
        // bb has no use for that, and "testing" would turn off its agent
        // safeguards, so the configured environment always wins.
        $this->app->instance('env', $this->app['config']->get('app.env', 'production'));

        $this->app->register(SaloonServiceProvider::class);

        $this->app->singleton(CredentialStore::class, fn () => new CredentialStore);
    }
}

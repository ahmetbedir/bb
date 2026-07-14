<?php

namespace App\Commands;

class AliasCommand extends BitbucketCommand
{
    protected $signature = 'alias
        {name? : Alias name}
        {expansion?* : What the alias expands to, e.g. pr:checkout}
        {--delete : Delete the alias}';

    protected $description = 'Manage command aliases (e.g. "bb alias co pr:checkout")';

    public function handle(): int
    {
        $store = $this->credentials();
        $name = $this->argument('name');

        if (blank($name)) {
            $aliases = $store->get('aliases', []);

            if ($aliases === []) {
                $this->info('No aliases defined. Add one with: bb alias co pr:checkout');

                return self::SUCCESS;
            }

            $this->table(
                ['Alias', 'Expands to'],
                array_map(fn ($k, $v) => [$k, $v], array_keys($aliases), array_values($aliases)),
            );

            return self::SUCCESS;
        }

        if ($this->option('delete')) {
            $store->forget("aliases.{$name}")->save();
            $this->info("Deleted alias \"{$name}\".");

            return self::SUCCESS;
        }

        $expansion = implode(' ', $this->argument('expansion'));

        if (blank($expansion)) {
            $current = $store->get("aliases.{$name}");
            $this->line($current ?? "(no alias \"{$name}\")");

            return self::SUCCESS;
        }

        $store->set("aliases.{$name}", $expansion)->save();
        $this->info("Aliased \"{$name}\" → {$expansion}");

        return self::SUCCESS;
    }
}

<?php

namespace App\Commands;

class ConfigCommand extends BitbucketCommand
{
    protected $signature = 'config
        {key? : Setting key (e.g. pager)}
        {value? : New value; omit to read the current value}
        {--unset : Remove the setting}';

    protected $description = 'Get or set bb preferences (e.g. pager)';

    /**
     * Known settings and their descriptions (shown when listing).
     */
    private const KNOWN = [
        'pager' => 'auto|off — page long output through $PAGER/less',
    ];

    public function handle(): int
    {
        $store = $this->credentials();
        $key = $this->argument('key');

        if (blank($key)) {
            $settings = $store->get('settings', []);
            $rows = [];
            foreach (self::KNOWN as $name => $desc) {
                $rows[] = [$name, $settings[$name] ?? '(unset)', $desc];
            }
            foreach ($settings as $name => $value) {
                if (! isset(self::KNOWN[$name])) {
                    $rows[] = [$name, is_scalar($value) ? (string) $value : json_encode($value), ''];
                }
            }
            $this->table(['Key', 'Value', 'Description'], $rows);

            return self::SUCCESS;
        }

        if ($this->option('unset')) {
            $store->forget("settings.{$key}")->save();
            $this->info("Unset \"{$key}\".");

            return self::SUCCESS;
        }

        if (blank($value = $this->argument('value'))) {
            $current = $store->get("settings.{$key}");
            $this->line($current === null ? '(unset)' : (string) $current);

            return self::SUCCESS;
        }

        $store->set("settings.{$key}", $value)->save();
        $this->info("Set {$key} = {$value}");

        return self::SUCCESS;
    }
}

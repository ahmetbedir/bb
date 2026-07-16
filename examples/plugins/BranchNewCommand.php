<?php

/**
 * Example bb plugin — a standardized branch creator.
 *
 * Install:
 *   cp examples/plugins/BranchNewCommand.php ~/.config/bb/plugins/
 *
 * Configure (optional — these are the defaults):
 *   bb config branch_types  "feature,bugfix,hotfix,chore"
 *   bb config branch_format "{type}/{ticket}-{slug}"
 *
 * Use (plugin commands are auto-namespaced under "plugin:"):
 *   bb plugin:branch:new
 *
 * A plugin is just a class extending App\Commands\BitbucketCommand, so it gets
 * the exact same Prompts/Termwind styling and helpers as native commands.
 * Declare its signature without the prefix (e.g. "branch:new"); bb registers
 * it as "plugin:branch:new".
 */

use App\Commands\BitbucketCommand;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class BranchNewCommand extends BitbucketCommand
{
    protected $signature = 'branch:new
        {--from= : Branch/commit to base the new branch on}
        {--push : Also push the new branch to origin}';

    protected $description = 'Create a standardized branch (plugin)';

    public function handle(): int
    {
        $types = $this->configList('branch_types', ['feature', 'bugfix', 'hotfix', 'chore']);
        $format = (string) ($this->credentials()->get('settings.branch_format') ?: '{type}/{ticket}-{slug}');

        $type = select('Type', $types);
        $ticket = text('Ticket', placeholder: 'NE-123');
        $description = text('Description', required: true);

        $name = $this->applyFormat($format, [
            'type' => $type,
            'ticket' => $ticket,
            'slug' => $this->slugify($description),
            'user' => (string) $this->credentials()->get('username'),
            'date' => date('Y-m-d'),
        ]);

        $this->line('New branch: '.$this->badge($type).' <options=bold>'.$name.'</>');

        if (! $this->confirm("Create branch \"{$name}\"?", true)) {
            $this->line('Aborted.');

            return self::SUCCESS;
        }

        $args = ['checkout', '-b', $name];
        if (filled($from = $this->option('from'))) {
            $args[] = $from;
        }

        if (! $this->git($args)) {
            return self::FAILURE;
        }

        if ($this->option('push')) {
            $this->git(['push', '-u', 'origin', $name]);
        }

        $this->info("Created branch \"{$name}\".");

        return self::SUCCESS;
    }

    /**
     * @return array<int, string>
     */
    private function configList(string $key, array $default): array
    {
        $raw = (string) $this->credentials()->get("settings.{$key}");
        $values = array_values(array_filter(preg_split('/[\s,]+/', $raw) ?: []));

        return $values ?: $default;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function applyFormat(string $format, array $values): string
    {
        foreach ($values as $key => $value) {
            $format = str_replace('{'.$key.'}', $value, $format);
        }

        // Drop any leftover placeholders and tidy separators left by empties.
        $format = preg_replace('/\{[a-z]+\}/', '', $format) ?? $format;
        $format = preg_replace('#-{2,}#', '-', $format) ?? $format;

        return trim(str_replace('/-', '/', $format), '-/');
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-');
    }
}

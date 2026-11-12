<?php

namespace App\Support;

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Keeps a globally installed bb skill in step with the bb binary. The skill
 * is pinned to the release tag it was installed from, so after `brew upgrade`
 * the first bb command re-runs `skill:install` once for the new version.
 */
class SkillUpdater
{
    public function __construct(private CredentialStore $store) {}

    /**
     * One-time stderr tip, shown when an agent runs bb without the skill.
     * Installing is left to the user: it writes into agent directories.
     */
    public function hintIfMissing(string $command, OutputInterface $output, bool $agent, bool $quiet = false): void
    {
        // In --json mode stderr carries JSON errors; keep the tip for a later run.
        if ($quiet
            || ! $agent
            || str_starts_with($command, 'skill:')
            || $this->store->get('skill.hinted')
            || filled($this->store->get('skill.version'))) {
            return;
        }

        if (! $this->installedOnDisk()) {
            $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $stderr->writeln('Tip: the bb agent skill is not installed. It teaches agents bb\'s --json/--yes conventions; '
                .'suggest the user run "bb skill:install -g". (Shown once.)', OutputInterface::OUTPUT_RAW);
        }

        $this->store->set('skill.hinted', true)->save();
    }

    /**
     * Whether the skill already exists where agents look for it (e.g. it was
     * added with `npx skills add` or copied by hand).
     */
    private function installedOnDisk(): bool
    {
        $home = CredentialStore::userHome();

        foreach ([$home, getcwd()] as $root) {
            foreach (['.claude', '.agents', '.cursor', '.codex'] as $dir) {
                if (is_file("{$root}/{$dir}/skills/bb/SKILL.md")) {
                    return true;
                }
            }
        }

        return false;
    }

    public function refreshIfOutdated(string $command, OutputInterface $output, bool $quiet = false): void
    {
        $installed = $this->store->get('skill.version');
        $current = (string) config('app.version');

        if (blank($installed)
            || $installed === $current
            || str_starts_with($command, 'skill:')
            || ! preg_match('/^v\d+\.\d+\.\d+$/', $current) // dev builds never auto-update
            || $this->store->get('settings.skill_auto_update') === 'off') {
            return;
        }

        $stderr = $quiet ? new BufferedOutput : ($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output);
        $stderr->writeln("Updating the bb agent skill {$installed} → {$current}...", OutputInterface::OUTPUT_RAW);

        // Record the attempt first: a crash or Ctrl-C mid-update must not make
        // every later command try again.
        $this->store->set('skill.version', $current)->save();

        $code = app(Kernel::class)->call('skill:install', array_filter([
            '--global' => true,
            '--agent' => $this->store->get('skill.agents', []),
            '--copy' => (bool) $this->store->get('skill.copy', false),
            '--no-interaction' => true,
        ]), new BufferedOutput);

        if ($code !== 0) {
            $stderr->writeln('Could not update the bb agent skill. Run "bb skill:install -g" to retry.', OutputInterface::OUTPUT_RAW);
        }
    }
}

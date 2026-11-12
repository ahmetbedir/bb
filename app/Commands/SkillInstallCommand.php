<?php

namespace App\Commands;

use App\Support\CredentialStore;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Installs the bb agent skill through the skills.sh CLI (`npx skills add`),
 * so it lands in every supported agent (Claude Code, Cursor, Codex...) and
 * stays updatable with `npx skills update`. Without Node it falls back to
 * copying the bundled SKILL.md into Claude Code's skills directory.
 */
class SkillInstallCommand extends BitbucketCommand
{
    public const REPO = 'ahmetbedir/bb';

    // Pinned so an upgrade of the npm package never runs unreviewed code.
    public const SKILLS_CLI = 'skills@1.8.1';

    protected $signature = 'skill:install
        {--g|global : Install for every project (user directory) instead of the current one}
        {--a|agent=* : Target agent(s), e.g. claude-code, cursor, codex (default: let skills.sh ask / detect)}
        {--ref= : Git tag or commit to install from (default: this bb version\'s release tag)}
        {--copy : Copy files instead of symlinking them into agent directories}';

    protected $description = 'Install the bb skill for AI agents (via skills.sh)';

    public function handle(): int
    {
        $npx = (new ExecutableFinder)->find('npx');

        if ($npx === null) {
            return $this->installWithoutNode();
        }

        $command = [$npx, '--yes', self::SKILLS_CLI, 'add', $this->source(), '--skill', 'bb'];

        if ($this->option('global')) {
            $command[] = '--global';
        }

        foreach ($this->option('agent') as $agent) {
            array_push($command, '--agent', $agent);
        }

        if ($this->option('copy')) {
            $command[] = '--copy';
        }

        // skills.sh prompts for agents and confirmation; nobody can answer here.
        if (! $this->canPrompt()) {
            $command[] = '--yes';
        }

        // Bounded: this also runs unattended after upgrades and must not hang bb.
        $process = Process::timeout(180);

        if ($this->canPrompt() && stream_isatty(STDOUT)) {
            $process = $process->tty();
        }

        try {
            $result = $process->run($command, function (string $type, string $output): void {
                $type === 'err' ? $this->stderr(rtrim($output, "\n")) : $this->output->write($output);
            });
        } catch (ProcessTimedOutException) {
            $this->error('The skills CLI timed out. Check your network and retry.');

            return self::FAILURE;
        }

        if (! $result->successful()) {
            return self::FAILURE;
        }

        $this->rememberInstall();

        return self::SUCCESS;
    }

    /**
     * Record a global, unpinned install so SkillUpdater can follow bb
     * upgrades. Project installs live in a repo (the team updates them) and
     * an explicit --ref is a deliberate pin, so neither is tracked.
     */
    private function rememberInstall(): void
    {
        if (! $this->option('global') || filled($this->option('ref'))) {
            return;
        }

        $this->credentials()->set('skill', [
            'version' => (string) config('app.version'),
            'agents' => $this->option('agent'),
            'copy' => (bool) $this->option('copy'),
        ])->save();

        $this->stderr('bb will refresh this skill after bb upgrades. Turn that off with: bb config skill_auto_update off');
    }

    /**
     * "ahmetbedir/bb@v1.2.0" for a release build, so the skill documents the
     * flags this binary actually has; the default branch for dev builds.
     */
    private function source(): string
    {
        if (filled($ref = $this->option('ref'))) {
            return self::REPO.'@'.$ref;
        }

        $version = (string) config('app.version');

        return preg_match('/^v\d+\.\d+\.\d+$/', $version) ? self::REPO.'@'.$version : self::REPO;
    }

    private function installWithoutNode(): int
    {
        if (filled($this->option('agent')) && $this->option('agent') !== ['claude-code']) {
            $this->error('Installing for other agents needs Node.js (npx). Install Node, or use --agent=claude-code.');

            return self::FAILURE;
        }

        $base = $this->option('global')
            ? CredentialStore::userHome().'/.claude/skills/bb'
            : getcwd().'/.claude/skills/bb';

        if (! is_dir($base) && ! mkdir($base, 0755, true) && ! is_dir($base)) {
            $this->error("Could not create {$base}.");

            return self::FAILURE;
        }

        // Replace a symlink (e.g. left by an earlier skills.sh install) rather
        // than writing through it to whatever it points at.
        if (is_link("{$base}/SKILL.md")) {
            unlink("{$base}/SKILL.md");
        }

        if (! copy(base_path('skills/bb/SKILL.md'), "{$base}/SKILL.md")) {
            $this->error("Could not write {$base}/SKILL.md.");

            return self::FAILURE;
        }

        $this->rememberInstall();

        $this->warn('npx not found: installed for Claude Code only. Install Node.js and re-run for other agents and updates.');
        $this->info("Installed the bb skill to {$base}/SKILL.md");

        return self::SUCCESS;
    }
}

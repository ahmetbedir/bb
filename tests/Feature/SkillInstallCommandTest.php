<?php

use App\Commands\SkillInstallCommand;
use App\Support\CredentialStore;
use App\Support\SkillUpdater;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Output\BufferedOutput;

it('installs through skills.sh, pinned to the given ref and non-interactive', function () {
    Process::fake();

    [$code] = runCommand('skill:install', ['--global' => true, '--agent' => ['claude-code', 'cursor'], '--ref' => 'v1.3.0', '--no-interaction' => true]);

    expect($code)->toBe(0);

    Process::assertRan(function (PendingProcess $process) {
        $args = array_slice($process->command, 1); // drop the resolved npx path

        return $args === ['--yes', SkillInstallCommand::SKILLS_CLI, 'add', 'ahmetbedir/bb@v1.3.0', '--skill', 'bb', '--global', '--agent', 'claude-code', '--agent', 'cursor', '--yes'];
    });
});

it('fails when the skills CLI fails', function () {
    Process::fake(['*' => Process::result(exitCode: 1)]);

    [$code] = runCommand('skill:install', ['--ref' => 'main', '--no-interaction' => true]);

    expect($code)->toBe(1);
});

it('copies the bundled skill for Claude Code when npx is missing', function () {
    Process::fake();
    $path = getenv('PATH');
    $home = getenv('HOME');
    $tmp = sys_get_temp_dir().'/bb-skill-'.uniqid();
    $this->app->instance(CredentialStore::class, new CredentialStore("{$tmp}/config.json"));

    try {
        putenv('PATH=/nonexistent');
        putenv("HOME={$tmp}");

        [$code] = runCommand('skill:install', ['--global' => true]);

        expect($code)->toBe(0)
            ->and(file_get_contents("{$tmp}/.claude/skills/bb/SKILL.md"))->toContain('name: bb');

        Process::assertNothingRan();
    } finally {
        putenv("PATH={$path}");
        putenv("HOME={$home}");
    }
});

describe('auto-update after a bb upgrade', function () {
    beforeEach(function () {
        $this->store = new CredentialStore(sys_get_temp_dir().'/bb-skill-'.uniqid().'/config.json');
        $this->app->instance(CredentialStore::class, $this->store);
        config(['app.version' => 'v1.3.0']);
    });

    it('remembers a global install for later updates', function () {
        Process::fake();

        runCommand('skill:install', ['--global' => true, '--agent' => ['cursor'], '--no-interaction' => true]);

        expect($this->store->get('skill'))->toBe(['version' => 'v1.3.0', 'agents' => ['cursor'], 'copy' => false]);
        Process::assertRan(fn (PendingProcess $p) => in_array('ahmetbedir/bb@v1.3.0', $p->command, true));
    });

    it('does not track project installs or explicit pins', function () {
        Process::fake();

        runCommand('skill:install', ['--no-interaction' => true]);
        runCommand('skill:install', ['--global' => true, '--ref' => 'v1.0.0', '--no-interaction' => true]);

        expect($this->store->get('skill'))->toBeNull();
    });

    it('reinstalls the skill for the new version once bb is upgraded', function () {
        Process::fake();
        $this->store->set('skill', ['version' => 'v1.2.0', 'agents' => ['cursor'], 'copy' => false])->save();
        $output = new BufferedOutput;

        app(SkillUpdater::class)->refreshIfOutdated('pr:list', $output);

        Process::assertRan(fn (PendingProcess $p) => in_array('ahmetbedir/bb@v1.3.0', $p->command, true)
            && in_array('--global', $p->command, true)
            && in_array('cursor', $p->command, true));
        expect($this->store->get('skill.version'))->toBe('v1.3.0')
            ->and($output->fetch())->toContain('v1.2.0 → v1.3.0');
    });

    it('stays quiet when up to date, opted out, or on a dev build', function (array $skill, ?string $setting, string $version) {
        Process::fake();
        config(['app.version' => $version]);
        $this->store->set('skill', $skill)->set('settings.skill_auto_update', $setting)->save();

        app(SkillUpdater::class)->refreshIfOutdated('pr:list', new BufferedOutput);

        Process::assertNothingRan();
    })->with([
        'up to date' => [['version' => 'v1.3.0'], null, 'v1.3.0'],
        'opted out' => [['version' => 'v1.2.0'], 'off', 'v1.3.0'],
        'dev build' => [['version' => 'v1.2.0'], null, 'v1.3.0-4-gabc1234'],
    ]);

    it('marks the version even when the update fails, so it does not retry every command', function () {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $this->store->set('skill', ['version' => 'v1.2.0'])->save();
        $output = new BufferedOutput;

        app(SkillUpdater::class)->refreshIfOutdated('pr:list', $output);

        expect($this->store->get('skill.version'))->toBe('v1.3.0')
            ->and($output->fetch())->toContain('bb skill:install -g');
    });
});

describe('install hint for agents', function () {
    beforeEach(function () {
        $this->tmp = sys_get_temp_dir().'/bb-hint-'.uniqid();
        $this->home = getenv('HOME');
        putenv("HOME={$this->tmp}");
        $this->store = new CredentialStore("{$this->tmp}/.config/bb/config.json");
    });

    afterEach(fn () => putenv("HOME={$this->home}"));

    it('tells an agent about the skill once', function () {
        $updater = new SkillUpdater($this->store);

        $updater->hintIfMissing('pr:list', $first = new BufferedOutput, agent: true);
        $updater->hintIfMissing('pr:list', $second = new BufferedOutput, agent: true);

        expect($first->fetch())->toContain('bb skill:install -g')
            ->and($second->fetch())->toBe('')
            ->and($this->store->get('skill.hinted'))->toBeTrue();
    });

    it('stays silent for humans and when the skill is already installed', function () {
        $updater = new SkillUpdater($this->store);
        $updater->hintIfMissing('pr:list', $human = new BufferedOutput, agent: false);

        mkdir("{$this->tmp}/.agents/skills/bb", 0755, true);
        touch("{$this->tmp}/.agents/skills/bb/SKILL.md");
        $updater->hintIfMissing('pr:list', $installed = new BufferedOutput, agent: true);

        expect($human->fetch())->toBe('')
            ->and($installed->fetch())->toBe('');
    });
});

it('stays quiet in --json mode and keeps the tip for later', function () {
    $tmp = sys_get_temp_dir().'/bb-hint-'.uniqid();
    $store = new CredentialStore("{$tmp}/config.json");
    $home = getenv('HOME');
    putenv("HOME={$tmp}");

    try {
        (new SkillUpdater($store))->hintIfMissing('pr:list', $out = new BufferedOutput, agent: true, quiet: true);

        expect($out->fetch())->toBe('')->and($store->get('skill.hinted'))->toBeNull();
    } finally {
        putenv("HOME={$home}");
    }
});

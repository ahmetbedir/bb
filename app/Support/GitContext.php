<?php

namespace App\Support;

use Symfony\Component\Process\Process;

/**
 * Detects the Bitbucket repository for the current working directory by
 * inspecting its git remotes — this is what lets bb work like `gh`,
 * where you can run commands inside a checkout without naming the repo.
 */
class GitContext
{
    public function __construct(protected ?string $cwd = null) {}

    /**
     * Resolve the current directory's Bitbucket repo as [workspace, slug].
     *
     * @return array{0: string, 1: string}|null
     */
    public function currentRepo(): ?array
    {
        foreach (['origin', 'upstream'] as $remote) {
            if ($url = $this->remoteUrl($remote)) {
                if ($parsed = static::parseRemote($url)) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * The checked-out branch of the current directory, if any.
     */
    public function currentBranch(): ?string
    {
        $result = $this->run(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);

        if ($result === null) {
            return null;
        }

        $branch = trim($result);

        return ($branch === '' || $branch === 'HEAD') ? null : $branch;
    }

    public function remoteUrl(string $remote = 'origin'): ?string
    {
        $result = $this->run(['git', 'remote', 'get-url', $remote]);

        return $result === null ? null : (trim($result) ?: null);
    }

    /**
     * Parse a bitbucket.org git remote URL into [workspace, slug].
     *
     * Handles:
     *   git@bitbucket.org:workspace/repo.git
     *   ssh://git@bitbucket.org/workspace/repo.git
     *   https://user@bitbucket.org/workspace/repo.git
     *
     * @return array{0: string, 1: string}|null
     */
    public static function parseRemote(string $url): ?array
    {
        if (! str_contains($url, 'bitbucket.org')) {
            return null;
        }

        // Normalise the scp-like syntax (git@host:path) to a slash form.
        $path = preg_replace('#^[^@]*@?bitbucket\.org[:/]#', '', $url);

        if ($path === $url || $path === null) {
            return null;
        }

        $path = preg_replace('/\.git$/', '', trim($path, '/'));
        $segments = explode('/', (string) $path);

        if (count($segments) < 2 || $segments[0] === '' || $segments[1] === '') {
            return null;
        }

        return [$segments[0], $segments[1]];
    }

    private function run(array $command): ?string
    {
        try {
            $process = new Process($command, $this->cwd);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return $process->isSuccessful() ? $process->getOutput() : null;
    }
}

<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequestDiff;
use Symfony\Component\Console\Formatter\OutputFormatter;

class PrDiffCommand extends BitbucketCommand
{
    protected $signature = 'pr:diff
        {id : Pull request id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}';

    protected $description = 'Show the diff for a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $response = $this->send(new GetPullRequestDiff($workspace, $slug, $this->argument('id')));

        if ($response === null) {
            return self::FAILURE;
        }

        $this->output->write($this->colorizeDiff($response->body()));

        return self::SUCCESS;
    }

    private function colorizeDiff(string $diff): string
    {
        return collect(explode("\n", $diff))
            ->map(function (string $line) {
                return match (true) {
                    str_starts_with($line, '+++'), str_starts_with($line, '---') => "<options=bold>{$line}</>",
                    str_starts_with($line, '@@') => "<fg=cyan>{$line}</>",
                    str_starts_with($line, '+') => "<fg=green>{$line}</>",
                    str_starts_with($line, '-') => "<fg=red>{$line}</>",
                    str_starts_with($line, 'diff ') => "<options=bold>{$line}</>",
                    default => OutputFormatter::escape($line),
                };
            })
            ->implode("\n")."\n";
    }
}

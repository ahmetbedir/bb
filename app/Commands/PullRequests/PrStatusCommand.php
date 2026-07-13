<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\CurrentUser;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\ListPullRequests;
use Illuminate\Support\Str;

class PrStatusCommand extends BitbucketCommand
{
    protected $signature = 'pr:status
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--limit=100 : Maximum number of open PRs to scan}';

    protected $description = 'Show open pull requests relevant to you in a repository';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $me = $this->send(new CurrentUser);

        if ($me === null) {
            return self::FAILURE;
        }

        $myUuid = $me->json('uuid');

        $prs = $this->collectAll(
            new ListPullRequests($workspace, $slug, 'OPEN'),
            limit: (int) $this->option('limit'),
        );

        if ($prs === null) {
            return self::FAILURE;
        }

        $authored = [];
        $reviewing = [];

        foreach ($prs as $pr) {
            if (($pr['author']['uuid'] ?? null) === $myUuid) {
                $authored[] = $pr;

                continue;
            }

            $isReviewer = collect($pr['participants'] ?? [])
                ->contains(fn ($p) => ($p['role'] ?? null) === 'REVIEWER' && ($p['user']['uuid'] ?? null) === $myUuid);

            if ($isReviewer) {
                $reviewing[] = $pr;
            }
        }

        $this->section('Created by you', $authored);
        $this->section('Requesting your review', $reviewing);

        return self::SUCCESS;
    }

    private function section(string $title, array $prs): void
    {
        $this->newLine();
        $this->line("<options=bold>{$title}</>");

        if ($prs === []) {
            $this->line('  <fg=gray>None</>');

            return;
        }

        foreach ($prs as $pr) {
            $id = $pr['id'] ?? '?';
            $prtitle = Str::limit($pr['title'] ?? '', 60);
            $branch = $pr['source']['branch']['name'] ?? '';
            $this->line("  <fg=green>#{$id}</> {$prtitle} <fg=gray>[{$branch}]</>");
        }
    }
}

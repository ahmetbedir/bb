<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequest;

class PrShowCommand extends BitbucketCommand
{
    protected $signature = 'pr:show
        {id? : Pull request id (defaults to the PR for the current branch)}
        {--D|--with-description : Show the pull request description}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--web : Open the pull request in a browser instead of printing details}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show details for a single pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        if (blank($id = $this->resolvePrId($workspace, $slug, $this->argument('id')))) {
            return self::FAILURE;
        }

        if ($this->option('web')) {
            $this->openInBrowser("https://bitbucket.org/{$workspace}/{$slug}/pull-requests/{$id}");

            return self::SUCCESS;
        }

        $response = $this->send(new GetPullRequest($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $pr = $response->json();

        if ($this->wantsJson()) {
            return $this->outputJson($pr);
        }

        $url = $pr['links']['html']['href'] ?? null;

        $this->detail([
            '#' => $this->link($url, $pr['id'] ?? ''),
            'Title' => e($pr['title'] ?? ''),
            'State' => $this->badgeHtml($pr['state'] ?? ''),
            'Author' => e($pr['author']['display_name'] ?? ''),
            'Source' => e($pr['source']['branch']['name'] ?? ''),
            'Destination' => e($pr['destination']['branch']['name'] ?? ''),
            'Reviewers' => e(collect($pr['reviewers'] ?? [])->pluck('display_name')->implode(', ')),
            'Comments' => $pr['comment_count'] ?? 0,
            'Created' => e(substr((string) ($pr['created_on'] ?? ''), 0, 10)),
            'Updated' => e(substr((string) ($pr['updated_on'] ?? ''), 0, 10)),
            'Web' => $this->link($url, $url ?? ''),
        ]);

        if ($this->option('with-description') && filled($description = $pr['description'] ?? '')) {
            $this->newLine();
            $this->line('<comment>Description:</comment>');
            $this->line($description);
        }

        return self::SUCCESS;
    }
}

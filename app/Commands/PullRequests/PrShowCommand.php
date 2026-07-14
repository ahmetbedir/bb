<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequest;

class PrShowCommand extends BitbucketCommand
{
    protected $signature = 'pr:show
        {id : Pull request id}
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
        $id = $this->argument('id');

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

        $this->table([], [
            ['#', $this->link($pr['links']['html']['href'] ?? null, $pr['id'] ?? '')],
            ['Title', $pr['title'] ?? ''],
            ['State', $pr['state'] ?? ''],
            ['Author', $pr['author']['display_name'] ?? ''],
            ['Source', $pr['source']['branch']['name'] ?? ''],
            ['Destination', $pr['destination']['branch']['name'] ?? ''],
            ['Reviewers', collect($pr['reviewers'] ?? [])->pluck('display_name')->implode(', ')],
            ['Comments', $pr['comment_count'] ?? 0],
            ['Created', $pr['created_on'] ?? ''],
            ['Updated', $pr['updated_on'] ?? ''],
            ['Web', $pr['links']['html']['href'] ?? ''],
        ]);

        if ($this->option('with-description') && filled($description = $pr['description'] ?? '')) {
            $this->newLine();
            $this->line('<comment>Description:</comment>');
            $this->line($description);
        }

        return self::SUCCESS;
    }
}

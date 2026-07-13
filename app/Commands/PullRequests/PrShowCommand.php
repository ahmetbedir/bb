<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\GetPullRequest;

class PrShowCommand extends BitbucketCommand
{
    protected $signature = 'pr:show
        {repo : Repository as "workspace/repo" or just "repo"}
        {id : Pull request id}';

    protected $description = 'Show details for a single pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo($this->argument('repo'))) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $response = $this->send(new GetPullRequest($workspace, $slug, $this->argument('id')));

        if ($response === null) {
            return self::FAILURE;
        }

        $pr = $response->json();

        $this->table([], [
            ['#', $pr['id'] ?? ''],
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

        if (filled($description = $pr['description'] ?? '')) {
            $this->newLine();
            $this->line('<comment>Description:</comment>');
            $this->line($description);
        }

        return self::SUCCESS;
    }
}

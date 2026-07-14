<?php

namespace App\Commands\Issues;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Issues\GetIssue;

class IssueViewCommand extends BitbucketCommand
{
    protected $signature = 'issue:view
        {id : Issue id}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--web : Open the issue in a browser instead of printing details}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show details for a single issue';

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
            $this->openInBrowser("https://bitbucket.org/{$workspace}/{$slug}/issues/{$id}");

            return self::SUCCESS;
        }

        $response = $this->send(new GetIssue($workspace, $slug, $id));

        if ($response === null) {
            return self::FAILURE;
        }

        $issue = $response->json();

        if ($this->wantsJson()) {
            return $this->outputJson($issue);
        }

        $url = $issue['links']['html']['href'] ?? null;

        $this->detail([
            '#' => $this->link($url, $issue['id'] ?? ''),
            'Title' => e($issue['title'] ?? ''),
            'State' => $this->badgeHtml($issue['state'] ?? ''),
            'Kind' => e($issue['kind'] ?? ''),
            'Priority' => e($issue['priority'] ?? ''),
            'Reporter' => e($issue['reporter']['display_name'] ?? ''),
            'Assignee' => e($issue['assignee']['display_name'] ?? '-'),
            'Created' => e(substr((string) ($issue['created_on'] ?? ''), 0, 10)),
            'Updated' => e(substr((string) ($issue['updated_on'] ?? ''), 0, 10)),
            'Web' => $this->link($url, $url ?? ''),
        ]);

        if (filled($content = $issue['content']['raw'] ?? '')) {
            $this->newLine();
            $this->line('<comment>Description:</comment>');
            $this->line($content);
        }

        return self::SUCCESS;
    }
}

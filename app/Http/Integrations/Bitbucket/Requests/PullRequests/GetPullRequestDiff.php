<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Returns the unified diff for a pull request as plain text.
 */
class GetPullRequestDiff extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}/diff";
    }

    protected function defaultHeaders(): array
    {
        // Override the connector's JSON Accept header — the diff is text.
        return ['Accept' => 'text/plain'];
    }
}

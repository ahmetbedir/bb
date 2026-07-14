<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The diffstat for a pull request — the list of changed files with their
 * status and added/removed line counts.
 */
class ListPullRequestFiles extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}/diffstat";
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 100];
    }
}

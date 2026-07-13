<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class DeclinePullRequest extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}/decline";
    }
}

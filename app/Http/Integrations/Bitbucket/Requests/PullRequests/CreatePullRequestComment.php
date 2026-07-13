<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreatePullRequestComment extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
        protected string $content,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}/comments";
    }

    protected function defaultBody(): array
    {
        return [
            'content' => ['raw' => $this->content],
        ];
    }
}

<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class UpdatePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, mixed>  $changes  e.g. ['title' => '...', 'description' => '...']
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
        protected array $changes = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}";
    }

    protected function defaultBody(): array
    {
        return $this->changes;
    }
}

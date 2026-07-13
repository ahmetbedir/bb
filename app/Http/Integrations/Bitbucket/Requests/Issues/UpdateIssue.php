<?php

namespace App\Http\Integrations\Bitbucket\Requests\Issues;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class UpdateIssue extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::PUT;

    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
        protected array $changes = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/issues/{$this->id}";
    }

    protected function defaultBody(): array
    {
        return $this->changes;
    }
}

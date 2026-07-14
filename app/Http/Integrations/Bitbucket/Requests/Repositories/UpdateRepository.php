<?php

namespace App\Http\Integrations\Bitbucket\Requests\Repositories;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class UpdateRepository extends Request implements HasBody
{
    use HasJsonBody;

    /**
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected array $changes = [],
    ) {}

    protected Method $method = Method::PUT;

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}";
    }

    protected function defaultBody(): array
    {
        return $this->changes;
    }
}

<?php

namespace App\Http\Integrations\Bitbucket\Requests;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Returns the unified diff for a "spec" (e.g. "source..destination") as plain
 * text — the engine behind `bb branch:diff`.
 */
class GetDiff extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $spec,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/diff/{$this->spec}";
    }

    protected function defaultHeaders(): array
    {
        return ['Accept' => 'text/plain'];
    }
}

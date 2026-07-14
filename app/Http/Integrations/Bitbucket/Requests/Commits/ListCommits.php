<?php

namespace App\Http\Integrations\Bitbucket\Requests\Commits;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class ListCommits extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected ?string $revision = null,
    ) {}

    public function resolveEndpoint(): string
    {
        $base = "/repositories/{$this->workspace}/{$this->repoSlug}/commits";

        return $this->revision !== null ? "{$base}/{$this->revision}" : $base;
    }

    protected function defaultQuery(): array
    {
        return ['pagelen' => 30];
    }
}

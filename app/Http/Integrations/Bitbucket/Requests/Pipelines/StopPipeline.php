<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class StopPipeline extends Request
{
    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $uuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines/{$this->uuid}/stopPipeline";
    }
}

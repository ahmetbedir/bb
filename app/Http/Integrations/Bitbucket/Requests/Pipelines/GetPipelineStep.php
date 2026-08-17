<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Enums\Method;
use Saloon\Http\Request;

class GetPipelineStep extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $pipeline,
        protected string $stepUuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines/{$this->pipeline}/steps/{$this->stepUuid}";
    }
}

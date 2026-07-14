<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class RunPipeline extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  string|null  $pattern  a custom pipeline definition name to run
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $branch,
        protected ?string $pattern = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines";
    }

    protected function defaultBody(): array
    {
        $target = [
            'type' => 'pipeline_ref_target',
            'ref_type' => 'branch',
            'ref_name' => $this->branch,
        ];

        if ($this->pattern !== null) {
            $target['selector'] = ['type' => 'custom', 'pattern' => $this->pattern];
        }

        return ['target' => $target];
    }
}

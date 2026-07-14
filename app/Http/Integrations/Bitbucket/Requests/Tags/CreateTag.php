<?php

namespace App\Http\Integrations\Bitbucket\Requests\Tags;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateTag extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $name,
        protected string $target,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/refs/tags";
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'target' => ['hash' => $this->target],
        ];
    }
}

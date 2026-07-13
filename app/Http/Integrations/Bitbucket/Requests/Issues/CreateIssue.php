<?php

namespace App\Http\Integrations\Bitbucket\Requests\Issues;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateIssue extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $title,
        protected ?string $content = null,
        protected ?string $kind = null,
        protected ?string $priority = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/issues";
    }

    protected function defaultBody(): array
    {
        $body = ['title' => $this->title];

        if ($this->content !== null) {
            $body['content'] = ['raw' => $this->content];
        }

        if ($this->kind !== null) {
            $body['kind'] = $this->kind;
        }

        if ($this->priority !== null) {
            $body['priority'] = $this->priority;
        }

        return $body;
    }
}

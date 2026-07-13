<?php

namespace App\Http\Integrations\Bitbucket\Requests\Repositories;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateRepository extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected bool $isPrivate = true,
        protected ?string $description = null,
        protected ?string $projectKey = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}";
    }

    protected function defaultBody(): array
    {
        $body = [
            'scm' => 'git',
            'is_private' => $this->isPrivate,
        ];

        if ($this->description !== null) {
            $body['description'] = $this->description;
        }

        if ($this->projectKey !== null) {
            $body['project'] = ['key' => $this->projectKey];
        }

        return $body;
    }
}

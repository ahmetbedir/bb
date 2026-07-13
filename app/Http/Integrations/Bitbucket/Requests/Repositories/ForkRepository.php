<?php

namespace App\Http\Integrations\Bitbucket\Requests\Repositories;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class ForkRepository extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected ?string $destinationWorkspace = null,
        protected ?string $name = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/forks";
    }

    protected function defaultBody(): array
    {
        $body = [];

        if ($this->name !== null) {
            $body['name'] = $this->name;
        }

        if ($this->destinationWorkspace !== null) {
            $body['workspace'] = ['slug' => $this->destinationWorkspace];
        }

        return $body;
    }
}

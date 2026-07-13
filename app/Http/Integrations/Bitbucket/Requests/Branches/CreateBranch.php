<?php

namespace App\Http\Integrations\Bitbucket\Requests\Branches;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreateBranch extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  string  $target  the commit hash (or branch name) the new branch points at
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $name,
        protected string $target,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/refs/branches";
    }

    protected function defaultBody(): array
    {
        return [
            'name' => $this->name,
            'target' => ['hash' => $this->target],
        ];
    }
}

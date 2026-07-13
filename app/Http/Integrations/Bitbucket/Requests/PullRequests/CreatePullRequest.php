<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class CreatePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $title,
        protected string $sourceBranch,
        protected ?string $destinationBranch = null,
        protected ?string $description = null,
        protected bool $closeSourceBranch = false,
        protected array $reviewers = [],
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests";
    }

    protected function defaultBody(): array
    {
        $body = [
            'title' => $this->title,
            'source' => ['branch' => ['name' => $this->sourceBranch]],
            'close_source_branch' => $this->closeSourceBranch,
        ];

        if ($this->destinationBranch !== null) {
            $body['destination'] = ['branch' => ['name' => $this->destinationBranch]];
        }

        if ($this->description !== null) {
            $body['description'] = $this->description;
        }

        if ($this->reviewers !== []) {
            $body['reviewers'] = array_map(
                fn (string $uuid) => ['uuid' => $uuid],
                $this->reviewers,
            );
        }

        return $body;
    }
}

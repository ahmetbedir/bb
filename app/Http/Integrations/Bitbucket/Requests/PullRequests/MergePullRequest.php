<?php

namespace App\Http\Integrations\Bitbucket\Requests\PullRequests;

use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Traits\Body\HasJsonBody;

class MergePullRequest extends Request implements HasBody
{
    use HasJsonBody;

    protected Method $method = Method::POST;

    /**
     * @param  string  $mergeStrategy  merge_commit, squash or fast_forward
     */
    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected int|string $id,
        protected string $mergeStrategy = 'merge_commit',
        protected ?string $message = null,
        protected bool $closeSourceBranch = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pullrequests/{$this->id}/merge";
    }

    protected function defaultBody(): array
    {
        $body = [
            'merge_strategy' => $this->mergeStrategy,
            'close_source_branch' => $this->closeSourceBranch,
        ];

        if ($this->message !== null) {
            $body['message'] = $this->message;
        }

        return $body;
    }
}

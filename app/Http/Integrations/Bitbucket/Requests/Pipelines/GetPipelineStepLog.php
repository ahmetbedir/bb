<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The raw build log of a single step. Bitbucket serves it as a plain byte
 * stream and supports HTTP range requests, which is what lets `--follow`
 * fetch only the bytes appended since the last poll.
 */
class GetPipelineStepLog extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $pipeline,
        protected string $stepUuid,
        protected int $offset = 0,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines/{$this->pipeline}/steps/{$this->stepUuid}/log";
    }

    protected function defaultHeaders(): array
    {
        // The log is not JSON — asking for JSON only confuses the response.
        $headers = ['Accept' => '*/*'];

        if ($this->offset > 0) {
            $headers['Range'] = "bytes={$this->offset}-";
        }

        return $headers;
    }
}

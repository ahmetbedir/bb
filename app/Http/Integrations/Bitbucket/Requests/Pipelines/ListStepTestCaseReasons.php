<?php

namespace App\Http\Integrations\Bitbucket\Requests\Pipelines;

use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * The failure output (message + stack trace) of a single test case.
 */
class ListStepTestCaseReasons extends Request
{
    protected Method $method = Method::GET;

    public function __construct(
        protected string $workspace,
        protected string $repoSlug,
        protected string $pipeline,
        protected string $stepUuid,
        protected string $testCaseUuid,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/repositories/{$this->workspace}/{$this->repoSlug}/pipelines/{$this->pipeline}/steps/{$this->stepUuid}"
            ."/test_reports/test_cases/{$this->testCaseUuid}/test_case_reasons";
    }
}

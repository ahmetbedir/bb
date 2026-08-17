<?php

namespace App\Commands\Pipelines;

use App\Commands\BitbucketCommand;
use App\Commands\Concerns\InteractsWithPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetStepTestReport;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListStepTestCaseReasons;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListStepTestCases;
use Illuminate\Support\Str;
use Symfony\Component\Console\Formatter\OutputFormatter;

class PipelineTestsCommand extends BitbucketCommand
{
    use InteractsWithPipelines;

    protected $signature = 'pipeline:tests
        {pipeline? : Build number or UUID (defaults to the most recent pipeline)}
        {step? : Step number, name fragment or UUID (defaults to the failed step, else prompts)}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--branch= : When no pipeline is given, use the latest one on this branch}
        {--all : List every test case, not just the failing ones}
        {--reasons : Include the failure message and stack trace of failing cases}
        {--json : Output raw JSON}
        {--jq= : Filter JSON output through a jq expression}';

    protected $description = 'Show the test report of a pipeline step';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $ref = $this->resolvePipelineRef($workspace, $slug, $this->argument('pipeline'));

        if ($ref === null) {
            return self::FAILURE;
        }

        $steps = $this->pipelineSteps($workspace, $slug, $ref);

        if ($steps === null) {
            return self::FAILURE;
        }

        $step = $this->pickStep($steps, $this->argument('step'), preferFailed: true);

        if ($step === null) {
            return self::FAILURE;
        }

        $stepUuid = $this->uuidSegment($step['uuid'] ?? '');

        // A step without a test report answers 404 — that is a normal answer
        // here, not an error, so the response is inspected directly.
        $response = $this->dispatch($this->connector(), new GetStepTestReport($workspace, $slug, $ref, $stepUuid));

        if (in_array($response->status(), [400, 404], true)) {
            if ($this->wantsJson()) {
                return $this->outputJson(['report' => null, 'test_cases' => []]);
            }

            $this->info('Step '.$step['_index'].' ('.($step['name'] ?? 'unnamed').') has no test report. '
                .'Bitbucket only collects one when the step writes JUnit/xUnit XML files.');

            return self::SUCCESS;
        }

        if ($response->failed()) {
            $this->reportApiError($response);

            return self::FAILURE;
        }

        $report = $response->json();
        $cases = $this->collectAll(new ListStepTestCases($workspace, $slug, $ref, $stepUuid)) ?? [];
        $failing = collect($cases)
            ->filter(fn (array $case) => in_array(strtoupper((string) ($case['status'] ?? '')), ['FAILED', 'ERROR'], true))
            ->values()
            ->all();

        if ($this->option('reasons')) {
            foreach ($failing as $i => $case) {
                $failing[$i]['reasons'] = $this->caseReasons($workspace, $slug, $ref, $stepUuid, (string) ($case['uuid'] ?? ''));
            }
        }

        if ($this->wantsJson()) {
            return $this->outputJson(['report' => $report, 'test_cases' => $cases]);
        }

        $this->renderReport($step, $report);
        $this->renderCases($this->option('all') ? $cases : $failing, showingAll: (bool) $this->option('all'));

        if ($this->option('reasons')) {
            $this->renderReasons($failing);
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string, mixed>  $step
     * @param  array<string, mixed>  $report
     */
    private function renderReport(array $step, array $report): void
    {
        // Bitbucket's report payload is undocumented and has grown fields over
        // time, so every scalar is rendered rather than a fixed whitelist.
        $pairs = ['Step' => e($step['_index'].'. '.($step['name'] ?? '(unnamed)'))];

        foreach ($report as $key => $value) {
            if (is_array($value) || in_array($key, ['type', 'uuid', 'report_uuid'], true)) {
                continue;
            }

            $label = Str::of((string) $key)
                ->replace(['number_of_', '_in_ms'], ['', ''])
                ->replace('_', ' ')
                ->ucfirst()
                ->toString();

            $pairs[$label] = e(str_ends_with((string) $key, '_in_ms') ? $value.' ms' : (string) $value);
        }

        $this->detail($pairs);
    }

    /**
     * @param  array<int, array<string, mixed>>  $cases
     */
    private function renderCases(array $cases, bool $showingAll): void
    {
        if ($cases === []) {
            $this->info($showingAll ? 'No test cases reported.' : 'No failing test cases.');

            return;
        }

        $this->table(
            ['Status', 'Test', 'Suite', 'Duration'],
            array_map(fn (array $case) => [
                $this->badge(strtoupper((string) ($case['status'] ?? ''))),
                (string) ($case['name'] ?? $case['fully_qualified_name'] ?? ''),
                (string) ($case['test_suite_name'] ?? ''),
                isset($case['duration_in_ms']) ? $case['duration_in_ms'].' ms' : '',
            ], $cases),
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $failing
     */
    private function renderReasons(array $failing): void
    {
        foreach ($failing as $case) {
            $reasons = $case['reasons'] ?? null;

            if (blank($reasons)) {
                continue;
            }

            $this->line('');
            $this->line(' <options=bold>'.OutputFormatter::escape(
                (string) ($case['name'] ?? $case['fully_qualified_name'] ?? 'test case')
            ).'</>');

            foreach ((array) $reasons as $reason) {
                foreach (['message', 'stack_trace'] as $field) {
                    foreach ((array) ($reason[$field] ?? []) as $line) {
                        $this->line('   '.OutputFormatter::escape((string) $line));
                    }
                }
            }
        }
    }

    /**
     * The failure output of one test case, or null when it cannot be read.
     */
    private function caseReasons(string $workspace, string $slug, string $ref, string $stepUuid, string $caseUuid): mixed
    {
        if ($caseUuid === '') {
            return null;
        }

        $response = $this->dispatch(
            $this->connector(),
            new ListStepTestCaseReasons($workspace, $slug, $ref, $stepUuid, $this->uuidSegment($caseUuid)),
        );

        if ($response->failed()) {
            return null;
        }

        $body = $response->json();

        return $body['values'] ?? (is_array($body) ? [$body] : null);
    }
}

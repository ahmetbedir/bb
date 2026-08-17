<?php

namespace App\Commands\Concerns;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\GetPipelineStepLog;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelines;
use App\Http\Integrations\Bitbucket\Requests\Pipelines\ListPipelineSteps;
use Symfony\Component\Console\Formatter\OutputFormatter;

use function Laravel\Prompts\select;

/**
 * Shared pipeline plumbing: turning what the user typed into an API reference,
 * loading and picking steps, and streaming step logs.
 *
 * @mixin BitbucketCommand
 */
trait InteractsWithPipelines
{
    /**
     * The build number of the pipeline resolved by resolvePipelineRef(), when
     * known — used to build bitbucket.org links, which are number based.
     */
    protected int|string|null $resolvedBuildNumber = null;

    /**
     * Turn a user-supplied pipeline reference into an API path segment.
     *
     * Accepts a build number ("4879", "#4879"), a UUID with or without braces,
     * or nothing / "latest" / "last" to mean the most recent pipeline — which
     * may be narrowed by the command's --branch option when it has one.
     */
    protected function resolvePipelineRef(string $workspace, string $slug, int|string|null $ref): ?string
    {
        $ref = trim((string) $ref);

        if ($ref !== '' && ! in_array(strtolower($ref), ['latest', 'last'], true)) {
            $ref = ltrim($ref, '#');

            if (ctype_digit($ref)) {
                $this->resolvedBuildNumber = $ref;
            }

            return $this->uuidSegment($ref);
        }

        $branch = $this->hasOption('branch') ? $this->option('branch') : null;

        if (($latest = $this->latestPipeline($workspace, $slug, $branch)) === null) {
            return null;
        }

        $this->resolvedBuildNumber = $latest['build_number'] ?? null;

        // Report which pipeline was auto-resolved on STDERR so it never
        // pollutes stdout (JSON output, a piped log, ...).
        fwrite(STDERR, 'Using pipeline #'.($latest['build_number'] ?? '?')
            .' ('.($latest['target']['ref_name'] ?? 'unknown ref').')'.PHP_EOL);

        return $this->uuidSegment($latest['uuid'] ?? null);
    }

    /**
     * The most recent pipeline in the repository, optionally on one branch.
     *
     * @return array<string, mixed>|null
     */
    protected function latestPipeline(string $workspace, string $slug, ?string $branch = null): ?array
    {
        $filters = filled($branch) ? ['target.branch' => $branch] : [];

        $pipelines = $this->collectAll(new ListPipelines($workspace, $slug, $filters), limit: 1);

        if (blank($pipelines)) {
            $this->error(filled($branch)
                ? "No pipelines found for branch \"{$branch}\" in \"{$workspace}/{$slug}\"."
                : "No pipelines found in \"{$workspace}/{$slug}\".");

            return null;
        }

        return $pipelines[0];
    }

    /**
     * The steps of a pipeline, each tagged with its 1-based position so it can
     * be referenced as "--step 2".
     *
     * @return array<int, array<string, mixed>>|null
     */
    protected function pipelineSteps(string $workspace, string $slug, string $pipeline): ?array
    {
        $steps = $this->collectAll(new ListPipelineSteps($workspace, $slug, $pipeline));

        if ($steps === null) {
            return null;
        }

        foreach (array_keys($steps) as $i) {
            $steps[$i]['_index'] = $i + 1;
        }

        return $steps;
    }

    /**
     * Pick one step out of a pipeline: by 1-based index, by UUID, or by a
     * case-insensitive name fragment. With no selector, a single-step pipeline
     * resolves itself, a failed step wins when asked for, and otherwise the
     * user is prompted (or told to choose, when not on a terminal).
     *
     * @param  array<int, array<string, mixed>>  $steps
     * @return array<string, mixed>|null
     */
    protected function pickStep(array $steps, int|string|null $selector, bool $preferFailed = false): ?array
    {
        if ($steps === []) {
            $this->error('This pipeline has no steps.');

            return null;
        }

        $selector = trim((string) $selector);

        if ($selector !== '') {
            $match = collect($steps)->first(function (array $step) use ($selector) {
                return (string) $step['_index'] === $selector
                    || $this->bareUuid($step['uuid'] ?? '') === $this->bareUuid($selector)
                    || str_contains(strtolower((string) ($step['name'] ?? '')), strtolower($selector));
            });

            if ($match === null) {
                $this->error("No step matching \"{$selector}\". Available: ".$this->stepSummaryLine($steps));

                return null;
            }

            return $match;
        }

        if (count($steps) === 1) {
            return $steps[0];
        }

        if ($preferFailed) {
            $failed = collect($steps)->first(fn (array $step) => in_array($this->stateOf($step), ['FAILED', 'ERROR'], true));

            if ($failed !== null) {
                fwrite(STDERR, 'Using failed step '.$failed['_index'].' ('.($failed['name'] ?? 'unnamed').')'.PHP_EOL);

                return $failed;
            }
        }

        if (! stream_isatty(STDIN)) {
            $this->error('This pipeline has several steps — pick one with --step. Available: '.$this->stepSummaryLine($steps));

            return null;
        }

        $choice = select(
            label: 'Which step?',
            options: collect($steps)
                ->mapWithKeys(fn (array $step) => [
                    (string) $step['_index'] => $step['_index'].'. '.($step['name'] ?? '(unnamed)').' — '.$this->stateOf($step),
                ])
                ->all(),
        );

        return collect($steps)->firstWhere('_index', (int) $choice);
    }

    /**
     * "1. Build, 2. Deploy to staging" — used in error messages.
     *
     * @param  array<int, array<string, mixed>>  $steps
     */
    protected function stepSummaryLine(array $steps): string
    {
        return collect($steps)
            ->map(fn (array $step) => $step['_index'].'. '.($step['name'] ?? '(unnamed)'))
            ->implode(', ');
    }

    /**
     * Fetch a step's log from $offset onwards.
     *
     * Returns [text, totalBytes] — text is empty when nothing new has been
     * appended yet, and totalBytes is the log size known so far. Returns null
     * when the log cannot be read at all (it does not exist yet, or the
     * request failed), with $missing set to true in the "not yet" case.
     *
     * @return array{0: string, 1: int}|null
     */
    protected function stepLogChunk(
        string $workspace,
        string $slug,
        string $pipeline,
        string $stepUuid,
        int $offset = 0,
        bool &$missing = false,
    ): ?array {
        try {
            $response = $this->dispatch(
                $this->connector(),
                new GetPipelineStepLog($workspace, $slug, $pipeline, $stepUuid, $offset),
            );
        } catch (\Throwable $e) {
            $this->error('Request failed: '.$e->getMessage());

            return null;
        }

        // 416 means "nothing past that offset yet" — expected while following.
        if ($response->status() === 416) {
            return ['', $offset];
        }

        // Bitbucket only materialises the log once the step starts producing
        // output; until then the endpoint 404s.
        if (in_array($response->status(), [404, 410], true)) {
            $missing = true;

            return null;
        }

        if ($response->failed()) {
            $this->reportApiError($response);

            return null;
        }

        $body = $response->body();

        return [$body, $offset + strlen($body)];
    }

    /**
     * A step's whole log, or null when it is not (yet) available.
     *
     * @param  array<string, mixed>  $step
     */
    protected function fetchStepLog(string $workspace, string $slug, string $pipeline, array $step): ?string
    {
        $missing = false;
        $chunk = $this->stepLogChunk($workspace, $slug, $pipeline, $this->uuidSegment($step['uuid'] ?? ''), 0, $missing);

        if ($chunk !== null) {
            return $chunk[0];
        }

        if ($missing) {
            $this->warn('No log for step '.($step['_index'] ?? '?').' ('.($step['name'] ?? 'unnamed').') — '
                .'it has not produced output yet, or its logs have expired.');
        }

        return null;
    }

    /**
     * Tail a step's log, polling for the bytes appended since the last read
     * until the step stops running. Returns the command exit code.
     *
     * @param  array<string, mixed>  $step
     */
    protected function followStepLog(string $workspace, string $slug, string $pipeline, array $step, int $interval): int
    {
        $interval = max(2, $interval);
        $stepUuid = $this->uuidSegment($step['uuid'] ?? '');
        $offset = 0;
        $missing = false;

        while (true) {
            $chunk = $this->stepLogChunk($workspace, $slug, $pipeline, $stepUuid, $offset, $missing);

            if ($chunk === null && ! $missing) {
                return 1;
            }

            if ($chunk !== null) {
                [$text, $offset] = $chunk;

                if ($text !== '') {
                    $this->output->write($this->highlightLog($text));
                }
            }

            $state = $this->refreshStepState($workspace, $slug, $pipeline, (string) ($step['uuid'] ?? ''));

            if ($state === null) {
                return 1;
            }

            if (! in_array($state, ['PENDING', 'IN_PROGRESS', 'RUNNING', 'BUILDING', 'PAUSED', 'READY'], true)) {
                $this->line('');
                $this->line('  '.$this->badge($state).' step '.($step['name'] ?? $step['_index'] ?? ''));

                return in_array($state, ['FAILED', 'ERROR'], true) ? 1 : 0;
            }

            sleep($interval);
        }
    }

    /**
     * Re-read one step's state — the log stream itself carries no status.
     */
    protected function refreshStepState(string $workspace, string $slug, string $pipeline, string $stepUuid): ?string
    {
        $steps = $this->collectAll(new ListPipelineSteps($workspace, $slug, $pipeline));

        if ($steps === null) {
            return null;
        }

        $match = collect($steps)->first(
            fn (array $step) => $this->bareUuid($step['uuid'] ?? '') === $this->bareUuid($stepUuid)
        );

        return $match === null ? 'UNKNOWN' : $this->stateOf($match);
    }

    /**
     * Apply the --grep / --tail / --output options, then write the log to the
     * file, the pager or the terminal. Returns the command exit code.
     */
    protected function emitLog(string $log): int
    {
        if ($this->hasOption('grep') && filled($needle = $this->option('grep'))) {
            $log = collect(explode("\n", $log))
                ->filter(fn (string $line) => stripos($line, (string) $needle) !== false)
                ->implode("\n")."\n";
        }

        if ($this->hasOption('tail') && filled($tail = $this->option('tail'))) {
            $lines = explode("\n", rtrim($log, "\n"));
            $log = implode("\n", array_slice($lines, -max(1, (int) $tail)))."\n";
        }

        if ($this->hasOption('output') && filled($path = $this->option('output'))) {
            if (file_put_contents($path, $log) === false) {
                $this->error("Could not write to \"{$path}\".");

                return 1;
            }

            $this->info('Wrote '.number_format(strlen($log)).' bytes to '.$path);

            return 0;
        }

        $this->pager($this->highlightLog($log));

        return 0;
    }

    /**
     * Escape console markup in build output (it is arbitrary text) and make
     * the "+ command" markers Bitbucket emits stand out.
     */
    protected function highlightLog(string $log): string
    {
        return collect(explode("\n", $log))
            ->map(function (string $line) {
                $escaped = OutputFormatter::escape($line);

                return str_starts_with($line, '+ ') ? "<options=bold>{$escaped}</>" : $escaped;
            })
            ->implode("\n");
    }
}

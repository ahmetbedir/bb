<?php

namespace App\Commands\PullRequests;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\PullRequests\CreatePullRequest;
use App\Support\GitContext;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

class PrCreateCommand extends BitbucketCommand
{
    protected $signature = 'pr:create
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--title= : Pull request title}
        {--source= : Source branch name (defaults to the current git branch)}
        {--dest= : Destination branch name (defaults to the main branch)}
        {--description= : Pull request description}
        {--fill : Fill title and description from the branch commits}
        {--close-source-branch : Close the source branch after merge}
        {--web : Open the created pull request in a browser}';

    protected $description = 'Create a pull request';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $source = $this->option('source')
            ?: app(GitContext::class)->currentBranch()
            ?: text('Source branch', required: true);

        // Ask for the destination when not given — a select box of the repo's
        // branches, pre-selecting the configured default (or the main branch).
        $dest = $this->option('dest');
        if (blank($dest)) {
            $dest = $this->promptDestination($source);
        }

        if (blank($dest)) {
            return self::FAILURE;
        }

        if ($source === $dest) {
            $this->error("Source and destination are both \"{$source}\" — choose a different destination branch.");

            return self::FAILURE;
        }

        [$filledTitle, $filledBody] = $this->option('fill') ? $this->fillFromCommits($dest) : [null, null];

        // Title isn't required: suggest the --fill title, else "Merge X into Y".
        if (filled($title = $this->option('title'))) {
            // use the explicit --title as-is
        } else {
            $suggested = $filledTitle ?: "Merge {$source} into {$dest}";
            $title = text('Title', default: $suggested) ?: $suggested;
        }

        $description = $this->option('description') ?: $filledBody;

        $response = $this->send(new CreatePullRequest(
            workspace: $workspace,
            repoSlug: $slug,
            title: $title,
            sourceBranch: $source,
            destinationBranch: $dest,
            description: $description,
            closeSourceBranch: (bool) $this->option('close-source-branch'),
        ));

        if ($response === null) {
            return self::FAILURE;
        }

        $pr = $response->json();
        $this->info("Created PR #{$pr['id']}: {$pr['title']}");
        $url = $pr['links']['html']['href'] ?? '';
        $this->line(' '.$url);

        if ($this->option('web') && filled($url)) {
            $this->openInBrowser($url);
        }

        return self::SUCCESS;
    }

    /**
     * Prompt for the destination branch. Uses the branches configured via
     * `bb config destinations ...` as a select box; falls back to free text.
     */
    private function promptDestination(string $source): ?string
    {
        $options = array_values(array_filter(
            $this->configuredDestinations(),
            fn ($b) => $b !== $source,
        ));

        $default = $this->credentials()->get('settings.default_destination') ?: ($options[0] ?? null);

        if ($options === []) {
            return text('Destination branch', default: (string) $default, required: true);
        }

        if (blank($default) || ! in_array($default, $options, true)) {
            $default = $options[0];
        }

        return select(
            label: 'Destination branch',
            options: $options,
            default: $default,
            scroll: 15,
        );
    }

    /**
     * Destination branches configured via `bb config destinations ...`.
     *
     * @return array<int, string>
     */
    private function configuredDestinations(): array
    {
        $raw = (string) $this->credentials()->get('settings.destinations', '');

        return array_values(array_filter(preg_split('/[\s,]+/', $raw) ?: []));
    }

    /**
     * Derive [title, description] from the commits on the current branch that
     * are not yet on the destination branch (like `gh pr create --fill`).
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function fillFromCommits(?string $dest): array
    {
        $base = $dest
            ? "origin/{$dest}"
            : ($this->gitOutput(['symbolic-ref', '--short', 'refs/remotes/origin/HEAD']) ?: 'origin/main');

        $log = $this->gitOutput(['log', '--format=%s', "{$base}..HEAD"]);
        $subjects = $log ? array_values(array_filter(explode("\n", $log))) : [];

        if (count($subjects) === 1) {
            $body = $this->gitOutput(['log', '-1', '--format=%b']);

            return [$subjects[0], filled($body) ? $body : null];
        }

        if (count($subjects) > 1) {
            $branch = $this->gitOutput(['rev-parse', '--abbrev-ref', 'HEAD']) ?: $subjects[0];
            $body = collect($subjects)->map(fn (string $s) => "- {$s}")->implode("\n");

            return [$branch, $body];
        }

        // No range available — fall back to the latest commit.
        $subject = $this->gitOutput(['log', '-1', '--format=%s']);
        $body = $this->gitOutput(['log', '-1', '--format=%b']);

        return [$subject ?: null, filled($body) ? $body : null];
    }
}

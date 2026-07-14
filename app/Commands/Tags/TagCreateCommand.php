<?php

namespace App\Commands\Tags;

use App\Commands\BitbucketCommand;
use App\Http\Integrations\Bitbucket\Requests\Tags\CreateTag;

use function Laravel\Prompts\text;

class TagCreateCommand extends BitbucketCommand
{
    protected $signature = 'tag:create
        {name? : Tag name}
        {--R|repo= : Target repository as "workspace/repo" (defaults to the current git repo)}
        {--target= : Commit hash to tag}';

    protected $description = 'Create a tag';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        if (! $resolved = $this->resolveRepo()) {
            return self::FAILURE;
        }

        [$workspace, $slug] = $resolved;

        $name = $this->argument('name') ?: text('Tag name', required: true);
        $target = $this->option('target') ?: text('Commit hash to tag', required: true);

        $response = $this->send(new CreateTag($workspace, $slug, $name, $target));

        if ($response === null) {
            return self::FAILURE;
        }

        $tag = $response->json();
        $this->info("Created tag \"{$tag['name']}\" at ".substr($tag['target']['hash'] ?? $target, 0, 8));

        return self::SUCCESS;
    }
}

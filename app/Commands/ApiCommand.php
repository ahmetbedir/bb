<?php

namespace App\Commands;

use App\Http\Integrations\Bitbucket\Requests\ApiRequest;
use App\Http\Integrations\Bitbucket\Requests\ApiRequestWithBody;
use Saloon\Enums\Method;
use Symfony\Component\Console\Output\OutputInterface;

class ApiCommand extends BitbucketCommand
{
    protected $signature = 'api
        {path : API path, e.g. "/repositories/acme/api" (relative to https://api.bitbucket.org/2.0)}
        {--X|method=GET : HTTP method}
        {--f|field=* : Body field as key=value (repeatable), added to a JSON body}
        {--query=* : Query parameter as key=value (repeatable)}
        {--input= : Raw JSON request body ("-" reads stdin)}
        {--paginate : Follow "next" links and return every "values" entry as one JSON array (GET only)}
        {--jq= : Filter the JSON response through a jq expression}
        {--y|yes : Confirm the action when running non-interactively (scripts, agents)}';

    protected $description = 'Make an authenticated request to the Bitbucket API';

    public function handle(): int
    {
        if (! $this->ensureConfigured()) {
            return self::FAILURE;
        }

        $body = $this->resolveBody();

        if ($body === false) {
            return self::FAILURE;
        }

        $method = strtoupper((string) $this->option('method'));

        if (Method::tryFrom($method) === null) {
            $this->error("Unsupported HTTP method \"{$method}\".");

            return self::FAILURE;
        }

        if ($method !== 'GET' && ! $this->allowUnattended("A {$method} request")) {
            return self::FAILURE;
        }

        if ($this->option('paginate') && ($method !== 'GET' || $body !== [])) {
            $this->error('--paginate only works with GET requests without a body.');

            return self::FAILURE;
        }

        $endpoint = $this->normalizePath($this->argument('path'));
        $query = $this->parsePairs($this->option('query'));

        // Only attach a body when there is one — Bitbucket's CDN answers 403
        // to a GET that carries a JSON payload.
        $request = $body === []
            ? new ApiRequest($method, $endpoint, $query)
            : new ApiRequestWithBody($method, $endpoint, $query, $body);

        if ($this->option('paginate')) {
            $values = $this->collectAll($request);

            return $values === null ? self::FAILURE : $this->outputJson($values);
        }

        $response = $this->send($request);

        if ($response === null) {
            return self::FAILURE;
        }

        $decoded = json_decode($response->body(), true);

        if (is_array($decoded)) {
            return $this->outputJson($decoded);
        }

        // Non-JSON payload (e.g. a diff) — print it verbatim.
        $this->output->write($response->body(), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function normalizePath(string $path): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        $path = '/'.ltrim($path, '/');

        // The connector base URL already includes /2.0.
        return preg_replace('#^/2\.0(?=/|$)#', '', $path) ?: $path;
    }

    /**
     * @return array<string, mixed>|false
     */
    private function resolveBody(): array|false
    {
        $input = $this->option('input');

        if (filled($input)) {
            $raw = $input === '-' ? file_get_contents('php://stdin') : $input;
            $decoded = json_decode((string) $raw, true);

            if (! is_array($decoded)) {
                $this->error('--input is not valid JSON.');

                return false;
            }

            return $decoded;
        }

        return $this->parsePairs($this->option('field'));
    }

    /**
     * Turn ["a=1", "b=2"] into ['a' => '1', 'b' => '2'].
     *
     * @param  array<int, string>  $pairs
     * @return array<string, string>
     */
    private function parsePairs(array $pairs): array
    {
        $result = [];

        foreach ($pairs as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $result[$key] = $value;
        }

        return $result;
    }
}

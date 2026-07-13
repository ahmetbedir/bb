<?php

namespace App\Support;

use RuntimeException;

/**
 * A tiny single-shot HTTP server that captures the OAuth redirect from the
 * browser. It listens on a loopback address, waits for one request, parses
 * the query string and returns it — this is how `bb auth login` receives
 * the authorization code without the user copy-pasting anything.
 */
class OAuthCallbackServer
{
    public function __construct(
        protected string $host = '127.0.0.1',
        protected int $port = 8888,
        protected int $timeout = 120,
    ) {}

    /**
     * Block until the browser hits the redirect URI, then return the parsed
     * query parameters (e.g. ['code' => '...', 'state' => '...']).
     *
     * @return array<string, string>
     */
    public function waitForCallback(): array
    {
        $server = @stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Could not start local server on {$this->host}:{$this->port} — {$errstr} ({$errno}). Is the port free?");
        }

        try {
            $client = @stream_socket_accept($server, $this->timeout);

            if ($client === false) {
                throw new RuntimeException('Timed out waiting for the browser redirect.');
            }

            $requestLine = fgets($client) ?: '';
            $query = $this->parseQuery($requestLine);

            $this->respond($client, $query);
            fclose($client);

            return $query;
        } finally {
            fclose($server);
        }
    }

    public function redirectUri(): string
    {
        return "http://{$this->host}:{$this->port}/callback";
    }

    /**
     * @return array<string, string>
     */
    private function parseQuery(string $requestLine): array
    {
        // e.g. "GET /callback?code=abc&state=xyz HTTP/1.1"
        if (! preg_match('#^GET\s+(\S+)\s+HTTP#', $requestLine, $matches)) {
            return [];
        }

        $queryString = parse_url($matches[1], PHP_URL_QUERY) ?: '';
        parse_str($queryString, $params);

        return array_map('strval', $params);
    }

    /**
     * @param  resource  $client
     * @param  array<string, string>  $query
     */
    private function respond($client, array $query): void
    {
        $ok = isset($query['code']);
        $title = $ok ? 'Authentication complete' : 'Authentication failed';
        $message = $ok
            ? 'You can close this tab and return to your terminal.'
            : 'No authorization code was received. Check your terminal.';

        $html = <<<HTML
            <!doctype html>
            <html><head><meta charset="utf-8"><title>bb</title>
            <style>body{font-family:system-ui,sans-serif;display:flex;height:100vh;margin:0;align-items:center;justify-content:center;background:#0b1424;color:#e7edf6}
            .card{text-align:center;padding:2rem 3rem;border:1px solid #24334d;border-radius:12px;background:#111d33}
            h1{margin:0 0 .5rem;font-size:1.25rem}p{margin:0;color:#9fb2cc}</style></head>
            <body><div class="card"><h1>{$title}</h1><p>{$message}</p></div></body></html>
            HTML;

        $body = $html;
        $length = strlen($body);
        $status = $ok ? '200 OK' : '400 Bad Request';

        fwrite($client, "HTTP/1.1 {$status}\r\n");
        fwrite($client, "Content-Type: text/html; charset=utf-8\r\n");
        fwrite($client, "Content-Length: {$length}\r\n");
        fwrite($client, "Connection: close\r\n\r\n");
        fwrite($client, $body);
    }
}

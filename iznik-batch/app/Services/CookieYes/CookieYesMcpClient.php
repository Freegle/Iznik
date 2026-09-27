<?php

namespace App\Services\CookieYes;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A minimal MCP client for CookieYes's server: JSON-RPC over the streamable
 * HTTP transport. It knows how to call a tool and unwrap the answer, and
 * nothing about what the tools mean.
 */
class CookieYesMcpClient
{
    public const PROTOCOL_VERSION = '2025-06-18';

    private bool $initialised = false;

    private ?string $sessionId = null;

    private ?string $protocolVersion = null;

    private int $nextId = 1;

    public function __construct(private readonly CookieYesOAuth $oauth)
    {
    }

    public function listTools(): array
    {
        $this->initialise();

        return $this->request('tools/list', new \stdClass())['tools'] ?? [];
    }

    /**
     * Call a tool and return its answer as an array: the structured content if
     * the server sent any, else the text decoded as JSON, else ['text' => ...].
     */
    public function callTool(string $name, array $arguments = []): array
    {
        $result = $this->callToolRaw($name, $arguments);
        $text = $this->text($result);

        if (! empty($result['isError'])) {
            throw new CookieYesException("CookieYes tool {$name} failed: " . ($text !== '' ? $text : json_encode($result)));
        }

        if (isset($result['structuredContent']) && is_array($result['structuredContent'])) {
            return $result['structuredContent'];
        }

        $decoded = json_decode($text, true);

        return is_array($decoded) ? $decoded : ['text' => $text];
    }

    /**
     * The tool result exactly as the server sent it, including a tool error.
     */
    public function callToolRaw(string $name, array $arguments = []): array
    {
        $this->initialise();

        return $this->request('tools/call', [
            'name' => $name,
            // An empty PHP array would encode as [], which is not an object.
            'arguments' => $arguments === [] ? new \stdClass() : $arguments,
        ]);
    }

    private function initialise(): void
    {
        if ($this->initialised) {
            return;
        }

        $result = $this->request('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'freegle-cookieyes-watchdog', 'version' => '1.0'],
        ]);
        $this->protocolVersion = $result['protocolVersion'] ?? self::PROTOCOL_VERSION;

        $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        $this->initialised = true;
    }

    private function request(string $method, array|\stdClass $params): array
    {
        $id = $this->nextId++;
        $response = $this->send(['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => $params]);

        if ($this->sessionId === null && $response->header('Mcp-Session-Id') !== '') {
            $this->sessionId = $response->header('Mcp-Session-Id');
        }

        $message = $this->messageFor($response, $id);

        if (isset($message['error'])) {
            throw new CookieYesException("CookieYes {$method} failed: " . ($message['error']['message'] ?? json_encode($message['error'])));
        }

        return is_array($message['result'] ?? null) ? $message['result'] : [];
    }

    /**
     * POST one JSON-RPC message. A rejected token gets one retry with a fresh
     * one; a second rejection means the login itself is gone.
     */
    private function send(array $payload): Response
    {
        foreach ([1, 2] as $attempt) {
            $headers = ['Accept' => 'application/json, text/event-stream'];
            if ($this->sessionId !== null) {
                $headers['Mcp-Session-Id'] = $this->sessionId;
            }
            if ($this->protocolVersion !== null) {
                $headers['MCP-Protocol-Version'] = $this->protocolVersion;
            }

            try {
                $response = Http::withHeaders($headers)
                    ->withToken($this->oauth->accessToken())
                    ->timeout(120)
                    ->withBody(json_encode($payload), 'application/json')
                    ->post($this->oauth->mcpUrl());
            } catch (ConnectionException $e) {
                throw new CookieYesException('Could not reach the CookieYes MCP server: ' . $e->getMessage(), 0, $e);
            }

            if ($response->status() !== 401) {
                break;
            }

            if ($attempt === 1) {
                $this->oauth->invalidateAccessToken();
            }
        }

        if ($response->status() === 401) {
            throw new CookieYesAuthException('CookieYes rejected the watchdog\'s login. Run php artisan cookieyes:authorize to log in again.');
        }

        if (! $response->successful()) {
            throw new CookieYesException("CookieYes MCP server returned HTTP {$response->status()} for {$payload['method']}: " . Str::limit($response->body(), 200));
        }

        return $response;
    }

    /**
     * Find the reply to request $id, whether the server answered with plain
     * JSON or an event stream (which may carry notifications first).
     */
    private function messageFor(Response $response, int $id): array
    {
        if (str_contains($response->header('Content-Type'), 'text/event-stream')) {
            foreach (preg_split('/\r?\n\r?\n/', $response->body()) as $event) {
                $data = [];
                foreach (preg_split('/\r?\n/', $event) as $line) {
                    if (str_starts_with($line, 'data:')) {
                        $data[] = ltrim(substr($line, 5));
                    }
                }

                $message = json_decode(implode("\n", $data), true);
                if (is_array($message) && ($message['id'] ?? null) === $id) {
                    return $message;
                }
            }

            throw new CookieYesException("CookieYes MCP server sent no reply to request {$id}");
        }

        $message = $response->json();
        if (! is_array($message)) {
            throw new CookieYesException('CookieYes MCP server sent something other than JSON: ' . Str::limit($response->body(), 200));
        }

        return $message;
    }

    private function text(array $result): string
    {
        $parts = [];
        foreach ($result['content'] ?? [] as $item) {
            if (($item['type'] ?? null) === 'text') {
                $parts[] = $item['text'];
            }
        }

        return implode("\n", $parts);
    }
}

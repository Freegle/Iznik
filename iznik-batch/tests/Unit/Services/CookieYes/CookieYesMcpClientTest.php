<?php

namespace Tests\Unit\Services\CookieYes;

use App\Services\CookieYes\CookieYesAuthException;
use App\Services\CookieYes\CookieYesException;
use App\Services\CookieYes\CookieYesMcpClient;
use App\Services\CookieYes\CookieYesOAuth;
use App\Services\CookieYes\CookieYesTokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CookieYesMcpClientTest extends TestCase
{
    private const MCP = 'https://app.cookieyes.com/mcp';

    /** Tokens handed out, in order; invalidate() moves to the next one. */
    private array $tokens = ['token-1', 'token-2'];

    private int $invalidated = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.cookieyes.base_url' => 'https://app.cookieyes.com']);
    }

    private function client(): CookieYesMcpClient
    {
        $test = $this;
        $oauth = new class (new CookieYesTokenStore(), $test) extends CookieYesOAuth {
            public function __construct(CookieYesTokenStore $store, private readonly CookieYesMcpClientTest $test)
            {
                parent::__construct($store);
            }

            public function accessToken(): string
            {
                return $this->test->currentToken();
            }

            public function invalidateAccessToken(): void
            {
                $this->test->invalidate();
            }
        };

        return new CookieYesMcpClient($oauth);
    }

    public function currentToken(): string
    {
        return $this->tokens[min($this->invalidated, count($this->tokens) - 1)];
    }

    public function invalidate(): void
    {
        $this->invalidated++;
    }

    /**
     * Fake the MCP endpoint. $answer gets the decoded JSON-RPC request (other
     * than initialize and notifications) and returns the response.
     *
     * @param  callable(array, Request): mixed  $answer
     */
    private function fakeMcp(callable $answer): void
    {
        Http::fake(function (Request $request) use ($answer) {
            $rpc = json_decode($request->body(), true);

            if ($rpc['method'] === 'initialize') {
                return Http::response([
                    'jsonrpc' => '2.0',
                    'id' => $rpc['id'],
                    'result' => [
                        'protocolVersion' => '2025-06-18',
                        'capabilities' => ['tools' => new \stdClass()],
                        'serverInfo' => ['name' => 'cookieyes', 'version' => '1'],
                    ],
                ], 200, ['Mcp-Session-Id' => 'session-abc']);
            }

            if (! isset($rpc['id'])) {
                return Http::response('', 202);
            }

            return $answer($rpc, $request);
        });
    }

    private function rpcResult(array $rpc, array $result): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => $result]);
    }

    public function test_it_initialises_once_then_calls_tools_in_the_session(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, [
            'content' => [['type' => 'text', 'text' => 'ignored']],
            'structuredContent' => ['live' => true],
        ]));

        $client = $this->client();
        $this->assertSame(['live' => true], $client->callTool('get_banner_status', ['domain' => 'example.org']));
        $client->callTool('get_banner_status', ['domain' => 'example.org']);

        $methods = [];
        Http::assertSent(function (Request $request) use (&$methods) {
            $methods[] = json_decode($request->body(), true)['method'];

            return true;
        });
        $this->assertSame(['initialize', 'notifications/initialized', 'tools/call', 'tools/call'], $methods);

        Http::assertSent(function (Request $request) {
            $rpc = json_decode($request->body(), true);

            return $rpc['method'] === 'tools/call'
                && $rpc['params'] === ['name' => 'get_banner_status', 'arguments' => ['domain' => 'example.org']]
                && $request->url() === self::MCP
                && $request->hasHeader('Authorization', 'Bearer token-1')
                && $request->hasHeader('Mcp-Session-Id', 'session-abc')
                && $request->hasHeader('MCP-Protocol-Version', '2025-06-18')
                && str_contains($request->header('Accept')[0], 'text/event-stream');
        });
    }

    public function test_a_json_text_result_is_decoded(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, [
            'content' => [['type' => 'text', 'text' => '{"domains":[{"id":1}]}']],
        ]));

        $this->assertSame(['domains' => [['id' => 1]]], $this->client()->callTool('list_domains'));
    }

    public function test_a_plain_text_result_is_returned_as_text(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, [
            'content' => [['type' => 'text', 'text' => 'Scan queued.'], ['type' => 'text', 'text' => 'Check back later.']],
        ]));

        $this->assertSame(['text' => "Scan queued.\nCheck back later."], $this->client()->callTool('trigger_cookie_scan'));
    }

    public function test_arguments_are_sent_as_an_object_even_when_empty(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, ['structuredContent' => []]));

        $this->client()->callTool('list_domains');

        Http::assertSent(fn (Request $request) => str_contains($request->body(), '"arguments":{}'));
    }

    public function test_an_event_stream_response_is_parsed(): void
    {
        $this->fakeMcp(function (array $rpc) {
            $message = json_encode(['jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => ['structuredContent' => ['ok' => 1]]]);
            $progress = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => []]);

            return Http::response(
                "event: message\ndata: {$progress}\n\nevent: message\ndata: {$message}\n\n",
                200,
                ['Content-Type' => 'text/event-stream']
            );
        });

        $this->assertSame(['ok' => 1], $this->client()->callTool('get_scan_results'));
    }

    public function test_a_tool_error_is_raised(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, [
            'isError' => true,
            'content' => [['type' => 'text', 'text' => 'Scan limit reached for this month']],
        ]));

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('Scan limit reached for this month');
        $this->client()->callTool('trigger_cookie_scan', ['domain' => 'example.org']);
    }

    public function test_a_json_rpc_error_is_raised(): void
    {
        $this->fakeMcp(fn (array $rpc) => Http::response([
            'jsonrpc' => '2.0', 'id' => $rpc['id'], 'error' => ['code' => -32602, 'message' => 'Unknown tool: nope'],
        ]));

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('Unknown tool: nope');
        $this->client()->callTool('nope');
    }

    public function test_a_rejected_token_is_refreshed_and_the_call_retried_once(): void
    {
        $this->fakeMcp(function (array $rpc, Request $request) {
            if ($request->hasHeader('Authorization', 'Bearer token-1')) {
                return Http::response(['error' => 'invalid_token'], 401);
            }

            return $this->rpcResult($rpc, ['structuredContent' => ['ok' => true]]);
        });

        $this->assertSame(['ok' => true], $this->client()->callTool('list_domains'));
        $this->assertSame(1, $this->invalidated);
    }

    public function test_a_token_rejected_twice_needs_a_new_login(): void
    {
        Http::fake(fn () => Http::response(['error' => 'invalid_token'], 401));

        $this->expectException(CookieYesAuthException::class);
        $this->expectExceptionMessage('cookieyes:authorize');
        $this->client()->callTool('list_domains');
    }

    public function test_a_server_error_is_raised(): void
    {
        $this->fakeMcp(fn () => Http::response('upstream timeout', 504));

        $this->expectException(CookieYesException::class);
        $this->expectExceptionMessage('504');
        $this->client()->callTool('list_domains');
    }

    public function test_list_tools_returns_the_tools(): void
    {
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, [
            'tools' => [['name' => 'list_domains', 'inputSchema' => ['type' => 'object']]],
        ]));

        $this->assertSame('list_domains', $this->client()->listTools()[0]['name']);
    }

    public function test_the_raw_result_is_available_for_diagnosis(): void
    {
        $raw = ['content' => [['type' => 'text', 'text' => 'x']], 'isError' => true];
        $this->fakeMcp(fn (array $rpc) => $this->rpcResult($rpc, $raw));

        // Raw calls do not throw on a tool error: the point is to see it.
        $this->assertSame($raw, $this->client()->callToolRaw('get_scan_results'));
    }
}

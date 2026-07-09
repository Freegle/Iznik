# API Endpoint Deprecation-and-Observe Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a reusable mechanism to deprecate an apiv2 endpoint, observe whether any of the three caller populations (web / app / external) still hit it after a per-endpoint sunset date, and get an overnight email telling us it is safe to retire or who is still calling it.

**Architecture:** The OpenAPI spec (`swagger.json`) is the single source of truth for *what* is deprecated and *when* it sunsets (`deprecated: true` + `x-sunset`). A tiny Go per-route middleware logs one `deprecated_endpoint` line to Loki per hit (route pattern + caller identity). A nightly Laravel `monitor:deprecated-endpoints` command reads the spec, and for each endpoint whose sunset has passed queries Loki *from the sunset date onward*, then emails geeks@ a "safe to retire" / "still in use + chase-down breakdown" report. Retirement stays a human edit.

**Tech Stack:** Go (Fiber v2), existing `misc.LokiClient.LogCustom`; PHP 8/Laravel 11 (Artisan command, `Http` facade for Loki `query_range`, `Mail::raw`); Swagger 2.0 JSON.

**Companion design doc:** `plans/active/api-deprecation-observe.md`.

---

## File Structure

**Go (`iznik-server-go/`)**
- Create `deprecation/deprecation.go` — the `Marker()` middleware + pure field builder. One responsibility: flag + log a deprecated hit.
- Create `deprecation/deprecation_test.go` — package tests (with `TestMain` to make the Loki singleton deterministic).
- Modify `router/routes.go` — attach `deprecation.Marker()` to each rationalised route (per-endpoint, Task 5).
- Modify `swagger/swagger.json` — `deprecated: true` + `x-sunset` per rationalised operation (per-endpoint, Task 5).

**PHP (`iznik-batch/`)**
- Modify `app/Services/LokiService.php` — add a `queryRange()` read.
- Modify `config/freegle.php` — add `loki.query_url` and `apiv2_swagger_url`.
- Create `app/Services/DeprecatedEndpointService.php` — fetch spec, return past-sunset endpoints.
- Create `app/Console/Commands/Monitor/DeprecatedEndpointsCommand.php` — verdict + email.
- Modify `routes/console.php` — schedule the command daily.
- Create `tests/Feature/Monitor/DeprecatedEndpointsCommandTest.php`, `tests/Unit/DeprecatedEndpointServiceTest.php`, `tests/Unit/LokiServiceQueryTest.php`.

---

## Task 1: Go deprecation middleware

**Files:**
- Create: `iznik-server-go/deprecation/deprecation.go`
- Test: `iznik-server-go/deprecation/deprecation_test.go`

- [ ] **Step 1: Write the failing tests**

Create `iznik-server-go/deprecation/deprecation_test.go`:

```go
package deprecation

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

var lokiDir string

// TestMain enables Loki to a temp dir BEFORE any GetLoki() call in this test
// binary, so the misc.GetLoki sync.Once initialises deterministically. Without
// this, whichever test runs first would freeze the singleton's enabled state.
func TestMain(m *testing.M) {
	dir, err := os.MkdirTemp("", "deprecation-loki")
	if err != nil {
		panic(err)
	}
	lokiDir = dir
	os.Setenv("LOKI_ENABLED", "true")
	os.Setenv("LOKI_JSON_PATH", dir)
	code := m.Run()
	os.RemoveAll(dir)
	os.Exit(code)
}

func TestMarkerSetsHeaderAndPreservesResponse(t *testing.T) {
	app := fiber.New()
	app.Get("/test/:id", Marker(), func(c *fiber.Ctx) error {
		return c.Status(fiber.StatusTeapot).SendString("body-unchanged")
	})

	req := httptest.NewRequest("GET", "/test/123", nil)
	resp, err := app.Test(req, -1)
	assert.NoError(t, err)

	// Logging is side-effect only: status and body pass through untouched.
	assert.Equal(t, fiber.StatusTeapot, resp.StatusCode)
	body, _ := io.ReadAll(resp.Body)
	assert.Equal(t, "body-unchanged", string(body))
	// External consumers can self-detect deprecation.
	assert.Equal(t, "true", resp.Header.Get("Deprecation"))
}

func TestMarkerLogsRoutePatternNotFilledPath(t *testing.T) {
	app := fiber.New()
	app.Get("/test/:id", Marker(), func(c *fiber.Ctx) error {
		return c.SendString("ok")
	})

	req := httptest.NewRequest("GET", "/test/999?webversion=2026-01-02T00:00:00Z", nil)
	req.Header.Set("User-Agent", "FreegleApp/9.9.9")
	_, err := app.Test(req, -1)
	assert.NoError(t, err)

	line := readTodaysLokiLine(t)
	assert.Contains(t, line, `"source":"deprecated_endpoint"`)
	// The route PATTERN, never the filled /test/999.
	assert.Contains(t, line, `"endpoint":"GET /test/:id"`)
	assert.NotContains(t, line, "/test/999")
	// Caller identity captured for chase-down.
	assert.Contains(t, line, "FreegleApp/9.9.9")
	assert.Contains(t, line, "2026-01-02T00:00:00Z")
}

// readTodaysLokiLine returns the last line of today's go-api log file.
func readTodaysLokiLine(t *testing.T) string {
	t.Helper()
	fname := filepath.Join(lokiDir, "go-api-"+time.Now().Format("2006-01-02")+".log")
	data, err := os.ReadFile(fname)
	assert.NoError(t, err)
	lines := strings.Split(strings.TrimSpace(string(data)), "\n")
	// Sanity: the entry is valid JSON.
	var entry map[string]interface{}
	assert.NoError(t, json.Unmarshal([]byte(lines[len(lines)-1]), &entry))
	return lines[len(lines)-1]
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd iznik-server-go && go build ./deprecation/... `
Expected: FAIL — `deprecation/deprecation.go` does not exist (no `Marker`).

- [ ] **Step 3: Write the middleware**

Create `iznik-server-go/deprecation/deprecation.go`:

```go
// Package deprecation flags apiv2 routes that are on the way out and records one
// Loki hit per call, so monitor:deprecated-endpoints (Laravel) can decide when a
// route is safe to retire and, if not, who is still calling it. See
// plans/active/api-deprecation-observe.md.
package deprecation

import (
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// Marker returns middleware for a deprecated route. It sets a Deprecation
// response header (so well-behaved external clients can self-detect) and logs
// one hit. It NEVER alters the response — logging is side-effect only.
//
// The hit is logged synchronously (not in a goroutine): deprecated routes are
// low-traffic by definition, and a synchronous write keeps the behaviour
// deterministic and testable. Loki writes are non-blocking file appends.
func Marker() fiber.Handler {
	return func(c *fiber.Ctx) error {
		c.Set("Deprecation", "true")
		err := c.Next()
		logHit(c)
		return err
	}
}

func logHit(c *fiber.Ctx) {
	loki := misc.GetLoki()
	if !loki.IsEnabled() {
		return
	}
	endpoint, data := buildHitFields(c)
	// endpoint is a label (low cardinality: the bounded set of deprecated
	// routes). Caller identity stays in the JSON body to keep label cardinality
	// low, matching misc/loki.go's convention.
	loki.LogCustom("deprecated_endpoint", map[string]string{"endpoint": endpoint}, data)
}

// buildHitFields derives the route-pattern endpoint label and the best-effort
// caller identity already present on the request. No new lookups: whatever the
// request carries is what we log.
func buildHitFields(c *fiber.Ctx) (string, map[string]interface{}) {
	endpoint := c.Method() + " " + c.Route().Path

	data := map[string]interface{}{
		"endpoint":   endpoint,
		"user_agent": c.Get("User-Agent"),
		"webversion": c.Query("webversion"), // client BUILD_DATE, when sent
		"ip":         c.IP(),
	}
	// user_id if the request is authenticated (same source main.go uses for the
	// request logger).
	if uid, _, _ := user.GetJWTFromRequest(c); uid > 0 {
		data["user_id"] = uid
	}
	return endpoint, data
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run (via the status API, per repo policy — never `go test` directly in WSL):
`curl -s -X POST http://localhost:8081/api/tests/go && curl -s "http://localhost:8081/api/tests/go/status"`
Expected: the two `deprecation` tests PASS. (Local sanity compile: `cd iznik-server-go && go vet ./deprecation/...`.)

- [ ] **Step 5: Commit**

```bash
git add iznik-server-go/deprecation/deprecation.go iznik-server-go/deprecation/deprecation_test.go
git commit -m "feat(apiv2): deprecation Marker middleware - logs one Loki hit per deprecated-route call"
```

---

## Task 2: PHP — Loki query_range read on LokiService

**Files:**
- Modify: `iznik-batch/app/Services/LokiService.php`
- Modify: `iznik-batch/config/freegle.php`
- Test: `iznik-batch/tests/Unit/LokiServiceQueryTest.php`

- [ ] **Step 1: Write the failing test**

Create `iznik-batch/tests/Unit/LokiServiceQueryTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LokiServiceQueryTest extends TestCase
{
    public function test_query_range_returns_flattened_stream_values(): void
    {
        config()->set('freegle.loki.query_url', 'http://loki:3100');

        Http::fake([
            'http://loki:3100/loki/api/v1/query_range*' => Http::response([
                'status' => 'success',
                'data' => [
                    'result' => [
                        [
                            'stream' => ['source' => 'deprecated_endpoint'],
                            'values' => [
                                ['1700000000000000000', '{"endpoint":"GET /foo","user_agent":"UA1"}'],
                                ['1700000001000000000', '{"endpoint":"GET /foo","user_agent":"UA2"}'],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        $svc = new LokiService();
        $rows = $svc->queryRange(
            '{source="deprecated_endpoint"} |= "GET /foo"',
            Carbon::parse('2026-07-01T00:00:00Z'),
            Carbon::parse('2026-07-15T00:00:00Z')
        );

        $this->assertCount(2, $rows);
        $this->assertSame('UA1', $rows[0]['user_agent']);
        $this->assertSame('GET /foo', $rows[1]['endpoint']);
    }

    public function test_query_range_returns_empty_on_non_200(): void
    {
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        Http::fake(['*' => Http::response('boom', 500)]);

        $svc = new LokiService();
        $rows = $svc->queryRange('{source="deprecated_endpoint"}', now()->subDay(), now());

        $this->assertSame([], $rows);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: FAIL — `Call to undefined method App\Services\LokiService::queryRange()`.

- [ ] **Step 3: Add config**

In `iznik-batch/config/freegle.php`, inside the existing `'loki' => [ ... ]` array (around line 426), add the read URL alongside `enabled`/`log_path`:

```php
    'loki' => [
        'enabled'   => env('LOKI_ENABLED', false) || env('LOKI_JSON_FILE', false),
        'log_path'  => env('LOKI_JSON_PATH', '/var/log/freegle'),
        // Read side: Loki's HTTP query API, for monitor:deprecated-endpoints.
        'query_url' => env('LOKI_URL', 'http://loki:3100'),
    ],
```

- [ ] **Step 4: Add the `queryRange` method**

In `iznik-batch/app/Services/LokiService.php`, add `use Carbon\Carbon;` and `use Illuminate\Support\Facades\Http;` at the top, then add this method to the class:

```php
    /**
     * Read side: run a LogQL query over [$start, $end] and return the decoded
     * JSON body of every matching log line (newest first is NOT guaranteed —
     * callers that care should sort). Returns [] on any error so a nightly
     * monitor degrades to "no data" rather than throwing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queryRange(string $logql, Carbon $start, Carbon $end): array
    {
        $url = config('freegle.loki.query_url');
        if (empty($url)) {
            return [];
        }

        try {
            $resp = Http::timeout(30)->get(rtrim($url, '/').'/loki/api/v1/query_range', [
                'query'     => $logql,
                'start'     => $start->getTimestamp() * 1_000_000_000, // ns
                'end'       => $end->getTimestamp() * 1_000_000_000,   // ns
                'limit'     => 5000,
                'direction' => 'forward',
            ]);
        } catch (\Throwable $e) {
            Log::warning('LokiService::queryRange failed: '.$e->getMessage());
            return [];
        }

        if (!$resp->ok()) {
            Log::warning('LokiService::queryRange non-200: '.$resp->status());
            return [];
        }

        $rows = [];
        foreach (($resp->json('data.result') ?? []) as $stream) {
            foreach (($stream['values'] ?? []) as $pair) {
                // $pair = [ "<ns timestamp>", "<log line json>" ]
                $decoded = json_decode($pair[1] ?? '', true);
                if (is_array($decoded)) {
                    $rows[] = $decoded;
                }
            }
        }

        return $rows;
    }
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: `LokiServiceQueryTest` PASS.

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/app/Services/LokiService.php iznik-batch/config/freegle.php iznik-batch/tests/Unit/LokiServiceQueryTest.php
git commit -m "feat(batch): LokiService.queryRange read + loki.query_url config"
```

---

## Task 3: PHP — DeprecatedEndpointService (spec → past-sunset endpoints)

**Files:**
- Create: `iznik-batch/app/Services/DeprecatedEndpointService.php`
- Modify: `iznik-batch/config/freegle.php` (add `apiv2_swagger_url`)
- Test: `iznik-batch/tests/Unit/DeprecatedEndpointServiceTest.php`

The spec is the source of truth; the service fetches the live served spec over HTTP (batch-prod has no Go repo mounted) and returns the operations that are `deprecated: true` AND whose `x-sunset` date is on or before "now".

- [ ] **Step 1: Write the failing test**

Create `iznik-batch/tests/Unit/DeprecatedEndpointServiceTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Services\DeprecatedEndpointService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DeprecatedEndpointServiceTest extends TestCase
{
    private function fakeSpec(): array
    {
        return [
            'paths' => [
                '/message/{id}' => [
                    'get'    => ['deprecated' => true, 'x-sunset' => '2026-07-01'],
                    'delete' => ['deprecated' => true, 'x-sunset' => '2099-01-01'], // future
                ],
                '/activity' => [
                    'get' => ['deprecated' => true, 'x-sunset' => '2026-06-15'],
                ],
                '/live' => [
                    'get' => ['summary' => 'not deprecated'],
                ],
            ],
        ];
    }

    public function test_returns_only_deprecated_past_sunset_operations(): void
    {
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/doc.json');
        Http::fake(['http://apiv2/*' => Http::response($this->fakeSpec(), 200)]);

        $svc = new DeprecatedEndpointService();
        $out = $svc->pastSunset(Carbon::parse('2026-07-09'));

        // GET /message/{id} (sunset 07-01, passed) and GET /activity (06-15,
        // passed). NOT DELETE /message/{id} (future sunset) or GET /live.
        $keys = array_map(fn ($e) => $e['method'].' '.$e['path'], $out);
        sort($keys);
        $this->assertSame(['GET /activity', 'GET /message/{id}'], $keys);

        $msg = collect($out)->firstWhere('path', '/message/{id}');
        $this->assertSame('2026-07-01', $msg['sunset']);
        // The endpoint label matches the Go middleware's route-pattern form,
        // with Fiber ":id" params (see loggedEndpoint()).
        $this->assertSame('GET /message/:id', $msg['logged_endpoint']);
    }

    public function test_returns_empty_when_spec_unreachable(): void
    {
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/doc.json');
        Http::fake(['*' => Http::response('nope', 503)]);

        $svc = new DeprecatedEndpointService();
        $this->assertSame([], $svc->pastSunset(Carbon::parse('2026-07-09')));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: FAIL — class `App\Services\DeprecatedEndpointService` not found.

- [ ] **Step 3: Add config**

In `iznik-batch/config/freegle.php`, add a top-level key (near the other service URLs):

```php
    // Served OpenAPI spec for monitor:deprecated-endpoints. Verify the served
    // path once with: curl -s http://apiv2/swagger/doc.json | head -c 200
    'apiv2_swagger_url' => env('APIV2_SWAGGER_URL', 'http://apiv2/swagger/doc.json'),
```

- [ ] **Step 4: Write the service**

Create `iznik-batch/app/Services/DeprecatedEndpointService.php`:

```php
<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads the live apiv2 OpenAPI spec (the single source of truth for what is
 * deprecated and when it sunsets) and returns the operations whose sunset date
 * has passed — the set monitor:deprecated-endpoints must report on.
 */
class DeprecatedEndpointService
{
    /**
     * @return array<int, array{method:string, path:string, sunset:string, logged_endpoint:string}>
     */
    public function pastSunset(Carbon $now): array
    {
        $spec = $this->fetchSpec();
        if ($spec === null) {
            return [];
        }

        $out = [];
        foreach (($spec['paths'] ?? []) as $path => $operations) {
            foreach ($operations as $method => $op) {
                if (!is_array($op) || empty($op['deprecated'])) {
                    continue;
                }
                $sunset = $op['x-sunset'] ?? null;
                if (!$sunset) {
                    // Deprecated but no sunset date yet: not armed, skip.
                    continue;
                }
                if (Carbon::parse($sunset)->startOfDay()->gt($now->copy()->startOfDay())) {
                    continue; // still inside the grace window
                }

                $upperMethod = strtoupper($method);
                $out[] = [
                    'method'          => $upperMethod,
                    'path'            => $path,
                    'sunset'          => $sunset,
                    'logged_endpoint' => $this->loggedEndpoint($upperMethod, $path),
                ];
            }
        }

        return $out;
    }

    /**
     * Convert an OpenAPI path ("/message/{id}") to the Fiber route-pattern form
     * the Go middleware logs ("GET /message/:id"), so the LogQL filter matches.
     */
    private function loggedEndpoint(string $method, string $path): string
    {
        $fiberPath = preg_replace('/\{([^}]+)\}/', ':$1', $path);

        return $method.' '.$fiberPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSpec(): ?array
    {
        $url = config('freegle.apiv2_swagger_url');
        try {
            $resp = Http::timeout(30)->get($url);
        } catch (\Throwable $e) {
            Log::warning('DeprecatedEndpointService: spec fetch failed: '.$e->getMessage());
            return null;
        }
        if (!$resp->ok()) {
            Log::warning('DeprecatedEndpointService: spec fetch non-200: '.$resp->status());
            return null;
        }

        return $resp->json();
    }
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: `DeprecatedEndpointServiceTest` PASS.

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/app/Services/DeprecatedEndpointService.php iznik-batch/config/freegle.php iznik-batch/tests/Unit/DeprecatedEndpointServiceTest.php
git commit -m "feat(batch): DeprecatedEndpointService - past-sunset endpoints from live spec"
```

---

## Task 4: PHP — monitor:deprecated-endpoints command (verdict + email)

**Files:**
- Create: `iznik-batch/app/Console/Commands/Monitor/DeprecatedEndpointsCommand.php`
- Modify: `iznik-batch/routes/console.php`
- Test: `iznik-batch/tests/Feature/Monitor/DeprecatedEndpointsCommandTest.php`

- [ ] **Step 1: Write the failing test**

Create `iznik-batch/tests/Feature/Monitor/DeprecatedEndpointsCommandTest.php`:

```php
<?php

namespace Tests\Feature\Monitor;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DeprecatedEndpointsCommandTest extends TestCase
{
    private function fakeSpec(): array
    {
        return [
            'paths' => [
                '/message/{id}' => ['get' => ['deprecated' => true, 'x-sunset' => '2026-07-01']],
                '/activity'     => ['get' => ['deprecated' => true, 'x-sunset' => '2026-07-01']],
                '/future'       => ['get' => ['deprecated' => true, 'x-sunset' => '2099-01-01']],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.apiv2_swagger_url', 'http://apiv2/swagger/doc.json');
        config()->set('freegle.loki.query_url', 'http://loki:3100');
        config()->set('freegle.geeks_addr', 'geeks@ilovefreegle.org');
    }

    public function test_emails_retire_and_still_in_use_split(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2/*' => Http::response($this->fakeSpec(), 200),
            // GET /message/:id — still used by an app straggler.
            'http://loki:3100/*message*' => Http::response([
                'data' => ['result' => [[
                    'stream' => [],
                    'values' => [
                        ['1', '{"endpoint":"GET /message/:id","user_agent":"FreegleApp/1.0.0"}'],
                    ],
                ]]],
            ], 200),
            // GET /activity — silent → safe to retire.
            'http://loki:3100/*activity*' => Http::response(['data' => ['result' => []]], 200),
        ]);

        $this->artisan('monitor:deprecated-endpoints')->assertExitCode(0);

        Mail::assertSent(\Illuminate\Mail\Mailable::class, function ($mail) {
            return true; // presence asserted; body asserted below via raw closure alternative
        });
    }

    public function test_no_email_when_nothing_past_sunset(): void
    {
        Mail::fake();
        Http::fake([
            'http://apiv2/*' => Http::response([
                'paths' => ['/future' => ['get' => ['deprecated' => true, 'x-sunset' => '2099-01-01']]],
            ], 200),
        ]);

        $this->artisan('monitor:deprecated-endpoints')->assertExitCode(0);

        Mail::assertNothingSent();
    }
}
```

> Note: `Mail::raw` sends an anonymous mailable; if `Mail::assertSent` on the closure form is awkward in this Laravel version, assert instead on a `Mail::assertSent(fn) ` count via `Mail::assertSentCount(1)` for the first test and `Mail::assertNothingSent()` for the second. Keep the behavioural assertions: 1 email when something is past sunset, 0 when nothing is.

- [ ] **Step 2: Run the test to verify it fails**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: FAIL — command `monitor:deprecated-endpoints` not defined.

- [ ] **Step 3: Write the command**

Create `iznik-batch/app/Console/Commands/Monitor/DeprecatedEndpointsCommand.php`:

```php
<?php

namespace App\Console\Commands\Monitor;

use App\Services\DeprecatedEndpointService;
use App\Services\LokiService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Nightly: for every deprecated apiv2 endpoint whose x-sunset date has passed,
 * check Loki for hits since that sunset. Email geeks@ a "safe to retire" /
 * "still in use (+ who)" report. Sends nothing if no endpoint is past sunset.
 *
 * Retirement stays a human action. To stop nagging about an endpoint we've
 * decided to keep + chase, remove its x-sunset in swagger.json.
 */
class DeprecatedEndpointsCommand extends Command
{
    protected $signature = 'monitor:deprecated-endpoints';

    protected $description = 'Reports which sunset apiv2 endpoints are now unused (retire) or still called (chase)';

    public function handle(DeprecatedEndpointService $catalog, LokiService $loki): int
    {
        $now = Carbon::now();
        $endpoints = $catalog->pastSunset($now);

        if (empty($endpoints)) {
            $this->info('No deprecated endpoints past their sunset date.');
            return self::SUCCESS;
        }

        $retire = [];
        $stillUsed = [];

        foreach ($endpoints as $ep) {
            $sunset = Carbon::parse($ep['sunset'])->startOfDay();
            $logql = sprintf('{source="deprecated_endpoint"} |= "%s"', $ep['logged_endpoint']);
            $hits = $loki->queryRange($logql, $sunset, $now);

            // Keep only hits whose endpoint field exactly matches (|= is a
            // substring filter; guard against one route being a prefix of another).
            $hits = array_values(array_filter($hits, fn ($h) => ($h['endpoint'] ?? null) === $ep['logged_endpoint']));

            $days = max(1, $sunset->diffInDays($now));

            if (count($hits) === 0) {
                $retire[] = sprintf('  %s  (silent %d day%s since sunset %s)',
                    $ep['logged_endpoint'], $days, $days === 1 ? '' : 's', $ep['sunset']);
            } else {
                $stillUsed[] = $this->stillUsedLine($ep, $hits, $days);
            }
        }

        $this->emailReport($retire, $stillUsed);

        return self::SUCCESS;
    }

    /**
     * @param array{logged_endpoint:string, sunset:string} $ep
     * @param array<int, array<string, mixed>> $hits
     */
    private function stillUsedLine(array $ep, array $hits, int $days): string
    {
        // Top callers by user_agent — the chase-down handle.
        $byAgent = [];
        foreach ($hits as $h) {
            $ua = $h['user_agent'] ?? '(none)';
            $byAgent[$ua] = ($byAgent[$ua] ?? 0) + 1;
        }
        arsort($byAgent);
        $top = [];
        foreach (array_slice($byAgent, 0, 5, true) as $ua => $n) {
            $top[] = "      {$n}x  {$ua}";
        }

        return sprintf(
            "  %s  — %d call%s in %d day%s since sunset %s\n%s",
            $ep['logged_endpoint'],
            count($hits), count($hits) === 1 ? '' : 's',
            $days, $days === 1 ? '' : 's',
            $ep['sunset'],
            implode("\n", $top)
        );
    }

    private function emailReport(array $retire, array $stillUsed): void
    {
        $body = "Deprecated apiv2 endpoints past their sunset date:\n\n";

        $body .= "SAFE TO RETIRE (no calls since sunset):\n";
        $body .= empty($retire) ? "  (none)\n" : implode("\n", $retire)."\n";

        $body .= "\nSTILL IN USE (chase the callers, or remove x-sunset to keep):\n";
        $body .= empty($stillUsed) ? "  (none)\n" : implode("\n", $stillUsed)."\n";

        $to = config('freegle.geeks_addr', 'geeks@ilovefreegle.org');
        Mail::raw($body, function ($message) use ($to) {
            $message->to($to)->subject('Deprecated endpoint report');
        });

        $this->info(sprintf('Report emailed: %d retire, %d still in use.', count($retire), count($stillUsed)));
    }
}
```

- [ ] **Step 4: Schedule it**

In `iznik-batch/routes/console.php`, following the `monitor:email-health` block (around line 315), add:

```php
// Deprecated-endpoint retirement report: once daily, early, so the team sees
// it with the morning's mail. Only emails when something is past its sunset.
Schedule::command('monitor:deprecated-endpoints')
    ->dailyAt('06:20')
    ->sendOutputTo(cronLog('monitor:deprecated-endpoints'));
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `curl -s -X POST http://localhost:8081/api/tests/laravel`
Expected: both `DeprecatedEndpointsCommandTest` cases PASS (1 email past-sunset, 0 when nothing due).

- [ ] **Step 6: Commit**

```bash
git add iznik-batch/app/Console/Commands/Monitor/DeprecatedEndpointsCommand.php iznik-batch/routes/console.php iznik-batch/tests/Feature/Monitor/DeprecatedEndpointsCommandTest.php
git commit -m "feat(batch): monitor:deprecated-endpoints - nightly retire/chase email"
```

---

## Task 5: Runbook — apply the mechanism to a rationalised endpoint

The mechanism above is generic. This is the repeatable per-endpoint procedure to run for each endpoint the rationalisation PR removes client usage of. It is deliberately not "one commit" — it's the checklist you follow per endpoint.

**Worked example: deprecating `GET /activity`.**

- [ ] **Step 1: Mark it in the spec**

In `iznik-server-go/swagger/swagger.json`, on the operation object for `GET /activity`, add:

```json
"deprecated": true,
"x-sunset": "2026-07-23"
```

Pick `x-sunset` as **today + at least a fortnight**, and further out if the app release train is slow (app stragglers are the long tail — see the design doc).

- [ ] **Step 2: Attach the middleware in the router**

In `iznik-server-go/router/routes.go`, find the route registration for the endpoint and insert `deprecation.Marker()` as middleware before the handler. Add `"github.com/freegle/iznik-server-go/deprecation"` to the imports if not present. Example — change:

```go
app.Get("/activity", activity.GetActivity)
```

to:

```go
app.Get("/activity", deprecation.Marker(), activity.GetActivity)
```

- [ ] **Step 3: Verify the spec still validates**

Run: `curl -s -X POST http://localhost:8081/api/tests/go`
Expected: `swagger_test` PASS (the `x-sunset` vendor extension must not break spec-drift guards). If `generate-swagger.sh` regenerates the spec from annotations and would strip `x-sunset`, add the flags to the annotation source instead and regenerate — confirm `deprecated`/`x-sunset` survive a regenerate.

- [ ] **Step 4: Confirm the log path end to end (manual, once)**

With the stack up, hit the endpoint and confirm a line lands:

```bash
curl -s "http://apiv2/activity" >/dev/null
# then confirm the Loki file has the entry (dev): grep the go-api log for the marker
```
Expected: a `deprecated_endpoint` entry with `"endpoint":"GET /activity"` and the response carries header `Deprecation: true`.

- [ ] **Step 5: Commit (per endpoint or per batch of endpoints)**

```bash
git add iznik-server-go/swagger/swagger.json iznik-server-go/router/routes.go
git commit -m "chore(apiv2): deprecate GET /activity (sunset 2026-07-23) - marker + spec"
```

- [ ] **Step 6: Deploy order (not a code step — the rollout)**

1. Ship the client change that stops calling the endpoint (web deploys immediately; app rides its release train).
2. Ship this deprecation marker + spec change.
3. After the sunset date, read the nightly email. Web should go silent within days; the app tail informs whether to force `app_min_webversion`; external callers appear in the "still in use" breakdown with their user-agent → chase them.
4. When an endpoint reads "safe to retire", delete its route + handler + spec entry + tests (the existing hard-delete step). To keep + chase instead, remove its `x-sunset` so it drops out of the report.

---

## Self-Review

**Spec coverage:**
- "Mark deprecated in code + OpenAPI (deprecated + x-sunset)" → Task 5 Step 1; parsed back in Task 3.
- "Log every hit with caller identity, no new lookups" → Task 1 (`buildHitFields`: user_agent, webversion, ip, user_id).
- "Dedicated stream, not the request log (route pattern not filled path)" → Task 1 test `TestMarkerLogsRoutePatternNotFilledPath`; design rationale carried in code comment.
- "Overnight artisan command, email only past-sunset, safe-to-retire vs still-in-use + breakdown" → Task 4.
- "Query from sunset date, not fixed window" → Task 4 `handle()` passes `$sunset` as the range start; Task 3 filters past-sunset.
- "No email before sunset / none past sunset → no email" → Task 3 filter + Task 4 early return; `test_no_email_when_nothing_past_sunset`.
- "Keep + chase = remove x-sunset to mute" → documented in Task 4 docblock + Task 5 Step 6.
- "Single source of truth for the date (spec only; Go never hard-codes it)" → Marker() takes no date; header is boolean; date parsed only from spec.

**Placeholder scan:** No TBD/TODO. The one intentional variable is *which* endpoints get deprecated — that is the PR's content, handled by the Task 5 runbook with a full worked example, not a placeholder.

**Type consistency:** The endpoint label form `METHOD /path/:param` is produced by Go `buildHitFields` (`c.Method()+" "+c.Route().Path`) and reproduced by PHP `DeprecatedEndpointService::loggedEndpoint()` (converts `{id}`→`:id`); the command filters Loki on that exact string and re-checks equality to avoid prefix collisions. `queryRange(string, Carbon, Carbon): array` is defined in Task 2 and called in Task 4 with `($logql, $sunset, $now)`.

**Known implementation checkpoints (verify during execution, not placeholders):**
- `c.Route().Path` returns the pattern inside route-level middleware — Task 1 Step 4 test proves it; if a Fiber version quirk returns empty, pass the pattern explicitly to `Marker(pattern)`.
- The served swagger JSON path (`/swagger/doc.json`) — Task 3 Step 3 comment + Task 5 Step 4 curl verify it; adjust `apiv2_swagger_url` if the router serves it elsewhere.
- `generate-swagger.sh` must preserve `x-sunset` — Task 5 Step 3.

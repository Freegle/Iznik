<?php

namespace App\Services;

use App\Services\WorkerPool\BoundedPool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Service for compiling MJML templates to HTML.
 *
 * Two engines (config('services.mjml.engine')):
 *   - 'mrml' (default): compiles in-process via the bundled mrml PHP extension
 *     (Rust reimplementation of MJML). No HTTP, no sidecar, no Redis
 *     back-pressure pool — each call is a local function call (~ms). The
 *     extension is built into the batch image (see iznik-batch/docker/Dockerfile)
 *     with the css-inline feature so <mj-style inline> rules are inlined onto
 *     elements (required for Gmail).
 *   - 'node': legacy path — POSTs to the adrianrudnik/mjml-server sidecar over
 *     HTTP, guarded by a BoundedPool for back-pressure. Kept as a fallback and
 *     for differential (node-vs-mrml) rendering tests.
 */
class MjmlCompilerService
{
    private ?BoundedPool $pool = null;

    private string $engine;

    public function __construct()
    {
        $this->engine = config('services.mjml.engine', 'mrml');

        // The BoundedPool (Redis BLPOP) only exists to throttle load against the
        // shared HTTP sidecar. In-process mrml has no shared resource to bound,
        // so we only spin it up for the node engine.
        if ($this->engine === 'node') {
            $this->pool = new BoundedPool(
                name: 'mjml',
                maxConcurrency: config('pools.mjml.max', 20),
                timeoutSeconds: config('pools.mjml.timeout', 30),
                sentryThrottleSeconds: config('pools.mjml.sentry_throttle', 300)
            );
            $this->pool->initialize();
        }
    }

    /**
     * Compile MJML to HTML.
     *
     * @throws \RuntimeException If compilation fails
     */
    public function compile(string $mjml): string
    {
        return $this->engine === 'node'
            ? $this->compileWithNode($mjml)
            : $this->compileWithMrml($mjml);
    }

    /**
     * In-process compile via the mrml PHP extension.
     *
     * @throws \RuntimeException If the extension is missing or compilation fails.
     */
    private function compileWithMrml(string $mjml): string
    {
        if (! extension_loaded('mjml')) {
            // Fail loud: a misbuilt image (extension absent) must not silently
            // ship broken email. Operators can set MJML_ENGINE=node to fall
            // back to the sidecar.
            throw new \RuntimeException(
                'mrml: the "mjml" PHP extension is not loaded; cannot compile MJML in-process. '
                .'Rebuild the batch image or set MJML_ENGINE=node.'
            );
        }

        try {
            // Default options keep MSO conditional comments (Outlook). CSS
            // inlining of <mj-style inline> rules is enabled at build time via
            // the mrml css-inline feature, not a render option.
            $html = (new \Mjml\Mjml())->render($mjml)->getBody();
        } catch (\Throwable $e) {
            Log::error('MJML compilation failed (mrml)', ['error' => $e->getMessage()]);
            throw new \RuntimeException('MJML compilation failed: '.$e->getMessage(), 0, $e);
        }

        if (empty(trim($html))) {
            throw new \RuntimeException('MJML compilation returned empty HTML');
        }

        return $html;
    }

    /**
     * Legacy compile via the Node mjml-server sidecar over HTTP.
     *
     * Blocks if all pool permits are in use (back pressure).
     *
     * @throws \RuntimeException If compilation fails
     */
    private function compileWithNode(string $mjml): string
    {
        return $this->pool->withPermit(function () use ($mjml) {
            $url = config('services.mjml.url');

            // adrianrudnik/mjml-server expects raw MJML text with Content-Type: text/plain
            // and returns raw HTML (not JSON)
            $response = Http::timeout(config('services.mjml.http_timeout', 30))
                ->withBody($mjml, 'text/plain')
                ->post($url);

            if (! $response->successful()) {
                Log::error('MJML compilation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException(
                    'MJML compilation failed: '.$response->body()
                );
            }

            $html = $response->body();

            if (empty(trim($html))) {
                throw new \RuntimeException('MJML compilation returned empty HTML');
            }

            return $html;
        });
    }

    /**
     * Get pool statistics for monitoring. Null pool (mrml engine) reports disabled.
     */
    public function getPoolStats(): array
    {
        return $this->pool?->getStats() ?? ['engine' => $this->engine, 'pool' => 'disabled'];
    }
}

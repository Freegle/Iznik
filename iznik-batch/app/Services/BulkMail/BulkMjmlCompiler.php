<?php

namespace App\Services\BulkMail;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\MjmlMailable;
use App\Services\MjmlCompilerService;
use Illuminate\Support\Facades\Log;

/**
 * Compile-once / substitute-many primitive for batch MJML mailables.
 *
 * Caches the compiled HTML body of a mailable's template per "shape" (a bucket
 * identifier produced by the mailable's BulkRenderable::shapeKey()) for the
 * lifetime of this service instance. For every recipient that shares a shape,
 * we make one MJML sidecar call and N cheap string substitutions.
 *
 * Cache is process-local. Use clear() between independent send batches if you
 * want to bound memory; the service is also typically instantiated fresh per
 * send loop via the container.
 *
 * Placeholder convention: literal {{varname}} in the compiled HTML, substituted
 * via str_replace. Survives MJML compile in text content and href/src/alt
 * attributes — verified against the live mjml sidecar 2026-05-27. Do not put
 * placeholders inside <style> blocks or style="" attributes: MJML's CSS inliner
 * rewrites those.
 *
 * Same pattern as AWS SES SendBulkEmail / Postmark Bulk API / Mailchimp merge
 * tags — see the design doc for references.
 */
class BulkMjmlCompiler
{
    /** @var array<string,string> shapeKey → compiled HTML with {{vars}} */
    private array $cache = [];

    /** @var array<string,int> shapeKey → number of recipients served (telemetry only) */
    private array $hitCount = [];

    /** @var int Number of times the sidecar was actually called */
    private int $compileCount = 0;

    public function __construct(
        private readonly MjmlCompilerService $mjml
    ) {}

    /**
     * Render the recipient's HTML body.
     *
     * On a cache miss for the mailable's shapeKey(), runs the mailable's
     * template + MJML compile and stores the result. Then substitutes the
     * mailable's mergeVars() into the cached HTML and returns the final body.
     *
     * The caller is expected to call MjmlMailable::setPrerenderedHtml() with
     * the returned string before Mail::send(), so the mailable's build()
     * short-circuits and uses this HTML instead of re-running the pipeline.
     *
     * @throws UnboundPlaceholderException If the cached HTML contains
     *         placeholders not covered by the mailable's mergeVars().
     * @throws \RuntimeException If MJML compilation fails.
     */
    public function htmlFor(MjmlMailable&BulkRenderable $mailable): string
    {
        $shape = $mailable->shapeKey();

        if (!isset($this->cache[$shape])) {
            $this->cache[$shape] = $this->renderTemplate($mailable);
            $this->hitCount[$shape] = 0;
            $this->compileCount++;
        }

        $this->hitCount[$shape]++;

        return $this->substitute(
            $this->cache[$shape],
            $mailable->mergeVars(),
            static::class.' (template of '.$mailable::class.')'
        );
    }

    /**
     * Substitute {{name}} placeholders in compiled HTML.
     *
     * Values are HTML-escaped (htmlspecialchars with ENT_QUOTES|ENT_HTML5)
     * to match Blade's {{ $var }} behaviour. Pass URLs with plain & — they
     * become &amp; in the output, same as Blade would render them.
     *
     * For raw HTML or pre-escaped values, pre-encode the value and the escape
     * will be a no-op for already-encoded chars. (htmlspecialchars without
     * the double_encode=false flag does double-encode; the cleaner pattern is
     * to keep merge values as plain-text and let this method encode them.)
     *
     * @param array<string,mixed> $vars
     */
    public function substitute(string $html, array $vars, string $context = ''): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            // Match Blade's e() helper exactly — same flag set Laravel uses
            // for {{ $var }} interpolation — so the bulk-substituted output
            // is byte-identical to what Blade would have produced for the
            // same value.
            $replacements['{{'.$key.'}}'] = htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5,
                'UTF-8'
            );
        }

        $result = strtr($html, $replacements);

        // Fail-loud on any remaining placeholders. If a placeholder slips
        // through to a sent email it appears as visible garbage to the
        // recipient. Better to crash the send loop than ship broken output.
        if (preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $result, $matches)) {
            $unique = array_values(array_unique($matches[1]));
            Log::error('BulkMjmlCompiler: unbound placeholders', [
                'context' => $context,
                'missing' => $unique,
            ]);
            throw new UnboundPlaceholderException($unique, $context);
        }

        return $result;
    }

    /**
     * Render the mailable's bulk template ONCE, capturing HTML with literal
     * {{name}} placeholders for per-recipient fields. The mailable supplies
     * both the template path and the bulk data (with placeholders for
     * per-recipient values in place of real values).
     */
    private function renderTemplate(MjmlMailable&BulkRenderable $mailable): string
    {
        return $mailable->renderHtmlForBulkCache(
            $mailable->bulkTemplate(),
            $mailable->bulkData()
        );
    }

    /**
     * Clear the cache. Typical use: between independent send batches in a
     * long-running daemon process, to bound memory.
     */
    public function clear(): void
    {
        $this->cache = [];
        $this->hitCount = [];
        $this->compileCount = 0;
    }

    /**
     * Telemetry: number of distinct shapes compiled in this instance's lifetime.
     */
    public function compileCount(): int
    {
        return $this->compileCount;
    }

    /**
     * Telemetry: number of recipients served per shape since last clear().
     *
     * @return array<string,int>
     */
    public function hitCounts(): array
    {
        return $this->hitCount;
    }
}

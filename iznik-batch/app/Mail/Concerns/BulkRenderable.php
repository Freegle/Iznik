<?php

namespace App\Mail\Concerns;

/**
 * Opt-in contract for mailables that can share a compiled MJML body across
 * multiple recipients.
 *
 * The trick: per-recipient values are passed to Blade as literal {{name}}
 * placeholder strings (in bulkData()). Blade's {{ $var }} interpolation does
 * NOT escape curly braces (htmlspecialchars only touches & " ' < >), so the
 * placeholders pass through Blade unchanged. MJML compile passes them through
 * unchanged. After compile, BulkMjmlCompiler::substitute() replaces them with
 * the recipient's real values from mergeVars().
 *
 * The Blade template itself does NOT change. Templates work the same way
 * whether rendered for bulk (placeholder strings as inputs) or normal sends
 * (real values as inputs). The mailable controls the mode via which data it
 * supplies.
 *
 * Implementors declare:
 *   - shapeKey(): identifies recipients that share compiled HTML. Must include
 *     every structural input that changes the rendered template (@if branches,
 *     loop bodies, ...). A change in any of these must produce a different key.
 *   - bulkData(): the data array passed to the Blade template for the SHARED
 *     compile. Per-recipient fields take literal placeholder strings like
 *     "{{messageUrl}}"; shared fields take their real values.
 *   - mergeVars(): per-recipient values keyed by placeholder name. Keys must
 *     match the placeholders used in bulkData().
 *
 * Values are HTML-encoded automatically before substitution (htmlspecialchars
 * with ENT_QUOTES|ENT_HTML5) — same convention Blade {{ $var }} uses. Pass
 * URLs with plain & (not &amp;); they become &amp; in the output.
 */
interface BulkRenderable
{
    /**
     * Stable identifier for the cache bucket this recipient falls into.
     */
    public function shapeKey(): string;

    /**
     * Blade template path to render for the cached compile (e.g.
     * 'emails.mjml.digest.unified').
     */
    public function bulkTemplate(): string;

    /**
     * Data array for the SHARED Blade render. Per-recipient fields take
     * literal "{{name}}" placeholder strings; shared fields take real values.
     *
     * Will be merged into the data array set on the mailable for the bulk
     * compile pass. Subclasses should match the variable names their existing
     * Blade template expects.
     *
     * @return array<string,mixed>
     */
    public function bulkData(): array;

    /**
     * Per-recipient substitutions keyed by placeholder name. Keys must match
     * the "{{name}}" placeholders that appear in bulkData() and end up in the
     * compiled MJML output. Values are cast to string and HTML-encoded.
     *
     * @return array<string,mixed>
     */
    public function mergeVars(): array;
}

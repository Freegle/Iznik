<?php

namespace Tests\Feature\Mail;

use App\Services\MjmlCompilerService;
use Tests\TestCase;

/**
 * Differential rendering: mrml (new, in-process) vs node (legacy sidecar).
 *
 * Layer B of the mrml test strategy. The node engine is the rendering we
 * already trust in production, so for a representative set of MJML structures
 * we compile through BOTH engines and assert mrml does not silently DROP or
 * MANGLE content relative to node:
 *   - the same content URLs (links + images) appear in both outputs
 *   - MSO conditional comments are present in both when the structure needs them
 *   - <mj-style inline> rules are inlined by both
 *
 * The comparison is semantic, not byte-for-byte: mrml and node legitimately
 * differ in whitespace, attribute order and doctype. We compare extracted,
 * normalised URL sets and structural flags — the things whose loss would be a
 * real regression. A side-by-side report is written for human (Layer D) review.
 *
 * Requires the node mjml sidecar (config services.mjml.url) AND the in-process
 * mrml extension — both present in the batch test environment.
 */
class MjmlEngineDifferentialTest extends TestCase
{
    /** Representative structures mirroring the real templates' feature matrix. */
    private function samples(): array
    {
        return [
            // The exact inline rule + link pattern every email carries via
            // partials/head.blade.php.
            'inline-link' => <<<'MJML'
<mjml><mj-head><mj-style inline="inline">a { color: #338808; text-decoration: none; font-weight: bold }</mj-style></mj-head>
<mj-body><mj-section><mj-column><mj-text><a href="https://www.ilovefreegle.org/message/57?reply=1">Reply</a></mj-text></mj-column></mj-section></mj-body></mjml>
MJML,
            // mj-button → MSO conditional table wrapper (Outlook).
            'button' => <<<'MJML'
<mjml><mj-body><mj-section><mj-column><mj-button href="https://www.ilovefreegle.org/browse">Browse other posts</mj-button></mj-column></mj-section></mj-body></mjml>
MJML,
            // mj-image with a delivery URL.
            'image' => <<<'MJML'
<mjml><mj-body><mj-section><mj-column><mj-image src="https://images.ilovefreegle.org/timg_1.jpg" alt="Sofa" /></mj-column></mj-section></mj-body></mjml>
MJML,
            // Multi-column card (digest-style) + entity in text.
            'multicol-entity' => <<<'MJML'
<mjml><mj-body><mj-section><mj-column width="38%"><mj-image src="https://images.ilovefreegle.org/timg_2.jpg" alt="x" /></mj-column>
<mj-column width="62%"><mj-text>Coffee &amp; Cake</mj-text><mj-button href="https://www.ilovefreegle.org/message/99">Reply</mj-button></mj-column></mj-section></mj-body></mjml>
MJML,
            // Non-inline mj-style with a media query.
            'media' => <<<'MJML'
<mjml><mj-head><mj-style>@media only screen and (max-width:480px){ .c{width:100%!important} }</mj-style></mj-head>
<mj-body><mj-section><mj-column><mj-text>hi</mj-text></mj-column></mj-section></mj-body></mjml>
MJML,
        ];
    }

    private function compileWith(string $engine, string $mjml): string
    {
        config(['services.mjml.engine' => $engine]);

        return (new MjmlCompilerService())->compile($mjml);
    }

    /** Extract normalised content URLs (our domains only — ignore font CDNs). */
    private function contentUrls(string $html): array
    {
        preg_match_all('/(?:href|src)="([^"]+)"/i', $html, $m);
        $urls = array_map(fn ($u) => html_entity_decode($u, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
        $urls = array_filter($urls, fn ($u) => str_contains($u, 'ilovefreegle.org'));
        $urls = array_values(array_unique($urls));
        sort($urls);

        return $urls;
    }

    public function test_both_engines_preserve_content_urls(): void
    {
        foreach ($this->samples() as $name => $mjml) {
            $node = $this->contentUrls($this->compileWith('node', $mjml));
            $mrml = $this->contentUrls($this->compileWith('mrml', $mjml));

            $this->assertSame(
                $node,
                $mrml,
                "Sample '{$name}': mrml content URLs differ from node — a link/image was dropped or mangled."
            );
        }
    }

    public function test_mso_conditional_parity(): void
    {
        foreach ($this->samples() as $name => $mjml) {
            $nodeHasMso = str_contains($this->compileWith('node', $mjml), '<!--[if mso');
            $mrmlHasMso = str_contains($this->compileWith('mrml', $mjml), '<!--[if mso');

            $this->assertSame(
                $nodeHasMso,
                $mrmlHasMso,
                "Sample '{$name}': MSO conditional-comment presence differs between engines (Outlook risk)."
            );
        }
    }

    public function test_both_engines_inline_the_inline_style(): void
    {
        $pattern = '/<a\b[^>]*style="[^"]*color:\s*#338808/i';
        $sample = $this->samples()['inline-link'];

        $this->assertMatchesRegularExpression($pattern, $this->compileWith('node', $sample), 'node should inline');
        $this->assertMatchesRegularExpression($pattern, $this->compileWith('mrml', $sample), 'mrml should inline');
    }

    /**
     * Always-green: emits a side-by-side report (lengths + a head excerpt) for
     * human review of cosmetic differences (Layer D). Stored under storage/logs.
     */
    public function test_emit_comparison_report(): void
    {
        $lines = ['mrml vs node differential report', str_repeat('=', 40), ''];
        foreach ($this->samples() as $name => $mjml) {
            $node = $this->compileWith('node', $mjml);
            $mrml = $this->compileWith('mrml', $mjml);
            $lines[] = sprintf('%-18s node=%5d bytes  mrml=%5d bytes', $name, strlen($node), strlen($mrml));
        }
        $report = implode("\n", $lines)."\n";

        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/mjml-diff-report.txt', $report);

        $this->assertStringContainsString('differential report', $report);
    }
}

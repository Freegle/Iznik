<?php

namespace Tests\Unit\Services;

use App\Services\MjmlCompilerService;
use Tests\TestCase;

/**
 * Engine-level invariants for the in-process mrml compiler.
 *
 * These guard the behaviours that, if broken, silently degrade real emails:
 *   - <mj-style inline> rules are inlined ONTO elements (Gmail strips <head>
 *     <style> in several contexts, so the link colour MUST be inline).
 *   - MSO conditional comments survive (Outlook layout).
 *   - No unbound {{placeholder}} leakage.
 *   - @media (responsive) rules land in a <head> <style> block.
 *
 * Layer C of the mrml test strategy. Layer A is the existing Mail suite run
 * under the default (mrml) engine; Layer B is MjmlEngineDifferentialTest.
 */
class MjmlEngineTest extends TestCase
{
    /**
     * The exact inline rule production ships in
     * resources/views/emails/mjml/partials/head.blade.php — every email
     * includes it. If mrml stops inlining it, every link in every email
     * renders as default-blue in Gmail.
     */
    private const SAMPLE_MJML = <<<'MJML'
<mjml>
  <mj-head>
    <mj-style inline="inline">
      a { color: #338808; text-decoration: none; font-weight: bold }
    </mj-style>
    <mj-style>
      @media only screen and (max-width:480px) { .col { width:100% !important } }
    </mj-style>
  </mj-head>
  <mj-body>
    <mj-section>
      <mj-column>
        <mj-text><a href="https://www.ilovefreegle.org/x">Reply</a></mj-text>
        <mj-button href="https://www.ilovefreegle.org/y">Big button</mj-button>
        <mj-image src="https://images.ilovefreegle.org/z.jpg" alt="thing" />
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
MJML;

    private function mrml(): MjmlCompilerService
    {
        config(['services.mjml.engine' => 'mrml']);

        return new MjmlCompilerService();
    }

    public function test_mrml_extension_is_loaded(): void
    {
        $this->assertTrue(
            extension_loaded('mjml'),
            'The mrml "mjml" PHP extension must be built into the batch image. '
            .'See iznik-batch/docker/Dockerfile (mjml-ext-builder stage).'
        );
    }

    public function test_inline_style_is_applied_to_anchor_for_gmail(): void
    {
        $html = $this->mrml()->compile(self::SAMPLE_MJML);

        $this->assertMatchesRegularExpression(
            '/<a\b[^>]*style="[^"]*color:\s*#338808/i',
            $html,
            'mrml must INLINE <mj-style inline> rules onto <a> elements (Gmail). '
            .'If this fails, the css-inline Cargo feature is not enabled on mrml.'
        );
    }

    public function test_mso_conditional_comments_are_preserved_for_outlook(): void
    {
        $html = $this->mrml()->compile(self::SAMPLE_MJML);

        $this->assertStringContainsString(
            '<!--[if mso',
            $html,
            'mrml must preserve MSO conditional comments for Outlook layout.'
        );
    }

    public function test_media_queries_land_in_a_style_block(): void
    {
        $html = $this->mrml()->compile(self::SAMPLE_MJML);

        $this->assertStringContainsString('@media', $html, 'Responsive @media rules must survive.');
        $this->assertStringContainsString('<style', $html, 'Non-inline styles must remain in a <style> block.');
    }

    public function test_links_and_images_survive_compilation(): void
    {
        $html = $this->mrml()->compile(self::SAMPLE_MJML);

        $this->assertStringContainsString('https://www.ilovefreegle.org/x', $html);
        $this->assertStringContainsString('https://www.ilovefreegle.org/y', $html);
        $this->assertStringContainsString('https://images.ilovefreegle.org/z.jpg', $html);
    }

    public function test_no_unbound_placeholders_leak_through(): void
    {
        // A literal {{...}} reaching the compiled HTML means a merge var was
        // never substituted. Real templates must not emit raw placeholders.
        $html = $this->mrml()->compile(self::SAMPLE_MJML);

        $this->assertDoesNotMatchRegularExpression(
            '/\{\{[a-zA-Z0-9_:.-]+\}\}/',
            $html,
            'Compiled HTML must not contain unbound {{placeholders}}.'
        );
    }

    public function test_invalid_mjml_throws_runtime_exception(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->mrml()->compile('<mjml><mj-body><mj-not-a-tag></mj-body></mjml>');
    }
}

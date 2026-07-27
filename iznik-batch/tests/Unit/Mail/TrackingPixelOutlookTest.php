<?php

namespace Tests\Unit\Mail;

use App\Mail\MjmlMailable;
use App\Mail\Traits\TrackableEmail;
use App\Models\EmailTracking;
use App\Services\MjmlCompilerService;
use Tests\TestCase;

/**
 * Regression tests for the stray inner scrollbar in Outlook Web (topic: every
 * tracked email, reported against the Unified Digest of 2026-07-27).
 *
 * MJML renders <mj-image> as a table stack whose <img> gets width:100%. For a
 * 1x1 tracking pixel that reads to Outlook as a downscaled image, so it
 * injects absolutely-positioned "Show original size" overlay buttons which
 * overhang the end of the body - adding scrollHeight without clientHeight and
 * hence a stray, nearly-immobile inner scrollbar.
 *
 * The fix: getTrackingPixelMjml() emits the plain pixel <img> inside <mj-raw>
 * so the compiled output never carries width:100%. These tests assert on the
 * COMPILED html (via the in-process mrml engine), because that is the layer
 * where the bug lives - the MJML source string is not what Outlook sees.
 */
class TrackingPixelOutlookTest extends TestCase
{
    private function trackedMailable(): MjmlMailable
    {
        $mailable = new class extends MjmlMailable {
            use TrackableEmail;

            public ?EmailTracking $testTracking = null;

            protected function getSubject(): string
            {
                return 'Test Subject';
            }

            public function getTracking(): ?EmailTracking
            {
                return $this->testTracking;
            }
        };

        $mailable->testTracking = new EmailTracking([
            'tracking_id' => 'outlook-scrollbar-regression',
            'email_type' => 'Test',
            'recipient_email' => 'test@example.com',
        ]);

        return $mailable;
    }

    private function compile(string $mjml): string
    {
        config(['services.mjml.engine' => 'mrml']);

        return app(MjmlCompilerService::class)->compile($mjml);
    }

    /**
     * The pixel img in the compiled output must keep its fixed 1px styling.
     * width:100% on it is exactly the property that triggers Outlook's
     * "Show original size" overlay.
     */
    private function assertPixelImgHasNoFullWidth(string $html, string $pixelUrl): void
    {
        $matched = preg_match_all('#<img[^>]*'.preg_quote($pixelUrl, '#').'[^>]*>#', $html, $matches);
        $this->assertGreaterThan(0, $matched, 'Tracking pixel <img> must survive MJML compilation');

        foreach ($matches[0] as $imgTag) {
            $this->assertStringNotContainsString('width:100%', $imgTag,
                'Tracking pixel must not be width:100% - Outlook treats a 1px image at 100% width '
                .'as downscaled and injects an overhanging "Show original size" overlay, causing '
                .'a stray inner scrollbar on every tracked email.');
            $this->assertStringContainsString('width:1px', $imgTag,
                'Tracking pixel must be pinned to 1px so Outlook does not offer to resize it');
        }
    }

    public function test_mjml_pixel_compiles_to_fixed_size_img_inside_column(): void
    {
        // 14 of the 15 tracked templates place the pixel inside
        // mj-section > mj-column.
        $mailable = $this->trackedMailable();
        $pixelUrl = $mailable->getTracking()->getPixelUrl();

        $html = $this->compile(
            '<mjml><mj-body><mj-section><mj-column>'
            .$mailable->getTrackingPixelMjml()
            .'</mj-column></mj-section></mj-body></mjml>'
        );

        $this->assertPixelImgHasNoFullWidth($html, $pixelUrl);
    }

    public function test_mjml_pixel_compiles_to_fixed_size_img_directly_in_body(): void
    {
        // digest/unified.blade.php places the pixel as a direct mj-body child;
        // mj-raw is valid there (the old mj-image never was).
        $mailable = $this->trackedMailable();
        $pixelUrl = $mailable->getTracking()->getPixelUrl();

        $html = $this->compile(
            '<mjml><mj-body>'
            .$mailable->getTrackingPixelMjml()
            .'</mj-body></mjml>'
        );

        $this->assertPixelImgHasNoFullWidth($html, $pixelUrl);
    }

    public function test_mjml_pixel_is_emitted_verbatim_via_mj_raw(): void
    {
        // mj-raw passes content through structurally untouched, so the
        // compiled output must contain the plain HTML pixel with all its
        // attributes intact - no MJML table stack around it. mrml normalizes
        // XML self-closing tags (' />' becomes '>'), so accept either form.
        $mailable = $this->trackedMailable();

        $html = $this->compile(
            '<mjml><mj-body><mj-section><mj-column>'
            .$mailable->getTrackingPixelMjml()
            .'</mj-column></mj-section></mj-body></mjml>'
        );

        $pixelHtml = $mailable->getTrackingPixelHtml();
        $normalized = str_replace(' />', '>', $pixelHtml);
        $this->assertTrue(
            str_contains($html, $pixelHtml) || str_contains($html, $normalized),
            'Compiled output must contain the plain pixel <img> verbatim (modulo XML self-closing normalization)'
        );
    }

    public function test_mjml_pixel_empty_when_tracking_disabled(): void
    {
        $mailable = new class extends MjmlMailable {
            use TrackableEmail;

            protected function getSubject(): string
            {
                return 'Test Subject';
            }
        };

        $this->assertSame('', $mailable->getTrackingPixelMjml());
    }
}

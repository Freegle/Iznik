<?php

namespace Tests\Unit\Mail;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mime\Email;

/**
 * Test AMP email CSS validation.
 *
 * These tests ensure that the AmpEmail trait correctly validates
 * AMP HTML for forbidden CSS properties before applying it to emails.
 */
class AmpEmailValidationTest extends TestCase
{
    /**
     * Create a test class that uses the AmpEmail trait.
     */
    private function createAmpEmailInstance(): object
    {
        return new class {
            use \App\Mail\Traits\AmpEmail;

            // Expose the protected method for testing.
            public function testValidateAmpCss(string $html): void
            {
                $this->validateAmpCss($html);
            }

            // Get the forbidden patterns for inspection.
            public function getForbiddenPatterns(): array
            {
                return self::$forbiddenAmpCssPatterns;
            }
        };
    }

    public function test_validates_clean_amp_html(): void
    {
        $instance = $this->createAmpEmailInstance();

        $cleanHtml = <<<'HTML'
<!doctype html>
<html ⚡4email data-css-strict>
<head>
  <style amp-custom>
    body { font-family: sans-serif; }
    .button { background-color: #338808; color: white; }
  </style>
</head>
<body>
  <div class="button">Click me</div>
</body>
</html>
HTML;

        // Should not throw.
        $instance->testValidateAmpCss($cleanHtml);
        $this->assertTrue(true);
    }

    public function test_rejects_pointer_events(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithPointerEvents = <<<'HTML'
<style amp-custom>
    .button { pointer-events: none; }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pointer-events');

        $instance->testValidateAmpCss($htmlWithPointerEvents);
    }

    public function test_rejects_pointer_events_with_whitespace_variations(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithPointerEvents = <<<'HTML'
<style amp-custom>
    .button { pointer-events :  auto; }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pointer-events');

        $instance->testValidateAmpCss($htmlWithPointerEvents);
    }

    public function test_allows_cursor_pointer(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithCursor = <<<'HTML'
<style amp-custom>
    .button { cursor: pointer; }
</style>
HTML;

        // Should not throw - cursor is allowed in AMP for Email.
        $instance->testValidateAmpCss($htmlWithCursor);
        $this->assertTrue(true);
    }

    public function test_rejects_css_variables(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithVars = <<<'HTML'
<style amp-custom>
    :root { --primary-color: #338808; }
    .button { color: var(--primary-color); }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('CSS variables');

        $instance->testValidateAmpCss($htmlWithVars);
    }

    public function test_rejects_import(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithImport = <<<'HTML'
<style amp-custom>
    @import url('https://fonts.googleapis.com/css?family=Roboto');
    body { font-family: Roboto; }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('@import');

        $instance->testValidateAmpCss($htmlWithImport);
    }

    public function test_rejects_external_stylesheet(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithLink = <<<'HTML'
<head>
    <link rel="stylesheet" href="styles.css">
</head>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('<link rel="stylesheet">');

        $instance->testValidateAmpCss($htmlWithLink);
    }

    public function test_rejects_pseudo_elements(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithPseudo = <<<'HTML'
<style amp-custom>
    .button::before { content: "→"; }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('::before');

        $instance->testValidateAmpCss($htmlWithPseudo);
    }

    public function test_rejects_filter_url(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithFilter = <<<'HTML'
<style amp-custom>
    .image { filter: url(#blur); }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('filter: url()');

        $instance->testValidateAmpCss($htmlWithFilter);
    }

    public function test_rejects_backdrop_filter(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithBackdrop = <<<'HTML'
<style amp-custom>
    .overlay { backdrop-filter: blur(5px); }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('backdrop-filter');

        $instance->testValidateAmpCss($htmlWithBackdrop);
    }

    public function test_rejects_clip_path(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithClip = <<<'HTML'
<style amp-custom>
    .shape { clip-path: circle(50%); }
</style>
HTML;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clip-path');

        $instance->testValidateAmpCss($htmlWithClip);
    }

    public function test_reports_multiple_violations(): void
    {
        $instance = $this->createAmpEmailInstance();

        $htmlWithMultiple = <<<'HTML'
<style amp-custom>
    .button { pointer-events: none; }
    .text { color: var(--text-color); }
</style>
HTML;

        try {
            $instance->testValidateAmpCss($htmlWithMultiple);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            // Should mention both violations.
            $this->assertStringContainsString('pointer-events', $e->getMessage());
            $this->assertStringContainsString('CSS variables', $e->getMessage());
        }
    }

    public function test_current_amp_template_is_valid(): void
    {
        $instance = $this->createAmpEmailInstance();

        $templatePath = resource_path('views/emails/amp/chat/notification.blade.php');
        if (!file_exists($templatePath)) {
            $this->markTestSkipped('AMP template not found at: ' . $templatePath);
        }

        $template = file_get_contents($templatePath);

        // Should not throw - if it does, the template has forbidden CSS.
        $instance->testValidateAmpCss($template);
        $this->assertTrue(true);
    }
}

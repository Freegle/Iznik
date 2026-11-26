<?php

namespace Tests\Unit\Services;

use App\Services\AdminMjmlSanitiser;
use Tests\TestCase;

class AdminMjmlSanitiserTest extends TestCase
{
    private function clean(string $mjml): string
    {
        return (new AdminMjmlSanitiser())->sanitise($mjml);
    }

    public function test_ordinary_mjml_passes_through(): void
    {
        $mjml = '<mj-section background-color="#ffffff"><mj-column>'
            . '<mj-image src="https://www.ilovefreegle.org/logo.png" alt="Logo" />'
            . '<mj-text font-size="14px">Hello <b>there</b>, <a href="https://www.ilovefreegle.org">visit</a>'
            . ' or <a href="mailto:info@ilovefreegle.org">mail</a>.</mj-text>'
            . '<mj-button href="https://www.ilovefreegle.org/donate">Donate</mj-button>'
            . '</mj-column></mj-section>';

        $this->assertSame($mjml, $this->clean($mjml));
    }

    public function test_script_and_other_active_elements_are_removed_with_their_content(): void
    {
        foreach ([
            '<script>alert(1)</script>',
            '<SCRIPT src="https://evil.example/x.js"></SCRIPT>',
            '<iframe src="https://evil.example"></iframe>',
            '<object data="x.swf"><embed src="x.swf"></object>',
            '<form action="https://evil.example"><input name="password"></form>',
            '<style>body{background:url(https://evil.example)}</style>',
            '<svg><script>alert(1)</script></svg>',
            '<mj-raw><script>alert(1)</script></mj-raw>',
        ] as $bad) {
            $out = $this->clean('<mj-section><mj-column><mj-text>Before' . $bad . 'After</mj-text></mj-column></mj-section>');
            $this->assertStringContainsString('BeforeAfter', strip_tags(str_replace(['<mj-raw>', '</mj-raw>'], '', $out)), $bad);
            $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|object|embed|form|input|style|svg)/i', $out, $bad);
            $this->assertStringNotContainsString('alert', $out, $bad);
        }
    }

    public function test_nested_dropped_elements_do_not_leak_their_tail(): void
    {
        $out = $this->clean('<mj-text><svg><svg></svg>hidden</svg>shown</mj-text>');
        $this->assertSame('<mj-text>shown</mj-text>', $out);
    }

    public function test_document_level_mjml_and_includes_are_removed(): void
    {
        $out = $this->clean('<mjml><mj-head><mj-style>.x{}</mj-style></mj-head><mj-body>'
            . '<mj-include path="/etc/passwd" />'
            . '<mj-section><mj-column><mj-text>Body</mj-text></mj-column></mj-section></mj-body></mjml>');

        $this->assertSame('<mj-section><mj-column><mj-text>Body</mj-text></mj-column></mj-section>', $out);
    }

    public function test_event_handlers_are_removed(): void
    {
        $out = $this->clean('<mj-text><img src="https://x.example/a.png" onerror="alert(1)" ONLOAD=alert(2)>'
            . '<img/src=x onerror=alert(3)></mj-text>');

        $this->assertStringNotContainsString('onerror', strtolower($out));
        $this->assertStringNotContainsString('onload', strtolower($out));
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('src="https://x.example/a.png"', $out);

        // Browsers read an unquoted value up to whitespace, so this is all one src, and stays one.
        $this->assertSame('<img src="x/onerror=alert(3)">', $this->clean('<img/src=x/onerror=alert(3)>'));
    }

    public function test_unsafe_url_schemes_are_removed_however_disguised(): void
    {
        foreach ([
            'javascript:alert(1)',
            'JavaScript:alert(1)',
            " java\tscript:alert(1)",
            'jav&#x61;script:alert(1)',
            'javascript&colon;alert(1)',
            'vbscript:msgbox(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
        ] as $url) {
            $out = $this->clean('<mj-button href="' . $url . '">Go</mj-button><mj-section background-url="' . $url . '"></mj-section>');
            $this->assertStringNotContainsString('href', $out, $url);
            $this->assertStringNotContainsString('background-url', $out, $url);
        }

        // Relative links, fragments and the safe schemes are kept.
        $out = $this->clean('<a href="/give">a</a><a href="#top">b</a><a href="tel:01234">c</a>');
        $this->assertSame('<a href="/give">a</a><a href="#top">b</a><a href="tel:01234">c</a>', $out);
    }

    public function test_scripted_styles_are_removed(): void
    {
        $out = $this->clean('<div style="width: expression(alert(1))">x</div>'
            . '<div style="background:url(javascript:alert(1))">y</div><div style="color: red">z</div>');

        $this->assertSame('<div>x</div><div>y</div><div style="color: red">z</div>', $out);
    }

    public function test_comments_and_half_formed_tags_are_neutralised(): void
    {
        $this->assertSame('<mj-text>ab</mj-text>', $this->clean('<mj-text>a<!--[if mso]><script>x</script><![endif]-->b</mj-text>'));

        // An unterminated tag cannot join up with the template markup that follows it.
        $out = $this->clean('<mj-text>Hi</mj-text><script src=x');
        $this->assertSame('<mj-text>Hi</mj-text>&lt;script src=x', $out);

        $this->assertSame('<mj-text>3 &lt; 5</mj-text>', $this->clean('<mj-text>3 < 5</mj-text>'));
    }

    public function test_attribute_values_are_re_escaped(): void
    {
        $out = $this->clean('<a title=\'He said "hi" & left\' href="https://x.example/?a=1&amp;b=2">x</a>');
        $this->assertSame('<a title="He said &quot;hi&quot; &amp; left" href="https://x.example/?a=1&amp;b=2">x</a>', $out);
    }
}

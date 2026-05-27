<?php

namespace Tests\Unit\Services\BulkMail;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\MjmlMailable;
use App\Services\BulkMail\BulkMjmlCompiler;
use App\Services\BulkMail\UnboundPlaceholderException;
use App\Services\MjmlCompilerService;
use Illuminate\Mail\Mailables\Envelope;
use Tests\TestCase;

class BulkMjmlCompilerTest extends TestCase
{
    public function test_substitute_replaces_simple_placeholders(): void
    {
        $compiler = $this->makeCompiler();
        $html = '<a href="{{url}}">{{label}}</a>';
        $out = $compiler->substitute($html, ['url' => 'https://example.com', 'label' => 'Click']);
        $this->assertSame('<a href="https://example.com">Click</a>', $out);
    }

    public function test_substitute_html_escapes_values(): void
    {
        $compiler = $this->makeCompiler();
        // Quotes and < > & all need encoding for HTML-safe attribute/text content.
        $out = $compiler->substitute('Hi {{name}}!', ['name' => 'A & B <Co>']);
        $this->assertSame('Hi A &amp; B &lt;Co&gt;!', $out);
    }

    public function test_substitute_encodes_ampersand_in_urls(): void
    {
        // Matches Blade's {{ $url }} behaviour: caller passes plain &, we encode to &amp;.
        $compiler = $this->makeCompiler();
        $out = $compiler->substitute(
            '<a href="{{url}}">link</a>',
            ['url' => 'https://x/?a=1&b=2']
        );
        $this->assertSame('<a href="https://x/?a=1&amp;b=2">link</a>', $out);
    }

    public function test_substitute_throws_on_unbound_placeholder(): void
    {
        $compiler = $this->makeCompiler();
        try {
            $compiler->substitute('Hi {{name}}, {{missing}}!', ['name' => 'x']);
            $this->fail('Expected UnboundPlaceholderException');
        } catch (UnboundPlaceholderException $e) {
            $this->assertSame(['missing'], $e->missingKeys);
        }
    }

    public function test_substitute_lists_all_missing_placeholders(): void
    {
        $compiler = $this->makeCompiler();
        try {
            $compiler->substitute('{{a}} {{b}} {{c}}', []);
            $this->fail('Expected UnboundPlaceholderException');
        } catch (UnboundPlaceholderException $e) {
            $this->assertSame(['a', 'b', 'c'], $e->missingKeys);
        }
    }

    public function test_substitute_dedupes_repeated_missing_placeholder(): void
    {
        $compiler = $this->makeCompiler();
        try {
            $compiler->substitute('{{x}} {{x}} {{x}}', []);
            $this->fail('Expected UnboundPlaceholderException');
        } catch (UnboundPlaceholderException $e) {
            $this->assertSame(['x'], $e->missingKeys);
        }
    }

    public function test_substitute_allows_curly_braces_not_matching_placeholder_pattern(): void
    {
        // Single braces, braces around invalid chars, etc. should not trip
        // the validation regex.
        $compiler = $this->makeCompiler();
        $out = $compiler->substitute('{name} {{ space }} {{1notavar}} done', []);
        $this->assertSame('{name} {{ space }} {{1notavar}} done', $out);
    }

    public function test_html_for_compiles_once_per_shape(): void
    {
        $compiler = $this->makeCompiler();

        // Three mailables, two distinct shapes (A used twice, B once). Each
        // mailable returns its bulkHtml verbatim from renderHtmlForBulkCache(),
        // so the BulkMjmlCompiler's own counter is what tells us whether the
        // cache was used.
        $m1 = $this->makeBulkMailable('shape-A', ['who' => 'alice'], '<root>{{who}}</root>');
        $m2 = $this->makeBulkMailable('shape-A', ['who' => 'bob'],   '<root>{{who}}</root>');
        $m3 = $this->makeBulkMailable('shape-B', ['who' => 'carol'], '<root>{{who}} v2</root>');

        $h1 = $compiler->htmlFor($m1);
        $h2 = $compiler->htmlFor($m2);
        $h3 = $compiler->htmlFor($m3);

        // Per recipient, the substituted values come through.
        $this->assertStringContainsString('alice', $h1);
        $this->assertStringContainsString('bob', $h2);
        $this->assertStringContainsString('carol', $h3);

        // Two distinct shapes → exactly two cache misses (= 2 compiles).
        $this->assertSame(2, $compiler->compileCount());

        // Hit counts: shape A served twice, shape B once.
        $hits = $compiler->hitCounts();
        $this->assertSame(2, $hits['shape-A']);
        $this->assertSame(1, $hits['shape-B']);
    }

    public function test_html_for_caches_first_recipients_html_for_subsequent_recipients(): void
    {
        $compiler = $this->makeCompiler();

        // First call seeds the cache with the FIRST mailable's bulkHtml.
        // A second mailable with the SAME shape but DIFFERENT bulkHtml should
        // be served the first cached HTML (only its mergeVars differ).
        $first  = $this->makeBulkMailable('same-shape', ['who' => 'alice'], '<root>{{who}} A</root>');
        $second = $this->makeBulkMailable('same-shape', ['who' => 'bob'],   '<root>{{who}} B</root>');

        $h1 = $compiler->htmlFor($first);
        $h2 = $compiler->htmlFor($second);

        // Both recipients see the first-cached body, with their own merge values.
        $this->assertSame('<root>alice A</root>', $h1);
        $this->assertSame('<root>bob A</root>', $h2);

        $this->assertSame(1, $compiler->compileCount());
    }

    public function test_clear_resets_cache(): void
    {
        $compiler = $this->makeCompiler();

        $compiler->htmlFor($this->makeBulkMailable('s', ['who' => 'a'], '<r>{{who}}</r>'));
        $compiler->htmlFor($this->makeBulkMailable('s', ['who' => 'b'], '<r>{{who}}</r>'));
        $this->assertSame(1, $compiler->compileCount());

        $compiler->clear();
        $compiler->htmlFor($this->makeBulkMailable('s', ['who' => 'c'], '<r>{{who}}</r>'));
        $this->assertSame(1, $compiler->compileCount()); // counter reset by clear(), then 1 fresh compile
    }

    private function makeCompiler(): BulkMjmlCompiler
    {
        // For pure substitute() tests, we don't need a working sidecar.
        $mjml = new class extends MjmlCompilerService {
            public function __construct() {}
            public function compile(string $mjml): string { return $mjml; }
        };
        return new BulkMjmlCompiler($mjml);
    }

    /**
     * Make a tiny test mailable that returns a fixed bulk-cache HTML and
     * declares the shape + merge vars in its constructor args. Avoids
     * needing a real Blade template.
     */
    private function makeBulkMailable(string $shape, array $vars, string $bulkHtml): MjmlMailable&BulkRenderable
    {
        return new class($shape, $vars, $bulkHtml) extends MjmlMailable implements BulkRenderable {
            public function __construct(
                private string $shape,
                private array $vars,
                private string $bulkHtml
            ) {
                parent::__construct();
            }

            public function shapeKey(): string { return $this->shape; }
            public function bulkTemplate(): string { return 'unused.in.test'; }
            public function bulkData(): array { return []; }
            public function mergeVars(): array { return $this->vars; }
            public function renderHtmlForBulkCache(string $template, array $sharedData): string
            {
                return $this->bulkHtml;
            }

            protected function getSubject(): string { return 'test'; }
            public function envelope(): Envelope
            {
                return new Envelope(subject: 'test');
            }
        };
    }
}

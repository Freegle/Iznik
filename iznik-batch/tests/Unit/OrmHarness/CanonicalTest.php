<?php

namespace Tests\Unit\OrmHarness;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\OrmHarness\Canonical;

/**
 * Pure PHPUnit\Framework\TestCase, not Tests\TestCase: Canonical is a pure
 * string function with no database dependency, and forcing it through the
 * app's TestCase (which asserts a live connection to iznik_batch_test in
 * setUp) would only add cost with no coverage benefit - same reasoning as
 * the existing tests/Unit/Support/EloquentUtilsTest.php.
 *
 * These cases are NOT independently authored: they are drawn from the
 * shared cross-language corpus, sourced from tools/orm-migration/canonical-corpus.json
 * (edit THAT file, not this directory's copy - see CORPUS_PATH below and
 * tools/orm-migration/check-canonical-corpus-sync.sh), which is itself
 * derived from iznik-server-go/ormharness/canonical_test.go. See
 * Canonical.php's header for why - two independently-maintained
 * canonicalisers drift, and this corpus is what turns that drift into a
 * failing test on both sides instead of a silent divergence. Do not add a
 * case here without adding it to the shared corpus too; a case added only
 * here proves this port matches ITSELF, not that it still agrees with Go.
 */
class CanonicalTest extends TestCase
{
    // Reads THIS PACKAGE'S OWN COPY of the corpus, not
    // tools/orm-migration/canonical-corpus.json directly: the batch
    // container only bind-mounts iznik-batch itself (confirmed while
    // building this - `docker inspect` shows no mount reaching
    // tools/orm-migration/), so a path escaping upward out of iznik-batch
    // would not exist at test-run time inside the container, the same
    // container-isolation problem golden.go's manifest embedding and this
    // repo's canonical_corpus_test.go (iznik-server-go/ormharness) both
    // hit for the identical reason. tools/orm-migration/canonical-corpus.json
    // remains the file to actually EDIT; this copy and
    // iznik-server-go/ormharness/canonical-corpus.json are kept
    // byte-identical to it by tools/orm-migration/check-canonical-corpus-sync.sh,
    // wired into gate (q).
    private const CORPUS_PATH = __DIR__.'/../../Support/OrmHarness/canonical-corpus.json';

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function equalPairsProvider(): array
    {
        return self::namedPairs(self::corpus()['equalPairs']);
    }

    /**
     * @return array<string,array{0:string,1:string}>
     */
    public static function notEqualPairsProvider(): array
    {
        return self::namedPairs(self::corpus()['notEqualPairs']);
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function idempotentProvider(): array
    {
        $out = [];
        foreach (self::corpus()['idempotent'] as $i => $sql) {
            $out["case {$i}: {$sql}"] = [$sql];
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function corpus(): array
    {
        $raw = file_get_contents(self::CORPUS_PATH);
        if ($raw === false) {
            throw new \RuntimeException('shared canonicaliser corpus not found at '.self::CORPUS_PATH);
        }

        return json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<array{0:string,1:string}>  $pairs
     * @return array<string,array{0:string,1:string}>
     */
    private static function namedPairs(array $pairs): array
    {
        $out = [];
        foreach ($pairs as $i => [$a, $b]) {
            $out["case {$i}: {$a} <=> {$b}"] = [$a, $b];
        }

        return $out;
    }

    #[DataProvider('equalPairsProvider')]
    public function test_corpus_equal_pair_canonicalises_the_same(string $a, string $b): void
    {
        $ca = Canonical::normalise($a);
        $cb = Canonical::normalise($b);
        $this->assertSame($ca, $cb, "expected canonically equal:\n  a: {$a}\n  -> {$ca}\n  b: {$b}\n  -> {$cb}");
    }

    #[DataProvider('notEqualPairsProvider')]
    public function test_corpus_not_equal_pair_canonicalises_differently(string $a, string $b): void
    {
        $ca = Canonical::normalise($a);
        $cb = Canonical::normalise($b);
        $this->assertNotSame($ca, $cb, "expected canonically different, both reduced to {$ca}\n  a: {$a}\n  b: {$b}");
    }

    #[DataProvider('idempotentProvider')]
    public function test_corpus_normalise_is_idempotent(string $sql): void
    {
        $once = Canonical::normalise($sql);
        $twice = Canonical::normalise($once);
        $this->assertSame($once, $twice, "Canonical::normalise is not idempotent:\n  once:  {$once}\n  twice: {$twice}");
    }

    // --- PHP-specific cases beyond the shared corpus -----------------------
    //
    // These are genuinely PHP-only concerns (a heredoc/nowdoc's literal
    // newlines, and a Laravel-style single-quoted string using MySQL's
    // backslash escape rather than the doubled-quote style) and have no Go
    // equivalent to pin against - Go's SQL never arrives via a PHP heredoc.
    // Kept separate from the shared corpus rather than force-fitted into it.

    public function test_heredoc_style_newlines_collapse_like_any_other_whitespace(): void
    {
        $heredocStyle = "SELECT *\nFROM users\nWHERE id = ?";
        $oneLine = 'SELECT * FROM users WHERE id = ?';
        $this->assertSame(Canonical::normalise($oneLine), Canonical::normalise($heredocStyle));
    }

    public function test_backslash_escaped_quote_inside_string_literal_does_not_terminate_it(): void
    {
        // MySQL accepts both '' and \' as an escaped quote inside a
        // single-quoted literal; scanQuoted must recognise the backslash
        // form too, or it misreads where the literal ends and mangles
        // everything after it.
        $sql = "SELECT * FROM t WHERE name = 'O\\'Brien'";
        $normalised = Canonical::normalise($sql);
        $this->assertStringContainsString("'O\\'Brien'", $normalised);
    }
}

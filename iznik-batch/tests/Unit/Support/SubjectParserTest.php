<?php

namespace Tests\Unit\Support;

use App\Support\SubjectParser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SubjectParser is the canonical "TYPE: item (location)" splitter reused
 * by mail handling and embedding pre-processing. These tests pin the
 * behaviour we rely on — particularly nested-bracket handling in location.
 */
class SubjectParserTest extends TestCase
{
    public function test_canonical_subject_splits_into_type_item_location(): void
    {
        $this->assertSame(
            ['OFFER', 'Coffee table', 'SE10 1BH'],
            SubjectParser::parse('OFFER: Coffee table (SE10 1BH)')
        );
        $this->assertSame(
            ['WANTED', 'Bicycle', 'N1 1AA'],
            SubjectParser::parse('WANTED: Bicycle (N1 1AA)')
        );
    }

    public function test_location_with_nested_brackets_handled(): void
    {
        // "London (Central)" inside the outer location parens.
        $this->assertSame(
            ['WANTED', 'Books', 'London (Central)'],
            SubjectParser::parse('WANTED: Books (London (Central))')
        );
    }

    public function test_subject_without_location_returns_nulls(): void
    {
        // Missing location parens → we can't carve the pieces apart safely,
        // all three parts come back null and the caller falls back.
        $this->assertSame(
            [null, null, null],
            SubjectParser::parse('OFFER: Sofa')
        );
    }

    public function test_subject_without_colon_returns_nulls(): void
    {
        $this->assertSame(
            [null, null, null],
            SubjectParser::parse('Free sofa available')
        );
    }

    public function test_empty_subject_returns_nulls(): void
    {
        $this->assertSame([null, null, null], SubjectParser::parse(''));
    }

    // =========================================================================
    // Table-driven: successful parse variants
    // =========================================================================

    public static function successfulParseProvider(): array
    {
        return [
            'empty location' => [
                'OFFER: table ()',
                ['OFFER', 'table', ''],
            ],
            'multiple colons — only first splits type' => [
                'OFFER: re: table (London)',
                ['OFFER', 're: table', 'London'],
            ],
            'colon in item — colon after item name' => [
                'OFFER: item: detail (London)',
                ['OFFER', 'item: detail', 'London'],
            ],
            'leading and trailing whitespace around type' => [
                '  OFFER  : table (London)',
                ['OFFER', 'table', 'London'],
            ],
            'empty type — colon at start' => [
                ': table (London)',
                ['', 'table', 'London'],
            ],
            'location with comma' => [
                'OFFER: table (London, SE1)',
                ['OFFER', 'table', 'London, SE1'],
            ],
            'triple-nested location brackets' => [
                'OFFER: table (London (East (SE1)))',
                ['OFFER', 'table', 'London (East (SE1))'],
            ],
            'unicode item' => [
                'OFFER: café table (London)',
                ['OFFER', 'café table', 'London'],
            ],
            'numeric type suffix' => [
                'OFFER2: table (London)',
                ['OFFER2', 'table', 'London'],
            ],
            'lowercase type and item' => [
                'offer: coffee table (se10 1bh)',
                ['offer', 'coffee table', 'se10 1bh'],
            ],
            'location with hyphenated postcode' => [
                'WANTED: bike (SW1A-1AA)',
                ['WANTED', 'bike', 'SW1A-1AA'],
            ],
        ];
    }

    #[DataProvider('successfulParseProvider')]
    public function test_parse_succeeds_for_valid_subjects(string $input, array $expected): void
    {
        $this->assertSame($expected, SubjectParser::parse($input));
    }

    // =========================================================================
    // Table-driven: cases that must return [null, null, null]
    // — covers the previously-untested $count != 0 branch in the do-while
    // =========================================================================

    public static function nullParseProvider(): array
    {
        return [
            'colon only — no rest' => [':'],
            'unbalanced closing paren — no opening paren at all' => ['OFFER: table )'],
            // The do-while loop walks backward but exits when p reaches 0 before
            // count drops to zero, because the opening paren is at position 0 of
            // $rest. With no text before the location there is no item, so
            // returning null is correct and intentional.
            'opening paren at position-0 of rest — no item' => ['OFFER: (London)'],
            'doubly unbalanced close — two ) one (' => ['OFFER: table (London))'],
            'only whitespace' => ['   '],
            'subject ends without closing paren' => ['OFFER: table (London'],
        ];
    }

    #[DataProvider('nullParseProvider')]
    public function test_parse_returns_null_triple_for_invalid_subjects(string $input): void
    {
        $this->assertSame([null, null, null], SubjectParser::parse($input));
    }
}

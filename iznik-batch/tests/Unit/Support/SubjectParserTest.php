<?php

namespace Tests\Unit\Support;

use App\Support\SubjectParser;
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
}

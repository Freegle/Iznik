<?php

namespace Tests\Unit\Helpers;

use App\Helpers\ItemQuality;
use Tests\TestCase;

class ItemQualityTest extends TestCase
{
    public function test_extracts_the_item_from_a_subject(): void
    {
        $this->assertSame('anything', ItemQuality::itemFromSubject('WANTED: Anything (Bristol BS1)'));
        $this->assertSame('red sofa', ItemQuality::itemFromSubject('OFFER: Red sofa (Leeds LS1)'));
        $this->assertSame(
            'things for the garden!',
            ItemQuality::itemFromSubject('WANTED: Things for the garden!  (Little Hulton M38)')
        );
        $this->assertSame('', ItemQuality::itemFromSubject(null));
        $this->assertSame('', ItemQuality::itemFromSubject(''));
    }

    public function test_flags_vague_catch_all_items(): void
    {
        $vague = [
            'WANTED: Anything (X BS1)',
            'WANTED: All (X)',
            'WANTED: Free stuff (X)',
            'WANTED: Various items (X)',
            'WANTED: Something (X)',
            'WANTED: Freebies (X)',
            'WANTED: Unwanted items (X)',
            'OFFER: stuff (X)',
            'WANTED: owt (X)',
            'WANTED: Anything nice (X)',
            'WANTED: everything really (X)',
            // "<vague> for/to X" — more aggressive than the compose block (Pending is reversible).
            'WANTED: Things for the garden (X)',
            'WANTED: Items for house (X)',
            'WANTED: Anything to resell (X)',
        ];
        foreach ($vague as $s) {
            $this->assertTrue(ItemQuality::subjectItemIsVague($s), "should be vague: $s");
        }
    }

    public function test_allows_specific_items(): void
    {
        $specific = [
            'WANTED: Bike (Wavertree L8)',
            'WANTED: Fan (Coventry CV1)',
            'OFFER: Red sofa (Leeds LS1)',
            'WANTED: Washing machine (X)',
            'OFFER: Fridge freezer (X)',
            'WANTED: Football boots (X)',
            // These start with an otherwise-vague word but carry real content, so are allowed.
            'WANTED: Various board games (X)',
            'WANTED: Free weights (X)',
        ];
        foreach ($specific as $s) {
            $this->assertFalse(ItemQuality::subjectItemIsVague($s), "should be allowed: $s");
        }
    }
}

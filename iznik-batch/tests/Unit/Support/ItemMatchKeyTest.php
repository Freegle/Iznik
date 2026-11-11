<?php

namespace Tests\Unit\Support;

use App\Support\ItemMatchKey;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ItemMatchKeyTest extends TestCase
{
    public static function sameKey(): array
    {
        return [
            'plural' => ['Knitting needles', 'knitting needle'],
            'case and punctuation' => ['Cat Litter!', 'cat litter'],
            'ies plural' => ['daisies', 'daisy'],
            'irregular plural' => ['shelves', 'shelf'],
            'possessive' => ["Child's bike", 'childs bike'],
            'curly apostrophe' => ["Child\u{2019}s bike", "child's bike"],
            'accent' => ['crème brûlée dish', 'creme brulee dish'],
        ];
    }

    #[DataProvider('sameKey')]
    public function test_spellings_of_one_item_share_a_key(string $a, string $b): void
    {
        $this->assertSame(ItemMatchKey::key($b), ItemMatchKey::key($a));
    }

    public static function differentKey(): array
    {
        return [
            // Measured: dropping the single letter made these "bike" and "plate".
            'hyphenated letter' => ['e-bike', 'bike'],
            'lone letter' => ['L plates', 'plates'],
            'plural-only noun' => ['glasses', 'glass'],
            'drawers' => ['chest of drawers', 'chest of drawer'],
        ];
    }

    #[DataProvider('differentKey')]
    public function test_different_items_keep_different_keys(string $a, string $b): void
    {
        $this->assertNotSame(ItemMatchKey::key($b), ItemMatchKey::key($a));
    }

    public function test_nothing_but_punctuation_has_no_key(): void
    {
        $this->assertSame('', ItemMatchKey::key(' -- '));
    }
}

<?php

namespace Tests\Unit\Support;

use App\Support\ItemName;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ItemName::stripCourtesy is what stops "iron please" reaching the image generator as the
 * thing to draw (Discourse topic 9209/98 - it drew a smooth white blob). These tests pin
 * both halves: courtesy words go, real item text stays.
 */
class ItemNameTest extends TestCase
{
    public static function courtesyProvider(): array
    {
        return [
            'trailing please' => ['iron please', 'iron'],
            'capitalised please' => ['Microwave Please', 'Microwave'],
            'please with punctuation' => ['Wooden Pallets Please!!', 'Wooden Pallets'],
            'please with full stop' => ['30 old bricks please.', '30 old bricks'],
            'pls' => ['garden parasol with base pls', 'garden parasol with base'],
            'plz' => ['black leather sofas plz', 'black leather sofas'],
            'drawn out please' => ['sheet of aluminium pleaseeeee', 'sheet of aluminium'],
            'please mid-name' => ['single bed please & mattress', 'single bed & mattress'],
            'leading please' => ['please can I have a kettle', 'can I have a kettle'],
            'no courtesy word' => ['wooden chair', 'wooden chair'],
            'prefix of a real word is kept' => ['Pleaser platform boots', 'Pleaser platform boots'],
            'pls inside a real word is kept' => ['duplex printer', 'duplex printer'],
            'nothing left falls back to the original' => ['please', 'please'],
            'nothing but punctuation left falls back' => ['Please!', 'Please!'],
            'empty stays empty' => ['', ''],
        ];
    }

    #[DataProvider('courtesyProvider')]
    public function test_strip_courtesy(string $input, string $expected): void
    {
        $this->assertSame($expected, ItemName::stripCourtesy($input));
    }
}

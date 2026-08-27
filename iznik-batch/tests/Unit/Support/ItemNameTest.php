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

            // Thanks-family sign-offs, anchored to the end of the name.
            'trailing thanks' => ['Anything fitness related thanks', 'Anything fitness related'],
            'thank you in advance' => ['digital multi meter thank you in advance', 'digital multi meter'],
            'thank you very much' => ['any old laptops for spares thank you very much', 'any old laptops for spares'],
            'thanks jammed against a comma' => ['Active speakers for PC,small,thanks.', 'Active speakers for PC,small'],
            'a run of sign-offs' => ['Working 2 slice toaster please, Thank you', 'Working 2 slice toaster'],
            'plz and thanks together' => ['3 chairs and 2 sofas plz thanks', '3 chairs and 2 sofas'],
            'thanks alone falls back' => ['thanks', 'thanks'],

            // Names that only LOOK like courtesy, and must survive intact.
            'thank you is the item itself' => ['thank you cards', 'thank you cards'],
            'thank you gift is the item' => ['thank you gift bags', 'thank you gift bags'],
            'ta is a job title' => ['SEN TA', 'SEN TA'],
            'ta inside a longer job title' => ['Early Years Teaching Assistant (TA)', 'Early Years Teaching Assistant (TA)'],
            'tia is Siemens software' => ['Junior Controls Engineer (TIA Portal)', 'Junior Controls Engineer (TIA Portal)'],
            'thanksgiving is not thanks' => ['decorations for Thanksgiving', 'decorations for Thanksgiving'],

            // Bias words, stripped anywhere like please/pls/plz - regression coverage for
            // Discourse topic 9630/60: "Adult bike" got a medicine/supplement bottle because
            // "adult" biases the image generator toward pharmacy imagery.
            'leading adult' => ['Adult bike', 'bike'],
            'adult mid-name' => ['large adult bike', 'large bike'],
            'trailing adult plural' => ['mountain bike, adults', 'mountain bike'],
            'adult alone falls back' => ['Adult', 'Adult'],
            'adult prefix of real word is kept' => ['Adulting for beginners book', 'Adulting for beginners book'],
        ];
    }

    #[DataProvider('courtesyProvider')]
    public function test_strip_courtesy(string $input, string $expected): void
    {
        $this->assertSame($expected, ItemName::stripCourtesy($input));
    }
}

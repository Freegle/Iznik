<?php

namespace Tests\Unit\Services\Desirability;

use App\Services\Desirability\TitleCanonicalService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TitleCanonicalServiceTest extends TestCase
{
    private TitleCanonicalService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new TitleCanonicalService;
    }

    /**
     * The 300 golden fixtures were recorded from the analysis pipeline that produced
     * the desirability artifact's canonical keys. That pipeline is gone and this
     * service is now the only implementation, so the fixtures are the record of what
     * it does: a mismatch means new posts map to different keys than the artifact
     * uses, silently losing their scores. Re-record an entry only when the change is
     * deliberate, and only that entry.
     */
    #[Test]
    public function it_matches_every_golden_fixture(): void
    {
        $fixtures = json_decode(
            (string) file_get_contents(base_path('tests/fixtures/desirability/golden-titles.json')),
            true
        );
        $this->assertNotEmpty($fixtures);
        $failures = [];
        foreach ($fixtures as $f) {
            $got = $this->svc->canonicalise($f['raw']);
            // Strict, typed comparison: loose != would let '' pass as null and
            // 0 pass as false, exactly the regressions this test exists to catch.
            $checks = [
                'canonical' => ['str', $f['canonical'], $got['canonical']],
                'clean' => ['str', $f['clean'], $got['clean']],
                'brand' => ['str', $f['brand'], $got['brand']],
                'is_ikea' => ['bool', $f['is_ikea'], $got['is_ikea']],
                'qty' => ['int', $f['qty'], $got['qty']],
                'is_multiple' => ['bool', $f['is_multiple'], $got['is_multiple']],
                'is_plural' => ['bool', $f['is_plural'], $got['is_plural']],
                'is_baby' => ['bool', $f['flags']['baby'], $got['is_baby']],
                'is_kids' => ['bool', $f['flags']['kids'], $got['is_kids']],
                'is_heavy' => ['bool', $f['flags']['heavy'], $got['is_heavy']],
                'is_vintage' => ['bool', $f['flags']['vintage'], $got['is_vintage']],
                'is_electrical' => ['bool', $f['flags']['electrical'], $got['is_electrical']],
                'is_digital' => ['bool', $f['flags']['digital'], $got['is_digital']],
                'screen_size' => ['float', $f['flags']['screen_size'], $got['screen_size']],
            ];
            foreach ($checks as $field => [$type, $want, $have]) {
                $equal = match ($type) {
                    'bool' => (bool) $want === (bool) $have,
                    'int' => ($want === null) === ($have === null) && ($want === null || (int) $want === (int) $have),
                    'float' => ($want === null) === ($have === null) && ($want === null || abs((float) $want - (float) $have) < 1e-9),
                    default => ($want === null) === ($have === null) && ($want === null || (string) $want === (string) $have),
                };
                if (! $equal) {
                    $failures[] = sprintf('[%s] %s: want %s, got %s (raw: %s)',
                        $f['cat'], $field, json_encode($want), json_encode($have), $f['raw']);
                }
            }
        }
        $this->assertSame([], array_slice($failures, 0, 25),
            count($failures).' golden mismatches (first 25 shown)');
    }

    #[Test]
    public function it_handles_null_and_empty_subjects(): void
    {
        $got = $this->svc->canonicalise(null);
        $this->assertNull($got['clean']);
        $this->assertNull($got['brand']);

        $got = $this->svc->canonicalise('');
        $this->assertSame('', $got['canonical']);
    }

    #[Test]
    public function it_strips_the_standard_subject_wrapper(): void
    {
        $got = $this->svc->canonicalise('OFFER: Bosch washing machine (Headington OX3)');
        $this->assertSame('washing machine', $got['canonical']);
        $this->assertSame('bosch', $got['brand']);
    }

    #[Test]
    public function invalid_utf8_is_scrubbed_not_silently_emptied(): void
    {
        // A subject truncated mid-multibyte used to make every /u preg_replace
        // return null, emptying the whole canonicalisation for the post.
        $got = $this->svc->canonicalise("OFFER: Wooden high chair \xE2\x82 (AB1)");
        $this->assertSame('wooden high chair', $got['canonical']);
    }
    /**
     * A leading number is a count when what follows is a plural noun, and part of
     * the item when it is a dimension. Getting that wrong put "2 uplighters" on the
     * electricals page as its own item, separate from every other uplighter.
     */
    #[Test]
    #[DataProvider('leadingNumberCases')]
    public function it_tells_a_count_from_a_dimension(string $raw, string $canonical, ?int $qty): void
    {
        $got = $this->svc->canonicalise($raw);
        $this->assertSame($canonical, $got['canonical'], $raw);
        $this->assertSame($qty, $got['qty'] === null ? null : (int) $got['qty'], $raw);
    }

    public static function leadingNumberCases(): array
    {
        return [
            // Counts: the number goes.
            '2 uplighters' => ['2 uplighters', 'uplighter', 2],
            '2 x sanders' => ['2 X sanders', 'sander', 2],
            '3 chairs' => ['3 chairs', 'chair', 3],
            '5 dvds' => ['5 dvds', 'dvd', 5],
            // Counts with a count noun: the noun goes with it.
            '4 pieces of' => ['4 pieces of sunlight roofing', 'sunlight roofing', 4],
            '10 pairs' => ['10 pairs XXL tights', 'xxl tight', 10],
            // Dimensions and ratings: the number stays.
            '3 seater' => ['3 seater sofa', '3 seater sofa', null],
            '2 door' => ['2 door wardrobe', '2 door wardrobe', null],
            '4 burner' => ['4 burner hob', '4 burner hob', null],
            '2 man' => ['2 man tent', '2 man tent', null],
            '3 piece' => ['3 piece suite', '3 piece suite', null],
            '20 litres' => ['20 litres fish tank', '20 litres fish tank', null],
            '1000 watts' => ['1000 watts microwave', '1000 watts microwave', null],
            '12 volts' => ['12 volts charger', '12 volts charger', null],
            '13 amps' => ['13 amps extension lead', '13 amps extension lead', null],
            '3 metres' => ['3 metres cable', '3 metres cable', null],
        ];
    }

    /**
     * Brands are stripped so that "Corby trouser press" clusters with every other
     * trouser press. An unrecognised brand is worse than cosmetic: the electricals
     * page picks its display name from the titles where no brand was detected, so a
     * missing brand is the name members see.
     */
    #[Test]
    #[DataProvider('brandCases')]
    public function it_strips_the_brand_and_keeps_the_item(string $raw, string $canonical, ?string $brand): void
    {
        $got = $this->svc->canonicalise($raw);
        $this->assertSame($canonical, $got['canonical'], $raw);
        $this->assertSame($brand, $got['brand'], $raw);
    }

    public static function brandCases(): array
    {
        return [
            'corby' => ['Corby trouser press', 'trouser press', 'corby'],
            'dolce gusto' => ['Dolce gusto coffee machine', 'coffee machine', 'dolce_gusto'],
            'aeg' => ['AEG oven', 'oven', 'aeg'],
            'brother' => ['Brother printer', 'printer', 'brother'],
            'singer' => ['Singer sewing machine', 'sewing machine', 'singer'],
            'sharp' => ['Sharp microwave', 'microwave', 'sharp'],
            'qualcast' => ['Qualcast electric lawnmower', 'electric lawnmower', 'qualcast'],
            // A console product name is the item, so it is recorded and kept, the
            // way the file already treats Kindle and Wii. Stripping left "wanted:".
            'ps5' => ['WANTED: ps5', 'wanted: ps5', 'playstation'],
            'xbox' => ['Wireless Xbox One Controller', 'wireless xbox one controller', 'xbox'],
            'playstation' => ['Playstation controller', 'controller', 'playstation'],
        ];
    }

    /**
     * Each of these words is a brand and an ordinary word, and the ordinary use is
     * the common one. Measured over 220,808 real subjects before they were excluded:
     * hoover fired 497 times, almost all generic ("Compact Hoover"), and stripping it
     * defeated the hoover -> vacuum cleaner synonym; tower fired 300 times, almost
     * all "computer tower"; sage fired 33 times on sage green and fresh sage.
     */
    #[Test]
    #[DataProvider('ordinaryWordCases')]
    public function it_does_not_read_an_ordinary_word_as_a_brand(string $raw, string $canonical): void
    {
        $got = $this->svc->canonicalise($raw);
        $this->assertSame($canonical, $got['canonical'], $raw);
    }

    public static function ordinaryWordCases(): array
    {
        return [
            'hoover is generic' => ['Compact Hoover', 'compact vacuum cleaner'],
            'hoover attachments' => ['Hoover attachments', 'vacuum cleaner attachment'],
            'computer tower' => ['Desktop computer tower', 'desktop computer tower'],
            'sage the colour' => ['Sage green armchair', 'sage green armchair'],
            'sage the herb' => ['Fresh sage', 'fresh sage'],
            'blackberry the fruit' => ['Thornless blackberry cuttings', 'thornless blackberry cutting'],
            'candy floss' => ['Candy floss machine', 'candy floss machine'],
            'candy canes' => ['Candy canes', 'candy cane'],
            // ... but the appliance brand of the same name still works.
            'candy the brand' => ['Candy tumble dryer', 'tumble dryer'],
        ];
    }
}

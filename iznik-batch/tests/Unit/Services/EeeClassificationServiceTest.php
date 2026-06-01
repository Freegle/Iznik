<?php

namespace Tests\Unit\Services;

use App\Services\EeeClassificationService;
use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Unit tests for EeeClassificationService.
 *
 * Covers the two pure-logic methods that don't touch DB or HTTP:
 *   - extractTextSignals(): public, pure string scan
 *   - computeConsensus():   protected, branch-heavy aggregation
 *
 * Key substring facts verified by inspection (PHP str_contains is byte-exact):
 *   - 'battery' is NOT a substring of 'batteries' (position 6: 'y' vs 'i')
 *   - 'charging' is a separate EEE signal from 'charger'
 *   - 'electric' IS a substring of 'not electric' and 'non-electric'
 *   - 'wifi' is NOT a substring of 'wi-fi' (hyphen at position 2 breaks it)
 *   - Signal order in results follows EEE_TEXT_SIGNALS constant array order
 */
class EeeClassificationServiceTest extends TestCase
{
    private EeeClassificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $vision    = $this->createMock(EeeVisionService::class);
        $sqlite    = $this->createMock(EeeSqliteService::class);
        $component = $this->createMock(EeeComponentService::class);

        $this->service = new EeeClassificationService($vision, $sqlite, $component);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // extractTextSignals — public, no DB/HTTP
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @dataProvider provideExtractTextSignalsCases
     */
    public function test_extract_text_signals(string $text, array $expectedEee, array $expectedNonEee): void
    {
        $result = $this->service->extractTextSignals($text);

        $this->assertSame($expectedEee, $result['eee'], "EEE signals for: {$text}");
        $this->assertSame($expectedNonEee, $result['non_eee'], "Non-EEE signals for: {$text}");
    }

    public static function provideExtractTextSignalsCases(): array
    {
        return [
            'empty string' => ['', [], []],

            'no match at all' => [
                'A wooden chair in good condition.',
                [],
                [],
            ],

            'single eee signal — plug' => [
                'Comes with UK plug.',
                ['plug'],
                [],
            ],

            'single eee signal — usb' => [
                'USB cable included.',
                ['usb'],
                [],
            ],

            // Order follows EEE_TEXT_SIGNALS constant: usb(6), charging(8), bluetooth(16), led(22), display(24), speaker(27)
            'multiple eee signals — bluetooth speaker' => [
                'Bluetooth speaker with USB charging and LED display.',
                ['usb', 'charging', 'bluetooth', 'led', 'display', 'speaker'],
                [],
            ],

            // 'battery' is NOT a substring of 'batteries' (position 6: y vs i).
            // Only 'batteries' matches. 'no batteries' and 'manual' are both non-EEE signals.
            'non-eee signal — no batteries text also triggers eee batteries signal' => [
                'No batteries required, completely manual.',
                ['batteries'],
                ['no batteries', 'manual'],
            ],

            // 'electric' IS a substring of 'not electric', so it appears as an EEE signal
            // alongside 'not electric' in the non-EEE list. This is expected — extractTextSignals
            // is a naive substring scan; conflict resolution happens in the caller.
            'electric substring match in not-electric phrase' => [
                'Not electric, hand-powered mechanism.',
                ['electric'],
                ['not electric', 'hand-powered'],
            ],

            'non-eee signal — wind-up only' => [
                'Wind-up mechanical clock.',
                [],
                ['wind-up'],
            ],

            // 'electric' appears in 'non-electric' → flagged as EEE + non-EEE conflict signal
            'conflicting signals — electric substring in non-electric' => [
                'Electric motor but non-electric mode possible.',
                ['electric'],
                ['non-electric'],
            ],

            // Case insensitive: all-caps input; 'charger' (not 'charging') is the literal word present
            'case insensitive — all upper' => [
                'BLUETOOTH SPEAKER WITH USB CHARGER.',
                ['usb', 'charger', 'bluetooth', 'speaker'],
                [],
            ],

            // 'battery' NOT in 'batteries'; only 'batteries' matches
            'case insensitive — mixed case non-eee' => [
                'NO BATTERIES included, WIND-UP movement.',
                ['batteries'],
                ['no batteries', 'wind-up'],
            ],

            // 'Batteries NOT included.' — 'battery' is NOT a substring of 'batteries'
            'batteries signal matches but battery does not' => [
                'Batteries NOT included.',
                ['batteries'],
                [],
            ],

            // 'smart' (index 19) appears before 'remote control'/'remote' (index 25/26)
            // 'remote control' not in text (no 'control' word), but 'remote' is
            'smart and remote signals ordered by constant index' => [
                'Smart TV remote.',
                ['smart', 'remote'],
                [],
            ],

            'solar signal' => [
                'Solar-powered garden light.',
                ['solar'],
                [],
            ],

            // 'battery' is NOT a substring of 'batteries'; 'batteries' and 'rechargeable' both match
            'rechargeable and batteries signals' => [
                'Rechargeable AA batteries.',
                ['batteries', 'rechargeable'],
                [],
            ],

            'cordless signal' => [
                'Cordless drill, working order.',
                ['cordless'],
                [],
            ],

            // 'wifi' (no hyphen) is NOT a substring of 'wi-fi' — hyphen breaks the match.
            // 'wi-fi' (index 18) is matched; 'router' (index 39) is also matched.
            'wi-fi matches but wifi does not' => [
                'Wi-Fi router, dual band.',
                ['wi-fi', 'router'],
                [],
            ],

            'laptop keyboard mouse signals' => [
                'Laptop with keyboard and mouse included.',
                ['keyboard', 'mouse', 'laptop'],
                [],
            ],

            'console signal' => [
                'Games console with controllers.',
                ['console'],
                [],
            ],

            'mechanical only signal' => [
                'Mechanical only watch movement.',
                [],
                ['mechanical only'],
            ],

            // 'electric' IS a substring of 'non-electric' → flagged as EEE even in obviously non-EEE text
            'electric substring in non-electric phrase' => [
                'Non-electric mower, push-only.',
                ['electric'],
                ['non-electric'],
            ],
        ];
    }

    public function test_extract_text_signals_returns_eee_and_non_eee_keys(): void
    {
        $result = $this->service->extractTextSignals('anything');
        $this->assertArrayHasKey('eee', $result);
        $this->assertArrayHasKey('non_eee', $result);
    }

    public function test_extract_text_signals_values_are_sequential_arrays(): void
    {
        $result = $this->service->extractTextSignals('USB charger, no batteries.');
        $this->assertSame(array_values($result['eee']), $result['eee']);
        $this->assertSame(array_values($result['non_eee']), $result['non_eee']);
    }

    public function test_extract_text_signals_no_exact_string_duplicates(): void
    {
        // 'remote control' and 'remote' both appear in EEE_TEXT_SIGNALS and both match
        // 'remote control unit'. They are different strings so no duplicate.
        $result = $this->service->extractTextSignals('remote control unit');
        $uniqueEee = array_unique($result['eee']);
        $this->assertCount(count($uniqueEee), $result['eee'], 'EEE signals must not contain exact duplicates');
        $this->assertContains('remote control', $result['eee']);
        $this->assertContains('remote', $result['eee']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // computeConsensus — protected; exercised via reflection
    //
    // NOTE: confidence = round(0.90 * agreeRate, 4) — max 0.90 for unanimous text votes.
    // CONFIDENCE_MIN = 0.92 > 0.90, so needs_image_analysis is always true when consensus
    // is derived from text votes (agreeRate branch) or the no-components/uncertain fallbacks.
    // This means the item-type lookup optimisation (classifyMessage fast-path) is never
    // triggered from computeConsensus output alone. See TODO below for the latent bug.
    // ─────────────────────────────────────────────────────────────────────────

    private function computeConsensus(array $results): array
    {
        $method = new ReflectionMethod(EeeClassificationService::class, 'computeConsensus');
        $method->setAccessible(true);
        return $method->invoke($this->service, $results);
    }

    // Helper: build a minimal result record for computeConsensus.
    private function makeResult(array $overrides = []): array
    {
        return array_merge([
            'is_eee'                         => null,
            'is_eee_from_text'               => null,
            'electrical_components_observed' => [],
            'weee_category'                  => null,
        ], $overrides);
    }

    public function test_consensus_no_text_votes_no_components_is_not_eee(): void
    {
        // No text votes, no components → falls into !$containsEee branch → is_eee=false
        $out = $this->computeConsensus([
            $this->makeResult(),
        ]);

        $this->assertFalse($out['is_eee'], 'No components, no text votes → not EEE');
        $this->assertSame(1.0, $out['is_eee_agree_rate']);
        $this->assertSame(0.70, $out['is_eee_confidence']);

        // TODO: latent bug — CONFIDENCE_MIN (0.92) exceeds the max possible confidence
        // from the text-votes formula (0.90 * 1.0 = 0.90), and also exceeds the 0.70
        // used in the no-components branch. This means needs_image_analysis is always true
        // from computeConsensus output, preventing the item-type lookup fast-path in
        // classifyMessage() from ever activating. Fix: lower CONFIDENCE_MIN to ≤0.90 or
        // raise the formula scale factor above 1/0.92.
        $this->assertTrue((bool) $out['needs_image_analysis'], 'confidence 0.70 < CONFIDENCE_MIN 0.92 → needs analysis');
    }

    public function test_consensus_all_eee_text_votes(): void
    {
        $results = [
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertTrue($out['is_eee']);
        $this->assertSame(1.0, $out['is_eee_agree_rate']);
        $this->assertSame(round(0.90 * 1.0, 4), $out['is_eee_confidence']);
        // confidence = 0.90 < CONFIDENCE_MIN 0.92 → needs_image_analysis is always true
        // (see TODO in test_consensus_no_text_votes_no_components_is_not_eee)
        $this->assertTrue((bool) $out['needs_image_analysis']);
    }

    public function test_consensus_all_non_eee_text_votes(): void
    {
        $results = [
            $this->makeResult(['is_eee_from_text' => false]),
            $this->makeResult(['is_eee_from_text' => false]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertFalse($out['is_eee']);
        $this->assertSame(1.0, $out['is_eee_agree_rate']);
        $this->assertSame(round(0.90 * 1.0, 4), $out['is_eee_confidence']);
    }

    public function test_consensus_majority_eee_text_votes(): void
    {
        // 2 of 3 say EEE → is_eee=true, agreeRate=2/3 < AGREE_RATE_MIN
        $results = [
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => false]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertTrue($out['is_eee']);
        $this->assertEqualsWithDelta(2 / 3, $out['is_eee_agree_rate'], 0.0001);
        $this->assertTrue((bool) $out['needs_image_analysis'], 'Low agree rate < 0.85 → needs image analysis');
    }

    public function test_consensus_exactly_half_eee_text_votes_is_not_eee(): void
    {
        // Exactly 50% EEE → (0.5 > 0.5) is false → is_eee = false
        $results = [
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => false]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertFalse($out['is_eee']);
    }

    public function test_consensus_null_text_votes_ignored(): void
    {
        // null is_eee_from_text values are filtered out; 2 real votes both EEE
        $results = [
            $this->makeResult(['is_eee_from_text' => null]),
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertTrue($out['is_eee']);
        $this->assertSame(1.0, $out['is_eee_agree_rate']);
    }

    public function test_consensus_no_text_votes_with_components_is_uncertain(): void
    {
        // No text votes, but electrical components present → null (uncertain)
        $results = [
            $this->makeResult(['electrical_components_observed' => ['motor', 'capacitor']]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertNull($out['is_eee']);
        $this->assertSame(0.50, $out['is_eee_confidence']);
        $this->assertTrue((bool) $out['needs_image_analysis']);
    }

    public function test_consensus_contains_eee_components_flag(): void
    {
        $results = [
            $this->makeResult(['electrical_components_observed' => ['motor']]),
            $this->makeResult(['electrical_components_observed' => []]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(1, $out['contains_eee_components']);
    }

    public function test_consensus_no_components_flag(): void
    {
        $results = [
            $this->makeResult(),
            $this->makeResult(),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(0, $out['contains_eee_components']);
    }

    public function test_consensus_electrical_components_description_union(): void
    {
        $results = [
            $this->makeResult(['electrical_components_observed' => ['motor', 'fan']]),
            $this->makeResult(['electrical_components_observed' => ['fan', 'capacitor']]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertNotNull($out['electrical_components_description']);
        $parts = explode('; ', $out['electrical_components_description']);
        $this->assertContains('motor', $parts);
        $this->assertContains('fan', $parts);
        $this->assertContains('capacitor', $parts);
        $this->assertCount(3, $parts, 'Duplicates should be removed from union');
    }

    public function test_consensus_no_components_gives_null_description(): void
    {
        $out = $this->computeConsensus([$this->makeResult()]);
        $this->assertNull($out['electrical_components_description']);
    }

    public function test_consensus_eee_sample_count(): void
    {
        $results = [
            $this->makeResult(['is_eee' => true]),
            $this->makeResult(['is_eee' => false]),
            $this->makeResult(['is_eee' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(2, $out['eee_sample_count']);
    }

    public function test_consensus_weee_category_modal_value(): void
    {
        // Categories: 2, 2, 5 → modal is 2 (appears twice)
        $results = [
            $this->makeResult(['weee_category' => 2, 'is_eee_from_text' => true]),
            $this->makeResult(['weee_category' => 2, 'is_eee_from_text' => true]),
            $this->makeResult(['weee_category' => 5, 'is_eee_from_text' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(2, $out['weee_category']);
        $this->assertSame('Screens and monitors', $out['weee_category_name']);
        $this->assertEqualsWithDelta(2 / 3, $out['weee_category_confidence'], 0.0001);
    }

    public function test_consensus_weee_category_null_when_all_null(): void
    {
        $results = [
            $this->makeResult(),
            $this->makeResult(),
        ];

        $out = $this->computeConsensus($results);

        $this->assertNull($out['weee_category']);
        $this->assertNull($out['weee_category_name']);
        $this->assertSame(0.0, $out['weee_category_confidence']);
    }

    public function test_consensus_weee_category_ignores_null_values(): void
    {
        // Only 1 of 3 has a non-null category; it wins with confidence=1.0
        $results = [
            $this->makeResult(['weee_category' => null, 'is_eee_from_text' => true]),
            $this->makeResult(['weee_category' => null, 'is_eee_from_text' => true]),
            $this->makeResult(['weee_category' => 6,    'is_eee_from_text' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(6, $out['weee_category']);
        $this->assertSame('Small IT and telecom equipment', $out['weee_category_name']);
        $this->assertSame(1.0, $out['weee_category_confidence']);
    }

    public function test_consensus_low_agree_rate_sets_needs_image_analysis(): void
    {
        // 2/4 EEE → agreeRate = 0.5 < AGREE_RATE_MIN (0.85) → needs_image_analysis = true
        $results = [
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => true]),
            $this->makeResult(['is_eee_from_text' => false]),
            $this->makeResult(['is_eee_from_text' => false]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertTrue((bool) $out['needs_image_analysis']);
    }

    public function test_consensus_returns_all_expected_keys(): void
    {
        $out = $this->computeConsensus([$this->makeResult()]);

        foreach ([
            'is_eee',
            'is_eee_confidence',
            'is_eee_agree_rate',
            'eee_sample_count',
            'contains_eee_components',
            'electrical_components_description',
            'weee_category',
            'weee_category_name',
            'weee_category_confidence',
            'needs_image_analysis',
        ] as $key) {
            $this->assertArrayHasKey($key, $out, "Missing key: {$key}");
        }
    }

    public function test_consensus_eee_sample_count_null_is_eee_values_excluded(): void
    {
        // is_eee=null results don't count toward eee_sample_count (??false = false)
        $results = [
            $this->makeResult(['is_eee' => null]),
            $this->makeResult(['is_eee' => true]),
        ];

        $out = $this->computeConsensus($results);

        $this->assertSame(1, $out['eee_sample_count'], 'null is_eee should not count as EEE');
    }

    public function test_consensus_components_union_deduplicates(): void
    {
        // Same component seen in two results — must appear only once in description
        $results = [
            $this->makeResult(['electrical_components_observed' => ['battery pack']]),
            $this->makeResult(['electrical_components_observed' => ['battery pack', 'motor']]),
        ];

        $out = $this->computeConsensus($results);

        $parts = explode('; ', (string) $out['electrical_components_description']);
        $this->assertCount(2, $parts, 'battery pack should appear only once after dedup');
        $this->assertContains('battery pack', $parts);
        $this->assertContains('motor', $parts);
    }
}

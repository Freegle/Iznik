<?php

namespace Tests\Unit\Services;

use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use Tests\TestCase;

/**
 * Tests for EeeComponentService::classifyComponents() — the is_eee decision.
 *
 * The adopted definition is the Material Focus line: anything with a plug, battery
 * or cable, with the products the Environment Agency names hard-coded either way.
 * See plans/2026-08-25-eee-definition-decision.md.
 *
 * A primary-function test is NOT the rule and must not creep back in. The cases
 * below are chosen so that a primary-function test fails at least five of them.
 *
 * lookupWithStatus() is stubbed to autoCategory() so these tests exercise the decision
 * logic rather than the embedding index, which needs the database.
 */
class EeeComponentDecisionTest extends TestCase
{
    private EeeComponentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $sqlite = $this->createMock(EeeSqliteService::class);

        $this->service = new class($sqlite) extends EeeComponentService {
            protected function lookupWithStatus(string $raw): array
            {
                $category = $this->autoCategory($raw);

                return [
                    'match'  => $category === 'unknown'
                        ? null
                        : ['canonical_name' => $raw, 'category' => $category],
                    'failed' => false,
                ];
            }
        };
    }

    private function decide(string $itemName, array $components): array
    {
        return $this->service->classifyComponents($components, $itemName);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Products the guidance names as EEE despite a non-electrical basic function
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A fish tank is named as EEE. It must come out true even when the photo shows
     * nothing electrical at all, because the naming is what settles it.
     */
    public function test_named_eee_product_is_eee_even_with_no_electrical_components(): void
    {
        $result = $this->decide('fish tank', ['glass panel']);

        $this->assertTrue($result['is_eee']);
        $this->assertSame('named_eee', $result['is_eee_reason']);
    }

    /**
     * @dataProvider provideNamedEeeItems
     */
    public function test_named_eee_items_are_eee(string $itemName): void
    {
        $result = $this->decide($itemName, ['metal frame']);

        $this->assertTrue($result['is_eee'], "Should be EEE: {$itemName}");
        $this->assertSame('named_eee', $result['is_eee_reason']);
    }

    public static function provideNamedEeeItems(): array
    {
        return array_map(fn($n) => [$n], [
            'fish tank', 'aquarium', 'spa bath', 'hydrotherapy bath', 'hot tub',
            'loft ladder', 'roller blind', 'gym equipment', 'exercise bike',
            'treadmill', 'cross trainer', 'rowing machine', 'games console',
            'riser chair', 'hospital bed',
        ]);
    }

    /**
     * An exercise bike with an electronic console was previously scored as NOT EEE
     * on the basis that pedalling is mechanical. Gym equipment is named as EEE, so
     * that expected answer was wrong. Guards the regression.
     */
    public function test_exercise_bike_with_console_is_eee(): void
    {
        $result = $this->decide('exercise bike', ['digital display console']);

        $this->assertTrue($result['is_eee']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Products the guidance names as NOT EEE
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * A gas cooker whose only electrics are a clock and an igniter is named as not
     * EEE. This is the one case where the adopted line yields to government.
     */
    public function test_gas_cooker_with_only_support_electrics_is_not_eee(): void
    {
        $result = $this->decide('gas cooker', ['electronic ignition', 'clock display']);

        $this->assertFalse($result['is_eee']);
        $this->assertSame('named_not_eee', $result['is_eee_reason']);
    }

    /**
     * The named exception must not swallow a cooker that genuinely needs electricity.
     * A gas cooker with a fan oven has a motor, so it escapes the exception.
     */
    public function test_gas_cooker_with_a_motor_is_eee(): void
    {
        $result = $this->decide('gas cooker', ['fan motor', 'electronic ignition']);

        $this->assertTrue($result['is_eee']);
        $this->assertSame('primary', $result['is_eee_reason']);
    }

    /**
     * @dataProvider provideNamedNotEeeItems
     */
    public function test_named_not_eee_items_are_not_eee(string $itemName): void
    {
        $result = $this->decide($itemName, ['electronic ignition']);

        $this->assertFalse($result['is_eee'], "Should not be EEE: {$itemName}");
    }

    public static function provideNamedNotEeeItems(): array
    {
        return array_map(fn($n) => [$n], [
            'gas cooker', 'gas hob', 'gas oven', 'gas stove', 'gas range',
            'petrol mower', 'petrol lawn mower', 'petrol lawnmower',
            'petrol strimmer', 'petrol chainsaw', 'petrol hedge cutter',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // The Material Focus line for everything not named
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The baby bouncer case. Bouncing a baby is not electrical, but the bouncer has
     * a battery-powered music player, so under the adopted line it is EEE. A
     * primary-function test would call this not EEE.
     */
    public function test_baby_bouncer_with_music_player_is_eee(): void
    {
        $result = $this->decide('baby bouncer', ['built-in speakers', 'battery compartment']);

        $this->assertTrue($result['is_eee']);
    }

    /**
     * Supplementary-only electrics are enough under the adopted line. This is the
     * behaviour change from the old rule, which deferred to the text signal here.
     */
    public function test_supplementary_only_components_make_the_item_eee(): void
    {
        $result = $this->decide('wardrobe', ['ambient light strip']);

        $this->assertTrue($result['is_eee']);
        $this->assertSame('supplementary', $result['is_eee_reason']);
    }

    public function test_item_with_no_electrical_components_is_not_eee(): void
    {
        $result = $this->decide('coffee table', ['fabric', 'door seal']);

        $this->assertFalse($result['is_eee']);
        $this->assertSame('no_electrical_components', $result['is_eee_reason']);
    }

    /**
     * No components observed is unknown, not false. An empty extraction means the
     * model saw nothing, which is not the same as seeing nothing electrical.
     */
    public function test_no_components_observed_is_null_not_false(): void
    {
        $result = $this->decide('toaster', []);

        $this->assertNull($result['is_eee']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Behaviour without an item name
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * The named lists can only be applied when the item name is known. Without it
     * the decision falls back to the components alone, so a gas cooker's ignition
     * would read as EEE. Callers should pass the name; this documents the cost of
     * not doing so.
     */
    public function test_without_item_name_named_exceptions_cannot_apply(): void
    {
        $result = $this->service->classifyComponents(['electronic ignition', 'clock display']);

        $this->assertTrue($result['is_eee']);
        $this->assertSame('supplementary', $result['is_eee_reason']);
    }

    public function test_contains_eee_components_is_independent_of_is_eee(): void
    {
        // Named not EEE, but it does physically contain electrical components, and
        // any battery in it is still reportable under the battery regulations.
        $result = $this->decide('gas cooker', ['electronic ignition']);

        $this->assertFalse($result['is_eee']);
        $this->assertTrue($result['contains_eee_components']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Lookup outages
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * When the embedding service fails, an unmatched component means "could not
     * look", not "looked and found nothing" — so the verdict must be unknown, not
     * a confident "not electrical" built on an outage.
     */
    public function test_failed_lookups_yield_unknown_not_false(): void
    {
        $sqlite  = $this->createMock(EeeSqliteService::class);
        $failing = new class($sqlite) extends EeeComponentService {
            protected function lookupWithStatus(string $raw): array
            {
                return ['match' => null, 'failed' => true];
            }
        };

        $result = $failing->classifyComponents(['mystery widget'], 'unknown thing');

        $this->assertNull($result['is_eee']);
        $this->assertSame('lookup_unavailable', $result['is_eee_reason']);
    }

    /**
     * A genuine no-match (the embedding worked, nothing similar exists) stays a
     * real negative: the outage path must not swallow ordinary non-electrical items.
     */
    public function test_genuine_no_match_still_reads_not_electrical(): void
    {
        $result = $this->decide('coffee table', ['completely unknown thing']);

        $this->assertFalse($result['is_eee']);
        $this->assertSame('no_electrical_components', $result['is_eee_reason']);
    }

    /**
     * A positive match still decides even when another component's lookup failed:
     * the outage only matters when it is the difference between verdicts.
     */
    public function test_positive_match_beats_a_failed_lookup(): void
    {
        $sqlite = $this->createMock(EeeSqliteService::class);
        $mixed  = new class($sqlite) extends EeeComponentService {
            protected function lookupWithStatus(string $raw): array
            {
                if ($raw === 'power cable') {
                    return [
                        'match'  => ['canonical_name' => $raw, 'category' => 'primary_eee'],
                        'failed' => false,
                    ];
                }

                return ['match' => null, 'failed' => true];
            }
        };

        $result = $mixed->classifyComponents(['power cable', 'mystery widget'], 'lamp');

        $this->assertTrue($result['is_eee']);
        $this->assertSame('primary', $result['is_eee_reason']);
    }
}

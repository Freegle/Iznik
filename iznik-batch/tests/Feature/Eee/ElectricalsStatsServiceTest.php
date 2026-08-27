<?php

namespace Tests\Feature\Eee;

use App\Services\ElectricalsStatsService;
use App\Services\EeeVisionService;
use App\Services\ItemService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the payload behind the public /electricals page.
 *
 * The figures here are the ones a council or Material Focus will quote back at us, so the
 * cases worth pinning are the ones where a plausible query would be quietly wrong:
 *
 *  - null is_eee must leave the denominator, not count as "not electrical"
 *  - the rippling fan-out must not multiply counts
 *  - unsettled posts must not drag the success rate down
 *  - a rare-looking item must not reach the page on one person's odd phrasing
 */
class ElectricalsStatsServiceTest extends TestCase
{
    private ElectricalsStatsService $stats;
    private ItemService $items;
    private string $model = 'test-model';

    protected function setUp(): void
    {
        parent::setUp();

        $vision = $this->createMock(EeeVisionService::class);
        $vision->method('getModelName')->willReturn($this->model);

        $this->stats = new ElectricalsStatsService($vision);
        $this->items = new ItemService();

        DB::table('messages_eee')->delete();
    }

    /**
     * Create an OFFER with an item name and a classification.
     *
     * $isEee may be 1, 0 or null; null is the "model observed nothing" case.
     */
    private function offer(
        string $itemName,
        ?int $isEee,
        string $arrival,
        ?string $outcome = null,
        ?string $condition = null,
        ?int $userid = null,
        ?int $groupid = null,
    ): int {
        $user  = $userid ?? $this->createTestUser();
        $group = $groupid ?? $this->createTestGroup();

        $message = $this->createTestMessage($user, $group);

        DB::table('messages')->where('id', $message->id)->update([
            'type'    => 'Offer',
            'arrival' => $arrival,
            'subject' => 'OFFER: ' . $itemName . ' (Test)',
        ]);
        DB::table('messages_groups')->where('msgid', $message->id)->update(['arrival' => $arrival]);

        $itemid = $this->items->findOrCreate($itemName);
        $this->items->linkToMessage($message->id, $itemid);

        DB::table('messages_eee')->insert([
            'msgid'          => $message->id,
            'is_eee'         => $isEee,
            'item_condition' => $condition,
            'model'          => $this->model,
            'prompt_version' => '1',
            'classified_at'  => $arrival,
        ]);

        if ($outcome) {
            DB::table('messages_outcomes')->insert([
                'msgid'     => $message->id,
                'outcome'   => $outcome,
                'timestamp' => $arrival,
            ]);
        }

        return (int) $message->id;
    }

    private function recent(int $daysAgo = 60): string
    {
        return now()->subDays($daysAgo)->toDateTimeString();
    }

    public function test_counts_electrical_share(): void
    {
        $this->offer('Kettle', 1, $this->recent());
        $this->offer('Toaster', 1, $this->recent());
        $this->offer('Sofa', 0, $this->recent());
        $this->offer('Table', 0, $this->recent());

        $counts = $this->stats->build()['counts'];

        $this->assertSame(4, $counts['classified']);
        $this->assertSame(2, $counts['electrical']);
        $this->assertSame(50.0, $counts['electrical_pct']);
    }

    /**
     * null means the model observed nothing, which is not the same as observing nothing
     * electrical. Counting nulls as false would understate the share on every figure.
     */
    public function test_unknown_classifications_leave_the_denominator(): void
    {
        $this->offer('Kettle', 1, $this->recent());
        $this->offer('Mystery', null, $this->recent());

        $counts = $this->stats->build()['counts'];

        $this->assertSame(1, $counts['classified'], 'the unknown must not be counted');
        $this->assertSame(1, $counts['electrical']);
        $this->assertSame(100.0, $counts['electrical_pct']);
    }

    /** Items outside the twelve-month window must not appear. */
    public function test_window_excludes_older_items(): void
    {
        $this->offer('Kettle', 1, $this->recent());
        $this->offer('Ancient Kettle', 1, now()->subMonths(18)->toDateTimeString());

        $this->assertSame(1, $this->stats->build()['counts']['classified']);
    }

    /**
     * A post that reached forty groups is one item, not forty. messages_groups carries a
     * row per group reached, so any join to it without rippled_in = 0 multiplies the
     * count by the fan-out.
     */
    public function test_rippled_copies_do_not_multiply_counts(): void
    {
        $msgid = $this->offer('Kettle', 1, $this->recent());

        // A ripple copy: same message, another group, flagged as rippled in.
        DB::table('messages_groups')->insert([
            'msgid'      => $msgid,
            'groupid'    => $this->createTestGroup(),
            'arrival'    => $this->recent(),
            'collection' => 'Approved',
            'rippled_in' => 1,
        ]);

        $this->assertSame(1, $this->stats->build()['counts']['electrical']);
    }

    public function test_success_rate_compares_electrical_with_the_rest(): void
    {
        $this->offer('Kettle', 1, $this->recent(90), 'Taken');
        $this->offer('Toaster', 1, $this->recent(90));
        $this->offer('Sofa', 0, $this->recent(90), 'Taken');
        $this->offer('Table', 0, $this->recent(90), 'Taken');

        $success = $this->stats->build()['success'];

        $this->assertSame(50.0, $success['electrical']['taken_pct']);
        $this->assertSame(100.0, $success['other']['taken_pct']);
    }

    /**
     * A post from last week that has not been taken has not failed, it has not finished.
     * Including it would drag the rate down for no reason.
     */
    public function test_success_rate_ignores_unsettled_posts(): void
    {
        $this->offer('Kettle', 1, $this->recent(90), 'Taken');
        $this->offer('New Kettle', 1, $this->recent(2));

        $success = $this->stats->build()['success'];

        $this->assertSame(1, $success['electrical']['posts'], 'the unsettled post must be excluded');
        $this->assertSame(100.0, $success['electrical']['taken_pct']);
    }

    public function test_condition_split_reports_taken_rate_per_condition(): void
    {
        $this->offer('Kettle', 1, $this->recent(), 'Taken', 'reusable');
        $this->offer('Broken Kettle', 1, $this->recent(), 'Taken', 'damaged');
        $this->offer('Other Broken Kettle', 1, $this->recent(), null, 'damaged');

        $condition = $this->stats->build()['condition'];

        $this->assertSame(1, $condition['reusable']['count']);
        $this->assertSame(2, $condition['damaged']['count']);
        $this->assertSame(50.0, $condition['damaged']['taken_pct']);
    }

    public function test_popular_lists_most_offered_electrical_items(): void
    {
        $group = $this->createTestGroup();
        foreach (range(1, 3) as $ignored) {
            $this->offer('Kettle', 1, $this->recent(), null, null, null, $group);
        }
        $this->offer('Toaster', 1, $this->recent(), null, null, null, $group);
        $this->offer('Sofa', 0, $this->recent(), null, null, null, $group);

        $popular = $this->stats->build()['popular'];

        $this->assertSame('Kettle', $popular[0]['name']);
        $this->assertSame(3, $popular[0]['count']);
        $this->assertNotContains('Sofa', array_column($popular, 'name'), 'non-electrical must not appear');
    }

    /**
     * The guard exists because raw rarity surfaces typos and one-off phrasings. One person
     * posting an odd name three times must not reach the page.
     */
    public function test_unusual_excludes_items_from_a_single_member(): void
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        foreach (range(1, 3) as $ignored) {
            $this->offer('Weird Gizmo', 1, $this->recent(), null, null, $user, $group);
        }

        $names = array_column($this->stats->build()['unusual']['items'], 'name');

        $this->assertNotContains('Weird Gizmo', $names);
    }

    /** And one community's local usage must not either. */
    public function test_unusual_excludes_items_from_a_single_group(): void
    {
        $group = $this->createTestGroup();

        foreach (range(1, 3) as $ignored) {
            $this->offer('Local Gizmo', 1, $this->recent(), null, null, null, $group);
        }

        $names = array_column($this->stats->build()['unusual']['items'], 'name');

        $this->assertNotContains('Local Gizmo', $names);
    }

    /** An item several people in different communities have offered does qualify. */
    public function test_unusual_includes_a_genuinely_shared_rare_item(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->offer('Bread Maker', 1, $this->recent());
        }

        $names = array_column($this->stats->build()['unusual']['items'], 'name');

        $this->assertContains('Bread Maker', $names);
    }

    /** A sentence typed into the subject is not an item name. */
    public function test_unusual_excludes_sentence_length_names(): void
    {
        $longName = 'Old broken electric heater needs fixing';

        foreach (range(1, 3) as $ignored) {
            $this->offer($longName, 1, $this->recent());
        }

        $names = array_column($this->stats->build()['unusual']['items'], 'name');

        $this->assertNotContains($longName, $names);
    }

    /**
     * The payload has to carry the measured accuracy so the page can state it next to each
     * figure, and has to keep saying that weight and size are not publishable.
     */
    public function test_payload_carries_accuracy_and_marks_weight_unpublishable(): void
    {
        $accuracy = $this->stats->build()['accuracy'];

        $this->assertTrue($accuracy['is_electrical']['publish']);
        $this->assertTrue($accuracy['condition']['publish']);
        $this->assertFalse($accuracy['size']['publish']);
        $this->assertFalse($accuracy['weight']['publish']);
    }

    /** Tonnage must come from the catalogue, and say so. */
    public function test_impact_states_its_weight_basis(): void
    {
        $impact = $this->stats->build()['impact'];

        $this->assertStringContainsString('items.weight', $impact['basis']);
        $this->assertStringContainsString('not the vision model', $impact['basis']);
        $this->assertSame(
            ElectricalsStatsService::CARBON_VALUE_PER_TONNE_GBP,
            $impact['carbon_proxy_gbp_per_tonne']
        );
    }
}

<?php

namespace Tests\Feature\Items;

use App\Services\ItemService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers items:backfill-popularity, which recomputes items.popularity from messages_items.
 *
 * The forward increment in ItemService only fixes items posted from now on. Popularity had
 * been frozen for a long time, so the history has to be put back or every consumer of the
 * popularity-weighted mean item weight stays wrong.
 */
class BackfillItemPopularityCommandTest extends TestCase
{
    private ItemService $items;

    protected function setUp(): void
    {
        parent::setUp();
        $this->items = new ItemService();
    }

    /** Link $count fresh messages to $itemid without going through ItemService. */
    private function linkRaw(int $itemid, int $count): void
    {
        $group = $this->createTestGroup();

        for ($i = 0; $i < $count; $i++) {
            $message = $this->createTestMessage($this->createTestUser(), $group);
            DB::statement(
                'INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)',
                [$message->id, $itemid]
            );
        }
    }

    public function test_backfill_sets_popularity_from_messages_items(): void
    {
        $itemid = $this->items->findOrCreate('Backfill Kettle');
        $this->linkRaw($itemid, 3);

        // Raw links bypassed the increment, so it is still zero.
        $this->assertSame(0, (int) DB::table('items')->where('id', $itemid)->value('popularity'));

        $this->artisan('items:backfill-popularity')->assertExitCode(0);

        $this->assertSame(3, (int) DB::table('items')->where('id', $itemid)->value('popularity'));
    }

    /**
     * Sets rather than adds, so running it twice does not double the count and it is safe
     * to run alongside the live forward increment.
     */
    public function test_backfill_is_idempotent(): void
    {
        $itemid = $this->items->findOrCreate('Backfill Blender');
        $this->linkRaw($itemid, 2);

        $this->artisan('items:backfill-popularity')->assertExitCode(0);
        $this->artisan('items:backfill-popularity')->assertExitCode(0);

        $this->assertSame(2, (int) DB::table('items')->where('id', $itemid)->value('popularity'));
    }

    /** An item nobody has posted must end at zero, not be left at a stale value. */
    public function test_backfill_zeroes_an_item_with_no_messages(): void
    {
        $itemid = $this->items->findOrCreate('Backfill Unposted');
        DB::table('items')->where('id', $itemid)->update(['popularity' => 99]);

        $this->artisan('items:backfill-popularity')->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('items')->where('id', $itemid)->value('popularity'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $itemid = $this->items->findOrCreate('Backfill Dry Run');
        $this->linkRaw($itemid, 2);

        $this->artisan('items:backfill-popularity', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, (int) DB::table('items')->where('id', $itemid)->value('popularity'));
    }

    /** Chunking must not change the answer, only how it is reached. */
    public function test_small_chunks_give_the_same_result(): void
    {
        $kettle  = $this->items->findOrCreate('Backfill Chunk Kettle');
        $blender = $this->items->findOrCreate('Backfill Chunk Blender');
        $this->linkRaw($kettle, 2);
        $this->linkRaw($blender, 1);

        $this->artisan('items:backfill-popularity', ['--chunk' => 1])->assertExitCode(0);

        $this->assertSame(2, (int) DB::table('items')->where('id', $kettle)->value('popularity'));
        $this->assertSame(1, (int) DB::table('items')->where('id', $blender)->value('popularity'));
    }
}

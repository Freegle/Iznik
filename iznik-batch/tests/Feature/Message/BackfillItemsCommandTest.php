<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Services\StatsGenerationService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backfill command reconstructs the messages_items links that the V2
 * incoming-mail migration stopped creating (cutover 2026-02-04), and then
 * regenerates the affected `stats` rows so the Weight figures recover.
 */
class BackfillItemsCommandTest extends TestCase
{
    private function seedTakenOfferWithoutItem(string $subject, string $date): Message
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser();
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => $subject,
            'source' => Message::SOURCE_EMAIL,
            'sourceheader' => 'TN-native-app',
            'arrival' => $date.' 09:00:00',
        ]);
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'userid' => $user->id,
            'outcome' => Message::OUTCOME_TAKEN,
            'timestamp' => $date.' 12:00:00',
        ]);

        // No messages_items row — this is the bug state.
        $this->assertSame(0, DB::table('messages_items')->where('msgid', $message->id)->count());

        return $message;
    }

    public function test_backfills_missing_messages_items_link(): void
    {
        $message = $this->seedTakenOfferWithoutItem('OFFER: Vintage Oak Table (Bristol BS1)', '2026-02-10');

        $this->artisan('messages:backfill-items', [
            '--from' => '2026-02-01',
            '--to' => '2026-02-28',
        ])->assertSuccessful();

        $link = DB::table('messages_items')->where('msgid', $message->id)->first();
        $this->assertNotNull($link, 'backfill should create the messages_items link');
        $this->assertSame('Vintage Oak Table', DB::table('items')->where('id', $link->itemid)->value('name'));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $message = $this->seedTakenOfferWithoutItem('OFFER: Dry Run Item (Bristol BS1)', '2026-02-10');

        $this->artisan('messages:backfill-items', [
            '--from' => '2026-02-01',
            '--to' => '2026-02-28',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertSame(0, DB::table('messages_items')->where('msgid', $message->id)->count());
    }

    public function test_skips_messages_that_already_have_items(): void
    {
        $message = $this->seedTakenOfferWithoutItem('OFFER: Already Linked (Bristol BS1)', '2026-02-10');
        $itemid = DB::table('items')->insertGetId(['name' => 'Pre Existing Item']);
        DB::table('messages_items')->insert(['msgid' => $message->id, 'itemid' => $itemid]);

        $this->artisan('messages:backfill-items', [
            '--from' => '2026-02-01',
            '--to' => '2026-02-28',
        ])->assertSuccessful();

        // The original link is untouched; no second item was added from the subject.
        $items = DB::table('messages_items')->where('msgid', $message->id)->pluck('itemid')->all();
        $this->assertSame([$itemid], $items);
    }

    public function test_regenerates_weight_stats_with_stats_flag(): void
    {
        DB::table('weights')->insert(['name' => 'table', 'simplename' => null, 'weight' => 30.00]);
        $message = $this->seedTakenOfferWithoutItem('OFFER: Vintage Oak Table (Bristol BS1)', '2026-02-10');
        $groupId = DB::table('messages_groups')->where('msgid', $message->id)->value('groupid');

        $this->artisan('messages:backfill-items', [
            '--from' => '2026-02-01',
            '--to' => '2026-02-28',
            '--stats' => true,
        ])->assertSuccessful();

        $weightRow = DB::table('stats')
            ->where('date', '2026-02-10')
            ->where('groupid', $groupId)
            ->where('type', StatsGenerationService::TYPE_WEIGHT)
            ->first();
        $this->assertNotNull($weightRow, 'Weight stat should be regenerated for the affected date');
        $this->assertEquals(30, $weightRow->count);
    }
}

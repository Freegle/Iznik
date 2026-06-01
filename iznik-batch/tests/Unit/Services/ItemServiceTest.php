<?php

namespace Tests\Unit\Services;

use App\Services\ItemService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers item catalog find-or-create + message linking + weight estimation,
 * ported from V1 iznik-server Item::create()/estimateWeight() and the
 * Message::save() item-extraction regex. This is the building block the
 * incoming-mail path and the backfill command both use to populate
 * messages_items so the Weight stat can find items moved.
 */
class ItemServiceTest extends TestCase
{
    private ItemService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ItemService();
    }

    public function test_extracts_item_name_from_well_formed_subject(): void
    {
        $this->assertSame('Big Red Sofa', $this->service->extractItemName('OFFER: Big Red Sofa (London)'));
        $this->assertSame('Bike', $this->service->extractItemName('WANTED: Bike (Edinburgh EH1)'));
    }

    public function test_extracts_item_name_returns_null_for_malformed_subject(): void
    {
        $this->assertNull($this->service->extractItemName('Re: a chat reply with no item'));
        $this->assertNull($this->service->extractItemName('OFFER: no parens here'));
        $this->assertNull($this->service->extractItemName('OFFER:  (London)'));
    }

    public function test_find_or_create_returns_null_for_empty_name(): void
    {
        $this->assertNull($this->service->findOrCreate(''));
        $this->assertNull($this->service->findOrCreate('   '));
    }

    public function test_find_or_create_is_idempotent_and_case_insensitive(): void
    {
        $id1 = $this->service->findOrCreate('Wooden Chair');
        $this->assertNotNull($id1);

        // Same name, different case -> same catalog row (name index is case-insensitive).
        $id2 = $this->service->findOrCreate('wooden chair');
        $this->assertSame($id1, $id2);

        $this->assertSame(1, DB::table('items')->where('name', 'Wooden Chair')->count());
    }

    public function test_find_or_create_truncates_long_names(): void
    {
        $long = str_repeat('a', 100);
        $id = $this->service->findOrCreate($long);
        $name = DB::table('items')->where('id', $id)->value('name');
        $this->assertSame(60, strlen($name));
    }

    public function test_find_or_create_estimates_weight_from_weights_table(): void
    {
        DB::table('weights')->insert(['name' => 'sofa', 'simplename' => null, 'weight' => 40.00]);

        $id = $this->service->findOrCreate('Big Comfy Sofa');

        $weight = DB::table('items')->where('id', $id)->value('weight');
        $this->assertNotNull($weight);
        $this->assertEquals(40.00, (float) $weight);
    }

    public function test_find_or_create_leaves_weight_null_when_no_match(): void
    {
        DB::table('weights')->insert(['name' => 'sofa', 'simplename' => null, 'weight' => 40.00]);

        $id = $this->service->findOrCreate('Xylophone');

        $this->assertNull(DB::table('items')->where('id', $id)->value('weight'));
    }

    public function test_link_to_message_is_idempotent(): void
    {
        // messages_items has a FK to messages, so we need a real message row.
        $message = $this->createTestMessage($this->createTestUser(), $this->createTestGroup());
        $itemid = $this->service->findOrCreate('Lamp');

        $this->service->linkToMessage($message->id, $itemid);
        $this->service->linkToMessage($message->id, $itemid);

        $this->assertSame(1, DB::table('messages_items')
            ->where('msgid', $message->id)->where('itemid', $itemid)->count());
    }

    public function test_record_from_subject_creates_item_and_link(): void
    {
        $message = $this->createTestMessage($this->createTestUser(), $this->createTestGroup());

        $itemid = $this->service->recordFromSubject($message->id, 'OFFER: Garden Spade (Leeds LS1)');

        $this->assertNotNull($itemid);
        $this->assertSame('Garden Spade', DB::table('items')->where('id', $itemid)->value('name'));
        $this->assertSame(1, DB::table('messages_items')
            ->where('msgid', $message->id)->where('itemid', $itemid)->count());
    }

    public function test_record_from_subject_returns_null_for_malformed_subject(): void
    {
        $message = $this->createTestMessage($this->createTestUser(), $this->createTestGroup());

        $this->assertNull($this->service->recordFromSubject($message->id, 'just a plain subject'));
        $this->assertSame(0, DB::table('messages_items')->where('msgid', $message->id)->count());
    }
}

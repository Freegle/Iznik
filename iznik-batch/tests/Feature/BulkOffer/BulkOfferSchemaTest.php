<?php

namespace Tests\Feature\BulkOffer;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Schema + integrity coverage for the bulk-offer (clearance) tables:
 *   messages_bulk_items, messages_bulk_items_interest, and the separate
 *   messages_bulk_item_attachments + messages_bulk_access tables. The feature is
 *   fully additive - it adds NO column to any core table.
 */
class BulkOfferSchemaTest extends TestCase
{
    public function test_tables_and_columns_exist(): void
    {
        $this->assertTrue(Schema::hasTable('messages_bulk_items'));
        $this->assertTrue(Schema::hasColumns('messages_bulk_items', [
            'id', 'msgid', 'position', 'name', 'quantity', 'condition', 'dimensions', 'description',
        ]));

        $this->assertTrue(Schema::hasTable('messages_bulk_items_interest'));
        $this->assertTrue(Schema::hasColumns('messages_bulk_items_interest', [
            'id', 'bulkitemid', 'msgid', 'userid', 'quantity', 'cancollect', 'state', 'chatid',
        ]));

        // Item photos and access instructions live in their own bulk-only
        // tables; the feature adds NO column to core tables.
        $this->assertTrue(Schema::hasTable('messages_bulk_item_attachments'));
        $this->assertTrue(Schema::hasColumns('messages_bulk_item_attachments', ['id', 'bulkitemid', 'attachmentid']));
        $this->assertTrue(Schema::hasTable('messages_bulk_access'));
        $this->assertTrue(Schema::hasColumns('messages_bulk_access', ['id', 'msgid', 'accessinstructions']));
        $this->assertFalse(
            Schema::hasColumn('messages_attachments', 'bulkitemid'),
            'bulk-offer must not add a column to the core messages_attachments table'
        );
    }

    public function test_defaults_applied(): void
    {
        [$msgid] = $this->makeBulkOffer();
        $itemId = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $msgid,
            'name' => 'Office desk',
        ]);
        $row = DB::table('messages_bulk_items')->find($itemId);
        $this->assertEquals(1, $row->quantity);
        $this->assertEquals('Unknown', $row->condition);
        $this->assertEquals(0, $row->position);
    }

    public function test_interest_unique_per_item_and_user(): void
    {
        [$msgid, $user] = $this->makeBulkOffer();
        $itemId = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $msgid, 'name' => 'Chair', 'quantity' => 14,
        ]);

        DB::table('messages_bulk_items_interest')->insert([
            'bulkitemid' => $itemId, 'msgid' => $msgid, 'userid' => $user->id, 'quantity' => 6,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('messages_bulk_items_interest')->insert([
            'bulkitemid' => $itemId, 'msgid' => $msgid, 'userid' => $user->id, 'quantity' => 2,
        ]);
    }

    public function test_deleting_message_cascades_items_and_interest(): void
    {
        [$msgid, $user] = $this->makeBulkOffer();
        $itemId = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $msgid, 'name' => 'Cabinet', 'quantity' => 3,
        ]);
        DB::table('messages_bulk_items_interest')->insert([
            'bulkitemid' => $itemId, 'msgid' => $msgid, 'userid' => $user->id, 'quantity' => 1,
        ]);

        $this->assertDatabaseHas('messages_bulk_items', ['id' => $itemId]);
        $this->assertDatabaseHas('messages_bulk_items_interest', ['bulkitemid' => $itemId]);

        DB::table('messages')->where('id', $msgid)->delete();

        $this->assertDatabaseMissing('messages_bulk_items', ['id' => $itemId]);
        $this->assertDatabaseMissing('messages_bulk_items_interest', ['bulkitemid' => $itemId]);
    }

    public function test_deleting_item_removes_attachment_link_but_keeps_attachment(): void
    {
        [$msgid] = $this->makeBulkOffer();
        $itemId = DB::table('messages_bulk_items')->insertGetId([
            'msgid' => $msgid, 'name' => 'Lamp',
        ]);
        $attId = DB::table('messages_attachments')->insertGetId([
            'msgid' => $msgid,
        ]);
        DB::table('messages_bulk_item_attachments')->insert([
            'bulkitemid' => $itemId, 'attachmentid' => $attId,
        ]);

        DB::table('messages_bulk_items')->where('id', $itemId)->delete();

        // The link cascades away with the item; the core attachment is untouched.
        $this->assertDatabaseMissing('messages_bulk_item_attachments', ['attachmentid' => $attId]);
        $this->assertNotNull(
            DB::table('messages_attachments')->find($attId),
            'attachment should survive item deletion'
        );
    }

    /**
     * @return array{0:int,1:\App\Models\User}
     */
    private function makeBulkOffer(): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Office Clearance (Brighton BN1)',
        ]);

        return [$message->id, $user];
    }
}

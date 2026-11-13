<?php

namespace Tests\Feature\Helper;

use Illuminate\Support\Facades\Schema;
use Tests\Support\DescribesColumns;
use Tests\TestCase;

/**
 * Verifies the Freegle Helper schema is created by the migrations (run against the
 * fresh test database). The five helper_* tables back the AI concierge; see
 * plans/active/freegle-helper-implementation.md.
 */
class HelperSchemaTest extends TestCase
{
    use DescribesColumns;

    public function test_all_helper_tables_exist(): void
    {
        foreach ([
            'helper_batches',
            'helper_repliers',
            'helper_item_states',
            'helper_proposals',
            'helper_sent_messages',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table $table");
        }
    }

    public function test_batches_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('helper_batches', [
            'id', 'msgid', 'offereruserid', 'status', 'briefing',
            'lastpolledat', 'lastrunat', 'pausedat',
        ]));
    }

    public function test_repliers_columns_and_fsm_states(): void
    {
        $this->assertTrue(Schema::hasColumns('helper_repliers', [
            'id', 'batchid', 'userid', 'chatid', 'state', 'collection_ok',
            'criteria_met', 'transport_ok', 'distance_miles', 'is_connector',
            'related_to', 'cooldown_until', 'last_processed_chatmsgid', 'knowledge',
        ]));

        // The state column must allow every FSM state the Helper drives.
        $states = $this->describeColumn('helper_repliers', 'state')['values'];
        foreach ([
            'NEW', 'GATHERING', 'QUALIFIED', 'ALLOCATED', 'CONFIRMED', 'COLLECTED',
            'PARKED_REPLIED', 'PARKED_QUIET', 'ESCALATED', 'TIMED_OUT', 'WITHDRAWN', 'REJECTED',
        ] as $state) {
            $this->assertContains($state, $states, "FSM state $state missing from the state column");
        }
    }

    public function test_item_states_and_proposals_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('helper_item_states', [
            'id', 'replierid', 'bulkitemid', 'state', 'qty_wanted', 'qty_allocated',
            'score', 'score_breakdown',
        ]));
        $this->assertTrue(Schema::hasColumns('helper_proposals', [
            'id', 'batchid', 'type', 'replierid', 'bulkitemid', 'summary',
            'proposed_text', 'payload', 'rationale', 'status', 'resolved_text',
            'resolvedat', 'resolvedby',
        ]));
    }

    public function test_sent_messages_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('helper_sent_messages', [
            'id', 'batchid', 'chatmsgid', 'chatid', 'replierid', 'kind', 'auto', 'proposalid',
        ]));
    }
}

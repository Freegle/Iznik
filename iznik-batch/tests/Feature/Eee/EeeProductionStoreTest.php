<?php

namespace Tests\Feature\Eee;

use App\Services\EeeProductionStore;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers the narrow production projection into messages_eee.
 *
 * The store's job is discipline, not storage: the verdict must come from the component
 * rule and never the model's overall opinion, null must survive as null, and the
 * high-water mark must read the same approval clock the incremental run selects on.
 */
class EeeProductionStoreTest extends TestCase
{
    private EeeProductionStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new EeeProductionStore();
        DB::table('messages_eee')->delete();
    }

    private function makeMessage(): int
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();

        return (int) $this->createTestMessage($user, $group)->id;
    }

    private function row(int $msgid): ?object
    {
        return DB::table('messages_eee')->where('msgid', $msgid)->first();
    }

    public function test_upsert_takes_verdict_from_components_not_the_model(): void
    {
        $msgid = $this->makeMessage();

        $this->store->upsert([
            'messageid'              => $msgid,
            'is_eee'                 => 1,     // the model's own opinion — must be ignored
            'is_eee_from_components' => 0,     // the component rule — must win
            'is_eee_reason'          => 'no_electrical_components',
            'model'                  => 'test-model',
            'prompt_version'         => '1',
        ]);

        $row = $this->row($msgid);

        $this->assertSame(0, (int) $row->is_eee);
        $this->assertSame('no_electrical_components', $row->is_eee_reason);
    }

    public function test_upsert_preserves_null_verdicts(): void
    {
        $msgid = $this->makeMessage();

        $this->store->upsert([
            'messageid'      => $msgid,
            'model'          => 'test-model',
            'prompt_version' => '1',
        ]);

        $this->assertNull($this->row($msgid)->is_eee, 'null must survive — it means undecided, not false');
    }

    public function test_upsert_corrects_rather_than_duplicates(): void
    {
        $msgid = $this->makeMessage();

        foreach ([0, 1] as $verdict) {
            $this->store->upsert([
                'messageid'              => $msgid,
                'is_eee_from_components' => $verdict,
                'model'                  => 'test-model',
                'prompt_version'         => '1',
            ]);
        }

        $this->assertSame(1, DB::table('messages_eee')->where('msgid', $msgid)->count());
        $this->assertSame(1, (int) $this->row($msgid)->is_eee);
    }

    public function test_buckets_collapse_free_text_onto_the_volunteer_buckets(): void
    {
        $msgid = $this->makeMessage();

        $this->store->upsert([
            'messageid'              => $msgid,
            'is_eee_from_components' => 1,
            // "working but scratched" contains both a damage and a reusable word;
            // nothing here is a damage word, so it must land reusable.
            'condition'              => 'working, some scratches',
            'size_cm'                => ['width' => 30, 'height' => 55, 'depth' => 20],
            'weight_kg_min'          => 2,
            'weight_kg_max'          => 4,
            'model'                  => 'test-model',
            'prompt_version'         => '1',
        ]);

        $row = $this->row($msgid);

        $this->assertSame('reusable', $row->item_condition);
        $this->assertSame('medium', $row->size_bucket, 'longest dimension 55cm is the medium bucket');
        $this->assertSame('1_5kg', $row->weight_bucket, 'midpoint 3kg is the 1-5kg bucket');
    }

    public function test_damage_words_win_over_reusable_words(): void
    {
        $msgid = $this->makeMessage();

        $this->store->upsert([
            'messageid'              => $msgid,
            'is_eee_from_components' => 1,
            'condition'              => 'good for spares or repair',
            'model'                  => 'test-model',
            'prompt_version'         => '1',
        ]);

        $this->assertSame('damaged', $this->row($msgid)->item_condition);
    }

    public function test_has_is_scoped_to_model_and_prompt(): void
    {
        $msgid = $this->makeMessage();

        $this->store->upsert([
            'messageid'              => $msgid,
            'is_eee_from_components' => 1,
            'model'                  => 'test-model',
            'prompt_version'         => '1',
        ]);

        $this->assertTrue($this->store->has($msgid, 'test-model', '1'));
        $this->assertFalse($this->store->has($msgid, 'test-model', '2'));
        $this->assertFalse($this->store->has($msgid, 'other-model', '1'));
    }

    /**
     * The mark is the approval clock — approvedat where a moderator approved, group
     * arrival where auto-approval left approvedat null — because that is the clock the
     * incremental run selects on. A different clock here skips or rescans whole runs.
     */
    public function test_high_water_mark_reads_the_approval_clock(): void
    {
        $early      = $this->makeMessage();
        $late       = $this->makeMessage();
        $approvedAt = now()->subDay()->toDateTimeString();

        // $early auto-approved long ago; $late held, then approved yesterday.
        DB::table('messages_groups')->where('msgid', $early)
            ->update(['arrival' => now()->subDays(30)->toDateTimeString(), 'approvedat' => null]);
        DB::table('messages_groups')->where('msgid', $late)
            ->update(['arrival' => now()->subDays(20)->toDateTimeString(), 'approvedat' => $approvedAt]);

        foreach ([$early, $late] as $msgid) {
            $this->store->upsert([
                'messageid'              => $msgid,
                'is_eee_from_components' => 1,
                'model'                  => 'test-model',
                'prompt_version'         => '1',
            ]);
        }

        $mark = $this->store->highWaterMark('test-model', '1');

        // MySQL returns the aggregate with microseconds; only the seconds matter.
        $this->assertSame($approvedAt, substr((string) $mark, 0, 19), 'the late approval must set the mark');
        $this->assertNull($this->store->highWaterMark('test-model', '2'), 'other prompts have no mark');
    }
}

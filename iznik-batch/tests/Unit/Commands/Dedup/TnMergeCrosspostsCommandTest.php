<?php

namespace Tests\Unit\Commands\Dedup;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Collapsing a set of messages that share a TrashNothing post id onto one message.
 *
 * The completeness assertion is the important one: after a merge, no table anywhere may
 * still point at the message that was merged away. It re-derives the referencing columns
 * from the live schema rather than trusting a hand-written list, so it stays true as
 * tables are added.
 */
class TnMergeCrosspostsCommandTest extends TestCase
{
    private function createTnMessage(string $tnPostId, int $groupid, int $userid): int
    {
        $msgid = DB::table('messages')->insertGetId([
            'date' => now(),
            'arrival' => now(),
            'source' => 'Email',
            'fromuser' => $userid,
            'subject' => 'OFFER: Singular Merge Fixture (London)',
            'tnpostid' => $tnPostId,
            'messageid' => uniqid('mid-', true),
            'type' => 'Offer',
        ]);

        DB::table('messages_groups')->insert([
            'msgid' => $msgid,
            'groupid' => $groupid,
            'collection' => 'Approved',
            'arrival' => now(),
            'msgtype' => 'Offer',
        ]);

        return $msgid;
    }

    /**
     * Every (table, column) holding a messages.id, derived the same way the command
     * derives it, so the test cannot drift from the schema.
     *
     * @return array<int, array{table: string, column: string}>
     */
    private function messageReferences(): array
    {
        $database = DB::connection()->getDatabaseName();

        $refs = DB::table('information_schema.COLUMNS')
            ->select('TABLE_NAME as table_name', 'COLUMN_NAME as column_name')
            ->where('TABLE_SCHEMA', $database)
            ->where('COLUMN_NAME', 'msgid')
            ->get()
            ->reject(fn ($r) => $r->table_name === 'messages')
            ->map(fn ($r) => ['table' => $r->table_name, 'column' => $r->column_name])
            ->values()
            ->all();

        $refs[] = ['table' => 'chat_messages', 'column' => 'refmsgid'];

        return $refs;
    }

    public function test_merges_copies_and_leaves_nothing_pointing_at_them(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmerge')]);

        $tnPostId = 'tn-merge-'.uniqid();
        $canonical = $this->createTnMessage($tnPostId, $groupA->id, $user->id);
        $copy = $this->createTnMessage($tnPostId, $groupB->id, $user->id);

        // Rows on the copy across three shapes: no unique key on msgid (messages_history,
        // logs) and a UNIQUE msgid that cannot move if the canonical already has one
        // (messages_spatial - what the browse feed reads).
        DB::table('messages_history')->insert([
            'groupid' => $groupB->id,
            'source' => 'Email',
            'fromuser' => $user->id,
            'subject' => 'OFFER: Singular Merge Fixture (London)',
            'msgid' => $copy,
        ]);
        DB::table('logs')->insert([
            'timestamp' => now(),
            'type' => 'Message',
            'subtype' => 'Received',
            'groupid' => $groupB->id,
            'user' => $user->id,
            'msgid' => $copy,
        ]);
        foreach ([$canonical, $copy] as $id) {
            DB::statement(
                'INSERT INTO messages_spatial (msgid, point, successful, groupid, msgtype, arrival)
                 VALUES (?, ST_SRID(POINT(-0.1, 51.5), 3857), 0, ?, ?, ?)',
                [$id, $id === $canonical ? $groupA->id : $groupB->id, 'Offer', now()]
            );
        }

        // Default --days=90 window; the fixtures arrive now, so they are in scope.
        $this->artisan('tn:merge-crossposts')->assertSuccessful();

        $live = DB::table('messages')->where('tnpostid', $tnPostId)->whereNull('deleted')->pluck('id')->all();
        $this->assertSame([$canonical], $live, 'exactly the canonical message must remain live');

        $copyRow = DB::table('messages')->where('id', $copy)->first();
        $this->assertNotNull($copyRow->deleted, 'the copy must be soft-deleted');
        $this->assertNull($copyRow->tnpostid, 'the copy must lose its tnpostid so it cannot become canonical');

        // The canonical must have inherited the copy's group.
        $groupIds = DB::table('messages_groups')->where('msgid', $canonical)->pluck('groupid')->sort()->values()->all();
        $expected = collect([$groupA->id, $groupB->id])->sort()->values()->all();
        $this->assertSame($expected, $groupIds, 'the canonical must carry both groups');

        // Completeness: nothing anywhere may still point at the merged copy.
        $orphans = [];
        foreach ($this->messageReferences() as $ref) {
            $count = DB::table($ref['table'])->where($ref['column'], $copy)->count();
            if ($count > 0) {
                $orphans[] = "{$ref['table']}.{$ref['column']} ({$count})";
            }
        }
        $this->assertSame([], $orphans, 'no table may be left pointing at the merged copy: '.implode(', ', $orphans));

        // The unique spatial row could not move, so it must have been removed, leaving the
        // canonical's own row intact - one feed entry for the item, which is the point.
        $spatial = DB::table('messages_spatial')->whereIn('msgid', [$canonical, $copy])->pluck('msgid')->all();
        $this->assertSame([$canonical], $spatial);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmerge')]);

        $tnPostId = 'tn-merge-'.uniqid();
        $canonical = $this->createTnMessage($tnPostId, $groupA->id, $user->id);
        $copy = $this->createTnMessage($tnPostId, $groupB->id, $user->id);

        $this->artisan('tn:merge-crossposts', ['--dry-run' => true])->assertSuccessful();

        $live = DB::table('messages')->where('tnpostid', $tnPostId)->whereNull('deleted')->pluck('id')->sort()->values()->all();
        $expected = collect([$canonical, $copy])->sort()->values()->all();
        $this->assertSame($expected, $live, 'a dry run must leave both messages live');
    }
}

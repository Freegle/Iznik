<?php

namespace Tests\Feature\Membership;

use App\Models\Membership;
use App\Services\MembershipOriginBackfillService;
use App\Services\Ripple\RippleEngagementService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillOriginCommandTest extends TestCase
{
    private function service(): MembershipOriginBackfillService
    {
        return new MembershipOriginBackfillService();
    }

    /** A membership as the old code left it: added_origin at the column default. */
    private function member(int $userId, int $groupId, array $attributes = []): int
    {
        return DB::table('memberships')->insertGetId(array_merge([
            'userid' => $userId, 'groupid' => $groupId, 'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED, 'added' => now()->subDays(10),
        ], $attributes));
    }

    private function joinLog(int $userId, int $groupId, ?string $text, ?int $byuser, Carbon $at): void
    {
        DB::table('logs')->insert([
            'timestamp' => $at, 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $userId, 'groupid' => $groupId, 'byuser' => $byuser, 'text' => $text,
        ]);
    }

    private function history(int $userId, int $groupId, Carbon $at, int $rippled = 0): int
    {
        return DB::table('memberships_history')->insertGetId([
            'userid' => $userId, 'groupid' => $groupId, 'collection' => Membership::COLLECTION_APPROVED,
            'added' => $at, 'rippled' => $rippled,
        ]);
    }

    private function origin(int $membershipId): string
    {
        return DB::table('memberships')->where('id', $membershipId)->value('added_origin');
    }

    private function historyOrigin(int $historyId): string
    {
        return DB::table('memberships_history')->where('id', $historyId)->value('added_origin');
    }

    /** One user, one group, one membership whose latest join is the given log. */
    private function memberJoinedBy(?string $text, string $actor, array $membership = []): array
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $byuser = match ($actor) {
            'self' => $user->id,
            'none' => null,
            'zero' => 0,
            'other' => $this->createTestUser()->id,
        };
        $id = $this->member($user->id, $group->id, $membership);
        $this->joinLog($user->id, $group->id, $text, $byuser, now()->subDays(10));

        return [$id, $user, $group];
    }

    public function test_each_join_text_maps_to_its_origin(): void
    {
        $cases = [
            [MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self', Membership::ADDED_ORIGIN_MANUAL],
            [MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, 'none', Membership::ADDED_ORIGIN_RIPPLED],
            [GroupPostIngestionService::JOIN_LOG_CHOSEN, 'self', Membership::ADDED_ORIGIN_PARTNER],
            ['Subscribed', 'self', Membership::ADDED_ORIGIN_SUBSCRIBED],
            ['Joined to reply to a post', 'self', Membership::ADDED_ORIGIN_REPLIED],
            ['LoveJunk user joining to reply', 'self', Membership::ADDED_ORIGIN_REPLIED],
            // Somebody else's join is a moderator add, whatever its text.
            [null, 'other', Membership::ADDED_ORIGIN_MODERATOR],
            [MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'other', Membership::ADDED_ORIGIN_MODERATOR],
            ['Reinstated by Geeks: reason', 'other', Membership::ADDED_ORIGIN_MODERATOR],
            // The system, or the legacy placeholder user 0, with no reason.
            [null, 'none', Membership::ADDED_ORIGIN_UNKNOWN],
            [null, 'zero', Membership::ADDED_ORIGIN_UNKNOWN],
            // No post near the join: Auto was a join to reply, no text can't be told.
            [MembershipOriginBackfillService::JOIN_TEXT_AUTO, 'self', Membership::ADDED_ORIGIN_REPLIED],
            [null, 'self', Membership::ADDED_ORIGIN_UNKNOWN],
            ['', 'self', Membership::ADDED_ORIGIN_UNKNOWN],
            ['Something new', 'self', Membership::ADDED_ORIGIN_UNKNOWN],
        ];

        foreach ($cases as [$text, $actor, $expected]) {
            [$id, $user] = $this->memberJoinedBy($text, $actor);
            $this->service()->backfill($user->id, 1000, send: true);
            $this->assertSame($expected, $this->origin($id), var_export($text, true)." by {$actor}");
        }
    }

    public function test_a_join_with_no_reason_and_a_post_near_it_is_posted(): void
    {
        foreach ([MembershipOriginBackfillService::JOIN_TEXT_AUTO, null] as $text) {
            [$id, $user, $group] = $this->memberJoinedBy($text, 'self');
            $this->createTestMessage($user, $group, ['arrival' => now()->subDays(10)->addMinutes(10)]);

            $this->service()->backfill($user->id, 1000, send: true);

            $this->assertSame(Membership::ADDED_ORIGIN_POSTED, $this->origin($id), var_export($text, true));
        }
    }

    public function test_a_chunk_of_ambiguous_joins_gets_the_same_answers_as_one_at_a_time(): void
    {
        // Several joins with no reason in one chunk, sharing users and groups. The posts are
        // looked for once for the chunk, so each row must still be matched to its own user,
        // group and join time and not to another row's.
        $userA = $this->createTestUser();
        $userB = $this->createTestUser();
        $groupX = $this->createTestGroup();
        $groupY = $this->createTestGroup();
        $joined = now()->subDays(10);

        $cases = [
            // [user, group, text, minutes from the join to their post on that group or null, expected]
            [$userA, $groupX, null, 10, Membership::ADDED_ORIGIN_POSTED],
            [$userA, $groupY, null, null, Membership::ADDED_ORIGIN_UNKNOWN],
            [$userB, $groupX, MembershipOriginBackfillService::JOIN_TEXT_AUTO, 29, Membership::ADDED_ORIGIN_POSTED],
            [$userB, $groupY, MembershipOriginBackfillService::JOIN_TEXT_AUTO, 31, Membership::ADDED_ORIGIN_REPLIED],
            [$userA, $this->createTestGroup(), '', -4, Membership::ADDED_ORIGIN_POSTED],
            [$userB, $this->createTestGroup(), '', -6, Membership::ADDED_ORIGIN_UNKNOWN],
        ];

        $ids = [];
        foreach ($cases as $i => [$user, $group, $text, $minutes, $expected]) {
            $ids[$i] = $this->member($user->id, $group->id);
            // Joins two hours apart, so one row's post is never inside another row's window.
            $at = $joined->copy()->addHours($i * 2);
            $this->joinLog($user->id, $group->id, $text, $user->id, $at);
            if ($minutes !== null) {
                $this->createTestMessage($user, $group, ['arrival' => $at->copy()->addMinutes($minutes)]);
            }
        }
        // A post by userA on groupY that fits none of userA's joins on it, and userB's post on
        // groupX at userA's join time: neither may leak onto another user's or group's row.
        $this->createTestMessage($userB, $groupX, ['arrival' => $joined->copy()->addMinutes(5)]);
        $this->createTestMessage($userA, $groupY, ['arrival' => $joined->copy()->addDays(3)]);

        $this->service()->backfill(null, 1000, send: true);
        $together = array_map(fn ($id) => $this->origin($id), $ids);

        // The same rows classified one per chunk give the same answers.
        DB::table('memberships')->whereIn('id', $ids)->update(['added_origin' => Membership::ADDED_ORIGIN_UNKNOWN]);
        $this->service()->backfill(null, 1, send: true);
        $apart = array_map(fn ($id) => $this->origin($id), $ids);

        $this->assertSame(array_column($cases, 4), $together);
        $this->assertSame($together, $apart);
    }

    public function test_a_post_outside_the_window_does_not_count(): void
    {
        [$id, $user, $group] = $this->memberJoinedBy(null, 'self');
        $this->createTestMessage($user, $group, ['arrival' => now()->subDays(10)->addHour()]);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->origin($id));
    }

    public function test_the_latest_join_decides_not_any_rippled_log(): void
    {
        // Rippled in, left, and later joined by hand: the current row is the manual join.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, null, now()->subDays(30));
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, now()->subDays(10));
        $id = $this->member($user->id, $group->id);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->origin($id));
    }

    public function test_a_became_ordinary_row_is_not_a_join(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, null, now()->subDays(10));
        $this->joinLog($user->id, $group->id, 'Ripple-created membership became ordinary: joined', $user->id, now()->subDay());
        // The flag has been cleared, so only the log says it was rippled.
        $id = $this->member($user->id, $group->id, ['rippled' => 0]);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->origin($id));
    }

    public function test_a_trashnothing_became_ordinary_row_is_not_a_join_either(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, null, now()->subDays(10));
        $this->joinLog($user->id, $group->id, RippleEngagementService::LOG_PREFIX_TN.'joined', $user->id, now()->subDay());
        $id = $this->member($user->id, $group->id, ['rippled' => 0]);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->origin($id));
    }

    public function test_the_rippled_flag_wins_over_the_logs(): void
    {
        [$withLog, $user] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self', ['rippled' => 1, 'implicit' => 1]);
        $withoutLog = $this->member($user->id, $this->createTestGroup()->id, ['rippled' => 1, 'implicit' => 1]);

        $stats = $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->origin($withLog));
        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->origin($withoutLog));
        $this->assertSame(2, $stats['checks'][MembershipOriginBackfillService::CHECK_FLAG_WITHOUT_LOG]);
        $this->assertEqualsCanonicalizing([$withLog, $withoutLog],
            $stats['samples'][MembershipOriginBackfillService::CHECK_FLAG_WITHOUT_LOG]);
    }

    public function test_a_membership_with_no_join_log_stays_unknown(): void
    {
        $user = $this->createTestUser();
        $id = $this->member($user->id, $this->createTestGroup()->id);

        $stats = $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->origin($id));
        $this->assertSame(1, $stats['memberships_unknown'][MembershipOriginBackfillService::UNKNOWN_NO_JOIN_LOG]);
    }

    public function test_a_known_origin_is_never_changed(): void
    {
        [$id, $user] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, 'none', ['added_origin' => Membership::ADDED_ORIGIN_PARTNER]);

        $stats = $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_PARTNER, $this->origin($id));
        $this->assertSame([], $stats['memberships'], 'only unknown rows are read');
    }

    public function test_dry_run_counts_and_writes_nothing(): void
    {
        [$id, $user, $group] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');
        $historyId = $this->history($user->id, $group->id, now()->subDays(10));

        $stats = $this->service()->backfill($user->id, 1000, send: false);

        $this->assertSame([Membership::ADDED_ORIGIN_MANUAL => 1], $stats['memberships']);
        $this->assertSame([Membership::ADDED_ORIGIN_MANUAL => 1], $stats['history']);
        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->origin($id));
        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->historyOrigin($historyId));
    }

    public function test_a_rerun_finds_nothing_left_but_the_unknowns(): void
    {
        [, $user] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');
        $this->member($user->id, $this->createTestGroup()->id);

        $this->service()->backfill($user->id, 1000, send: true);
        $again = $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame([Membership::ADDED_ORIGIN_UNKNOWN => 1], $again['memberships']);
    }

    public function test_chunks_smaller_than_the_rows_cover_every_row(): void
    {
        $user = $this->createTestUser();
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $group = $this->createTestGroup();
            $ids[] = $this->member($user->id, $group->id);
            $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, now()->subDays(10));
            $this->history($user->id, $group->id, now()->subDays(10));
        }

        $stats = $this->service()->backfill($user->id, 2, send: true);

        foreach ($ids as $id) {
            $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->origin($id));
        }
        $this->assertSame([Membership::ADDED_ORIGIN_MANUAL => 5], $stats['history']);
    }

    public function test_each_history_row_takes_the_join_logged_with_it(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $first = now()->subDays(30);
        $second = now()->subDays(10);
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, null, $first->copy()->addSeconds(2));
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, $second->copy()->addSeconds(3));
        $rippledJoin = $this->history($user->id, $group->id, $first);
        $manualJoin = $this->history($user->id, $group->id, $second);
        $noLog = $this->history($user->id, $group->id, now()->subDays(5));

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->historyOrigin($rippledJoin));
        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->historyOrigin($manualJoin));
        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->historyOrigin($noLog));
    }

    public function test_a_history_row_takes_the_nearest_join_and_none_outside_the_window(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $at = now()->subDays(10);
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_AUTO, $user->id, $at->copy()->subSeconds(40));
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, $at->copy()->addSeconds(5));
        $near = $this->history($user->id, $group->id, $at);

        // The window is a minute either side.
        $otherGroup = $this->createTestGroup();
        $this->joinLog($user->id, $otherGroup->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, $at->copy()->addSeconds(65));
        $tooFar = $this->history($user->id, $otherGroup->id, $at);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->historyOrigin($near));
        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->historyOrigin($tooFar));
    }

    public function test_a_rippled_history_row_is_rippled_without_a_log(): void
    {
        $user = $this->createTestUser();
        $id = $this->history($user->id, $this->createTestGroup()->id, now()->subDays(10), rippled: 1);

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_RIPPLED, $this->historyOrigin($id));
    }

    public function test_history_rows_far_apart_in_time_are_each_matched(): void
    {
        // Two rows in one chunk years apart: their logs are read in separate windows.
        $user = $this->createTestUser();
        $old = $this->createTestGroup();
        $new = $this->createTestGroup();
        $this->joinLog($user->id, $old->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, now()->subYears(5));
        $this->joinLog($user->id, $new->id, 'Subscribed', $user->id, now()->subDay());
        $oldRow = $this->history($user->id, $old->id, now()->subYears(5));
        $newRow = $this->history($user->id, $new->id, now()->subDay());

        $this->service()->backfill($user->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->historyOrigin($oldRow));
        $this->assertSame(Membership::ADDED_ORIGIN_SUBSCRIBED, $this->historyOrigin($newRow));
    }

    public function test_disagreements_with_the_history_and_merged_joins_are_reported(): void
    {
        // Latest join Rippled with no rippled history row (the June 2026 window).
        [$noHistory, $user] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_RIPPLED, 'none');

        // Latest history row rippled, latest join not.
        $group = $this->createTestGroup();
        $historyOnly = $this->member($user->id, $group->id);
        $this->joinLog($user->id, $group->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, now()->subDays(10));
        $this->history($user->id, $group->id, now()->subDays(10), rippled: 1);

        // A join logged long after the row was added: carried over by a merge.
        $merged = $this->createTestGroup();
        $mergedId = $this->member($user->id, $merged->id, ['added' => now()->subYear()]);
        $this->joinLog($user->id, $merged->id, MembershipOriginBackfillService::JOIN_TEXT_MANUAL, $user->id, now()->subDays(10));

        $stats = $this->service()->backfill($user->id, 1000, send: false);

        $this->assertSame([$noHistory], $stats['samples'][MembershipOriginBackfillService::CHECK_LOG_WITHOUT_HISTORY]);
        $this->assertSame([$historyOnly], $stats['samples'][MembershipOriginBackfillService::CHECK_HISTORY_WITHOUT_LOG]);
        $this->assertSame([$mergedId], $stats['samples'][MembershipOriginBackfillService::CHECK_JOIN_AFTER_ADDED]);
    }

    public function test_user_option_restricts_the_backfill(): void
    {
        [$id] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');
        [$otherId, $other] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');

        $this->service()->backfill($other->id, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->origin($id));
        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->origin($otherId));
    }

    public function test_command_is_a_dry_run_unless_sent(): void
    {
        [$id, $user] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');

        $this->artisan('memberships:backfill-origin', ['--user' => $user->id])
            ->expectsOutputToContain('DRY RUN')
            ->assertSuccessful();
        $this->assertSame(Membership::ADDED_ORIGIN_UNKNOWN, $this->origin($id));

        $this->artisan('memberships:backfill-origin', ['--user' => $user->id, '--send' => true])
            ->expectsOutputToContain('Set on memberships')
            ->assertSuccessful();
        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->origin($id));
    }

    public function test_the_forced_log_indexes_exist(): void
    {
        // The backfill forces two logs indexes by name; it rots if either is renamed.
        $columns = fn (string $index) => collect(DB::select('SHOW INDEX FROM logs WHERE Key_name = ?', [$index]))
            ->sortBy('Seq_in_index')->pluck('Column_name')->values()->all();
        $this->assertSame(['type', 'subtype'], $columns('type'));
        $this->assertSame(['timestamp', 'type', 'subtype'], $columns('timestamp_2'));

        [$id] = $this->memberJoinedBy(MembershipOriginBackfillService::JOIN_TEXT_MANUAL, 'self');

        $this->service()->backfill(null, 1000, send: true);

        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $this->origin($id));
    }
}

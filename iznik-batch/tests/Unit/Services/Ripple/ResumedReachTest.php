<?php

namespace Tests\Unit\Services\Ripple;

use App\Models\Membership;
use App\Mail\Digest\UnifiedDigest;
use App\Mail\Ripple\RippleIntroMail;
use App\Models\Message;
use App\Services\EmailSpoolerService;
use App\Services\Ripple\CellSetService;
use App\Services\Ripple\ExpandService;
use App\Services\Ripple\ReachService;
use App\Services\UnifiedDigestService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

/**
 * A reach that was frozen ('held') and is then resumed carries on from where it had got to. It does
 * not start again, and it does not tell anyone twice.
 *
 * A moderator's Approve of a post's home copy lifts the freeze with one write to the post's
 * rippling_reach row: status back to 'expanding' and next_expansion_at = NOW(), unless a
 * rippling_blocked row bars the post (ResumeReachIfHeld, iznik-server-go/microvolunteering). Nothing
 * in the batch hears about it. The row is simply due again, so ExpandService::advanceDue picks it up
 * on its next run, and the reach mailer (UnifiedDigestService::sendReachDigests) picks it up once the
 * engine has written updated_at, which the resume deliberately leaves alone.
 *
 * Who a reach mail goes to is decided by one query (mailNewlyReachedForPost): immediate-frequency
 * members of a community the post is Approved and not deleted on, whose point the stored label admits
 * at the row's CURRENT tick budget, and who are not in rippling_reach_notified for this item. That
 * ledger is the only record that someone has been reached, and spoolPostToRecipients writes it with
 * every mail. So a resumed reach tells nobody twice, whatever the engine did while it was frozen.
 *
 * The world here (longitude, latitude):
 *
 *   reach   tick 1: lng -0.20..0.00    tick 2: lng -0.20..0.12    tick 3: lng -0.20..0.30
 *   home    members A (in tick 1), B (new at tick 2), C (new at tick 3), D (in tick 1, joined during the
 *           freeze); A was told before the freeze, D was not
 *   N       a neighbour whose copy was pulled back to Pending; its member NM is inside tick 1
 *   W       a neighbour whose copy was withdrawn (deleted = 1) and its poster's membership removed;
 *           its member WM is inside tick 1
 *   F       a community the reach first touches at tick 2, with no copy yet; its member FM is new
 *   G       a community the reach first touches at tick 3, with no copy yet; its member GM is new
 *
 * The routing server is faked (a label is answered by budget, which is how the real one decides who
 * a tick has reached); the spatial server that rasterises a reach is the real one.
 */
class ResumedReachTest extends TestCase
{
    use SeedsReachCells;

    private const P1 = 'POLYGON((-0.20 51.45, 0.00 51.45, 0.00 51.55, -0.20 51.55, -0.20 51.45))';
    private const P2 = 'POLYGON((-0.20 51.45, 0.12 51.45, 0.12 51.55, -0.20 51.55, -0.20 51.45))';
    private const P3 = 'POLYGON((-0.20 51.45, 0.30 51.45, 0.30 51.55, -0.20 51.55, -0.20 51.45))';

    /** Where N and W are: inside tick 1, so a copy could have rippled in there at the start. */
    private const OLD_AREA = 'POLYGON((-0.18 51.47, -0.12 51.47, -0.12 51.53, -0.18 51.53, -0.18 51.47))';

    /** Where F is: first touched by tick 2. */
    private const NEW_AREA = 'POLYGON((0.04 51.47, 0.10 51.47, 0.10 51.53, 0.04 51.53, 0.04 51.47))';

    /** Where G is: first touched by tick 3. */
    private const NEWER_AREA = 'POLYGON((0.18 51.47, 0.26 51.47, 0.26 51.53, 0.18 51.53, 0.18 51.47))';

    /** [lat, lng] of each member. */
    private const AT_A = [51.50, -0.15];
    private const AT_B = [51.50, 0.06];
    private const AT_C = [51.50, 0.20];
    private const AT_D = [51.48, -0.16];
    private const AT_NM = [51.50, -0.14];
    private const AT_WM = [51.50, -0.13];
    private const AT_FM = [51.50, 0.07];
    private const AT_GM = [51.50, 0.22];

    /** Drive-time budget of each tick, in seconds, as the stored schedule below gives them. */
    private const BUDGET = [1 => 300.0, 2 => 600.0, 3 => 900.0];

    /** @var array<int, array{mailable: object, to: mixed}> everything handed to the spooler */
    private array $spooled = [];

    /** @var float[] the budgets the faked routing server was asked to evaluate a label at */
    private array $labelBudgets = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['freegle.ripple.enabled' => true]);
        // Short, deterministic hazard schedule: tick 2 from 3h after arrival, tick 3 from 6h.
        config(['freegle.ripple.hazard_hours' => [1, 3, 6]]);
        config(['freegle.ripple.active_start_hour' => 0]);
        config(['freegle.ripple.active_end_hour' => 24]);
        config(['freegle.ripple.enabled_at' => '']);
        config(['freegle.digest.immediate_allowlist' => '*']);

        DB::statement('DELETE FROM rippling_held_replies');
        DB::statement('DELETE FROM rippling_reach');
        DB::statement('DELETE FROM messages_spatial');

        // A reach mailer that has been running: its mark is minutes old, so a row is only looked at
        // once something has written its updated_at since.
        DB::table('config')->upsert(
            ['key' => UnifiedDigestService::reachMailMarkKey(0), 'value' => now()->subMinutes(10)->toDateTimeString()],
            ['key'],
            ['value']
        );

        $this->spooled = [];
        $this->labelBudgets = [];

        // rasterise / vectorise reach the real spatial server; only the label evaluation is faked.
        $this->fakeSpatialHttp(['*reach-arrival*' => fn (Request $request) => $this->answerLabelEvaluation($request)]);

        $spooler = \Mockery::mock(EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andReturnUsing(function ($mailable, $to = null) {
            $this->spooled[] = ['mailable' => $mailable, 'to' => $to];

            return 'spooled';
        });
        $this->app->instance(EmailSpoolerService::class, $spooler);
    }

    // ── the faked routing server ────────────────────────────────────────────────────────────────

    /**
     * Answer POST /v1/reach-arrival the way the label does: the budget (t, in seconds) decides how
     * far the reach goes, so a member is admitted once the row's tick is wide enough to cover them.
     */
    private function answerLabelEvaluation(Request $request)
    {
        $budget = (float) ($request['t'] ?? 0);
        $this->labelBudgets[] = $budget;

        $tick = $budget <= self::BUDGET[1] ? 1 : ($budget <= self::BUDGET[2] ? 2 : 3);
        [$minLng, $minLat, $maxLng, $maxLat] = $this->boundsOf([1 => self::P1, 2 => self::P2, 3 => self::P3][$tick]);

        $results = [];
        foreach ($request['points'] ?? [] as $point) {
            $lat = (float) ($point['lat'] ?? 0);
            $lng = (float) ($point['lng'] ?? 0);
            $in = $lat >= $minLat && $lat <= $maxLat && $lng >= $minLng && $lng <= $maxLng;
            $results[] = ['arrival' => $in ? 100 : null, 'in' => $in];
        }

        return Http::response(['results' => $results]);
    }

    /** [minLng, minLat, maxLng, maxLat] of a rectangle WKT. */
    private function boundsOf(string $wkt): array
    {
        preg_match_all('/(-?\d+\.?\d*) (-?\d+\.?\d*)/', $wkt, $m, PREG_SET_ORDER);
        $lngs = array_map(fn ($p) => (float) $p[1], $m);
        $lats = array_map(fn ($p) => (float) $p[2], $m);

        return [min($lngs), min($lats), max($lngs), max($lats)];
    }

    // ── fixtures ────────────────────────────────────────────────────────────────────────────────

    /** An immediate-mail member of $group with a known point ([lat, lng]). */
    private function memberAt(\App\Models\Group $group, array $at): \App\Models\User
    {
        $user = $this->createTestUser();
        $user->settings = ['mylocation' => ['lat' => $at[0], 'lng' => $at[1]]];
        $user->save();
        $this->createMembership($user, $group);

        return $user->fresh();
    }

    /** A publishing Freegle community whose area is the given rectangle. */
    private function communityCovering(string $wkt): \App\Models\Group
    {
        $group = $this->createTestGroup();
        DB::statement(
            'UPDATE `groups` SET publish = 1, polyindex = ST_GeomFromText(?, ' . config('freegle.srid') . ') WHERE id = ?',
            [$wkt, $group->id]
        );

        return $group;
    }

    /** The poster's ripple-join to a community, as rippling writes it (membership and Joined log). */
    private function rippleJoin(int $posterId, int $groupId): void
    {
        DB::table('memberships')->insert([
            'userid' => $posterId, 'groupid' => $groupId, 'role' => Membership::ROLE_MEMBER, 'collection' => 'Approved',
            'emailfrequency' => 24, 'eventsallowed' => 1, 'volunteeringallowed' => 1, 'rippled' => 1, 'added' => now(),
        ]);
        $this->recordRippleJoinLog($posterId, $groupId);
    }

    private function recordRippleJoinLog(int $posterId, int $groupId): void
    {
        DB::table('logs')->insert([
            'timestamp' => now()->subHours(3), 'type' => 'Group', 'subtype' => 'Joined',
            'user' => $posterId, 'byuser' => null, 'groupid' => $groupId, 'text' => 'Rippled',
        ]);
    }

    /**
     * A post whose reach has been frozen at $tick since shortly after it got there, as a moderator's
     * Back to pending leaves it: status 'held', next_expansion_at NULL, copies as they were.
     *
     * $shape 'grid' keeps the stored polygon (polygon_cells); 'retired' is a row that has both its
     * label and its union threshold, so the grid has been drained and the label is the only record
     * of the reach (what most live rows look like).
     *
     * @param string[] $told the members already in rippling_reach_notified
     */
    private function frozenReach(string $shape = 'grid', int $tick = 1, int $arrivedHoursAgo = 4, array $told = ['A']): \stdClass
    {
        $f = new \stdClass();
        $arrival = now()->subHours($arrivedHoursAgo);

        $f->mod = $this->createTestUser();
        $f->poster = $this->createTestUser();
        $f->home = $this->createTestGroup();
        $this->createMembership($f->poster, $f->home);
        $f->A = $this->memberAt($f->home, self::AT_A);
        $f->B = $this->memberAt($f->home, self::AT_B);
        $f->C = $this->memberAt($f->home, self::AT_C);
        // D joined the home community while the reach was frozen. They sit inside the area it had already
        // reached, but nothing has told them about the post, so the ledger has no row for them.
        $f->D = $this->memberAt($f->home, self::AT_D);

        $message = Message::create([
            'type' => Message::TYPE_OFFER, 'fromuser' => $f->poster->id,
            'subject' => 'OFFER: sofa (London)', 'textbody' => 'A sofa.', 'source' => 'Platform',
            'date' => $arrival, 'arrival' => $arrival, 'lat' => 51.5, 'lng' => -0.10,
        ]);
        $f->msgid = (int) $message->id;

        // The home copy: Approved by a moderator, which is what lifted the freeze.
        DB::table('messages_groups')->insert([
            'msgid' => $f->msgid, 'groupid' => $f->home->id, 'collection' => 'Approved', 'arrival' => $arrival,
            'approvedby' => $f->mod->id, 'approvedat' => now(), 'autoreposts' => 0,
            'msgtype' => Message::TYPE_OFFER, 'rippled_in' => 0, 'deleted' => 0,
        ]);
        DB::insert(
            'INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival)
             VALUES (?, ST_GeomFromText(?, ' . config('freegle.srid') . '), ?, ?, ?)',
            [$f->msgid, 'POINT(-0.10 51.50)', $f->home->id, Message::TYPE_OFFER, $arrival]
        );

        // N: the copy was pulled back to Pending on a neighbour, which keeps the poster's ripple-join.
        $f->N = $this->communityCovering(self::OLD_AREA);
        DB::table('messages_groups')->insert([
            'msgid' => $f->msgid, 'groupid' => $f->N->id, 'collection' => 'Pending', 'approvedat' => null,
            'arrival' => $arrival, 'autoreposts' => 0, 'msgtype' => Message::TYPE_OFFER, 'rippled_in' => 1, 'deleted' => 0,
        ]);
        $this->rippleJoin((int) $f->poster->id, (int) $f->N->id);
        $f->NM = $this->memberAt($f->N, self::AT_NM);

        // W: the copy was withdrawn. The withdrawal soft-deletes the copy and removes the poster's
        // ripple-join, and writes no Group/Left (that would read as the poster opting out for good),
        // so what it leaves is a deleted copy and a Joined log with no membership behind it.
        $f->W = $this->communityCovering(self::OLD_AREA);
        DB::table('messages_groups')->insert([
            'msgid' => $f->msgid, 'groupid' => $f->W->id, 'collection' => 'Approved', 'approvedat' => $arrival,
            'arrival' => $arrival, 'autoreposts' => 0, 'msgtype' => Message::TYPE_OFFER, 'rippled_in' => 1, 'deleted' => 1,
        ]);
        $this->recordRippleJoinLog((int) $f->poster->id, (int) $f->W->id);
        $f->WM = $this->memberAt($f->W, self::AT_WM);

        // F: first touched at tick 2. No copy yet.
        $f->F = $this->communityCovering(self::NEW_AREA);
        $f->FM = $this->memberAt($f->F, self::AT_FM);

        // G: first touched at tick 3. No copy yet.
        $f->G = $this->communityCovering(self::NEWER_AREA);
        $f->GM = $this->memberAt($f->G, self::AT_GM);

        $polygon = [1 => self::P1, 2 => self::P2, 3 => self::P3][$tick];
        $schedule = json_encode([
            ['tick' => 1, 'drive_min' => 5, 'cumulative_users' => 30, 'wkt' => self::P1],
            ['tick' => 2, 'drive_min' => 10, 'cumulative_users' => 60, 'wkt' => self::P2],
            ['tick' => 3, 'drive_min' => 15, 'cumulative_users' => 90, 'wkt' => self::P3],
        ]);
        $frozenAt = now()->subHours(max(1, $arrivedHoursAgo - 1));
        DB::statement(
            "INSERT INTO rippling_reach
               (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, total_freeglers,
                max_drive_min, schedule, next_expansion_at, status, ripple_intro_sent, created_at, updated_at)
             VALUES (?, 51.5, -0.10, ?, ST_Envelope(ST_GeomFromText(?, " . config('freegle.srid') . ")), ?, 'drive', ?, 3, 90, 15, ?, NULL, 'held', 1, ?, ?)",
            [
                $f->msgid, $shape === 'grid' ? $this->reachCellsFor($polygon) : null, $polygon, $arrival,
                $tick, $schedule, $arrival, $frozenAt,
            ]
        );
        DB::table('rippling_reach')->where('msgid', $f->msgid)->update(
            ['reach_labels' => 'label-bytes'] + ($shape === 'retired' ? ['origin_union_secs' => -1] : [])
        );

        foreach ($told as $name) {
            DB::table('rippling_reach_notified')->insert([
                'msgid' => $f->msgid, 'userid' => $f->$name->id, 'notified_at' => $arrival->copy()->addMinutes(5),
            ]);
        }

        return $f;
    }

    /**
     * The write a moderator's Approve of the home copy makes: exactly the statement
     * ResumeReachIfHeld runs (iznik-server-go/microvolunteering/microvolunteering.go), so the row
     * has the shape the engine will meet. It touches the two columns and nothing else, updated_at
     * included.
     *
     * @return int rows resumed
     */
    private function resume(\stdClass $f): int
    {
        return DB::affectingStatement(
            "UPDATE rippling_reach SET status = 'expanding', next_expansion_at = NOW()
              WHERE msgid = ? AND status = 'held'
                AND NOT EXISTS (SELECT 1 FROM rippling_blocked rb WHERE rb.msgid = rippling_reach.msgid)
                AND EXISTS (SELECT 1 FROM messages_groups mg WHERE mg.msgid = rippling_reach.msgid
                              AND mg.rippled_in = 0 AND mg.deleted = 0 AND mg.collection = 'Approved')",
            [$f->msgid]
        );
    }

    // ── the two things that run ─────────────────────────────────────────────────────────────────

    /** One run of ripple:expand. */
    private function engineRun(): array
    {
        return (new ExpandService(new ReachService()))->process(false, 500);
    }

    /**
     * One run of the reach mailer, starting from nothing sent.
     *
     * @return array{0: array, 1: int[]} its stats and the ids of the members it mailed
     */
    private function mailerRun(): array
    {
        $this->spooled = [];
        $this->labelBudgets = [];

        $stats = (new UnifiedDigestService())->sendReachDigests();

        return [$stats, $this->mailedUserIds()];
    }

    /** @return int[] sorted ids of the members a reach mail was spooled to since the last mailerRun() */
    private function mailedUserIds(): array
    {
        $addresses = [];
        foreach ($this->spooled as $spooled) {
            if ($spooled['mailable'] instanceof UnifiedDigest) {
                $addresses[] = $spooled['to'];
            }
        }

        $ids = DB::table('users_emails')->whereIn('email', $addresses)->pluck('userid')
            ->map(fn ($id) => (int) $id)->all();
        sort($ids);

        return $ids;
    }

    private function ids(\stdClass $f, string ...$names): array
    {
        $ids = array_map(fn ($name) => (int) $f->$name->id, $names);
        sort($ids);

        return $ids;
    }

    /** @return string[] the routing-server calls that would recompute a reach or fetch a tick's polygon */
    private function routingCalls(): array
    {
        $calls = [];
        foreach (Http::recorded() as [$request]) {
            $url = $request->url();
            if (str_contains($url, 'ripple-schedule') || str_contains($url, '/v1/catchment') || str_contains($url, '/v1/isochrone')) {
                $calls[] = $url;
            }
        }

        return $calls;
    }

    // ── reading the result back ─────────────────────────────────────────────────────────────────

    private function reachRow(\stdClass $f): array
    {
        return (array) DB::selectOne(
            'SELECT status, tick, total_ticks, next_expansion_at, updated_at, created_at, ripple_intro_sent, arrival,
                    MD5(schedule) AS schedule_md5, MD5(polygon_cells) AS cells_md5, polygon_cells IS NULL AS no_cells
               FROM rippling_reach WHERE msgid = ?',
            [$f->msgid]
        );
    }

    /** The row was last written this long ago, so it is still inside the mailer's window. */
    private function lastWrittenMinutesAgo(\stdClass $f, int $minutes): void
    {
        DB::table('rippling_reach')->where('msgid', $f->msgid)->update(['updated_at' => now()->subMinutes($minutes)]);
    }

    private function outerBoundContains(\stdClass $f, array $at): bool
    {
        return (bool) DB::selectOne(
            'SELECT ST_Contains(outer_bound, ST_SRID(POINT(?, ?), ' . config('freegle.srid') . ')) AS inside FROM rippling_reach WHERE msgid = ?',
            [$at[1], $at[0], $f->msgid]
        )->inside;
    }

    private function storedGridContains(\stdClass $f, array $at): bool
    {
        $cells = DB::table('rippling_reach')->where('msgid', $f->msgid)->value('polygon_cells');
        $this->assertNotNull($cells, 'the row must hold a reach grid to probe');

        return (new CellSetService())->containsEncoded($cells, $at[1], $at[0]) === true;
    }

    private function copy(\stdClass $f, string $community): ?object
    {
        return DB::table('messages_groups')->where('msgid', $f->msgid)->where('groupid', $f->$community->id)->first();
    }

    private function posterMembership(\stdClass $f, string $community): ?object
    {
        return DB::table('memberships')->where('userid', $f->poster->id)->where('groupid', $f->$community->id)->first();
    }

    private function joinedLogCount(\stdClass $f, string $community): int
    {
        return DB::table('logs')->where('type', 'Group')->where('subtype', 'Joined')
            ->where('user', $f->poster->id)->where('groupid', $f->$community->id)->count();
    }

    /** @return array<string, array{0: string}> */
    public static function rowShapes(): array
    {
        return ['a row that keeps its grid' => ['grid'], 'a retired row, label only' => ['retired']];
    }

    // ── (4) a frozen reach is left alone, blocked or not ────────────────────────────────────────

    /**
     * A reach that is 'held' is not the engine's to touch, and not the mailer's: nothing expands,
     * nothing ripples into a new community, nothing is mailed. This is the state a post sits in
     * until a moderator approves its home copy.
     *
     * Nobody has been told yet and the row was written a minute ago, so the mailer would look at it
     * and find A inside its reach if the freeze were not what stopped it.
     */
    public function test_a_frozen_reach_is_left_alone_by_the_engine_and_the_mailer(): void
    {
        $f = $this->frozenReach('grid', 1, 4, []);
        $this->lastWrittenMinutesAgo($f, 1);
        $before = $this->reachRow($f);

        $stats = $this->engineRun();
        [$mail, $mailed] = $this->mailerRun();

        $this->assertSame(0, $stats['initialized'], 'a frozen row is a reach, so nothing starts a second one');
        $this->assertSame(0, $stats['expanded']);
        $this->assertSame(0, $stats['rippled_in']);
        $this->assertSame($before, $this->reachRow($f), 'the row is exactly as it was');
        $this->assertNull($this->copy($f, 'F'), 'no new community');
        $this->assertSame([], $mailed);
        $this->assertSame(0, $mail['emails_sent']);
    }

    /**
     * A moderator's Approve does not resume a post its home community sent back: the resume write
     * finds the rippling_blocked row and does nothing, and the engine then goes on skipping the
     * frozen row, so the post stays where the send-back left it.
     */
    public function test_a_frozen_reach_with_a_block_row_stays_frozen_and_is_still_skipped(): void
    {
        $f = $this->frozenReach('grid', 1, 4, []);
        $this->lastWrittenMinutesAgo($f, 1);
        DB::table('rippling_blocked')->insert([
            'msgid' => $f->msgid, 'byuser' => $f->mod->id, 'reason' => 'home sent back to pending',
        ]);
        $before = $this->reachRow($f);

        $this->assertSame(0, $this->resume($f), 'the resume write leaves a blocked post frozen');

        $stats = $this->engineRun();
        [$mail, $mailed] = $this->mailerRun();

        $this->assertSame(0, $stats['expanded']);
        $this->assertSame(0, $stats['rippled_in']);
        $this->assertSame(0, $stats['initialized']);
        $this->assertSame($before, $this->reachRow($f), 'still held, same tick, same polygon, not due');
        $this->assertSame('held', $before['status']);
        $this->assertNull($this->copy($f, 'F'));
        $this->assertSame([], $mailed);
        $this->assertSame(0, $mail['emails_sent']);
        $this->assertSame(1, DB::table('rippling_blocked')->where('msgid', $f->msgid)->count(), 'the block is not touched');
    }

    // ── (1) the next tick carries on ────────────────────────────────────────────────────────────

    /**
     * Frozen at tick 1, resumed four hours after arrival: the engine takes the row on from tick 1 to
     * the tick the clock is at (2), using the schedule and polygon it already holds. It does not
     * compute the reach again and it does not go back to the start.
     */
    #[DataProvider('rowShapes')]
    public function test_the_next_tick_carries_on_from_the_stored_tick_and_polygon(string $shape): void
    {
        $f = $this->frozenReach($shape);
        $before = $this->reachRow($f);

        $this->assertSame(1, $this->resume($f));
        $resumed = $this->reachRow($f);
        $this->assertSame('expanding', $resumed['status']);
        $this->assertTrue(
            DB::table('rippling_reach')->where('msgid', $f->msgid)->where('next_expansion_at', '<=', now())->exists(),
            'due now, by the clock the engine uses'
        );
        $this->assertSame($before['tick'], $resumed['tick'], 'the resume itself leaves the tick');
        $this->assertSame($before['updated_at'], $resumed['updated_at'], 'and updated_at, which is the mailer\'s signal');

        $stats = $this->engineRun();

        $this->assertSame(0, $stats['initialized'], 'the row is not started again');
        $this->assertSame(1, $stats['expanded']);
        $this->assertSame(0, $stats['errors']);
        $this->assertSame([], $this->routingCalls(), 'the stored schedule is used: no new reach is computed');

        $after = $this->reachRow($f);
        $this->assertSame(2, (int) $after['tick'], 'from the stored tick 1 to the tick four hours in');
        $this->assertSame(3, (int) $after['total_ticks']);
        $this->assertSame($before['schedule_md5'], $after['schedule_md5'], 'the schedule is the stored one');
        $this->assertSame($before['created_at'], $after['created_at']);
        $this->assertSame('expanding', $after['status']);
        $this->assertEqualsWithDelta(
            Carbon::parse($after['arrival'])->addHours(6)->timestamp,
            Carbon::parse($after['next_expansion_at'])->timestamp,
            5,
            'the next step is the schedule\'s, six hours after arrival, not another immediate run'
        );
        $this->assertGreaterThan(Carbon::parse($before['updated_at'])->timestamp, Carbon::parse($after['updated_at'])->timestamp);

        // The reach has grown from the stored one. The mailer finds new members through the outer bound.
        $this->assertFalse($this->outerBoundContains($f, self::AT_C), 'tick 2 does not reach C');
        $this->assertTrue($this->outerBoundContains($f, self::AT_A));
        $this->assertTrue($this->outerBoundContains($f, self::AT_B), 'tick 2 reaches B');

        if ($shape === 'grid') {
            $this->assertNotSame($before['cells_md5'], $after['cells_md5'], 'the grid is the tick 2 polygon');
            $this->assertTrue($this->storedGridContains($f, self::AT_A), 'what was reached stays reached');
            $this->assertTrue($this->storedGridContains($f, self::AT_B), 'and the new area is added');
            $this->assertFalse($this->storedGridContains($f, self::AT_C));
        } else {
            $this->assertSame(1, (int) $after['no_cells'], 'a retired row stays drained through the tick');
        }
    }

    /**
     * Frozen at the tick the clock is already at: the resume makes the row due, and the engine finds
     * nothing to do but put it back on the schedule. It is not expanded again, nothing is rippled,
     * and it is not left due, which would have it looked at every minute.
     */
    #[DataProvider('rowShapes')]
    public function test_a_resumed_reach_that_is_already_at_the_current_tick_is_put_back_on_schedule(string $shape): void
    {
        $f = $this->frozenReach($shape, 2, 4, ['A', 'B', 'D']);
        $before = $this->reachRow($f);
        $this->resume($f);

        $stats = $this->engineRun();

        $this->assertSame(0, $stats['expanded']);
        $this->assertSame(0, $stats['rippled_in']);
        $this->assertSame(0, $stats['initialized']);
        $this->assertGreaterThanOrEqual(1, $stats['skipped']);

        $after = $this->reachRow($f);
        $this->assertSame('expanding', $after['status']);
        $this->assertSame(2, (int) $after['tick']);
        $this->assertSame($before['cells_md5'], $after['cells_md5'], 'the polygon is untouched');
        $this->assertEqualsWithDelta(
            Carbon::parse($after['arrival'])->addHours(6)->timestamp,
            Carbon::parse($after['next_expansion_at'])->timestamp,
            5,
            'next due when tick 3 is'
        );
        $this->assertNull($this->copy($f, 'F'), 'tick 2 had already been through its communities');

        [, $mailed] = $this->mailerRun();
        $this->assertSame([], $mailed, 'everyone the tick reaches had been told before the freeze');
    }

    /**
     * Frozen after the last tick: the resume makes it 'expanding' and due, and the engine settles
     * it back to 'done' without doing any work, which is the state it was in before the freeze.
     */
    #[DataProvider('rowShapes')]
    public function test_a_resumed_reach_that_was_finished_goes_back_to_done(string $shape): void
    {
        $f = $this->frozenReach($shape, 3, 7, ['A', 'B', 'D']);
        $before = $this->reachRow($f);
        $this->resume($f);

        $stats = $this->engineRun();

        $this->assertSame(0, $stats['expanded']);
        $this->assertSame(0, $stats['initialized']);
        $after = $this->reachRow($f);
        $this->assertSame('done', $after['status']);
        $this->assertNull($after['next_expansion_at']);
        $this->assertSame(3, (int) $after['tick']);
        $this->assertSame($before['cells_md5'], $after['cells_md5']);
    }

    // ── (2) nobody is told twice ────────────────────────────────────────────────────────────────

    /**
     * Members already reached are not mailed again. After the resume and the engine's tick, the
     * mailer tells exactly the members the reach now covers whom the ledger has no record of: B,
     * who is inside tick 2 but not tick 1, FM, a member of the community tick 2 rippled into, and D,
     * who joined while the reach was frozen and is inside what it had already reached. Reached means
     * recorded in rippling_reach_notified, not "inside the old cells". A was told before the freeze
     * and is not told again; C and GM, outside tick 2, wait for tick 3, which tells them and nobody
     * else; NM and WM are inside the reach but are on communities where the post is not live.
     */
    #[DataProvider('rowShapes')]
    public function test_after_a_resume_only_members_not_yet_reached_are_mailed(string $shape): void
    {
        $f = $this->frozenReach($shape);

        // While frozen, the mailer has nothing to do for it.
        [, $mailed] = $this->mailerRun();
        $this->assertSame([], $mailed);

        $this->resume($f);
        $this->engineRun();
        $this->assertNotNull($this->copy($f, 'F'), 'precondition: the tick rippled into the new community');
        $this->assertNull($this->copy($f, 'G'), 'tick 2 does not reach G');

        [$mail, $mailed] = $this->mailerRun();

        $this->assertSame(
            $this->ids($f, 'B', 'D', 'FM'),
            $mailed,
            'B (new cells), D (never told) and FM (new community), nobody else'
        );
        $this->assertSame(3, $mail['emails_sent']);
        $this->assertSame([self::BUDGET[2]], array_values(array_unique($this->labelBudgets)),
            'asked at tick 2\'s budget: the mailer follows the tick the engine carried on to');
        $this->assertEqualsCanonicalizing(
            $this->ids($f, 'A', 'B', 'D', 'FM'),
            DB::table('rippling_reach_notified')->where('msgid', $f->msgid)->pluck('userid')->map(fn ($id) => (int) $id)->all(),
            'the ledger holds A from before, and B, D and FM now'
        );

        // Neither a second mailer run nor a direct ask mails anyone again.
        [, $again] = $this->mailerRun();
        $this->assertSame([], $again);
        $this->assertSame(0, (new UnifiedDigestService())->mailNewlyReachedForPost($f->msgid));

        // Time passes: tick 3 is due, the reach widens to C and to G, and only they are new.
        DB::table('rippling_reach')->where('msgid', $f->msgid)->update([
            'arrival' => now()->subHours(7), 'next_expansion_at' => now()->subMinute(),
        ]);
        $stats = $this->engineRun();
        $this->assertSame(1, $stats['expanded']);
        $this->assertSame('done', $this->reachRow($f)['status']);
        $this->assertNotNull($this->copy($f, 'G'), 'tick 3 rippled into G');

        [, $mailed] = $this->mailerRun();
        $this->assertSame($this->ids($f, 'C', 'GM'), $mailed, 'tick 3 tells C and GM and nobody who has been told');
        $this->assertSame([self::BUDGET[3]], array_values(array_unique($this->labelBudgets)));
    }

    /**
     * The resume keeps the post's original clock, so a reach held for hours takes every step it
     * missed in one run. Here it was frozen at tick 1 and is resumed seven hours after arrival: one
     * run goes straight to the last tick, ripples into the communities ticks 2 and 3 first touch, and
     * the mailer then tells everyone new once, in one pass, at the last tick's budget. Nobody is told
     * twice for having been inside two of the steps it skipped, and the poster's introduction is not
     * sent again.
     */
    #[DataProvider('rowShapes')]
    public function test_a_reach_held_for_hours_takes_several_steps_at_once_and_tells_each_member_once(string $shape): void
    {
        $f = $this->frozenReach($shape, 1, 7);
        $before = $this->reachRow($f);
        $this->resume($f);

        $stats = $this->engineRun();

        $this->assertSame(1, $stats['expanded'], 'one run, however many steps it covers');
        $this->assertSame(0, $stats['initialized']);
        $this->assertSame(2, $stats['rippled_in'], 'F, which tick 2 first touches, and G, which tick 3 does');
        $this->assertSame(2, $stats['memberships_added'], 'the poster joins each of the two, and not W');
        $this->assertSame([], $this->routingCalls());
        $after = $this->reachRow($f);
        $this->assertSame(3, (int) $after['tick'], 'from the stored tick 1 straight to tick 3');
        $this->assertSame('done', $after['status']);
        $this->assertNull($after['next_expansion_at']);
        $this->assertSame($before['schedule_md5'], $after['schedule_md5']);
        $this->assertNotNull($this->copy($f, 'F'));
        $this->assertNotNull($this->copy($f, 'G'));
        $this->assertSame(1, (int) $this->copy($f, 'W')->deleted);
        $this->assertSame('Pending', $this->copy($f, 'N')->collection);
        $this->assertNull($this->posterMembership($f, 'W'));
        $this->assertSame(
            [],
            array_filter($this->spooled, fn ($s) => $s['mailable'] instanceof RippleIntroMail),
            'the introduction is sent once per post, and this one has had it'
        );

        [$mail, $mailed] = $this->mailerRun();

        $this->assertSame($this->ids($f, 'B', 'C', 'D', 'FM', 'GM'), $mailed, 'everyone new, and A who was told before is not');
        $this->assertSame(5, $mail['emails_sent']);
        $addresses = array_values(array_map(
            fn ($s) => $s['to'],
            array_filter($this->spooled, fn ($s) => $s['mailable'] instanceof UnifiedDigest)
        ));
        $this->assertCount(5, $addresses);
        $this->assertSame($addresses, array_values(array_unique($addresses)), 'each member gets one mail');
        $this->assertSame([self::BUDGET[3]], array_values(array_unique($this->labelBudgets)), 'asked once, at the last tick\'s budget');

        [, $again] = $this->mailerRun();
        $this->assertSame([], $again);
        $this->assertEqualsCanonicalizing(
            $this->ids($f, 'A', 'B', 'C', 'D', 'FM', 'GM'),
            DB::table('rippling_reach_notified')->where('msgid', $f->msgid)->pluck('userid')->map(fn ($id) => (int) $id)->all()
        );
    }

    // ── (3) copies are not brought back ─────────────────────────────────────────────────────────

    /**
     * A copy that was withdrawn (deleted = 1) is not brought back by the resumed reach, in any form.
     * The copy stays deleted and is not duplicated, the poster is not put back on that community
     * (their ripple-join was removed with the copy, and a re-join would write a new Joined log and
     * queue them for reach mail there), and its members are not mailed, because the post is not live
     * on it. The same tick does ripple into the community it reaches for the first time, which also
     * shows the tick really ran.
     */
    #[DataProvider('rowShapes')]
    public function test_a_withdrawn_copy_stays_withdrawn_and_its_poster_is_not_rejoined(string $shape): void
    {
        $f = $this->frozenReach($shape);
        $this->assertNull($this->posterMembership($f, 'W'), 'precondition: the withdrawal removed the poster from W');
        $joinedBefore = $this->joinedLogCount($f, 'W');

        $this->resume($f);
        $stats = $this->engineRun();

        $this->assertSame(1, $stats['expanded'], 'the tick ran');
        $this->assertSame(1, $stats['rippled_in'], 'into the one community with no copy at all');
        $this->assertSame(1, (int) DB::table('messages_groups')->where('msgid', $f->msgid)->where('groupid', $f->W->id)->count(),
            'no second row for W');
        $this->assertSame(1, (int) $this->copy($f, 'W')->deleted, 'the copy is still withdrawn');
        $this->assertNull($this->posterMembership($f, 'W'), 'the poster is not put back on a community the post was withdrawn from');
        $this->assertSame($joinedBefore, $this->joinedLogCount($f, 'W'), 'no new join is logged there');
        $this->assertSame(1, $stats['memberships_added'], 'the only join is the new community');
        $this->assertNotNull($this->posterMembership($f, 'F'), 'the new community, by contrast, gets the poster');
        $this->assertSame(
            [],
            array_filter($this->spooled, fn ($s) => $s['mailable'] instanceof RippleIntroMail),
            'the introduction is sent once per post, and this one has had it'
        );

        [, $mailed] = $this->mailerRun();
        $this->assertContains((int) $f->B->id, $mailed, 'the pass ran and mailed the newly reached');
        $this->assertNotContains((int) $f->WM->id, $mailed, 'a member of W is not mailed about a post that is not on W');
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $f->msgid)->where('userid', $f->WM->id)->exists()
        );
    }

    /**
     * A copy a neighbouring community's moderators now hold in Pending is theirs to decide. The
     * resumed reach does not approve it, delete it, duplicate it or mail its members, and the
     * poster keeps the ripple-join they have there.
     */
    #[DataProvider('rowShapes')]
    public function test_a_copy_pulled_back_to_pending_is_left_as_it_is(string $shape): void
    {
        $f = $this->frozenReach($shape);
        $before = (array) $this->copy($f, 'N');
        $this->assertSame('Pending', $before['collection']);
        $memberBefore = (array) $this->posterMembership($f, 'N');

        $this->resume($f);
        $stats = $this->engineRun();
        [, $mailed] = $this->mailerRun();

        $this->assertSame(1, $stats['expanded'], 'the tick ran');
        $this->assertSame(
            1,
            (int) DB::table('messages_groups')->where('msgid', $f->msgid)->where('groupid', $f->N->id)->count(),
            'no second row for N'
        );
        $this->assertSame($before, (array) $this->copy($f, 'N'), 'every column of the Pending copy is as it was');
        $this->assertSame($memberBefore, (array) $this->posterMembership($f, 'N'), 'the poster keeps their join');
        $this->assertContains((int) $f->B->id, $mailed, 'the pass ran and mailed the newly reached');
        $this->assertNotContains((int) $f->NM->id, $mailed, 'a member of N is not mailed about a post still waiting for N\'s moderators');
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $f->msgid)->where('userid', $f->NM->id)->exists()
        );
    }
}

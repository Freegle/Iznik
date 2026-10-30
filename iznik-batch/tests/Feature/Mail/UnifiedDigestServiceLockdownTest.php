<?php

namespace Tests\Feature\Mail;

use App\Models\Message;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\Lockdown\LockdownService;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\SeedsReachCells;
use Tests\TestCase;

/**
 * UnifiedDigestService under the lockdown switch (plan 2026-09-27-lockdown-switch.md,
 * section 11.7). Ordinary (no lockdown) behaviour is covered by UnifiedDigestServiceTest;
 * this file is only the lockdown branch, across the service's remaining mail-generation
 * call sites and their separate watermarks (the per-community immediate cursor went with
 * communities: immediate mail is reach mail only):
 *
 * - sendReachDigests() + spoolPostToRecipients() - TWO watermarks for the same
 *   'digest_immediate' mail type: the per-shard reachMailMark (checked once per pass, at the
 *   top of sendReachDigests(), before the mark is read - the pass never gets far enough to
 *   read it while held) and the per-recipient rippling_reach_notified ledger (checked per
 *   recipient inside spoolPostToRecipients(), the same spot the existing suppression and
 *   distance-preference skips sit in - the entry point that AutoApproveService and
 *   mailPostToUsers reach directly, without going through sendReachDigests() at all).
 * - sendDigestToUser() - the per-user UserDigest tracker. Checked once per user, before the
 *   tracker is fetched, for the same "advances on every exit path" reason as the reach mark.
 *
 * All three checks count `deferred:digest_immediate` or `deferred:digest_<mode>` and call
 * ack('mail-loops'); none of them touch MailSuppressionService.
 */
class UnifiedDigestServiceLockdownTest extends TestCase
{
    use \Tests\Support\MembersLiveInLondon;

    use SeedsReachCells;

    private LockdownService $lockdown;

    private UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_counters')->delete();
        DB::table('lockdown_acks')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->service = new UnifiedDigestService();
    }

    // ─── REACH-MAIL PATH (sendReachDigests + spoolPostToRecipients) ─────

    private function setUpRippledPostWithReachableImmediateMember(): array
    {
        config(['freegle.digest.immediate_allowlist' => '*']);
        $poster = $this->createTestUser(['added' => now()->subHours(72)]);
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        $member = $this->createTestUser(['added' => now()->subHours(72)]);
        DB::table('users')->where('id', $member->id)->update(['emailfrequency' => -1]); // immediate
        $member->settings = ['mylocation' => ['lat' => 51.5, 'lng' => -0.1]];
        $member->save();

        $message = $this->createTestMessage($poster, [
            'collection' => Message::COLLECTION_APPROVED,
            'arrival' => now()->subHours(1),
        ]);
        // Reach (status 'expanding', just updated) covering the member's location.
        DB::statement(
            'INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks, '
            . 'total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) '
            . "VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 3, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))'), 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))']
        );
        DB::table('rippling_reach')->where('msgid', $message->id)->update(['reach_labels' => 'label-bytes']);

        // The stored label admits every asked point: the lockdown behaviour is under test,
        // not the geometry.
        Http::fake(function ($request) {
            if (!str_contains($request->url(), 'reach-arrival')) {
                return null;
            }
            $results = array_map(
                fn ($pt) => ['arrival' => 100, 'in' => true],
                $request['points'] ?? []
            );

            return Http::response(['results' => $results]);
        });

        return [$message, $member];
    }

    public function test_held_reach_pass_mails_nobody_and_leaves_the_shard_mark_and_ledger_untouched(): void
    {
        [$message, $member] = $this->setUpRippledPostWithReachableImmediateMember();

        $this->lockdown->press(null, 'test lockdown');

        $stats = $this->service->sendReachDigests();

        $this->assertSame(0, $stats['emails_sent']);
        Mail::assertNothingSent();
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'the ledger must stay unwritten - a skipped member is naturally re-considered later'
        );
        $this->assertNull(
            DB::table('config')->where('key', UnifiedDigestService::reachMailMarkKey(0))->value('value'),
            'the shard mark must not be written while the pass is held - it is never even read'
        );

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:digest_immediate')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_held_direct_reach_mail_call_defers_the_recipient_without_ledgering_them(): void
    {
        // AutoApproveService (the post-'done' approval gap) and mailPostToUsers' match-mail
        // path both call into spoolPostToRecipients() directly, without ever going through
        // sendReachDigests()'s own top-level check - this is that path, exercised the same
        // way AutoApproveService reaches it.
        [$message, $member] = $this->setUpRippledPostWithReachableImmediateMember();

        $this->lockdown->press(null, 'test lockdown');

        $mailed = $this->service->mailNewlyReachedForPost($message->id);

        $this->assertSame(0, $mailed);
        Mail::assertNothingSent();
        $this->assertFalse(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists(),
            'skipping without writing the ledger means the member is a fresh candidate once email resumes'
        );

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:digest_immediate')->first();
        $this->assertNotNull($counter);
        $this->assertGreaterThanOrEqual(1, (int) $counter->count);
    }

    public function test_lifting_email_mails_the_member_the_reach_pass_had_held(): void
    {
        [$message, $member] = $this->setUpRippledPostWithReachableImmediateMember();

        $this->lockdown->press(null, 'test lockdown');
        $held = $this->service->sendReachDigests();
        $this->assertSame(0, $held['emails_sent']);

        $this->lockdown->setSurfaces(['email' => false], null);

        $sent = $this->service->sendReachDigests();
        $this->assertGreaterThanOrEqual(1, $sent['emails_sent']);
        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $message->id)->where('userid', $member->id)->exists()
        );
    }

    // ─── DAILY-DIGEST PATH (sendDigestToUser) ────────────────────────────

    private function bootstrapDailyRecipient(): array
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        DB::table('users')->where('id', $poster->id)->update(['emailfrequency' => -1]);
        DB::table('users')->where('id', $recipient->id)->update(['emailfrequency' => 24]);
        $this->createTestMessage($poster);

        return [$recipient];
    }

    public function test_held_daily_digest_sends_nothing_and_creates_no_tracker(): void
    {
        [$recipient] = $this->bootstrapDailyRecipient();

        $this->lockdown->press(null, 'test lockdown');

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertSame(0, $stats['emails_sent']);
        Mail::assertNothingSent();
        $this->assertNull(
            UserDigest::where('userid', $recipient->id)->where('mode', UnifiedDigestService::MODE_DAILY)->first(),
            'no tracker means this member is examined from scratch, not from a stale cursor, once email resumes'
        );

        $counter = DB::table('lockdown_counters')->where('kind', 'deferred:digest_daily')->first();
        $this->assertNotNull($counter);
        $this->assertSame(1, (int) $counter->count);

        $ack = DB::table('lockdown_acks')->where('loop', 'mail-loops')->first();
        $this->assertNotNull($ack);
        $this->assertEquals($this->lockdown->current()->id, $ack->lockdownrowid);
    }

    public function test_lifting_email_sends_the_daily_digest_that_was_held(): void
    {
        [$recipient] = $this->bootstrapDailyRecipient();

        $this->lockdown->press(null, 'test lockdown');
        $held = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertSame(0, $held['emails_sent']);

        $this->lockdown->setSurfaces(['email' => false], null);

        $sent = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertSame(1, $sent['emails_sent']);
        $this->assertNotNull(
            UserDigest::where('userid', $recipient->id)->where('mode', UnifiedDigestService::MODE_DAILY)->first()
        );
    }
}

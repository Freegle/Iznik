<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\User;
use App\Models\UserDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UnifiedDigestServiceTest extends TestCase
{
    protected UnifiedDigestService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UnifiedDigestService();
        Mail::fake();
    }

    public function test_deduplication_with_tnpostid(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Create two messages with same tnpostid (cross-posted via TN).
        $message1 = $this->createTestMessage($user, $group1, [
            'tnpostid' => 'TN12345',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'tnpostid' => 'TN12345',
            'subject' => $message1->subject,
        ]);

        // Add groupid attribute for deduplication test.
        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_deduplication_without_tnpostid(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Create two similar messages without tnpostid but with same subject/location.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Test Item (London)',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Test Item (London)',
        ]);

        // Set same locationid after creation (nullable, no FK constraint).
        $message1->locationid = $message1->locationid;
        $message2->locationid = $message1->locationid;

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_different_items_not_deduplicated(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $message1 = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Sofa (London)',
        ]);
        $message2 = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Table (London)',
        ]);

        $message1->groupid = $group->id;
        $message2->groupid = $group->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(2, $deduplicated);
    }

    public function test_user_digest_tracker_created(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        // Set recipient to want daily digests and be active.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);

        // Create a message from another user (so recipient has something to receive).
        $this->createTestMessage($poster, $group);

        // Run digest - should create tracker and send email.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $tracker = UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->first();

        $this->assertNotNull($tracker);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_format_posted_to_multiple_groups(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $result = $this->service->formatPostedTo([$group1->id, $group2->id]);

        $this->assertStringContainsString('Posted to:', $result);
        $this->assertStringContainsString($group1->nameshort, $result);
        $this->assertStringContainsString($group2->nameshort, $result);
    }

    public function test_format_posted_to_single_group_returns_empty(): void
    {
        $group = $this->createTestGroup();

        $result = $this->service->formatPostedTo([$group->id]);

        $this->assertEmpty($result);
    }

    public function test_digest_excludes_users_own_posts(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        // Recipient wants daily digests.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);

        // Create a message from the recipient (should be filtered out).
        $this->createTestMessage($recipient, $group);

        // Run digest for recipient.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        // No emails should be sent because the only message is from the recipient.
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_deduplication_same_subject_different_body_not_deduped(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Two messages with same subject but different body text.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'I have a spade and a fork available for collection.',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Garden tools (London)',
            'textbody' => 'Lawnmower available, needs collecting this weekend.',
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should NOT be deduplicated because bodies are different.
        $this->assertCount(2, $deduplicated);
    }

    public function test_deduplication_same_subject_same_body_deduped(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $bodyText = 'I have a lovely sofa available for collection.';

        // Two messages with same subject AND same body.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Sofa (London)',
            'textbody' => $bodyText,
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Sofa (London)',
            'textbody' => $bodyText,
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should be deduplicated because both subject and body match.
        $this->assertCount(1, $deduplicated);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
    }

    public function test_deduplication_null_body_treated_as_matching(): void
    {
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Two messages with same subject and both null bodies.
        $message1 = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'subject' => 'OFFER: Table (London)',
            'textbody' => null,
        ]);

        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $posts = collect([$message1, $message2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        // Should be deduplicated - null bodies both normalize to ''.
        $this->assertCount(1, $deduplicated);
    }

    public function test_sponsors_are_included_and_deduplicated(): void
    {
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        // Recipient wants daily digests.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group1);
        $this->createMembership($poster, $group2);
        $this->createMembership($recipient, $group1);
        $this->createMembership($recipient, $group2);

        // Create messages so the digest has content.
        $this->createTestMessage($poster, $group1);
        $this->createTestMessage($poster, $group2);

        // Same sponsor on both groups (Essex-style: one sponsor, many groups).
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group1->id,
            'name' => 'Essex County Council',
            'linkurl' => 'https://essex.gov.uk',
            'imageurl' => 'https://essex.gov.uk/logo.png',
            'tagline' => 'Supporting reuse in Essex',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Test Contact',
            'contactemail' => 'test@essex.gov.uk',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group2->id,
            'name' => 'Essex County Council',
            'linkurl' => 'https://essex.gov.uk',
            'imageurl' => 'https://essex.gov.uk/logo.png',
            'tagline' => 'Supporting reuse in Essex',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Test Contact',
            'contactemail' => 'test@essex.gov.uk',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        // Different sponsor on group2 only.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group2->id,
            'name' => 'Local Business',
            'linkurl' => 'https://localbiz.example.com',
            'imageurl' => 'https://localbiz.example.com/logo.png',
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Biz Contact',
            'contactemail' => 'biz@example.com',
            'amount' => 50,
            'visible' => TRUE,
        ]);

        // Get sponsors for this user — should deduplicate Essex across groups.
        $sponsors = $this->service->getSponsorsForUser($recipient);

        // Should have 2 unique sponsors, not 3.
        $this->assertCount(2, $sponsors);

        // Essex should appear once with the highest amount.
        $essex = $sponsors->firstWhere('name', 'Essex County Council');
        $this->assertNotNull($essex);
        $this->assertEquals('https://essex.gov.uk', $essex->linkurl);
        $this->assertEquals('Supporting reuse in Essex', $essex->tagline);

        // Local Business should appear once.
        $localBiz = $sponsors->firstWhere('name', 'Local Business');
        $this->assertNotNull($localBiz);
    }

    public function test_expired_sponsors_are_excluded(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Expired sponsor.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Old Sponsor',
            'startdate' => now()->subYear(),
            'enddate' => now()->subMonth(),
            'contactname' => 'Old',
            'contactemail' => 'old@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        // Hidden sponsor.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Hidden Sponsor',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Hidden',
            'contactemail' => 'hidden@example.com',
            'amount' => 100,
            'visible' => FALSE,
        ]);

        $sponsors = $this->service->getSponsorsForUser($user);
        $this->assertCount(0, $sponsors);
    }

    // PER-USER ELIGIBILITY TESTS REMOVED — they were based on the prior
    // per-user iteration model. The new V1-parity per-group iteration
    // determines recipients purely by memberships.emailfrequency=-1 within
    // each group it walks; standalone "would THIS user be eligible?" tests
    // don't map cleanly. The new per-group tests at the end of this file
    // cover the equivalent guarantees (fanout, cursor advance, tie-break,
    // allowlist gate, dry-run, --limit, --group, --user).

    // (further per-user immediate-mode tests removed — superseded by the
    // per-group iteration tests near the end of the file.)

    /**
     * Daily mode must NOT fan out — every new post since the previous send
     * is bundled into a single rolled-up email regardless of how many there
     * are.
     */
    public function test_daily_mode_bundles_all_posts_into_one_email(): void
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_empty_allows_everyone(): void
    {
        // Empty config means "no restriction" — both members in the group
        // get the immediate notification.
        config(['freegle.digest.immediate_allowlist' => '']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_filters_to_specified_addresses(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $allowed = $this->createTestUser();
        $blocked = $this->createTestUser();
        $this->createMembership($poster, $group);
        $this->createMembership($allowed, $group);
        $this->createMembership($blocked, $group);
        $this->seedImmediateCursor($group);

        $allowedEmail = $allowed->emails()->first()->email;
        config(['freegle.digest.immediate_allowlist' => $allowedEmail]);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Only the allowed-list recipient gets the message; the other
        // immediate-frequency member in the same group is filtered out.
        $this->assertEquals(1, $stats['emails_sent']);
        $this->assertEquals(1, $stats['users_processed']);
    }

    /**
     * The default value checked into config must be '*' so the rolled-out
     * cron emails every eligible user — V1's bulk3 immediate-digest cron was
     * disabled on 2026-05-27 and this Laravel job is now the only source of
     * immediate notifications. A regression that re-pinned the default to a
     * specific address would silently drop all but that user's notifications.
     */
    public function test_immediate_mode_default_is_wildcard(): void
    {
        $this->assertEquals('*', config('freegle.digest.immediate_allowlist'));

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    /**
     * Helper: create a group, a poster, a recipient (both at
     * emailfrequency=-1) and seed the immediate cursor. Returns
     * [$group, $poster, $recipient]. Used by the allowlist tests above
     * to keep their setup terse.
     */
    protected function bootstrapImmediateGroup(): array
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group);
        $this->seedImmediateCursor($group);
        return [$group, $poster, $recipient];
    }

    /**
     * Helper: seed/update a groups_digests row at frequency=-1 for one
     * group. The per-group cron walks groups_digests, so a row must exist
     * (V1 INSERT IGNOREs in production; tests need the same shape).
     */
    protected function seedImmediateCursor(Group $group, ?string $msgdate = null, int $msgid = 0): void
    {
        \App\Models\GroupDigest::updateOrCreate(
            [
                'groupid' => $group->id,
                'frequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            ],
            [
                'msgdate' => $msgdate,
                'msgid' => $msgid,
            ]
        );
    }

    /**
     * Allowlist must not affect daily mode — that's a separate, already-running
     * flow that we don't want to gate behind this setting.
     */
    public function test_immediate_allowlist_does_not_affect_daily_mode(): void
    {
        // Pin to an address that nobody in this test has, then run daily.
        config(['freegle.digest.immediate_allowlist' => 'nobody-test@example.invalid']);

        $user = $this->createTestUser();
        $user->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    // ─── V1-PARITY PER-GROUP IMMEDIATE TESTS ────────────────────────────
    // These pin the new sendImmediateDigests behaviour: walk
    // groups_digests at frequency=-1, find messages since the per-group
    // cursor with (arrival, msgid) tuple compare, send to every member
    // at emailfrequency=-1 minus the poster, advance the cursor.

    public function test_immediate_sends_to_each_immediate_frequency_member_in_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $r1 = $this->createTestUser();
        $r2 = $this->createTestUser();
        $dailyOnly = $this->createTestUser();

        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($r1, $group);
        $this->createMembership($r2, $group);
        $this->createMembership($dailyOnly, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->seedImmediateCursor($group);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['users_processed']);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_skips_poster_from_recipients(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->seedImmediateCursor($group);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Own item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Poster is the only immediate-frequency member; their own post
        // must not loop back to themselves.
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_immediate_sends_one_email_per_new_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(3, $stats['emails_sent']);
    }

    public function test_immediate_advances_groups_digests_cursor(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();

        $this->assertEquals($msg2->id, $cursor->msgid);
        $this->assertNotNull($cursor->msgdate);

        // Running again with no new posts must not re-send.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_immediate_tuple_tiebreak_catches_messages_with_identical_arrival(): void
    {
        // V1 only uses `arrival > msgdate`; we tighten to (arrival, msgid)
        // tuple compare so a same-arrival collision can't drop a message
        // between two cron ticks. Force two messages_groups rows to share
        // an arrival, run once to send both, then run again to confirm
        // nothing fires again.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();

        $sharedArrival = now()->format('Y-m-d H:i:s.u');
        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg1->id)->update(['arrival' => $sharedArrival]);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg2->id)->update(['arrival' => $sharedArrival]);

        $first = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $first['emails_sent'], 'Both same-arrival messages must be picked up');

        $second = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $second['emails_sent'], 'Cursor msgid must keep same-arrival messages from re-firing');
    }

    public function test_immediate_skips_groups_with_no_immediate_members(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $dailyMember = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($dailyMember, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->seedImmediateCursor($group);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // The whereExists() filter in sendImmediateDigests should skip the
        // group entirely because no membership has emailfrequency=-1.
        $this->assertEquals(0, $stats['groups_processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_immediate_limit_caps_groups_processed_per_run(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        for ($i = 0; $i < 3; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $this->createTestMessage($poster, $g, ['subject' => "OFFER: Item {$i} (TestLocation)"]);
        }

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, null, 1);

        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_group_filter_restricts_to_single_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        foreach ([$groupA, $groupB] as $g) {
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $this->createTestMessage($poster, $g, ['subject' => 'OFFER: Item (TestLocation)']);
        }

        $stats = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, $groupA->id
        );

        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(1, $stats['emails_sent']);

        $cursorB = DB::table('groups_digests')
            ->where('groupid', $groupB->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursorB->msgdate, 'Group B must not have advanced its cursor');
    }

    public function test_immediate_user_filter_restricts_recipients_within_group(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $targeted = $this->createTestUser();
        $other = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($targeted, $group);
        $this->createMembership($other, $group);
        $this->seedImmediateCursor($group);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $targeted->id);

        // Only the targeted user gets the email.
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_dry_run_does_not_advance_cursor(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, null, null, true);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();

        $this->assertNull($cursor->msgdate, 'Dry run must not advance the cursor');
    }
}

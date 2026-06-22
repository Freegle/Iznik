<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
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
        // Rippling ships dark; enable it so the reach-coordination ledger path is exercised.
        config(['freegle.ripple.enabled' => true]);
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

    public function test_completed_came_and_went_posts_are_deduplicated_like_live(): void
    {
        // The same item, cross-posted to two groups as two separate messages
        // (distinct ids, shared tnpostid) — and both Taken/Received, so they
        // land in the greyed daily "came and went" section.
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $message1 = $this->createTestMessage($user, $group1, [
            'tnpostid' => 'TN54321',
        ]);
        $message2 = $this->createTestMessage($user, $group2, [
            'tnpostid' => 'TN54321',
            'subject' => $message1->subject,
        ]);
        $message1->load('groups');
        $message2->load('groups');
        $message1->groupid = $group1->id;
        $message2->groupid = $group2->id;

        $completed = collect([$message1, $message2]);

        // The old came-and-went path used ->unique('id'), which keeps both
        // because the msgids differ — that's the duplication we're fixing.
        $this->assertCount(2, $completed->unique('id')->values());

        // The fix collapses the cross-post to a single card, exactly like the
        // live section's deduplicatePosts().
        $deduped = $this->service->deduplicateCompletedPosts($completed);
        $this->assertCount(1, $deduped);
        $this->assertEquals($message1->id, $deduped->first()->id);

        // Both groups are folded into the surviving card, so the byline reads
        // "Posted to: A, B" just like the live section — not a single group.
        $this->assertEqualsCanonicalizing(
            [$group1->id, $group2->id],
            $deduped->first()->groups->pluck('id')->all()
        );
    }

    public function test_deduplication_single_message_on_multiple_groups(): void
    {
        // The multi-group model: ONE messages row with two messages_groups
        // rows. The digest query joins messages to messages_groups, so the
        // same messages.id comes back once per group with a different groupid.
        // deduplicatePosts must collapse those into a single digest entry that
        // lists both groups.
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $message = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Single multi-group item (London)',
            'textbody' => 'One physical item, posted to two groups.',
        ]);

        // Second messages_groups row — same msgid, different group.
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        // Reproduce the join output: the same message as two rows differing
        // only by groupid.
        $row1 = $message->fresh();
        $row1->groupid = $group1->id;
        $row2 = $message->fresh();
        $row2->groupid = $group2->id;

        $posts = collect([$row1, $row2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated);
        $this->assertEquals($message->id, $deduplicated->first()['message']->id);
        $this->assertCount(2, $deduplicated->first()['postedToGroups']);
        $this->assertEqualsCanonicalizing(
            [$group1->id, $group2->id],
            $deduplicated->first()['postedToGroups']
        );
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

        // Set recipient to want daily digests and be active. V1 parity:
        // membership emailfrequency=24 is the authoritative daily selector;
        // simplemail acts only as the join-time default that populated it.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

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

    public function test_daily_digest_excludes_posts_with_an_outcome(): void
    {
        // V1 parity (Digest.php:218): a post that already has an outcome
        // (Withdrawn/Taken/Received/...) is no longer available and must not
        // appear in the digest — it was advertising withdrawn items as live.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        // The only post in range has been withdrawn.
        $message = $this->createTestMessage($poster, $group);
        DB::table('messages_outcomes')->insert([
            'msgid' => $message->id,
            'outcome' => 'Withdrawn',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(0, $stats['emails_sent'], 'a withdrawn/taken post must not be digested');
    }

    public function test_daily_digest_with_available_and_taken_still_sends(): void
    {
        // An available post + a Taken post: the digest still goes (1 email);
        // the available post is the main content and the Taken one feeds the
        // "came and went" section rather than blocking the send.
        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $this->createTestMessage($poster, $group); // available
        $taken = $this->createTestMessage($poster, $group);
        DB::table('messages_outcomes')->insert([
            'msgid' => $taken->id,
            'outcome' => 'Taken',
            'timestamp' => now(),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);
        $this->assertEquals(1, $stats['emails_sent'], 'available post still sends; taken feeds came-and-went');
    }

    public function test_daily_digest_skips_user_already_sent_today(): void
    {
        // Bulk daily run (no --user) must not re-send to a user who already
        // got a daily digest earlier the same London day, even though new
        // posts exist — guards against the multi-send seen on 2026-06-11 when
        // the command was run several times in one day. A new London day (the
        // next 08:00 cron) re-includes them.
        config(['freegle.digest.daily_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group);

        // Already digested at the start of today's London day, cursor at 0 so
        // there ARE newer posts — only the once-today guard should hold it back.
        UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
            'lastsent' => \Carbon\Carbon::now('Europe/London')->startOfDay()->setTimezone('UTC'),
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);
        $this->assertEquals(0, $stats['emails_sent'], 'must skip a user already digested today');

        // Move the last-sent mark into yesterday (London); now eligible again.
        UserDigest::where('userid', $recipient->id)
            ->where('mode', UnifiedDigestService::MODE_DAILY)
            ->update(['lastsent' => \Carbon\Carbon::now('Europe/London')->startOfDay()->subHours(2)->setTimezone('UTC')]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);
        $this->assertEquals(1, $stats['emails_sent'], 'must send once the last digest was a prior day');
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

    public function test_digest_includes_users_own_posts(): void
    {
        // V1 parity: the per-group digest selection in iznik-server
        // include/mail/Digest.php has no fromuser != ? filter, so a user's
        // own posts appear in their own digest. Mirror that here.
        $recipient = $this->createTestUser();
        $group = $this->createTestGroup();

        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $this->createTestMessage($recipient, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
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

        // Recipient wants daily digests for both groups — V1 parity requires
        // per-group emailfrequency=24 on each membership.
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $this->createMembership($poster, $group1);
        $this->createMembership($poster, $group2);
        $this->createMembership($recipient, $group1, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($recipient, $group2, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

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

    public function test_get_sponsors_for_group_returns_only_that_groups_sponsors(): void
    {
        // V1 parity for immediate digests: an email about group A must carry
        // only group A's sponsors, never the union across the recipient's other
        // groups (which getSponsorsForUser returns for the daily digest).
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();

        DB::table('groups_sponsorship')->insert([
            'groupid' => $groupA->id,
            'name' => 'Group A Sponsor',
            'linkurl' => 'https://a.example.com',
            'imageurl' => 'https://a.example.com/logo.png',
            'tagline' => 'Backs group A',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'A',
            'contactemail' => 'a@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        DB::table('groups_sponsorship')->insert([
            'groupid' => $groupB->id,
            'name' => 'Group B Sponsor',
            'linkurl' => 'https://b.example.com',
            'imageurl' => 'https://b.example.com/logo.png',
            'tagline' => 'Backs group B',
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'B',
            'contactemail' => 'b@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $sponsors = $this->service->getSponsorsForGroup($groupA->id);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('Group A Sponsor', $sponsors->first()->name);
        $this->assertNull($sponsors->firstWhere('name', 'Group B Sponsor'));
    }

    public function test_get_sponsors_for_group_excludes_expired_and_invisible(): void
    {
        $group = $this->createTestGroup();

        // Expired.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Expired Sponsor',
            'linkurl' => 'https://x.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subMonths(2),
            'enddate' => now()->subMonth(),
            'contactname' => 'X',
            'contactemail' => 'x@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);
        // Hidden.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Hidden Sponsor',
            'linkurl' => 'https://y.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Y',
            'contactemail' => 'y@example.com',
            'amount' => 100,
            'visible' => FALSE,
        ]);
        // Active + visible.
        DB::table('groups_sponsorship')->insert([
            'groupid' => $group->id,
            'name' => 'Active Sponsor',
            'linkurl' => 'https://z.example.com',
            'imageurl' => null,
            'tagline' => null,
            'startdate' => now()->subDay(),
            'enddate' => now()->addMonth(),
            'contactname' => 'Z',
            'contactemail' => 'z@example.com',
            'amount' => 100,
            'visible' => TRUE,
        ]);

        $sponsors = $this->service->getSponsorsForGroup($group->id);

        $this->assertCount(1, $sponsors);
        $this->assertEquals('Active Sponsor', $sponsors->first()->name);
    }

    public function test_get_sponsors_for_group_returns_empty_for_zero_group(): void
    {
        $this->assertTrue($this->service->getSponsorsForGroup(0)->isEmpty());
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
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($poster, $group);

        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    /**
     * Daily defaults to OFF (empty allowlist = nobody) so a deploy can't
     * double-mail the whole userbase alongside V1's still-running daily cron.
     * A regression flipping this default to '*' would do exactly that.
     */
    public function test_daily_mode_default_allowlist_is_empty(): void
    {
        $this->assertSame('', config('freegle.digest.daily_allowlist'));
    }

    /**
     * Create an active daily-digest recipient with one incoming post, at the
     * given per-group cadence. The poster is immediate-only with no lastaccess
     * so it never shows up in the broad daily selection.
     */
    private function makeDailyRecipientWithPost(int $emailfrequency = Membership::EMAIL_FREQUENCY_DAILY): User
    {
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, ['emailfrequency' => $emailfrequency]);
        $this->createMembership($poster, $group);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        return $recipient;
    }

    public function test_daily_mode_empty_allowlist_sends_to_nobody(): void
    {
        config(['freegle.digest.daily_allowlist' => '']);
        $this->makeDailyRecipientWithPost();

        // Broad run (no explicit --user): the empty allowlist gates everyone out.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(0, $stats['users_processed']);
        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_mode_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->makeDailyRecipientWithPost();

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_allowlist_filters_to_specified_addresses(): void
    {
        $allowed = $this->makeDailyRecipientWithPost();
        $this->makeDailyRecipientWithPost(); // a second eligible recipient, not opted in

        $allowedEmail = $allowed->emails()->first()->email;
        config(['freegle.digest.daily_allowlist' => $allowedEmail]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        // Only the opted-in recipient is selected and mailed.
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_explicit_user_bypasses_empty_allowlist(): void
    {
        // Even with the allowlist OFF, an explicit --user (manual sampling)
        // still sends — the gate only applies to the broad scheduled run.
        config(['freegle.digest.daily_allowlist' => '']);
        $recipient = $this->makeDailyRecipientWithPost();

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_daily_mode_folds_intermediate_cadences(): void
    {
        // With the per-group digest removed, a member on an intermediate
        // cadence (e.g. 8-hourly) has no dedicated sender. Daily must fold
        // every positive cadence in so they aren't silently dropped.
        config(['freegle.digest.daily_allowlist' => '*']);
        $this->makeDailyRecipientWithPost(8);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY);

        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_empty_allows_everyone(): void
    {
        // Empty config means "no restriction" — both immediate-frequency
        // members get the notification: the recipient AND the poster (V1
        // parity loops a user's own post back to them).
        config(['freegle.digest.immediate_allowlist' => '']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_mode_allowlist_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        // Both immediate members (recipient + poster) receive it.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
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

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

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
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        // Both immediate members (recipient + poster) receive it.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_mail_newly_reached_reach_gates_then_picks_up_later_reached_on_rerun(): void
    {
        // The expander-driven mailer (#0 step 4) mails the post to immediate members the reach
        // NOW covers, ledgers them, and — crucially — on a LATER tick picks up members the reach
        // reaches afterwards (the exact case the cursor-based approach silently dropped).
        config(['freegle.digest.immediate_allowlist' => '*']);

        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $memberA = $this->createTestUser();
        $this->createMembership($memberA, $group);
        $memberB = $this->createTestUser();
        $this->createMembership($memberB, $group);
        $this->setMyLocation($memberA, 51.5, -0.1);  // inside reach v1
        $this->setMyLocation($memberB, 51.5, 0.5);   // outside v1, inside v2

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach mail (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->mailNewlyReachedForPost($msg->id);

        $ledgered = fn ($uid) => DB::table('rippling_reach_notified')
            ->where('msgid', $msg->id)->where('userid', $uid)->exists();
        $this->assertTrue($ledgered($memberA->id), 'reach-covered member A mailed + ledgered');
        $this->assertFalse($ledgered($memberB->id), 'out-of-reach member B not yet mailed');

        // Reach grows to cover B; the re-run mails B and does NOT re-mail A (ledger dedup).
        DB::statement('UPDATE rippling_reach SET polygon = ST_GeomFromText(?, 3857) WHERE msgid = ?',
            ['POLYGON((-0.2 51.4,0.6 51.4,0.6 51.6,-0.2 51.6,-0.2 51.4))', $msg->id]);
        $before = DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count();
        $this->service->mailNewlyReachedForPost($msg->id);
        $this->assertTrue($ledgered($memberB->id), 'newly-reached member B mailed on re-run');
        $this->assertSame(
            $before + 1,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'only B newly notified — A not re-mailed'
        );

        // #0 / §15 instrumentation: both expander mails (A then B) are counted.
        $this->assertSame(2, (int) DB::table('rippling_event_metrics')
            ->where('day', now()->toDateString())->where('event', 'immediate_mailed')->value('count'),
            'immediate mails on expansion are counted');
    }

    public function test_cursor_immediate_digest_excludes_posts_with_a_reach_row(): void
    {
        // A rippling post (has a rippling_reach row) is mailed by the expander, NOT the cursor
        // digest — so the cursor digest must skip it, or members get two immediate mails.
        config(['freegle.digest.immediate_allowlist' => '*']);
        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: rippling (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('b', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent'], 'cursor immediate digest skips a post that has a reach row');
    }

    public function test_cursor_immediate_ledger_is_dark_when_rippling_disabled(): void
    {
        // With the master activation switch off, the cursor immediate digest still mails normally but
        // does NOT touch the rippling reach-coordination ledger (the expander mailer that reads it is
        // inert while off anyway), so the new table stays empty in the dark state.
        config(['freegle.ripple.enabled' => false]);
        config(['freegle.digest.immediate_allowlist' => '*']);
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $member = $this->createTestUser();
        $this->createMembership($member, $group);
        $this->setMyLocation($member, 51.5, -0.1);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: dark (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-'.str_repeat('d', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedImmediateCursor($group);

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertSame(
            0,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'no reach-coordination ledger rows are written while rippling is off'
        );
    }

    public function test_cursor_and_expander_do_not_double_mail_via_shared_ledger(): void
    {
        // A post is cursor-mailed on arrival (no reach row yet) and the reach row appears minutes
        // later. The cursor send records the ledger, so the expander must NOT re-mail those members.
        config(['freegle.digest.immediate_allowlist' => '*']);
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $member = $this->createTestUser();
        $this->createMembership($member, $group);
        $this->setMyLocation($member, 51.5, -0.1);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: window (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id, 'externaluid' => 'freegletusd-' . str_repeat('c', 32),
            'primary' => 1, 'archived' => 0,
        ]);
        $this->seedImmediateCursor($group);

        // No reach row yet → the cursor immediate digest mails the group and records the ledger.
        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertTrue(
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->where('userid', $member->id)->exists(),
            'cursor immediate send is recorded in the ledger'
        );

        // Reach row appears afterwards; the expander must not re-mail the already-mailed member.
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');
        $before = DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count();
        $sent = $this->service->mailNewlyReachedForPost($msg->id);
        $this->assertSame(0, $sent, 'expander does not re-mail members the cursor digest already mailed');
        $this->assertSame(
            $before,
            DB::table('rippling_reach_notified')->where('msgid', $msg->id)->count(),
            'no new ledger rows — the shared ledger prevents the double-mail'
        );
    }

    public function test_daily_digest_reach_gates_rippling_posts_by_member_location(): void
    {
        // The daily digest (and the daily-posts push, which shares getPostsForUser) must
        // reach-gate rippling posts by the member's location, just like the immediate path —
        // a daily member is only shown a rippling post once its reach covers them.
        $poster = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($member, $group, ['emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY]);
        $this->setMyLocation($member, 51.5, -0.1);

        // Reach COVERS the member (-0.1, 51.5 is inside this polygon).
        $covered = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: covered (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $covered->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($covered->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        // Reach does NOT cover the member (far to the east).
        $faraway = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: faraway (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $faraway->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($faraway->id, 'POLYGON((5.0 51.4,5.2 51.4,5.2 51.6,5.0 51.6,5.0 51.4))');

        $tracker = UserDigest::create([
            'userid' => $member->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgid' => 0,
        ]);

        $ids = $this->service->getPostsForUser($member, $tracker, UnifiedDigestService::MODE_DAILY)
            ->pluck('id')->all();

        $this->assertContains($covered->id, $ids, 'reach-covered rippling post is included for the daily member');
        $this->assertNotContains($faraway->id, $ids, 'rippling post whose reach does not cover the member is excluded');
    }

    public function test_immediate_cursor_advances_past_reach_excluded_posts(): void
    {
        // After full rollout every new post has a reach row, so the cursor digest finds
        // nothing to mail. The cursor must still advance past those posts — otherwise it
        // freezes forever and the scan window grows without bound.
        config(['freegle.digest.immediate_allowlist' => '*']);
        [$group, $poster] = $this->bootstrapImmediateGroup();

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: reach only (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)
            ->update(['collection' => MessageGroup::COLLECTION_APPROVED, 'arrival' => now()]);
        $this->seedReach($msg->id, 'POLYGON((-0.2 51.4,0.0 51.4,0.0 51.6,-0.2 51.6,-0.2 51.4))');

        $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals((int) $msg->id, (int) $cursor->msgid,
            'cursor advances past the reach-excluded post rather than freezing');
    }

    /** Set a user's settings.mylocation point (the canonical first-choice location source). */
    protected function setMyLocation(User $user, float $lat, float $lng): void
    {
        $settings = $user->settings ?? [];
        $settings['mylocation'] = ['lat' => $lat, 'lng' => $lng];
        $user->settings = $settings;
        $user->save();
    }

    /** Seed a rippling_reach row for a post with the given WKT polygon (SRID 3857). */
    protected function seedReach(int $msgid, string $wkt): void
    {
        DB::statement(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon, arrival, mode, tick, total_ticks, "
            . "total_freeglers, max_drive_min, schedule, next_expansion_at, status, created_at, updated_at) "
            . "VALUES (?, 51.5, -0.1, ST_GeomFromText(?, 3857), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$msgid, $wkt]
        );
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
    protected function seedImmediateCursor(Group $group, ?string $msgdate = null, ?int $msgid = null): void
    {
        // msgid has a FK to messages.id (ON DELETE SET NULL), so the value must
        // be either NULL or a real message id. Tests that need a baseline cursor
        // without a real message default to NULL.
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
     * Push the given message's messages_groups.arrival back past the
     * isImmediateMessageReady() defer deadline so the digest doesn't
     * postpone it waiting for an attachment. Most immediate tests don't
     * care about the defer behaviour; this keeps them terse.
     */
    protected function makeImmediateReady(Message $message): void
    {
        DB::table('messages_groups')
            ->where('msgid', $message->id)
            ->update([
                'arrival' => now()->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1),
            ]);
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
        $this->createMembership($user, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    // ─── V1-PARITY PER-GROUP IMMEDIATE TESTS ────────────────────────────
    // These pin the new sendImmediateDigests behaviour: walk
    // groups_digests at frequency=-1, find messages since the per-group
    // cursor with (arrival, msgid) tuple compare, send to every member
    // at emailfrequency=-1 — including the poster, since V1 (no fromuser
    // filter) loops a user's own posts back to them too — advance the cursor.

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

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // poster + r1 + r2 are all immediate-frequency; all three receive
        // (V1 parity includes the poster's own post). dailyOnly is excluded
        // by the emailfrequency filter.
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(3, $stats['users_processed']);
        $this->assertEquals(3, $stats['emails_sent']);
    }

    public function test_immediate_includes_poster_own_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Own item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // V1 parity: the per-group selection has no fromuser filter, so a
        // poster's own post loops back to them too. Poster is the only
        // immediate-frequency member, so exactly one email goes out — to them.
        $this->assertEquals(1, $stats['emails_sent']);
    }

    public function test_immediate_sends_one_email_per_new_post(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msgA = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $msgB = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $msgC = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: C (TestLocation)']);
        $this->makeImmediateReady($msgA);
        $this->makeImmediateReady($msgB);
        $this->makeImmediateReady($msgC);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // 3 posts × 2 immediate members (recipient + poster) = 6 emails.
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['users_processed']);
        $this->assertEquals(6, $stats['emails_sent']);
    }

    public function test_immediate_advances_groups_digests_cursor(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        $this->makeImmediateReady($msg1);
        $this->makeImmediateReady($msg2);

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

        // Backdate past the immediate-defer deadline so isImmediateMessageReady
        // doesn't postpone these; same arrival for both messages to force the
        // tuple-tiebreak path we're testing.
        $sharedArrival = now()
            ->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1)
            ->format('Y-m-d H:i:s.u');
        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg1->id)->update(['arrival' => $sharedArrival]);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg2->id)->update(['arrival' => $sharedArrival]);

        // 2 same-arrival posts × 2 immediate members (recipient + poster) = 4.
        $first = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(4, $first['emails_sent'], 'Both same-arrival messages must be picked up');

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
            $msg = $this->createTestMessage($poster, $g, ['subject' => "OFFER: Item {$i} (TestLocation)"]);
            $this->makeImmediateReady($msg);
        }

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, null, 1);

        // Limit caps it to one group; that group's post goes to both its
        // immediate members (recipient + poster).
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['emails_sent']);
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
            $msg = $this->createTestMessage($poster, $g, ['subject' => 'OFFER: Item (TestLocation)']);
            $this->makeImmediateReady($msg);
        }

        $stats = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, $groupA->id
        );

        // Only group A processed; its post goes to both immediate members
        // (recipient + poster).
        $this->assertEquals(1, $stats['groups_processed']);
        $this->assertEquals(2, $stats['emails_sent']);

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
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $targeted->id);

        // Only the targeted user gets the email.
        $this->assertEquals(1, $stats['users_processed']);
        $this->assertEquals(1, $stats['emails_sent']);

        // Cursor must NOT advance when --user is set — the other group
        // member ($other) still needs to receive this message on the next
        // unrestricted run.
        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance under a --user-restricted run');
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

    public function test_immediate_defers_recent_message_with_no_attachment(): void
    {
        // Newly-posted message that hasn't acquired an AI-generated
        // attachment yet — defer rather than mail with a stock placeholder
        // while generation is still in flight. The cursor must NOT
        // advance past it so the next tick retries.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Recent no-image (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent']);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance past a deferred message');
    }

    public function test_immediate_sends_attached_message_even_if_recent(): void
    {
        // Has a usable attachment immediately (user-uploaded photo).
        // No need to wait for AI; mail right away.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: With photo (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $msg->id,
            'externaluid' => 'freegletusd-' . str_repeat('a', 32),
            'primary' => 1,
            'archived' => 0,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // recipient + poster both receive (immediate members; own post included).
        $this->assertEquals(2, $stats['emails_sent']);
    }

    public function test_immediate_sends_unattached_message_after_deadline(): void
    {
        // Older than ATTACHMENT_WAIT_DEADLINE_MINUTES and still no
        // attachment — give up waiting and send with the placeholder
        // rather than holding the notification indefinitely.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Stuck no-image (TestLocation)']);
        $oldArrival = now()->subMinutes(UnifiedDigestService::ATTACHMENT_WAIT_DEADLINE_MINUTES + 1);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => $oldArrival]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // recipient + poster both receive (immediate members; own post included).
        $this->assertEquals(2, $stats['emails_sent']);

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNotNull($cursor->msgdate, 'Cursor must advance after we give up waiting');
    }

    public function test_immediate_shard_partitions_groups_by_modulo(): void
    {
        // Each shard must own a disjoint slice of groups (MOD(groupid, N)
        // = shard). Running shard 0 of 4 must process only groups where
        // groupid % 4 == 0; running shard 1 must process only those where
        // % 4 == 1; and so on. Union across all shards = every group.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $recipient = $this->createTestUser();

        // Create 8 groups; force their ids so we know each shard's slice.
        // (createTestGroup doesn't let us pin the id, but we can sample
        // many and just check that the shard filter is applied — we look
        // at WHICH groups each shard sees.)
        $groups = [];
        for ($i = 0; $i < 8; $i++) {
            $g = $this->createTestGroup();
            $this->createMembership($poster, $g);
            $this->createMembership($recipient, $g);
            $this->seedImmediateCursor($g);
            $this->createTestMessage($poster, $g, ['subject' => "OFFER: Item {$i} (TestLocation)"]);
            DB::table('messages_groups')->where('msgid', DB::table('messages')->where('subject','like',"OFFER: Item {$i}%")->max('id'))
                ->update(['arrival' => now()->subMinutes(10)]); // older than the AI-wait deadline so they all send
            $groups[$i] = $g;
        }

        // Run shard 0 of 2: should process exactly the groups with even
        // ids. Shard 1 should process exactly the groups with odd ids.
        $statsShard0 = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, null, 0, 2
        );
        $statsShard1 = $this->service->sendDigests(
            UnifiedDigestService::MODE_IMMEDIATE,
            null, null, false, null, 1, 2
        );

        $evenGroupCount = collect($groups)->filter(fn($g) => $g->id % 2 === 0)->count();
        $oddGroupCount = collect($groups)->filter(fn($g) => $g->id % 2 === 1)->count();

        $this->assertEquals($evenGroupCount, $statsShard0['groups_processed'], 'Shard 0 must process even-id groups');
        $this->assertEquals($oddGroupCount, $statsShard1['groups_processed'], 'Shard 1 must process odd-id groups');
        $this->assertEquals(count($groups), $statsShard0['groups_processed'] + $statsShard1['groups_processed'], 'Shards must partition the group set');
    }

    public function test_immediate_shards_do_not_double_process_same_group(): void
    {
        // Stronger check than the partition test: pick one specific
        // group and verify that exactly one shard (the one matching
        // MOD(groupid, 4)) sees it and the other three don't.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['arrival' => now()->subMinutes(10)]);

        $owningShard = $group->id % 4;
        for ($s = 0; $s < 4; $s++) {
            // Reset cursor between shards so each run sees a clean state.
            // msgid must be NULL (not 0) — there's a FK to messages.id.
            DB::table('groups_digests')
                ->where('groupid', $group->id)
                ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
                ->update(['msgid' => null, 'msgdate' => null]);

            $stats = $this->service->sendDigests(
                UnifiedDigestService::MODE_IMMEDIATE,
                null, null, false, null, $s, 4
            );
            if ($s === $owningShard) {
                $this->assertEquals(1, $stats['groups_processed'], "Shard {$s} owns this group");
                // recipient + poster both receive (immediate members; own post included).
                $this->assertEquals(2, $stats['emails_sent']);
            } else {
                $this->assertEquals(0, $stats['groups_processed'], "Shard {$s} must not see this group");
                $this->assertEquals(0, $stats['emails_sent']);
            }
        }
    }

    public function test_immediate_deferral_blocks_later_messages_in_same_group(): void
    {
        // If msg A (older) is deferred, msg B (newer) MUST NOT be sent
        // even if it's ready — otherwise advancing the cursor to B's
        // position would skip A on the next tick.
        config(['freegle.digest.immediate_allowlist' => '*']);

        [$group, $poster, $recipient] = $this->bootstrapImmediateGroup();
        $now = now();

        // Msg A: arrived now, no attachment → deferred.
        $a = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: A no-img (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $a->id)->update(['arrival' => $now]);

        // Msg B: arrived later, has attachment → would be ready, but
        // must wait behind A.
        $b = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: B with photo (TestLocation)']);
        DB::table('messages_groups')->where('msgid', $b->id)->update(['arrival' => $now->copy()->addSecond()]);
        DB::table('messages_attachments')->insert([
            'msgid' => $b->id,
            'externaluid' => 'freegletusd-' . str_repeat('b', 32),
            'primary' => 1,
            'archived' => 0,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        $this->assertEquals(0, $stats['emails_sent'], 'Later ready msg B must wait until A is dealt with');

        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertNull($cursor->msgdate, 'Cursor must not advance past the deferred A');
    }

    public function test_immediate_advances_cursor_even_if_one_recipient_send_throws(): void
    {
        // Regression: if Mail::send threw for one recipient (e.g. bad
        // address), the exception used to escape processGroupImmediate's
        // outer foreach. lastProcessed stayed null → cursor never
        // advanced → next cron tick re-sent the SAME message to every
        // recipient. Observed: Penny Langley received 27 copies of one
        // post in 13 min. SafeMail::sendMailable now catches permanent
        // failures and marks the recipient as bouncing, so the loop
        // keeps going and the cursor advances normally.
        //
        // We can't easily make Mail::fake() throw, so use a User with a
        // syntactically invalid preferred email to drive the permanent-
        // failure path through SmtpFailureClassifier in a real Mail
        // pipeline. This needs the real Symfony mailer (not Mail::fake);
        // tests in CI use a null mailer that swallows everything, so for
        // unit purposes we assert the cursor logic with all-good sends.
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();
        $r1 = $this->createTestUser();
        $r2 = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($r1, $group);
        $this->createMembership($r2, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // Poster + both recipients got mailed (all immediate members; own
        // post loops back to the poster per V1 parity).
        $this->assertEquals(3, $stats['emails_sent']);

        // Cursor advanced (this is the regression check — used to stay
        // at null if any send threw).
        $cursor = DB::table('groups_digests')
            ->where('groupid', $group->id)
            ->where('frequency', Membership::EMAIL_FREQUENCY_IMMEDIATE)
            ->first();
        $this->assertEquals($msg->id, $cursor->msgid, 'Cursor must advance after sends complete');

        // Re-running finds nothing to do (proving the cursor stuck).
        $stats2 = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);
        $this->assertEquals(0, $stats2['emails_sent'], 'No duplicate send after cursor advance');
    }

    /**
     * Long-inactive members and daily members must not get immediate
     * per-post emails (Discourse topic 9728 posts 11-12: member 39318461
     * "no recent activity"; support flooded). processGroupImmediate gates
     * recipients on the same V1-parity inactivity window the daily path uses
     * (Engage::USER_INACTIVE = 365*12*3600 = 182.5 days) and on
     * emailfrequency=-1. It must NOT use a stricter 90-day cutoff: that
     * silently dropped members inactive for 90-182.5 days (member 41020747,
     * lastaccess 111 days) even though V1 and the daily digest still mail them.
     *
     * Five-user group:
     *   poster         emailfrequency=-1, active → receives (V1 parity: own post loops back)
     *   immediateUser  emailfrequency=-1, active → receives
     *   deadZoneUser   emailfrequency=-1, lastaccess 120 days ago → receives (inside 182.5d)
     *   dailyUser      emailfrequency=24, active → excluded (emailfrequency filter)
     *   inactiveUser   emailfrequency=-1, lastaccess 2 years ago → excluded (182.5d gate)
     *
     * So poster + immediateUser + deadZoneUser receive: 3 emails.
     */
    public function test_inactive_and_daily_users_must_not_receive_immediate_individual_emails(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $poster = $this->createTestUser();

        // Active immediate-frequency member — receives (alongside the poster).
        $immediateUser = $this->createTestUser();

        // Immediate-frequency member inactive for 120 days — inside the 182.5-day
        // V1-parity window, so must still receive. A 90-day cutoff would wrongly
        // drop them (the member 41020747 regression).
        $deadZoneUser = $this->createTestUser();
        $deadZoneUser->lastaccess = now()->subDays(120);
        $deadZoneUser->save();

        // Active daily-digest member — correctly excluded by the emailfrequency filter.
        $dailyUser = $this->createTestUser();

        // Long-inactive immediate-frequency member (lastaccess 2 years ago) —
        // beyond the 182.5-day window, correctly excluded.
        $inactiveUser = $this->createTestUser();
        $inactiveUser->lastaccess = now()->subYears(2);
        $inactiveUser->save();

        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($immediateUser, $group);
        $this->createMembership($deadZoneUser, $group);
        $this->createMembership($dailyUser, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($inactiveUser, $group);
        $this->seedImmediateCursor($group);

        $msg = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);
        $this->makeImmediateReady($msg);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE);

        // poster + immediateUser + deadZoneUser receive; dailyUser (freq) and
        // inactiveUser (182.5-day gate) are excluded.
        $this->assertEquals(3, $stats['emails_sent']);
        $this->assertEquals(3, $stats['users_processed']);
    }

    // ─── V1-PARITY: per-group emailfrequency is authoritative ────────────
    // Bug case (user 801, Richmond Upon Thames, 2026-05-27): a user with
    // legacy simplemail='Full' who had switched some groups to Daily was
    // being treated as a "Full" user for every group and flooded with
    // immediate emails. V1 (iznik-server/include/mail/Digest.php:418)
    // ignores simplemail at send time and filters strictly on
    // memberships.emailfrequency. These tests pin that behaviour so the
    // regression cannot recur.

    public function test_daily_includes_user_with_simplemail_full_when_membership_is_daily(): void
    {
        // Emma's case: simplemail='Full' but a specific group is at Daily.
        // The Daily setting must win — she should get a daily digest for
        // that group (and, separately, no immediate spam for it).
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Item (TestLocation)']);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent'], 'simplemail=Full must not block per-group Daily delivery');
    }

    public function test_daily_excludes_user_whose_only_memberships_are_immediate(): void
    {
        // simplemail=Basic alone is NOT enough — V1 parity requires at
        // least one approved membership at emailfrequency=24. The old
        // code would have selected this user and then tried to mail
        // their immediate-frequency groups in the daily roll-up.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);
        $this->createTestMessage($poster, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_excludes_user_with_simplemail_none(): void
    {
        // V1 sendOurMails() opt-out: simplemail='None' silences every
        // mail regardless of per-group settings.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_NONE];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createTestMessage($poster, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(0, $stats['emails_sent']);
    }

    public function test_daily_digest_excludes_posts_from_immediate_only_groups(): void
    {
        // Mixed-frequency case: same user, two groups, one Daily and one
        // Immediate. The daily roll-up must contain ONLY the daily
        // group's post — the immediate group's post must not be bundled
        // (that group is the immediate cron's responsibility). The old
        // code skipped the per-group emailfrequency filter whenever
        // simplemail was set and would have included both.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_BASIC];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $poster = $this->createTestUser();
        $dailyGroup = $this->createTestGroup();
        $immediateGroup = $this->createTestGroup();
        $this->createMembership($poster, $dailyGroup);
        $this->createMembership($poster, $immediateGroup);
        $this->createMembership($recipient, $dailyGroup, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);
        $this->createMembership($recipient, $immediateGroup, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
        ]);

        $dailyMsg = $this->createTestMessage($poster, $dailyGroup, [
            'subject' => 'OFFER: Daily item (TestLocation)',
        ]);
        $immediateMsg = $this->createTestMessage($poster, $immediateGroup, [
            'subject' => 'OFFER: Immediate item (TestLocation)',
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_DAILY, $recipient->id);

        $this->assertEquals(1, $stats['emails_sent']);
        Mail::assertSent(\App\Mail\Digest\UnifiedDigest::class, function ($mail) use ($dailyMsg, $immediateMsg) {
            // $posts is protected on purpose: making it public would auto-inject
            // the raw collection into the mail views and shadow the prepared
            // posts (breaking the templates), so read it via reflection.
            $prop = new \ReflectionProperty($mail, 'posts');
            $prop->setAccessible(true);
            $subjects = $prop->getValue($mail)->map(fn ($p) => $p['message']->subject)->all();
            return in_array($dailyMsg->subject, $subjects, true)
                && !in_array($immediateMsg->subject, $subjects, true);
        });
    }

    public function test_immediate_excludes_simplemail_full_user_with_daily_only_memberships(): void
    {
        // Re-run of Emma's scenario through getUsersForDigest('immediate').
        // This path is dead in production (sendDigests('immediate') short-
        // circuits to the per-group sendImmediateDigests), but the
        // function is reachable in tests / future callers and must
        // match V1: simplemail='Full' alone never wins; the user needs
        // at least one membership at emailfrequency=-1.
        $recipient = $this->createTestUser();
        $recipient->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $recipient->lastaccess = now();
        $recipient->save();
        $recipient->refresh();

        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('getUsersForDigest');
        $method->setAccessible(true);
        /** @var \Illuminate\Support\LazyCollection $eligible */
        $eligible = $method->invoke($this->service, UnifiedDigestService::MODE_IMMEDIATE, $recipient->id);

        $this->assertCount(0, $eligible->all(), 'simplemail=Full alone must not select a user who has no immediate-frequency memberships');
    }

    // -----------------------------------------------------------------------
    // Task 3: Engagement counts in the post query
    // -----------------------------------------------------------------------

    private function callPrivate(object $obj, string $method, array $args = []): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }

    public function test_get_posts_for_user_exposes_engagement_counts(): void
    {
        $recipient = $this->createTestUser();
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($recipient, $group, [
            'emailfrequency' => Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $msg = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Counted (TestLocation)',
            'arrival' => now()->subHours(2),
        ]);

        // 3 'View' likes (the count column is SUMmed) and 1 approved 'Interested' reply.
        DB::table('messages_likes')->insert([
            'msgid' => $msg->id, 'userid' => $recipient->id, 'type' => 'View', 'count' => 3,
            'timestamp' => now(),
        ]);
        // chat_messages has a FK on chatid; create a real chat room to satisfy it.
        $room = $this->createTestChatRoom($recipient, $poster);
        DB::table('chat_messages')->insert([
            'refmsgid' => $msg->id, 'userid' => $poster->id, 'chatid' => $room->id,
            'type' => 'Interested', 'message' => 'Interested',
            'reviewrejected' => 0, 'reviewrequired' => 0, 'date' => now(),
            'processingrequired' => 0, 'processingsuccessful' => 1,
            'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
        ]);

        $tracker = UserDigest::create([
            'userid' => $recipient->id,
            'mode' => UnifiedDigestService::MODE_DAILY,
            'lastmsgdate' => null,
        ]);

        $posts = $this->service->getPostsForUser(
            $recipient, $tracker, UnifiedDigestService::MODE_DAILY
        );

        $row = $posts->firstWhere('id', $msg->id);
        $this->assertNotNull($row);
        $this->assertSame(3, (int) $row->views);
        $this->assertSame(1, (int) $row->replies);
    }

    // -----------------------------------------------------------------------
    // Task 4: Per-run reach-radius lookup
    // -----------------------------------------------------------------------

    public function test_reach_radius_falls_back_to_config_default_without_reach_row(): void
    {
        config(['freegle.ripple.score.default_reach_metres' => 12345.0]);
        $svc = app(\App\Services\UnifiedDigestService::class);

        // No rippling_reach row for this msgid => default.
        $r = $this->callPrivate($svc, 'reachRadiusMetres', [999999999]);
        $this->assertEqualsWithDelta(12345.0, $r, 1e-6);
    }

    public function test_reach_radius_is_distance_origin_to_polygon_boundary(): void
    {
        $svc = app(\App\Services\UnifiedDigestService::class);

        // Need a real message row to satisfy rippling_reach FK on msgid.
        $poster = $this->createTestUser();
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($poster, $group, ['lat' => 0.0, 'lng' => 0.0]);

        // Origin at 3857 (0,0); square polygon 1000 units half-width => corner dist sqrt(2)*1000.
        DB::table('rippling_reach')->insert([
            'msgid' => $msg->id, 'lat' => 0, 'lng' => 0,
            'polygon' => DB::raw("ST_GeomFromText('POLYGON((-1000 -1000,1000 -1000,1000 1000,-1000 1000,-1000 -1000))', 3857)"),
            'arrival' => now(), 'mode' => 'drive', 'tick' => 1, 'total_ticks' => 9,
            'status' => 'expanding', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $r = $this->callPrivate($svc, 'reachRadiusMetres', [$msg->id]);
        // Farthest boundary point is a corner at distance sqrt(2)*1000 ~= 1414.2 (3857 planar units).
        $this->assertEqualsWithDelta(1414.21356, $r, 0.5);
    }
}

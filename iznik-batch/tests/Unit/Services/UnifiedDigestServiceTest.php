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
        $recipient->update([
            'settings' => json_encode(['simplemail' => User::SIMPLE_MAIL_BASIC]),
            'lastaccess' => now(),
        ]);

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
        $recipient->update([
            'settings' => json_encode(['simplemail' => User::SIMPLE_MAIL_BASIC]),
            'lastaccess' => now(),
        ]);

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

    public function test_immediate_mode_requires_full_setting(): void
    {
        // Open the allowlist so this test only proves the simplemail filter,
        // not the allowlist gate (which has its own tests below).
        config(['freegle.digest.immediate_allowlist' => '*']);

        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // User has Basic setting (daily only). Basic isn't NULL so the
        // per-group fallback below cannot rescue them either.
        $user->update([
            'settings' => json_encode(['simplemail' => User::SIMPLE_MAIL_BASIC]),
            'lastaccess' => now(),
        ]);

        $this->createMembership($user, $group);

        // Run immediate mode - should not include this user.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $user->id);

        // User should not be processed for immediate mode.
        $this->assertEquals(0, $stats['users_processed']);
    }

    /**
     * V1 parity: a user with no global simplemail setting but at least one
     * approved membership configured for per-group immediate frequency
     * (emailfrequency=-1) must be eligible for immediate digests. This mirrors
     * the existing daily/Basic fallback that catches users with per-group
     * daily (emailfrequency=24) but no global simplemail.
     */
    public function test_immediate_mode_includes_users_with_per_group_immediate_membership(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $user = $this->createTestUser();
        // No simplemail key at all in settings — purely per-group control.
        $user->settings = ['notificationmails' => true];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();

        $group = $this->createTestGroup();
        // createMembership() defaults to EMAIL_FREQUENCY_IMMEDIATE, which is
        // exactly the per-group setting this path looks for.
        $this->createMembership($user, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    /**
     * Counter-test for the per-group immediate path: a user with no simplemail
     * AND only non-immediate per-group frequencies must NOT be picked up.
     * Otherwise the fallback would over-match.
     */
    public function test_immediate_mode_skips_users_whose_only_membership_is_daily(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $user = $this->createTestUser();
        $user->settings = ['notificationmails' => true];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();

        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'emailfrequency' => \App\Models\Membership::EMAIL_FREQUENCY_DAILY,
        ]);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $user->id);

        $this->assertEquals(0, $stats['users_processed']);
    }

    /**
     * Empty allowlist means "no restriction" — every simplemail=Full user is
     * eligible. This is the "fully on" state once we're done piloting.
     */
    public function test_immediate_mode_allowlist_empty_allows_everyone(): void
    {
        config(['freegle.digest.immediate_allowlist' => '']);

        $user = $this->createTestUser();
        $user->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    /**
     * Explicit '*' is equivalent to empty — no restriction.
     */
    public function test_immediate_mode_allowlist_wildcard_allows_everyone(): void
    {
        config(['freegle.digest.immediate_allowlist' => '*']);

        $user = $this->createTestUser();
        $user->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $user->lastaccess = now();
        $user->save();
        $user->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $user->id);

        $this->assertEquals(1, $stats['users_processed']);
    }

    /**
     * Specific allowlist entries let only matching addresses through. This is
     * the pilot mode — one or two test recipients while we validate end-to-end
     * before clearing the env var to flip immediate emails on for everyone.
     */
    public function test_immediate_mode_allowlist_filters_to_specified_addresses(): void
    {
        $allowed = $this->createTestUser();
        $allowed->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $allowed->lastaccess = now();
        $allowed->save();
        $allowed->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($allowed, $group);

        $blocked = $this->createTestUser();
        $blocked->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $blocked->lastaccess = now();
        $blocked->save();
        $blocked->refresh();
        $this->createMembership($blocked, $group);

        // Pick the allowed user's preferred email and use it as the allowlist.
        $allowedEmail = $allowed->emails()->first()->email;
        config(['freegle.digest.immediate_allowlist' => $allowedEmail]);

        // Allowed user is processed.
        $statsAllowed = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $allowed->id);
        $this->assertEquals(1, $statsAllowed['users_processed']);

        // Other user with same Full setting is filtered out.
        $statsBlocked = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $blocked->id);
        $this->assertEquals(0, $statsBlocked['users_processed']);
    }

    /**
     * The default value checked into config (a single pilot email) must
     * restrict immediate emails to that address — otherwise deploying the
     * cron in prod without a custom env var would email everyone.
     */
    public function test_immediate_mode_uses_pinned_pilot_default(): void
    {
        // Don't override — use whatever's baked into config/freegle.php.
        $pilot = config('freegle.digest.immediate_allowlist');
        $this->assertNotEquals('', $pilot, 'Pinned default must not be empty');
        $this->assertNotEquals('*', $pilot, 'Pinned default must not be wildcard');

        $blocked = $this->createTestUser();
        $blocked->settings = ['simplemail' => User::SIMPLE_MAIL_FULL];
        $blocked->lastaccess = now();
        $blocked->save();
        $blocked->refresh();
        $group = $this->createTestGroup();
        $this->createMembership($blocked, $group);

        // User's email is not the pilot address → must be skipped.
        $stats = $this->service->sendDigests(UnifiedDigestService::MODE_IMMEDIATE, $blocked->id);
        $this->assertEquals(0, $stats['users_processed']);
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
}

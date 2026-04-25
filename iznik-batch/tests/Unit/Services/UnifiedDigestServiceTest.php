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

    public function test_deduplication_with_same_message_on_multiple_groups(): void
    {
        // Multi-group model: same messages.id has two messages_groups rows.
        // The digest query joins messages with messages_groups, so the same message
        // appears twice with different groupids. deduplicatePosts should merge them.
        $user = $this->createTestUser();
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $message = $this->createTestMessage($user, $group1, [
            'subject' => 'OFFER: Multi-Group Item (TestTown)',
        ]);

        // Add the same message to a second group (simulating the multi-group model).
        DB::table('messages_groups')->insert([
            'msgid' => $message->id,
            'groupid' => $group2->id,
            'collection' => 'Approved',
            'arrival' => now(),
        ]);

        // Simulate what getPostsForUser returns: two rows for the same message
        // with different groupid attributes (from the join).
        $row1 = clone $message;
        $row1->groupid = $group1->id;
        $row2 = clone $message;
        $row2->groupid = $group2->id;

        $posts = collect([$row1, $row2]);
        $deduplicated = $this->service->deduplicatePosts($posts);

        $this->assertCount(1, $deduplicated, 'Same message on two groups should deduplicate to one');
        $this->assertCount(2, $deduplicated->first()['postedToGroups'], 'Both groups should be in postedToGroups');
        $this->assertContains($group1->id, $deduplicated->first()['postedToGroups']);
        $this->assertContains($group2->id, $deduplicated->first()['postedToGroups']);
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


    public function test_immediate_mode_requires_full_setting(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        // User has Basic setting (daily only).
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
}

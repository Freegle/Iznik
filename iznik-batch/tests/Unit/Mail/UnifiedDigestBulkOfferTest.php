<?php

namespace Tests\Unit\Mail;

use App\Mail\Digest\UnifiedDigest;
use App\Services\UnifiedDigestService;
use Illuminate\Support\Facades\DB;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * The digest must render the structured catalogue of a bulk offer ("clearance")
 * in the HTML and text parts, so recipients see the items even before opening
 * the post.
 */
class UnifiedDigestBulkOfferTest extends TestCase
{
    use IsolatedSpoolDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpIsolatedSpoolDirectory();
    }

    protected function tearDown(): void
    {
        $this->tearDownIsolatedSpoolDirectory();
        parent::tearDown();
    }

    private function spoolAndLoad(UnifiedDigest $mail, string $recipient): array
    {
        $id = $this->spooler->spool($mail, $recipient);
        return json_decode(file_get_contents($this->testSpoolDir . '/pending/' . $id . '.json'), true);
    }

    public function test_bulk_offer_catalogue_rendered_in_html_and_text(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Office Clearance (Brighton)',
            'textbody' => 'Charity clearance — collection from Brighton.',
        ]);

        DB::table('messages_bulk_items')->insert([
            ['msgid' => $message->id, 'position' => 0, 'name' => 'Office desk', 'quantity' => 4, 'condition' => 'Good'],
            ['msgid' => $message->id, 'position' => 1, 'name' => 'Swivel chair', 'quantity' => 14, 'condition' => 'Used'],
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');

        $html = $spooled['html'] ?? '';
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('items in this offer', $html);
        $this->assertStringContainsString('Office desk', $html);
        $this->assertStringContainsString('Swivel chair', $html);
        // Condition label and quantity surfaced.
        $this->assertStringContainsString('Good', $html);

        $text = $spooled['text'] ?? '';
        $this->assertStringContainsString('Office desk', $text);
        $this->assertStringContainsString('items in this offer', $text);
        $this->assertStringContainsString('Swivel chair', $text);
        // Reference numbers disambiguate similar items (same name, different
        // condition) for free-text / Trash Nothing repliers and the AI Helper.
        $this->assertStringContainsString('1)', $text);
        $this->assertStringContainsString('2)', $text);
    }

    public function test_bulk_offer_amp_forces_website_reply(): void
    {
        // AMP in-email forms can't capture a per-item, per-quantity selection, so
        // a bulk offer must send the replier to the website instead.
        config(['freegle.amp.enabled' => true]);
        config(['freegle.amp.secret' => 'test-secret-key']);

        // Recipient must be on an AMP-supporting provider (Gmail) — the AMP part is
        // only rendered for those recipients now (see UnifiedDigest::ampForRecipient).
        $user = $this->createTestUser(['email_preferred' => 'bulkoffer@gmail.com']);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Office Clearance (Brighton)',
            'textbody' => 'Charity clearance — collection from Brighton.',
        ]);

        DB::table('messages_bulk_items')->insert([
            ['msgid' => $message->id, 'position' => 0, 'name' => 'Office desk', 'quantity' => 4, 'condition' => 'Good'],
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_DAILY);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');

        $amp = $spooled['amp_html'] ?? '';
        $this->assertNotEmpty($amp, 'AMP part should be rendered when AMP is enabled');
        // Bulk offers get a "go to the website" link, not the quick AMP reply form.
        $this->assertStringContainsString('Choose the items', $amp);
    }

    public function test_bulk_offer_photourl_used_as_thumbnail_fallback(): void
    {
        // A spreadsheet-supplied photo link (photourl) is shown when the item has
        // no uploaded attachment yet, so the digest is never imageless.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Office Clearance (Brighton)',
        ]);

        DB::table('messages_bulk_items')->insert([
            [
                'msgid' => $message->id,
                'position' => 0,
                'name' => 'Office desk',
                'quantity' => 4,
                'condition' => 'Good',
                'photourl' => 'https://example.com/special-desk-photo.jpg',
            ],
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');

        $html = $spooled['html'] ?? '';
        // The slug survives both the raw URL and the delivery-proxy (urlencoded) form.
        $this->assertStringContainsString('special-desk-photo', $html);
    }

    public function test_ordinary_post_has_no_catalogue(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, [
            'subject' => 'OFFER: Single sofa (London)',
        ]);

        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        $mail = new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
        $spooled = $this->spoolAndLoad($mail, $user->email_preferred ?? 'recipient@example.com');

        $this->assertStringNotContainsString('items in this offer', $spooled['html'] ?? '');
    }
}

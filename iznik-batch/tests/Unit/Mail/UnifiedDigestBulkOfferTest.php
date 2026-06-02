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

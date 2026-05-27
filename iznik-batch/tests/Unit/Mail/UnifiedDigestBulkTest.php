<?php

namespace Tests\Unit\Mail;

use App\Mail\Concerns\BulkRenderable;
use App\Mail\Digest\UnifiedDigest;
use App\Services\BulkMail\BulkMjmlCompiler;
use App\Services\UnifiedDigestService;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * Verifies the BulkRenderable contract on UnifiedDigest for immediate-mode
 * digests:
 *  - shapeKey() buckets recipients correctly
 *  - bulkData() emits placeholder strings for per-recipient fields
 *  - mergeVars() returns matching real values
 *  - bulk-path HTML body is byte-identical to the non-bulk path
 */
class UnifiedDigestBulkTest extends TestCase
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

    private function spooledHtml(UnifiedDigest $mail, string $recipientEmail): string
    {
        $id = $this->spooler->spool($mail, $recipientEmail);
        $data = json_decode(file_get_contents($this->testSpoolDir.'/pending/'.$id.'.json'), true);
        return $data['html'] ?? '';
    }

    private function makeImmediateDigest(\App\Models\User $user, \App\Models\User $poster, \App\Models\Group $group, $message): UnifiedDigest
    {
        $posts = collect([
            ['message' => $message, 'postedToGroups' => [$group->id]],
        ]);

        return new UnifiedDigest($user, $posts, UnifiedDigestService::MODE_IMMEDIATE);
    }

    public function test_immediate_digest_is_bulk_renderable(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail = $this->makeImmediateDigest($user, $poster, $group, $message);

        $this->assertInstanceOf(BulkRenderable::class, $mail);
    }

    public function test_shape_key_is_stable_for_same_inputs(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail1 = $this->makeImmediateDigest($user, $poster, $group, $message);
        $mail2 = $this->makeImmediateDigest($user, $poster, $group, $message);

        $this->assertSame($mail1->shapeKey(), $mail2->shapeKey());
    }

    public function test_shape_key_changes_when_message_changes(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);

        $msg1 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);
        $msg2 = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Table (London)']);

        $mail1 = $this->makeImmediateDigest($user, $poster, $group, $msg1);
        $mail2 = $this->makeImmediateDigest($user, $poster, $group, $msg2);

        $this->assertNotSame($mail1->shapeKey(), $mail2->shapeKey());
    }

    public function test_bulk_data_carries_placeholders_for_per_recipient_fields(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail = $this->makeImmediateDigest($user, $poster, $group, $message);

        // bulkData() requires a bulk token — set one explicitly for the test.
        // Real sends get the token from BulkMjmlCompiler::htmlFor().
        $token = 'testtoken123';
        $mail->setBulkToken($token);
        $data = $mail->bulkData();

        $this->assertSame('{{'.$token.':browseUrl}}', $data['browseUrl']);
        $this->assertSame('{{'.$token.':donateUrl}}', $data['donateUrl']);
        $this->assertSame('{{'.$token.':jobsUrl}}', $data['jobsUrl']);
        $this->assertSame('{{'.$token.':settingsUrl}}', $data['settingsUrl']);
        $this->assertSame('{{'.$token.':unsubscribeUrl}}', $data['unsubscribeUrl']);
        $this->assertSame('{{'.$token.':userEmail}}', $data['userEmail']);
        $this->assertStringContainsString('{{'.$token.':trackingPixelUrl}}', $data['trackingPixelMjml']);

        // Posts collection: per-post messageUrl is a placeholder string.
        $firstPost = $data['posts']->first();
        $this->assertSame('{{'.$token.':messageUrl}}', $firstPost['messageUrl']);

        // Shared fields are real values, not placeholders.
        $this->assertSame('Sofa', $firstPost['itemName']);
        $this->assertSame('London', $firstPost['locationName']);
    }

    public function test_merge_vars_keys_match_bulk_data_placeholders(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail = $this->makeImmediateDigest($user, $poster, $group, $message);

        $vars = $mail->mergeVars();

        // Every placeholder that bulkData injects MUST have a matching merge var.
        foreach (['browseUrl', 'donateUrl', 'jobsUrl', 'settingsUrl', 'unsubscribeUrl', 'userEmail', 'messageUrl', 'trackingPixelUrl'] as $key) {
            $this->assertArrayHasKey($key, $vars, "mergeVars missing key '{$key}'");
        }
    }

    public function test_merge_vars_url_values_match_trackedUrl_format(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        $mail = $this->makeImmediateDigest($user, $poster, $group, $message);

        $vars = $mail->mergeVars();

        // Tracked URL format: <api_base>/e/d/r/<tracking_id>?url=<base64>...
        $this->assertStringContainsString('/e/d/r/', $vars['browseUrl']);
        $this->assertStringContainsString('/e/d/r/', $vars['donateUrl']);
        $this->assertStringContainsString('/e/d/r/', $vars['messageUrl']);
    }

    public function test_bulk_path_renders_byte_identical_html_to_non_bulk(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $poster = $this->createTestUser();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group, ['subject' => 'OFFER: Sofa (London)']);

        // PATH A: non-bulk — build() runs Blade + MJML with real per-user values.
        $nonBulkMail = $this->makeImmediateDigest($user, $poster, $group, $message);
        $nonBulkHtml = $this->spooledHtml($nonBulkMail, $user->email_preferred);

        // PATH B: bulk — compile once with placeholders, substitute per recipient.
        $bulkMail = $this->makeImmediateDigest($user, $poster, $group, $message);
        $compiler = app(BulkMjmlCompiler::class);
        $bulkHtml = $compiler->htmlFor($bulkMail);
        $bulkMail->setPrerenderedHtml($bulkHtml);
        $bulkRenderedHtml = $this->spooledHtml($bulkMail, $user->email_preferred);

        // Per-user tracking IDs differ between mailable instances (each
        // initTracking() creates a fresh record). For a byte-comparable test
        // we'd need to fix the tracking ID — skip that here and instead diff
        // ignoring the tracking-id segment. Tracking IDs appear in:
        //   /e/d/r/<tracking_id>?url=...   (link tracking)
        //   /e/d/p/<tracking_id>.png       (open pixel)
        // Replace them with a fixed placeholder for comparison.
        $normalize = static fn (string $h) => preg_replace(
            '#/e/d/[a-z]/[A-Za-z0-9\-_]+#',
            '/e/d/X/NORMALISED',
            $h
        );

        $this->assertSame(
            $normalize($nonBulkHtml),
            $normalize($bulkRenderedHtml),
            'Bulk-rendered HTML should byte-match the non-bulk render after normalising tracking IDs. '
            .'Diff suggests a per-recipient field is not covered by mergeVars or bulkData differs structurally.'
        );
    }
}

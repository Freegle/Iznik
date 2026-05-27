<?php

namespace Tests\Unit\Mail;

use App\Mail\Admin\ChaseAdminMail;
use App\Mail\Concerns\BulkRenderable;
use App\Mail\Event\EventsDigestMail;
use App\Mail\Stories\AskMail;
use App\Mail\Stories\StoriesNewsletterMail;
use App\Mail\Volunteering\VolunteeringDigestMail;
use App\Services\BulkMail\BulkMjmlCompiler;
use Tests\Support\IsolatedSpoolDirectory;
use Tests\TestCase;

/**
 * Verifies that the bulk path produces byte-identical HTML to the non-bulk
 * path for each migrated batch-send mailable. Tracking IDs are normalised
 * out of the comparison (they're per-instance random strings — each Mailable
 * instance gets a fresh tracking_id when initTracking() runs).
 */
class BulkRenderableMailablesParityTest extends TestCase
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

    private function spooledHtml(\Illuminate\Mail\Mailable $mail, string $recipient): string
    {
        $id = $this->spooler->spool($mail, $recipient);
        $data = json_decode(file_get_contents($this->testSpoolDir.'/pending/'.$id.'.json'), true);
        return $data['html'] ?? '';
    }

    /**
     * Normalise out tracking IDs (per-instance random) so two Mailable
     * instances producing the SAME body via different paths compare equal.
     */
    private function normalise(string $html): string
    {
        return preg_replace('#/e/d/[a-z]/[A-Za-z0-9\-_]+#', '/e/d/X/NORMALISED', $html);
    }

    public function test_stories_newsletter_bulk_parity(): void
    {
        $stories = [
            ['headline' => 'A great freegle', 'username' => 'Alice', 'groupname' => 'London', 'story' => 'Saved a sofa from landfill.', 'profileurl' => '', 'photo' => null],
            ['headline' => 'Another one', 'username' => 'Bob', 'groupname' => 'Bristol', 'story' => 'Found a free bike.', 'profileurl' => '', 'photo' => null],
        ];
        $args = [
            'userId'         => 42,
            'recipientName'  => 'Test User',
            'recipientEmail' => 'test@example.com',
            'stories'        => $stories,
            'headerImageUrl' => 'https://x/header.png',
            'tellUrl'        => 'https://x/stories?src=test',
            'giveUrl'        => 'https://x/give?src=test',
            'findUrl'        => 'https://x/find?src=test',
            'previewText'    => 'preview',
            'unsubscribeUrl' => 'https://x/unsubscribe',
            'settingsUrl'    => 'https://x/settings',
        ];

        $nonBulk = new StoriesNewsletterMail(...$args);
        $nonBulkHtml = $this->spooledHtml($nonBulk, 'test@example.com');

        $bulk = new StoriesNewsletterMail(...$args);
        $this->assertInstanceOf(BulkRenderable::class, $bulk);

        $compiler = app(BulkMjmlCompiler::class);
        $bulk->setPrerenderedHtml($compiler->htmlFor($bulk));
        $bulkHtml = $this->spooledHtml($bulk, 'test@example.com');

        $this->assertSame(
            $this->normalise($nonBulkHtml),
            $this->normalise($bulkHtml),
            'StoriesNewsletter bulk HTML must byte-match non-bulk HTML'
        );
    }

    public function test_stories_ask_bulk_parity(): void
    {
        $args = [
            'recipientName'  => 'Charlie Freegler',
            'recipientEmail' => 'charlie@example.com',
            'storiesUrl'     => 'https://x/stories',
            'unsubscribeUrl' => 'https://x/unsubscribe',
        ];

        $nonBulk = new AskMail(...$args);
        $nonBulkHtml = $this->spooledHtml($nonBulk, 'charlie@example.com');

        $bulk = new AskMail(...$args);
        $this->assertInstanceOf(BulkRenderable::class, $bulk);

        $compiler = app(BulkMjmlCompiler::class);
        $bulk->setPrerenderedHtml($compiler->htmlFor($bulk));
        $bulkHtml = $this->spooledHtml($bulk, 'charlie@example.com');

        $this->assertSame(
            $this->normalise($nonBulkHtml),
            $this->normalise($bulkHtml),
            'AskMail bulk HTML must byte-match non-bulk HTML'
        );
    }

    public function test_chase_admin_bulk_parity(): void
    {
        $mod = $this->createTestUser(['firstname' => 'Dana']);

        $args = [
            $mod,
            'Pending mod application from someone',
            'Test Group',
            48,
            12345,
        ];

        $nonBulk = new ChaseAdminMail(...$args);
        $nonBulkHtml = $this->spooledHtml($nonBulk, $mod->email_preferred);

        $bulk = new ChaseAdminMail(...$args);
        $this->assertInstanceOf(BulkRenderable::class, $bulk);

        $compiler = app(BulkMjmlCompiler::class);
        $bulk->setPrerenderedHtml($compiler->htmlFor($bulk));
        $bulkHtml = $this->spooledHtml($bulk, $mod->email_preferred);

        $this->assertSame(
            $this->normalise($nonBulkHtml),
            $this->normalise($bulkHtml),
            'ChaseAdminMail bulk HTML must byte-match non-bulk HTML'
        );
    }

    public function test_chase_admin_shape_groups_by_admin_id(): void
    {
        $mod1 = $this->createTestUser(['firstname' => 'Eve']);
        $mod2 = $this->createTestUser(['firstname' => 'Frank']);

        // Same admin, same group, same pending hours → same shape
        $m1 = new ChaseAdminMail($mod1, 'Subject', 'Group A', 48, 100);
        $m2 = new ChaseAdminMail($mod2, 'Subject', 'Group A', 48, 100);
        $this->assertSame($m1->shapeKey(), $m2->shapeKey());

        // Different admin ID → different shape
        $m3 = new ChaseAdminMail($mod1, 'Subject', 'Group A', 48, 999);
        $this->assertNotSame($m1->shapeKey(), $m3->shapeKey());
    }

    public function test_events_digest_bulk_parity(): void
    {
        $events = [
            ['title' => 'Repair Café', 'url' => 'https://x/event/1', 'start' => '2026-06-01 14:00', 'end' => '', 'location' => 'Town Hall', 'description' => 'Bring your broken things.', 'imageUrl' => null],
            ['title' => 'Litter Pick', 'url' => 'https://x/event/2', 'start' => '2026-06-08 10:00', 'end' => '', 'location' => 'High Street', 'description' => 'Help clean up.', 'imageUrl' => null],
        ];

        $args = [
            'recipientEmail' => 'test@example.com',
            'groupName' => 'Test Group',
            'events' => $events,
            'unsubscribeUrl' => 'https://x/unsubscribe?email=test%40example.com',
            'userId' => 42,
        ];

        $nonBulk = new EventsDigestMail(...$args);
        $nonBulkHtml = $this->spooledHtml($nonBulk, 'test@example.com');

        $bulk = new EventsDigestMail(...$args);
        $this->assertInstanceOf(BulkRenderable::class, $bulk);

        $compiler = app(BulkMjmlCompiler::class);
        $bulk->setPrerenderedHtml($compiler->htmlFor($bulk));
        $bulkHtml = $this->spooledHtml($bulk, 'test@example.com');

        $this->assertSame(
            $this->normalise($nonBulkHtml),
            $this->normalise($bulkHtml),
            'EventsDigest bulk HTML must byte-match non-bulk HTML'
        );
    }

    public function test_volunteering_digest_no_jobs_bulk_parity(): void
    {
        $vols = [
            ['title' => 'Garden helper', 'url' => 'https://x/vol/1', 'description' => 'Help out.', 'photo_thumb' => null],
        ];

        $args = [
            'recipientEmail' => 'test@example.com',
            'groupName' => 'Test Group',
            'volunteerings' => $vols,
            'unsubscribeUrl' => 'https://x/unsubscribe?email=test%40example.com',
            'jobAds' => collect(),  // no jobs → shared shape
            'userId' => 42,
        ];

        $nonBulk = new VolunteeringDigestMail(...$args);
        $nonBulkHtml = $this->spooledHtml($nonBulk, 'test@example.com');

        $bulk = new VolunteeringDigestMail(...$args);
        $this->assertInstanceOf(BulkRenderable::class, $bulk);

        $compiler = app(BulkMjmlCompiler::class);
        $bulk->setPrerenderedHtml($compiler->htmlFor($bulk));
        $bulkHtml = $this->spooledHtml($bulk, 'test@example.com');

        $this->assertSame(
            $this->normalise($nonBulkHtml),
            $this->normalise($bulkHtml),
            'VolunteeringDigest (no jobs) bulk HTML must byte-match non-bulk HTML'
        );
    }

    public function test_stories_newsletter_shape_groups_by_content_hash(): void
    {
        $stories1 = [['headline' => 'S1', 'username' => '', 'groupname' => '', 'story' => 's', 'profileurl' => '', 'photo' => null]];
        $stories2 = [['headline' => 'S2', 'username' => '', 'groupname' => '', 'story' => 's', 'profileurl' => '', 'photo' => null]];

        $common = [
            'userId' => 1,
            'recipientName' => 'A',
            'recipientEmail' => 'a@example.com',
            'headerImageUrl' => 'https://x/h.png',
            'tellUrl' => 'https://x/t',
            'giveUrl' => 'https://x/g',
            'findUrl' => 'https://x/f',
            'previewText' => 'p',
            'unsubscribeUrl' => 'https://x/u',
            'settingsUrl' => 'https://x/s',
        ];

        $m1 = new StoriesNewsletterMail(...['stories' => $stories1] + $common);
        $m2 = new StoriesNewsletterMail(...['stories' => $stories1] + ['recipientEmail' => 'b@example.com'] + $common);
        $m3 = new StoriesNewsletterMail(...['stories' => $stories2] + $common);

        $this->assertSame($m1->shapeKey(), $m2->shapeKey(), 'Different recipient, same content → same shape');
        $this->assertNotSame($m1->shapeKey(), $m3->shapeKey(), 'Different stories → different shape');
    }
}

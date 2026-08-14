<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\Mail\Incoming\IncomingArchiveReader;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TrashNothing\Verify\ArchiveInventoryService;
use App\Services\TrashNothing\Verify\TnEmailRoutingGate;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The inventory is the witness the whole coverage check rests on: anything it
 * fails to pick up is a post that silently never gets verified. See
 * plans/tn-api-post-ingestion.md section S.1.
 */
class ArchiveInventoryServiceTest extends TestCase
{
    private string $archiveDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->archiveDir = storage_path('incoming-archive/test-inventory-' . uniqid('', true));
        mkdir($this->archiveDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->archiveDir);
        parent::tearDown();
    }

    private function deleteTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir), ['.', '..']) as $entry) {
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function service(?string $dir = null): ArchiveInventoryService
    {
        return new ArchiveInventoryService(
            new IncomingArchiveReader(app(MailParserService::class), $dir ?? $this->archiveDir),
            new TnEmailRoutingGate(),
        );
    }

    /**
     * Write one archive record in the shape IncomingArchiveService produces.
     */
    private function archive(string $timestamp, string $rawEmail, string $envelopeTo, ?string $outcome = null): string
    {
        $ts      = CarbonImmutable::parse($timestamp, 'UTC');
        $dateDir = $this->archiveDir . '/' . $ts->format('Y-m-d');

        if (! is_dir($dateDir)) {
            mkdir($dateDir, 0755, true);
        }

        $path = $dateDir . '/' . $ts->format('His') . '_' . random_int(100000, 999999) . '.json';

        $data = [
            'version'   => 3,
            'timestamp' => $ts->format('Y-m-d\TH:i:s\Z'),
            'envelope'  => ['from' => 'poster@user.trashnothing.com', 'to' => $envelopeTo],
            'raw_email' => base64_encode($rawEmail),
        ];

        if ($outcome !== null) {
            $data['routing_outcome'] = $outcome;
        }

        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));

        return $path;
    }

    private function tnEmail(string $postId, ?string $coordinates = '51.5074,-0.1278', string $subject = 'OFFER: Bookshelf (Camden)'): string
    {
        $lines = [
            'From: Poster <poster@user.trashnothing.com>',
            'Subject: ' . $subject,
            'Message-ID: <' . $postId . '@tn.trashnothing.com>',
            'Date: Fri, 14 Aug 2026 09:00:00 +0000',
            'X-Trash-Nothing-Post-Id: ' . $postId,
        ];

        if ($coordinates !== null) {
            $lines[] = 'X-Trash-Nothing-Post-Coordinates: ' . $coordinates;
        }

        return implode("\r\n", $lines) . "\r\n\r\nFree to collect.\r\n";
    }

    private function groupAddress(string $localPart = 'camdengroup'): string
    {
        return $localPart . '@' . config('freegle.mail.group_domain');
    }

    public function test_collects_tn_posts_in_the_window_with_coordinates(): void
    {
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('47102958'), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertArrayHasKey('47102958', $result['posts']);

        $entry = $result['posts']['47102958'];
        $this->assertSame('OFFER: Bookshelf (Camden)', $entry['subject']);
        $this->assertEqualsWithDelta(51.5074, $entry['lat'], 0.0001);
        $this->assertEqualsWithDelta(-0.1278, $entry['lng'], 0.0001);
        $this->assertSame(1, $result['stats']['tn_posts']);
    }

    public function test_excludes_records_outside_the_window(): void
    {
        $this->archive('2026-08-14T05:00:00Z', $this->tnEmail('too-early'), $this->groupAddress());
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('in-window'), $this->groupAddress());
        $this->archive('2026-08-14T23:00:00Z', $this->tnEmail('too-late'), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame(['in-window'], array_keys($result['posts']));
    }

    public function test_ignores_non_tn_mail_without_parsing_it_as_a_post(): void
    {
        $plain = "From: someone@example.com\r\nSubject: Hello\r\nDate: Fri, 14 Aug 2026 09:00:00 +0000\r\n\r\nHi.\r\n";
        $this->archive('2026-08-14T09:00:00Z', $plain, $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame([], $result['posts']);
        $this->assertSame(1, $result['stats']['files_scanned']);
    }

    public function test_ignores_a_tn_post_sent_to_a_volunteers_address(): void
    {
        // Same exclusion the routing gate applies, so the inventory contains
        // exactly the mail the cutover stopped routing.
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('vol-post'), $this->groupAddress('camdengroup-volunteers'));

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame([], $result['posts']);
    }

    public function test_keeps_the_earliest_copy_of_a_redelivered_post(): void
    {
        $this->archive('2026-08-14T09:30:00Z', $this->tnEmail('47102958', subject: 'OFFER: Second delivery'), $this->groupAddress());
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('47102958', subject: 'OFFER: First delivery'), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertCount(1, $result['posts']);
        $this->assertSame('OFFER: First delivery', $result['posts']['47102958']['subject']);
        $this->assertSame(1, $result['stats']['duplicates']);
    }

    public function test_crossposts_are_not_collapsed(): void
    {
        // TN gives each per-group copy its own post id. The inventory must keep
        // them all — only the API can say which is the source post, and
        // collapsing them here would hide a real miss.
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('copy-a'), $this->groupAddress('groupa'));
        $this->archive('2026-08-14T09:00:01Z', $this->tnEmail('copy-b'), $this->groupAddress('groupb'));

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertCount(2, $result['posts']);
    }

    public function test_a_post_with_no_coordinates_is_still_collected(): void
    {
        // It must reach the verifier, which is what decides that an unplaceable
        // post is expected-absent. Dropping it here would hide it entirely.
        $this->archive('2026-08-14T09:00:00Z', $this->tnEmail('no-coords', coordinates: null), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertArrayHasKey('no-coords', $result['posts']);
        $this->assertNull($result['posts']['no-coords']['lat']);
    }

    public function test_a_truncated_file_is_counted_not_fatal(): void
    {
        // Files are read while Postfix may still be writing others.
        $dateDir = $this->archiveDir . '/2026-08-14';
        mkdir($dateDir, 0755, true);
        file_put_contents($dateDir . '/090000_111111.json', '{"version":3,"raw_em');

        $this->archive('2026-08-14T09:05:00Z', $this->tnEmail('47102958'), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame(1, $result['stats']['files_unreadable']);
        $this->assertArrayHasKey('47102958', $result['posts']);
    }

    public function test_an_unparseable_filename_is_still_read(): void
    {
        // The filename prefilter is an optimisation over production mail
        // volume, not a correctness boundary: if the naming scheme ever
        // changes, it must cost performance, never silently drop a post from
        // the inventory.
        $dateDir = $this->archiveDir . '/2026-08-14';
        mkdir($dateDir, 0755, true);

        $ts   = CarbonImmutable::parse('2026-08-14T09:00:00Z');
        $path = $dateDir . '/unexpected-name.json';

        file_put_contents($path, json_encode([
            'version'   => 3,
            'timestamp' => $ts->format('Y-m-d\TH:i:s\Z'),
            'envelope'  => ['from' => 'poster@user.trashnothing.com', 'to' => $this->groupAddress()],
            'raw_email' => base64_encode($this->tnEmail('odd-name')),
        ], JSON_UNESCAPED_SLASHES));

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertArrayHasKey('odd-name', $result['posts']);
    }

    public function test_a_post_at_the_very_edge_of_the_window_survives_the_prefilter(): void
    {
        $this->archive('2026-08-14T08:00:00Z', $this->tnEmail('at-start'), $this->groupAddress());
        $this->archive('2026-08-14T10:00:00Z', $this->tnEmail('at-end'), $this->groupAddress());

        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertArrayHasKey('at-start', $result['posts']);
        $this->assertArrayHasKey('at-end', $result['posts']);
    }

    public function test_missing_archive_directory_is_not_fatal(): void
    {
        $service = $this->service($this->archiveDir . '/does-not-exist');

        $result = $service->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame([], $result['posts']);
        // Reported distinctly from "the window was quiet" — the command fails
        // on this rather than passing green while blind.
        $this->assertFalse($result['stats']['archive_present']);
    }

    public function test_an_existing_but_empty_archive_is_not_reported_as_missing(): void
    {
        $result = $this->service()->collect(
            CarbonImmutable::parse('2026-08-14T08:00:00Z'),
            CarbonImmutable::parse('2026-08-14T10:00:00Z'),
        );

        $this->assertSame([], $result['posts']);
        $this->assertTrue($result['stats']['archive_present']);
    }
}

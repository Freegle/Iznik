<?php

namespace Tests\Feature\Images;

use App\Services\ImageStore\LegacyMigrationService;
use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The legacy store is one flat NFS directory that must never be listed, so the
 * migrator is driven by the database: every row whose externaluid names a tusd
 * upload, walked by id with a cursor per source, copying into the object store
 * whatever is not already there. It never deletes from the legacy store.
 */
class LegacyMigrationServiceTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tusd-legacy');
        Storage::fake('images');
        DB::table('image_store_migration')->delete();
        DB::table('users_images')->delete();
        DB::table('groups_images')->delete();
    }

    private function migrator(): LegacyMigrationService
    {
        return new LegacyMigrationService(
            new ObjectStore(Storage::disk('images')),
            Storage::disk('tusd-legacy'),
        );
    }

    private function legacyFile(string $id, string $bytes = self::JPEG): void
    {
        Storage::disk('tusd-legacy')->put($id, $bytes);
        Storage::disk('tusd-legacy')->put($id . '.info', json_encode(['ID' => $id, 'Size' => strlen($bytes), 'MetaData' => ['filetype' => 'image/jpeg']]));
    }

    private function userImage(?string $externaluid): int
    {
        return DB::table('users_images')->insertGetId([
            'contenttype' => 'image/jpeg',
            'externaluid' => $externaluid,
        ]);
    }

    private function groupImage(?string $externaluid): int
    {
        return DB::table('groups_images')->insertGetId([
            'contenttype' => 'image/jpeg',
            'externaluid' => $externaluid,
        ]);
    }

    public function test_copies_referenced_files_that_the_store_does_not_have(): void
    {
        $this->legacyFile('a1');
        $this->legacyFile('a2');
        $this->userImage('freegletusd-a1');
        $this->userImage('freegletusd-a2');
        // Not a tusd upload: an archived row, an external URL row, and a null.
        $this->userImage(null);
        $this->userImage('uploadcare-uuid');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(2, $stats['copied']);
        $this->assertSame(2 * strlen(self::JPEG), $stats['bytes']);
        Storage::disk('images')->assertExists('a1');
        Storage::disk('images')->assertExists('a2');
        $this->assertSame('image/jpeg', Storage::disk('images')->mimeType('a1'));
        // The legacy copy is never removed by the migrator.
        Storage::disk('tusd-legacy')->assertExists('a1');
        // Only the bytes travel; the .info stays on the host.
        Storage::disk('images')->assertMissing('a1.info');
        $this->assertTrue($stats['finished']);
    }

    public function test_skips_what_the_store_already_has_and_records_what_the_legacy_store_lacks(): void
    {
        $this->legacyFile('b1');
        Storage::disk('images')->put('b1', self::JPEG);
        $this->userImage('freegletusd-b1');
        // Referenced but gone from NFS: nothing can be done, and it must be counted, not hidden.
        $this->userImage('freegletusd-b2');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(0, $stats['copied']);
        $this->assertSame(1, $stats['present']);
        $this->assertSame(1, $stats['missing_source']);
    }

    public function test_a_store_object_of_the_wrong_length_is_recopied(): void
    {
        $this->legacyFile('c1');
        Storage::disk('images')->put('c1', 'truncated');
        $this->userImage('freegletusd-c1');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(1, $stats['copied']);
        $this->assertSame(self::JPEG, Storage::disk('images')->get('c1'));
    }

    public function test_the_cursor_survives_between_runs_and_a_limit_stops_early(): void
    {
        foreach (['d1', 'd2', 'd3'] as $id) {
            $this->legacyFile($id);
            $this->userImage('freegletusd-' . $id);
        }

        $first = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100, limit: 2);

        $this->assertSame(2, $first['copied']);
        $this->assertFalse($first['finished']);
        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->copied);
        $this->assertNull($row->completed_at);

        $second = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        // Resumes after d2; d1 and d2 are not re-examined.
        $this->assertSame(1, $second['copied']);
        $this->assertSame(0, $second['present']);
        $this->assertTrue($second['finished']);
        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertSame(3, (int) $row->copied);
        $this->assertNotNull($row->completed_at);
    }

    public function test_a_finished_source_is_not_walked_again_until_reset(): void
    {
        $this->legacyFile('e1');
        $this->userImage('freegletusd-e1');
        $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);
        Storage::disk('images')->delete('e1');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(0, $stats['copied']);
        $this->assertSame(0, $stats['scanned']);

        $this->migrator()->resetCursor('users_images');
        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(1, $stats['copied']);
    }

    public function test_the_time_budget_stops_a_run_between_chunks(): void
    {
        foreach (['f1', 'f2', 'f3'] as $id) {
            $this->legacyFile($id);
            $this->userImage('freegletusd-' . $id);
        }

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 0, chunk: 1);

        // A zero budget still does one chunk, so progress is always possible.
        $this->assertSame(1, $stats['copied']);
        $this->assertTrue($stats['budget_exhausted']);
        $this->assertFalse($stats['finished']);
    }

    public function test_walks_every_source_and_each_keeps_its_own_cursor(): void
    {
        $this->legacyFile('g1');
        $this->legacyFile('g2');
        $this->userImage('freegletusd-g1');
        $this->groupImage('freegletusd-g2');

        $stats = $this->migrator()->migrate(['users_images', 'groups_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(2, $stats['copied']);
        $this->assertSame(['groups_images', 'users_images'], DB::table('image_store_migration')->orderBy('source')->pluck('source')->all());
    }

    public function test_an_id_that_could_escape_the_directory_is_refused(): void
    {
        $this->userImage('freegletusd-../etc/passwd');
        $this->userImage('freegletusd-');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(0, $stats['copied']);
        $this->assertSame(2, $stats['invalid']);
    }

    public function test_dry_run_copies_nothing_and_moves_no_cursor(): void
    {
        $this->legacyFile('h1');
        $this->userImage('freegletusd-h1');

        $stats = $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100, dryRun: true);

        $this->assertSame(1, $stats['copied']);
        Storage::disk('images')->assertMissing('h1');
        $this->assertSame(0, DB::table('image_store_migration')->count());
    }

    public function test_a_copy_failure_is_counted_and_the_walk_continues(): void
    {
        $this->legacyFile('i1');
        $this->legacyFile('i2');
        $this->userImage('freegletusd-i1');
        $this->userImage('freegletusd-i2');

        $store = new class(Storage::disk('images')) extends ObjectStore {
            public function put(string $key, $stream, string $contentType): void
            {
                if ($key === 'i1') {
                    throw new \RuntimeException('bucket unreachable');
                }
                parent::put($key, $stream, $contentType);
            }
        };

        $stats = (new LegacyMigrationService($store, Storage::disk('tusd-legacy')))
            ->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(1, $stats['copied']);
        Storage::disk('images')->assertExists('i2');
    }

    public function test_verify_reports_referenced_files_the_store_lacks_without_copying(): void
    {
        $this->legacyFile('j1');
        $this->legacyFile('j2');
        Storage::disk('images')->put('j1', self::JPEG);
        $this->userImage('freegletusd-j1');
        $this->userImage('freegletusd-j2');

        $stats = $this->migrator()->verify(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertSame(1, $stats['present']);
        $this->assertSame(1, $stats['missing']);
        $this->assertSame(['j2'], $stats['missing_ids']);
        Storage::disk('images')->assertMissing('j2');
        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertSame(1, (int) $row->verify_missing);
        $this->assertNotNull($row->verify_completed_at);
        // The copy cursor is untouched by a verify pass.
        $this->assertSame(0, (int) $row->last_id);
    }

    public function test_status_lists_every_source_whether_or_not_it_has_started(): void
    {
        $this->legacyFile('k1');
        $this->userImage('freegletusd-k1');
        $this->migrator()->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $status = $this->migrator()->status();

        $sources = array_column($status, 'source');
        $this->assertContains('users_images', $sources);
        $this->assertContains('messages_attachments', $sources);
        $this->assertContains('ai_images:pending_externaluid', $sources);
        $users = array_values(array_filter($status, fn ($r) => $r['source'] === 'users_images'))[0];
        $this->assertSame(1, $users['copied']);
        $this->assertNotNull($users['completed_at']);
    }
    public function test_an_unavailable_store_stops_the_walk_before_the_row_and_counts_nothing_failed(): void
    {
        $this->legacyFile('i1');
        $this->legacyFile('i2');
        $this->legacyFile('i3');
        $first = $this->userImage('freegletusd-i1');
        $this->userImage('freegletusd-i2');
        $this->userImage('freegletusd-i3');

        // The store goes dark at i2: everything from there on would get the
        // same answer, so the run stops, the cursor stays on i1, and i2 is not
        // a failed row but the first row of the next run.
        $store = new class(Storage::disk('images')) extends ObjectStore {
            public bool $down = true;

            public function sizeOf(string $key): ?int
            {
                if ($this->down && $key === 'i2') {
                    throw new ObjectStoreUnavailable('Object store unavailable: HeadObject i2 got HTTP 403');
                }

                return parent::sizeOf($key);
            }
        };
        $migrator = new LegacyMigrationService($store, Storage::disk('tusd-legacy'));

        $stats = $migrator->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertStringContainsString('HTTP 403', (string) $stats['unavailable']);
        $this->assertSame(1, $stats['copied']);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame(1, $stats['scanned']);
        $this->assertFalse($stats['finished']);
        Storage::disk('images')->assertExists('i1');
        Storage::disk('images')->assertMissing('i2');
        Storage::disk('images')->assertMissing('i3');

        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertSame($first, (int) $row->last_id);
        $this->assertSame(0, (int) $row->failed);
        $this->assertNull($row->completed_at);

        // The store is back: the next run carries on from i2.
        $store->down = false;
        $stats = $migrator->migrate(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertNull($stats['unavailable']);
        $this->assertSame(2, $stats['copied']);
        $this->assertTrue($stats['finished']);
        Storage::disk('images')->assertExists('i2');
        Storage::disk('images')->assertExists('i3');
    }

    public function test_verify_stops_on_an_unavailable_store_and_reports_nothing_missing(): void
    {
        Storage::disk('images')->put('i1', self::JPEG);
        $first = $this->userImage('freegletusd-i1');
        $this->userImage('freegletusd-i2');

        $store = new class(Storage::disk('images')) extends ObjectStore {
            public function sizeOf(string $key): ?int
            {
                if ($key === 'i2') {
                    throw new ObjectStoreUnavailable('Object store unavailable: HeadObject i2 got no answer');
                }

                return parent::sizeOf($key);
            }
        };

        $stats = (new LegacyMigrationService($store, Storage::disk('tusd-legacy')))
            ->verify(['users_images'], timeBudgetSeconds: 60, chunk: 100);

        $this->assertStringContainsString('no answer', (string) $stats['unavailable']);
        $this->assertSame(1, $stats['present']);
        $this->assertSame(0, $stats['missing']);
        $this->assertFalse($stats['finished']);

        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertSame($first, (int) $row->verify_last_id);
        $this->assertSame(0, (int) $row->verify_missing);
    }
}

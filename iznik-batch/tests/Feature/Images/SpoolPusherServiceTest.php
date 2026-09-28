<?php

namespace Tests\Feature\Images;

use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\SpoolPusherService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * tusd writes every upload to a local spool as <id> plus <id>.info. The pusher
 * moves each COMPLETED upload to the object store and then removes it locally.
 * These tests pin down what "completed" means and what is never touched.
 */
class SpoolPusherServiceTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tusd-spool');
        Storage::fake('images');
    }

    private function pusher(int $grace = 60, int $abandonHours = 24): SpoolPusherService
    {
        return new SpoolPusherService(
            new ObjectStore(Storage::disk('images')),
            Storage::disk('tusd-spool'),
            $grace,
            $abandonHours,
        );
    }

    /**
     * Put an upload in the spool the way tusd v2.4.0 filestore does: the bytes
     * in <id>, the declaration in <id>.info. $ageSeconds backdates both files.
     */
    private function spool(string $id, string $bytes, ?int $declaredSize = null, int $ageSeconds = 0, array $meta = ['filetype' => 'image/jpeg'], bool $deferred = false): void
    {
        $disk = Storage::disk('tusd-spool');
        $disk->put($id, $bytes);
        $disk->put($id . '.info', json_encode([
            'ID' => $id,
            'Size' => $declaredSize ?? strlen($bytes),
            'SizeIsDeferred' => $deferred,
            'Offset' => 0,
            'MetaData' => $meta,
            'IsPartial' => false,
            'IsFinal' => false,
            'PartialUploads' => null,
            'Storage' => ['Path' => '/spool/' . $id, 'Type' => 'filestore'],
        ]));

        if ($ageSeconds > 0) {
            $when = time() - $ageSeconds;
            touch($disk->path($id), $when);
            touch($disk->path($id . '.info'), $when);
        }
    }

    public function test_a_completed_upload_older_than_the_grace_period_is_pushed_and_removed(): void
    {
        $this->spool('aaaa', self::JPEG, ageSeconds: 120);
        Storage::disk('tusd-spool')->put('aaaa.lock', '');

        $stats = $this->pusher()->push();

        $this->assertSame(1, $stats['pushed']);
        $this->assertSame(strlen(self::JPEG), $stats['bytes']);
        Storage::disk('images')->assertExists('aaaa');
        $this->assertSame(self::JPEG, Storage::disk('images')->get('aaaa'));
        $this->assertSame('image/jpeg', Storage::disk('images')->mimeType('aaaa'));
        Storage::disk('tusd-spool')->assertMissing('aaaa');
        Storage::disk('tusd-spool')->assertMissing('aaaa.info');
        // The lock file tusd leaves behind goes with it.
        Storage::disk('tusd-spool')->assertMissing('aaaa.lock');
    }

    public function test_a_completed_upload_inside_the_grace_period_waits(): void
    {
        $this->spool('bbbb', self::JPEG, ageSeconds: 5);

        $stats = $this->pusher(grace: 60)->push();

        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(1, $stats['recent']);
        Storage::disk('images')->assertMissing('bbbb');
        Storage::disk('tusd-spool')->assertExists('bbbb');
    }

    public function test_an_incomplete_upload_is_left_alone(): void
    {
        // Half the declared length: a PATCH is still to come (or never will be).
        $this->spool('cccc', substr(self::JPEG, 0, 8), declaredSize: strlen(self::JPEG), ageSeconds: 600);

        $stats = $this->pusher()->push();

        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(1, $stats['incomplete']);
        Storage::disk('tusd-spool')->assertExists('cccc');
        Storage::disk('images')->assertMissing('cccc');
    }

    public function test_an_incomplete_upload_older_than_the_abandon_window_is_deleted(): void
    {
        $this->spool('dddd', substr(self::JPEG, 0, 8), declaredSize: strlen(self::JPEG), ageSeconds: 25 * 3600);

        $stats = $this->pusher(abandonHours: 24)->push();

        $this->assertSame(1, $stats['abandoned']);
        Storage::disk('tusd-spool')->assertMissing('dddd');
        Storage::disk('tusd-spool')->assertMissing('dddd.info');
        Storage::disk('images')->assertMissing('dddd');
    }

    public function test_a_deferred_length_upload_is_never_treated_as_complete(): void
    {
        $this->spool('eeee', self::JPEG, declaredSize: 0, ageSeconds: 600, deferred: true);

        $stats = $this->pusher()->push();

        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(1, $stats['incomplete']);
        Storage::disk('tusd-spool')->assertExists('eeee');
    }

    public function test_an_object_already_in_the_store_with_the_same_length_is_only_cleaned_up(): void
    {
        // The previous run crashed between the upload and the local delete.
        $this->spool('ffff', self::JPEG, ageSeconds: 120);
        Storage::disk('images')->put('ffff', self::JPEG);

        $stats = $this->pusher()->push();

        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(1, $stats['present']);
        Storage::disk('tusd-spool')->assertMissing('ffff');
        Storage::disk('images')->assertExists('ffff');
    }

    public function test_an_object_in_the_store_with_a_different_length_is_replaced(): void
    {
        $this->spool('gggg', self::JPEG, ageSeconds: 120);
        Storage::disk('images')->put('gggg', 'short');

        $stats = $this->pusher()->push();

        $this->assertSame(1, $stats['pushed']);
        $this->assertSame(self::JPEG, Storage::disk('images')->get('gggg'));
        Storage::disk('tusd-spool')->assertMissing('gggg');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->spool('hhhh', self::JPEG, ageSeconds: 120);
        $this->spool('iiii', substr(self::JPEG, 0, 8), declaredSize: strlen(self::JPEG), ageSeconds: 25 * 3600);

        $stats = $this->pusher()->push(dryRun: true);

        $this->assertSame(1, $stats['pushed']);
        $this->assertSame(1, $stats['abandoned']);
        Storage::disk('images')->assertMissing('hhhh');
        Storage::disk('tusd-spool')->assertExists('hhhh');
        Storage::disk('tusd-spool')->assertExists('iiii');
    }

    public function test_limit_bounds_a_run_and_the_rest_wait_for_the_next(): void
    {
        $this->spool('j001', self::JPEG, ageSeconds: 120);
        $this->spool('j002', self::JPEG, ageSeconds: 120);
        $this->spool('j003', self::JPEG, ageSeconds: 120);

        $stats = $this->pusher()->push(limit: 2);

        $this->assertSame(2, $stats['pushed']);
        $this->assertCount(1, array_filter(Storage::disk('tusd-spool')->files(), fn ($f) => ! str_ends_with($f, '.info')));

        $stats = $this->pusher()->push(limit: 2);

        $this->assertSame(1, $stats['pushed']);
    }

    public function test_an_info_with_no_bytes_file_is_removed_once_old(): void
    {
        // tusd creates <id> before <id>.info, so an orphan .info is a terminated
        // upload. Young ones might be mid-creation; old ones are litter.
        Storage::disk('tusd-spool')->put('kkkk.info', json_encode(['ID' => 'kkkk', 'Size' => 10, 'MetaData' => []]));
        Storage::disk('tusd-spool')->put('llll.info', json_encode(['ID' => 'llll', 'Size' => 10, 'MetaData' => []]));
        touch(Storage::disk('tusd-spool')->path('llll.info'), time() - 25 * 3600);

        $stats = $this->pusher()->push();

        $this->assertSame(1, $stats['abandoned']);
        Storage::disk('tusd-spool')->assertExists('kkkk.info');
        Storage::disk('tusd-spool')->assertMissing('llll.info');
    }

    public function test_an_unreadable_info_is_skipped_until_old(): void
    {
        Storage::disk('tusd-spool')->put('mmmm', self::JPEG);
        Storage::disk('tusd-spool')->put('mmmm.info', 'garbage');
        touch(Storage::disk('tusd-spool')->path('mmmm.info'), time() - 25 * 3600);
        touch(Storage::disk('tusd-spool')->path('mmmm'), time() - 25 * 3600);

        $stats = $this->pusher()->push();

        // Nothing we can verify the length against: never pushed. Old enough to purge.
        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(1, $stats['abandoned']);
        Storage::disk('tusd-spool')->assertMissing('mmmm');
    }

    public function test_a_store_failure_keeps_the_local_copy_and_is_counted(): void
    {
        $this->spool('nnnn', self::JPEG, ageSeconds: 120);

        $store = new class(Storage::disk('images')) extends ObjectStore {
            public function put(string $key, $stream, string $contentType): void
            {
                throw new \RuntimeException('bucket unreachable');
            }
        };
        $pusher = new SpoolPusherService($store, Storage::disk('tusd-spool'), 60, 24);

        $stats = $pusher->push();

        $this->assertSame(1, $stats['failed']);
        $this->assertSame(0, $stats['pushed']);
        Storage::disk('tusd-spool')->assertExists('nnnn');
        Storage::disk('tusd-spool')->assertExists('nnnn.info');
    }

    public function test_a_length_mismatch_after_upload_is_a_failure_not_a_delete(): void
    {
        $this->spool('oooo', self::JPEG, ageSeconds: 120);

        $store = new class(Storage::disk('images')) extends ObjectStore {
            public function sizeOf(string $key): ?int
            {
                // The store reports a truncated object after our own upload.
                return parent::sizeOf($key) === null ? null : 3;
            }
        };
        $pusher = new SpoolPusherService($store, Storage::disk('tusd-spool'), 60, 24);

        $stats = $pusher->push();

        $this->assertSame(1, $stats['failed']);
        Storage::disk('tusd-spool')->assertExists('oooo');
    }

    public function test_ids_that_are_not_tusd_ids_are_ignored(): void
    {
        // Anything with a path separator or odd characters is not ours to touch.
        Storage::disk('tusd-spool')->put('.hidden.info', '{}');
        Storage::disk('tusd-spool')->put('with space.info', '{}');

        $stats = $this->pusher()->push();

        $this->assertSame(0, $stats['pushed']);
        $this->assertSame(0, $stats['abandoned']);
        Storage::disk('tusd-spool')->assertExists('.hidden.info');
        Storage::disk('tusd-spool')->assertExists('with space.info');
    }
}

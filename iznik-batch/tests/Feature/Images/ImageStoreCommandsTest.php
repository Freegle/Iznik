<?php

namespace Tests\Feature\Images;

use App\Services\ImageStore\ObjectStoreUnavailable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The three artisan commands are thin: they hand options to the services and
 * report. What is pinned here is the wiring, the exit codes and the one check
 * that has to fail loudly - a bucket that is not actually public.
 */
class ImageStoreCommandsTest extends TestCase
{
    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('tusd-spool');
        Storage::fake('tusd-legacy');
        Storage::fake('images');
        DB::table('image_store_migration')->delete();
        DB::table('users_images')->delete();
        config(['filesystems.disks.images.url' => 'http://store.test/images/']);
        // The commands check the configured roots exist before touching a
        // disk, so point them at the fakes' directories.
        config(['filesystems.disks.tusd-spool.root' => Storage::disk('tusd-spool')->path('')]);
        config(['filesystems.disks.tusd-legacy.root' => Storage::disk('tusd-legacy')->path('')]);
    }

    private function spool(string $id, int $ageSeconds = 120): void
    {
        $disk = Storage::disk('tusd-spool');
        $disk->put($id, self::JPEG);
        $disk->put($id . '.info', json_encode(['ID' => $id, 'Size' => strlen(self::JPEG), 'MetaData' => ['filetype' => 'image/jpeg']]));
        touch($disk->path($id), time() - $ageSeconds);
        touch($disk->path($id . '.info'), time() - $ageSeconds);
    }

    public function test_push_spool_pushes_and_reports(): void
    {
        $this->spool('p1');

        $this->artisan('images:push-spool')
            ->expectsOutputToContain('Pushed')
            ->assertExitCode(0);

        Storage::disk('images')->assertExists('p1');
        Storage::disk('tusd-spool')->assertMissing('p1');
    }

    public function test_push_spool_dry_run_touches_nothing(): void
    {
        $this->spool('p2');

        $this->artisan('images:push-spool --dry-run')->assertExitCode(0);

        Storage::disk('images')->assertMissing('p2');
        Storage::disk('tusd-spool')->assertExists('p2');
    }

    public function test_push_spool_fails_when_the_spool_is_absent(): void
    {
        // Somewhere that cannot be created either: resolving a local disk
        // makes its root, and the batch container runs as root.
        config(['filesystems.disks.tusd-spool.root' => '/proc/nonexistent/spool']);

        $this->artisan('images:push-spool')
            ->expectsOutputToContain('not a directory')
            ->assertExitCode(1);
    }

    public function test_migrate_legacy_copies_and_status_shows_the_cursor(): void
    {
        Storage::disk('tusd-legacy')->put('m1', self::JPEG);
        DB::table('users_images')->insert(['contenttype' => 'image/jpeg', 'externaluid' => 'freegletusd-m1']);

        $this->artisan('images:migrate-legacy --source=users_images --time-budget=30')
            ->assertExitCode(0);

        Storage::disk('images')->assertExists('m1');

        $this->artisan('images:migrate-legacy --status')
            ->expectsOutputToContain('users_images')
            ->assertExitCode(0);
    }

    public function test_migrate_legacy_verify_exits_non_zero_when_something_is_missing(): void
    {
        DB::table('users_images')->insert(['contenttype' => 'image/jpeg', 'externaluid' => 'freegletusd-m2']);

        $this->artisan('images:migrate-legacy --source=users_images --verify')
            ->expectsOutputToContain('m2')
            ->assertExitCode(1);
    }

    public function test_migrate_legacy_rejects_an_unknown_source(): void
    {
        $this->artisan('images:migrate-legacy --source=users')
            ->assertExitCode(1);
    }

    public function test_object_store_check_passes_when_the_probe_is_publicly_readable(): void
    {
        Http::fake(function ($request) {
            $key = basename(parse_url($request->url(), PHP_URL_PATH));

            return Storage::disk('images')->exists($key)
                ? Http::response(Storage::disk('images')->get($key), 200, ['Content-Type' => 'text/plain'])
                : Http::response('', 404);
        });

        $this->artisan('images:object-store-check')
            ->expectsOutputToContain('OK')
            ->assertExitCode(0);

        // The probe is cleaned up.
        $this->assertSame([], Storage::disk('images')->files());
    }

    public function test_object_store_check_fails_when_anonymous_reads_are_refused(): void
    {
        // A private bucket answers 403. nginx would fall through to the legacy
        // hop and every NEW image would 404 with nothing in any log naming the
        // cause, so this is the check that must be loud.
        Http::fake(['http://store.test/images/*' => Http::response('AccessDenied', 403)]);

        $this->artisan('images:object-store-check')
            ->expectsOutputToContain('403')
            ->assertExitCode(1);

        $this->assertSame([], Storage::disk('images')->files());
    }

    public function test_object_store_check_fails_when_no_public_url_is_configured(): void
    {
        config(['filesystems.disks.images.url' => null]);

        $this->artisan('images:object-store-check')
            ->expectsOutputToContain('IMAGE_STORE_PUBLIC_URL')
            ->assertExitCode(1);
    }
    /**
     * Swap the faked images disk for a real S3 driver whose every request the
     * bucket answers with the given status, without any network. 403 is what
     * the bucket answered when its public read and key were both revoked.
     */
    private function bucketThatAnswers(int $status): void
    {
        Storage::forgetDisk('images');
        config(['filesystems.disks.images' => [
            'driver' => 's3',
            'key' => 'key',
            'secret' => 'secret',
            'region' => 'us-east-1',
            'bucket' => 'images',
            'url' => 'http://store.test/images/',
            'endpoint' => 'http://store.test',
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
            'throw' => true,
            'report' => false,
            'http_handler' => fn () => Create::promiseFor(
                new Response($status, [], '<?xml version="1.0"?><Error><Code>AccessDenied</Code><Message>Access Denied.</Message></Error>')
            ),
        ]]);
    }

    public function test_push_spool_stops_and_reports_when_the_store_is_unavailable(): void
    {
        Exceptions::fake();
        $this->spool('aaaa');
        $this->spool('bbbb');
        $this->bucketThatAnswers(403);

        $this->artisan('images:push-spool')
            ->expectsOutputToContain('unavailable')
            ->assertExitCode(1);

        Storage::disk('tusd-spool')->assertExists('aaaa');
        Storage::disk('tusd-spool')->assertExists('bbbb');
        Exceptions::assertReported(ObjectStoreUnavailable::class);
    }

    public function test_migrate_legacy_stops_and_reports_when_the_store_is_unavailable(): void
    {
        Exceptions::fake();
        Storage::disk('tusd-legacy')->put('cccc', self::JPEG);
        DB::table('users_images')->insert(['contenttype' => 'image/jpeg', 'externaluid' => 'freegletusd-cccc']);
        $this->bucketThatAnswers(403);

        $this->artisan('images:migrate-legacy --source=users_images --time-budget=30')
            ->expectsOutputToContain('unavailable')
            ->assertExitCode(1);

        $row = DB::table('image_store_migration')->where('source', 'users_images')->first();
        $this->assertSame(0, (int) $row->failed);
        $this->assertSame(0, (int) $row->last_id);
        Exceptions::assertReported(ObjectStoreUnavailable::class);
    }

    public function test_object_store_check_reports_to_sentry_only_when_asked(): void
    {
        Exceptions::fake();
        $this->bucketThatAnswers(403);

        // By hand, before the cutover, a failing bucket is the operator's to see.
        $this->artisan('images:object-store-check')
            ->expectsOutputToContain('403')
            ->assertExitCode(1);
        Exceptions::assertNothingReported();

        // On the schedule it must reach Sentry.
        $this->artisan('images:object-store-check --report')
            ->assertExitCode(1);
        Exceptions::assertReported(ObjectStoreUnavailable::class);
    }
}

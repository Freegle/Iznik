<?php

namespace Tests\Feature\AzureArchive;

use App\Services\AzureArchive\ArchiveCopier;
use App\Services\AzureArchive\ArchiveLedger;
use App\Services\AzureArchive\BlobFetcher;
use App\Services\AzureArchive\LosslessJpeg;
use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The copier's promises: each blob is read from Azure once (one retry at most), checked against
 * Azure's own MD5, stored under archive/, and recorded; it never spends past its allowance; and
 * it stops rather than burning fetches when something is broken for every blob.
 */
class ArchiveCopierTest extends TestCase
{
    private const SOURCE = 'https://freegle.blob.core.windows.net/images';

    /** @var array<string, array{status: int, body: string, md5: ?string}> blob name => answer */
    private array $azure = [];

    /** @var array<string, int> blob name => times fetched */
    private array $fetches = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('images');
        DB::table(ArchiveLedger::TABLE)->delete();
        DB::table('config')->whereIn('key', ['azure_archive_cursor', 'azure_archive_stop'])->delete();

        Http::fake([
            'freegle.blob.core.windows.net/*' => function (Request $request) {
                $name = basename(parse_url($request->url(), PHP_URL_PATH));
                $this->fetches[$name] = ($this->fetches[$name] ?? 0) + 1;
                $answer = $this->azure[$name] ?? ['status' => 404, 'body' => 'BlobNotFound', 'md5' => null];

                if ($answer['status'] === 0) {
                    return Http::failedConnection('Connection reset by peer');
                }
                $headers = $answer['md5'] === null ? [] : ['Content-MD5' => $answer['md5']];

                if (isset($answer['length'])) {
                    $headers['Content-Length'] = (string) $answer['length'];
                }

                if ($answer['status'] === 409) {
                    $headers['x-ms-error-code'] = 'BlobArchived';
                }

                return Http::response($answer['body'], $answer['status'], $headers);
            },
        ]);
    }

    private function blob(string $name, string $body, ?string $md5 = 'right', int $status = 200): void
    {
        $this->azure[$name] = [
            'status' => $status,
            'body' => $body,
            'md5' => $md5 === 'right' ? base64_encode(md5($body, true)) : $md5,
        ];
    }

    private function attachment(?string $externaluid = null, int $archived = 1): int
    {
        return (int) DB::table('messages_attachments')->insertGetId([
            'contenttype' => 'image/jpeg',
            'archived' => $archived,
            'externaluid' => $externaluid,
        ]);
    }

    private function chatImage(): int
    {
        return (int) DB::table('chat_images')->insertGetId(['contenttype' => 'image/jpeg', 'archived' => 1]);
    }

    private function copier(?ObjectStore $store = null, ?LosslessJpeg $jpeg = null): ArchiveCopier
    {
        return new ArchiveCopier(new ArchiveLedger(), new BlobFetcher(self::SOURCE), $store ?? new ObjectStore(Storage::disk('images')), $jpeg, 'archive/');
    }

    private function ledger(string $blob): ?object
    {
        return DB::table(ArchiveLedger::TABLE)->where('blobname', $blob)->first();
    }

    private const PLENTY = 100 * 1000 * 1000;

    public function test_a_verified_blob_is_stored_under_archive_and_recorded(): void
    {
        $id = $this->attachment();
        $this->blob("img_{$id}.jpg", 'jpeg bytes ' . str_repeat('x', 500));

        $result = $this->copier()->copy(self::PLENTY);

        Storage::disk('images')->assertExists("archive/img_{$id}.jpg");
        $this->assertSame('jpeg bytes ' . str_repeat('x', 500), Storage::disk('images')->get("archive/img_{$id}.jpg"));
        $row = $this->ledger("img_{$id}.jpg");
        $this->assertSame('Copied', $row->status);
        $this->assertSame('messages_attachments', $row->source);
        $this->assertSame($id, (int) $row->sourceid);
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame(511, (int) $row->storedbytes);
        $this->assertSame(511 + BlobFetcher::REQUEST_OVERHEAD_BYTES, (int) $row->azurebytes);
        $this->assertSame(base64_encode(md5('jpeg bytes ' . str_repeat('x', 500), true)), $row->azuremd5);
        $this->assertNotNull($row->copiedat);
        $this->assertGreaterThanOrEqual(1, $result['stats']['copied']);
        $this->assertNull($result['stopped']);
    }

    public function test_every_kind_of_source_uses_its_own_prefix(): void
    {
        $id = $this->chatImage();
        $this->blob("mimg_{$id}.jpg", 'chat image');

        $this->copier()->copy(self::PLENTY);

        Storage::disk('images')->assertExists("archive/mimg_{$id}.jpg");
        $this->assertSame('chat_images', $this->ledger("mimg_{$id}.jpg")->source);
    }

    public function test_a_blob_azure_does_not_have_is_recorded_missing_and_never_asked_for_again(): void
    {
        $id = $this->attachment();

        $this->copier()->copy(self::PLENTY);
        $this->copier()->copy(self::PLENTY);

        $this->assertSame('Missing', $this->ledger("img_{$id}.jpg")->status);
        $this->assertSame(1, $this->fetches["img_{$id}.jpg"]);
        Storage::disk('images')->assertMissing("archive/img_{$id}.jpg");
    }

    public function test_a_row_served_from_uploads_is_not_fetched(): void
    {
        $id = $this->attachment('freegletusd-abc');
        $this->blob("img_{$id}.jpg", 'never needed');

        $this->copier()->copy(self::PLENTY);

        $this->assertSame('Superseded', $this->ledger("img_{$id}.jpg")->status);
        $this->assertArrayNotHasKey("img_{$id}.jpg", $this->fetches);
    }

    public function test_a_row_that_was_never_archived_is_not_touched(): void
    {
        $id = $this->attachment(null, 0);
        $this->blob("img_{$id}.jpg", 'live image');

        $this->copier()->copy(self::PLENTY);

        $this->assertNull($this->ledger("img_{$id}.jpg"));
        $this->assertArrayNotHasKey("img_{$id}.jpg", $this->fetches);
    }

    public function test_a_hash_mismatch_is_fetched_once_more_and_then_given_up(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'corrupted in transit', base64_encode(md5('the real bytes', true)));

        $this->copier()->copy(self::PLENTY);
        $first = $this->ledger($name);
        $this->assertSame('Failed', $first->status);
        $this->assertStringStartsWith(ArchiveLedger::RETRY, $first->error);

        $this->copier()->copy(self::PLENTY);
        $second = $this->ledger($name);
        $this->assertSame('Failed', $second->status);
        $this->assertSame(2, (int) $second->attempts);
        $this->assertStringStartsNotWith(ArchiveLedger::RETRY, $second->error);

        $this->copier()->copy(self::PLENTY);
        $this->assertSame(2, $this->fetches[$name], 'read at most twice');
        // Both fetches count against the budget.
        $this->assertSame(2 * (strlen('corrupted in transit') + BlobFetcher::REQUEST_OVERHEAD_BYTES), (int) $this->ledger($name)->azurebytes);
        Storage::disk('images')->assertMissing("archive/{$name}");
    }

    public function test_a_retry_that_succeeds_is_copied(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'bad', base64_encode(md5('good', true)));
        $this->copier()->copy(self::PLENTY);

        $this->blob($name, 'good');
        $this->copier()->copy(self::PLENTY);

        $this->assertSame('Copied', $this->ledger($name)->status);
        $this->assertNull($this->ledger($name)->error);
        $this->assertSame('good', Storage::disk('images')->get("archive/{$name}"));
    }

    public function test_a_blob_with_no_md5_and_a_matching_length_is_kept_and_says_how_it_was_checked(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'written in blocks', null);
        $this->azure[$name]['length'] = strlen('written in blocks');

        $this->copier()->copy(self::PLENTY);

        $row = $this->ledger($name);
        $this->assertSame('Copied', $row->status);
        $this->assertNull($row->azuremd5);
        $this->assertStringContainsString('length only', $row->error);
        $this->assertSame('written in blocks', Storage::disk('images')->get("archive/{$name}"));
    }

    public function test_a_blob_with_no_md5_and_a_short_body_is_tried_once_more_then_given_up(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'cut short', null);
        $this->azure[$name]['length'] = 5000;

        $this->copier()->copy(self::PLENTY);
        $this->copier()->copy(self::PLENTY);
        $this->copier()->copy(self::PLENTY);

        $this->assertSame('Failed', $this->ledger($name)->status);
        $this->assertStringContainsString('Content-Length', $this->ledger($name)->error);
        $this->assertStringStartsNotWith(ArchiveLedger::RETRY, $this->ledger($name)->error);
        $this->assertSame(2, $this->fetches[$name]);
        Storage::disk('images')->assertMissing("archive/{$name}");
    }

    public function test_an_archive_tier_blob_is_left_alone_rather_than_rehydrated(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'This operation is not permitted on an archived blob.', null, 409);

        $this->copier()->copy(self::PLENTY);
        $this->copier()->copy(self::PLENTY);

        $this->assertSame('Failed', $this->ledger($name)->status);
        $this->assertStringContainsString('BlobArchived', $this->ledger($name)->error);
        $this->assertSame(1, $this->fetches[$name]);
    }

    public function test_nothing_is_fetched_when_the_allowance_cannot_cover_one_blob(): void
    {
        $id = $this->attachment();
        $this->blob("img_{$id}.jpg", 'x');

        $result = $this->copier()->copy(ArchiveCopier::BLOB_RESERVE_BYTES - 1);

        $this->assertSame([], $this->fetches);
        $this->assertSame(ArchiveCopier::STOP_BUDGET, $result['stopped']['kind']);
    }

    public function test_a_spent_allowance_stops_the_walk_and_the_next_run_carries_on_where_it_stopped(): void
    {
        $ids = [];

        for ($i = 0; $i < 5; $i++) {
            $ids[$i] = $this->attachment();
            $this->blob("img_{$ids[$i]}.jpg", str_repeat('a', 1000));
        }

        // Each blob is counted as 2,024 bytes: room for two, and one byte short of a third.
        $allowance = ArchiveCopier::BLOB_RESERVE_BYTES + 2 * 2024 - 1;
        $first = $this->copier()->copy($allowance);

        $this->assertSame(ArchiveCopier::STOP_BUDGET, $first['stopped']['kind']);
        $this->assertSame(2, $first['stats']['copied']);
        $this->assertSame(2 * 2024, $first['stats']['bytes_fetched']);

        $this->copier()->copy(self::PLENTY);

        foreach ($ids as $id) {
            $this->assertSame('Copied', $this->ledger("img_{$id}.jpg")->status);
            $this->assertSame(1, $this->fetches["img_{$id}.jpg"]);
        }
    }

    public function test_azure_not_answering_three_times_stops_the_run(): void
    {
        $names = [];

        for ($i = 0; $i < 5; $i++) {
            $id = $this->attachment();
            $names[] = "img_{$id}.jpg";
            $this->blob("img_{$id}.jpg", 'busy', null, 503);
        }

        $result = $this->copier()->copy(self::PLENTY);

        $this->assertSame(ArchiveCopier::STOP_AZURE, $result['stopped']['kind']);
        $this->assertCount(3, $this->fetches);
        $this->assertStringStartsWith(ArchiveLedger::RETRY, $this->ledger($names[0])->error);
    }

    public function test_a_bucket_that_is_down_stops_everything_before_any_fetch(): void
    {
        $id = $this->attachment();
        $this->blob("img_{$id}.jpg", 'x');
        $store = $this->createMock(ObjectStore::class);
        $store->method('sizeOf')->willThrowException(new ObjectStoreUnavailable('HTTP 403'));

        $result = $this->copier($store)->copy(self::PLENTY);

        $this->assertSame(ArchiveCopier::STOP_BUCKET, $result['stopped']['kind']);
        $this->assertSame([], $this->fetches);
    }

    public function test_a_bucket_that_fails_mid_run_keeps_the_blob_for_one_retry_and_stops(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'x');
        $store = $this->createMock(ObjectStore::class);
        $store->method('sizeOf')->willReturn(null);
        $store->method('put')->willThrowException(new ObjectStoreUnavailable('HTTP 503'));

        $result = $this->copier($store)->copy(self::PLENTY);

        $this->assertSame(ArchiveCopier::STOP_BUCKET, $result['stopped']['kind']);
        $this->assertStringStartsWith(ArchiveLedger::RETRY, $this->ledger($name)->error);
    }

    public function test_a_fetch_the_process_died_in_is_counted_at_the_worst_case_and_retried_once(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'recovered');
        (new ArchiveLedger())->begin('messages_attachments', $id, $name);
        DB::table(ArchiveLedger::TABLE)->where('blobname', $name)->update(['fetchedat' => CarbonImmutable::now('UTC')->subHour()]);

        $this->assertSame(ArchiveLedger::UNKNOWN_FETCH_BYTES, (new ArchiveLedger())->bytesSince(CarbonImmutable::now('UTC')->subDay()));

        $this->copier()->copy(self::PLENTY);

        $row = $this->ledger($name);
        $this->assertSame('Copied', $row->status);
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(1, $this->fetches[$name]);
    }

    public function test_a_fetch_still_in_flight_is_not_retried(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'x');
        (new ArchiveLedger())->begin('messages_attachments', $id, $name);

        $this->copier()->copy(self::PLENTY);

        $this->assertArrayNotHasKey($name, $this->fetches);
    }

    public function test_the_store_must_report_the_bytes_written(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, 'twelve bytes');
        $store = $this->createMock(ObjectStore::class);
        $store->method('sizeOf')->willReturnOnConsecutiveCalls(null, 5);

        $this->copier($store)->copy(self::PLENTY);

        $this->assertSame('Failed', $this->ledger($name)->status);
        $this->assertStringContainsString('reported 5 bytes', $this->ledger($name)->error);
    }

    public function test_the_lossless_optimisation_is_what_is_stored(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->blob($name, "\xFF\xD8\xFF original");
        $jpeg = $this->createMock(LosslessJpeg::class);
        $jpeg->method('optimise')->willReturn(['bytes' => "\xFF\xD8\xFF small", 'optimised' => true, 'note' => null]);

        $this->copier(null, $jpeg)->copy(self::PLENTY);

        $this->assertSame("\xFF\xD8\xFF small", Storage::disk('images')->get("archive/{$name}"));
        $row = $this->ledger($name);
        $this->assertSame(1, (int) $row->optimised);
        $this->assertSame(strlen("\xFF\xD8\xFF small"), (int) $row->storedbytes);
        $this->assertSame(base64_encode(md5("\xFF\xD8\xFF original", true)), $row->azuremd5, 'the ledger keeps Azure\'s hash of the original');
    }

    public function test_the_preview_names_the_next_blobs_and_fetches_nothing(): void
    {
        $a = $this->attachment();
        $b = $this->attachment('freegletusd-x');

        $names = $this->copier()->preview(1000);

        $this->assertContains("img_{$a}.jpg", $names);
        $this->assertContains("img_{$b}.jpg (superseded)", $names);
        $this->assertSame([], $this->fetches);
        $this->assertSame(0, DB::table(ArchiveLedger::TABLE)->count());
    }

    public function test_a_connection_that_breaks_is_counted_at_the_worst_case_and_retried_once(): void
    {
        $id = $this->attachment();
        $name = "img_{$id}.jpg";
        $this->azure[$name] = ['status' => 0, 'body' => '', 'md5' => null];

        $this->copier()->copy(self::PLENTY);

        $row = $this->ledger($name);
        $this->assertSame('Failed', $row->status);
        $this->assertStringContainsString('Connection reset', $row->error);
        $this->assertSame(ArchiveLedger::UNKNOWN_FETCH_BYTES, (int) $row->azurebytes, 'how much arrived is unknown, so the worst case is counted');
        $this->assertNotEmpty($this->ledger($name)->error);
        $this->assertCount(1, (new ArchiveLedger())->retryable(CarbonImmutable::now('UTC'), 10));
    }

    public function test_an_unknown_source_table_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        \App\Services\AzureArchive\ArchiveSources::blobName('users', 1);
    }
}

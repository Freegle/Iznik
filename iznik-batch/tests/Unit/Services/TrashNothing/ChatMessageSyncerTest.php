<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\ChatIngestPlan;
use App\Services\TrashNothing\Sync\ChatMessageSyncer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * ChatMessageSyncer: paging, day windows, ordering and a page that cannot be read. Read-only until
 * the cutover, so every record is planned and none written.
 */
class ChatMessageSyncerTest extends TestCase
{
    private function syncer(bool $localTesting = false): ChatMessageSyncer
    {
        return new ChatMessageSyncer(
            dryRun: false,
            localTesting: $localTesting,
            apiKey: 'partner-key',
            apiBaseUrl: 'https://tn.example.invalid/fd/api',
            loki: app(LokiService::class),
        );
    }

    private function row(string $id, string $date): array
    {
        return ['id' => $id, 'date' => $date, 'tn_user_id' => 1, 'kind' => 'tn_notice', 'text' => 'x'];
    }

    public function test_records_come_back_in_date_then_id_order(): void
    {
        Http::fake(['*/chat-messages*' => Http::response(['chat_messages' => [
            $this->row('b', '2026-11-17T10:00:02Z'),
            $this->row('c', '2026-11-17T10:00:01Z'),
            $this->row('a', '2026-11-17T10:00:02Z'),
        ]], 200)]);

        $ids = array_map(fn ($r) => $r->id, $this->syncer()->records('2026-11-17T10:00:00Z', '2026-11-17T11:00:00Z'));

        $this->assertSame(['c', 'a', 'b'], $ids);
    }

    public function test_reads_every_page_of_a_full_window_and_a_day_at_a_time(): void
    {
        Http::fake(function (Request $request) {
            if ($request['page'] == 1) {
                $rows = [];
                for ($i = 0; $i < 100; $i++) {
                    $rows[] = $this->row('p1-' . $request['date_min'] . '-' . $i, '2026-11-17T10:00:00Z');
                }

                return Http::response(['chat_messages' => $rows], 200);
            }

            return Http::response(['chat_messages' => [$this->row('p2-' . $request['date_min'], '2026-11-17T10:00:00Z')]], 200);
        });

        [$count] = $this->syncer()->sync('2026-11-16T12:00:00Z', '2026-11-17T18:00:00Z');

        // Two days, each a full page and one more.
        $this->assertSame(202, $count);
        Http::assertSent(fn (Request $r) => $r['key'] === 'partner-key' && $r['per_page'] == 100);
    }

    public function test_an_unreadable_page_sets_fetch_failed_and_keeps_what_was_read(): void
    {
        Http::fake(fn (Request $request) => $request['page'] == 1
            ? Http::response(['chat_messages' => array_map(fn ($i) => $this->row("r$i", '2026-11-17T10:00:00Z'), range(1, 100))], 200)
            : Http::response(null, 503));

        $syncer = $this->syncer();
        [$count, $maxDate] = $syncer->sync('2026-11-17T09:00:00Z', '2026-11-17T11:00:00Z');

        $this->assertTrue($syncer->fetchFailed());
        $this->assertSame(100, $count);
        $this->assertSame('2026-11-17T10:00:00Z', $maxDate);
    }

    public function test_a_record_with_no_id_is_skipped(): void
    {
        Http::fake(['*/chat-messages*' => Http::response(['chat_messages' => [
            ['kind' => 'chat_reply'],
            $this->row('ok', '2026-11-17T10:00:00Z'),
        ]], 200)]);

        $syncer = $this->syncer();
        [$count] = $syncer->sync('2026-11-17T09:00:00Z', '2026-11-17T11:00:00Z');

        $this->assertSame(1, $count);
        $this->assertSame(1, $syncer->results()['invalid']);
        $this->assertSame(1, $syncer->results()[ChatIngestPlan::RESULT_SKIPPED]);
    }

    public function test_local_testing_reads_the_fixture_pages(): void
    {
        Http::preventStrayRequests();

        $syncer = $this->syncer(true);
        [$count, $maxDate] = $syncer->sync('2026-11-17T00:00:00Z', '2026-11-18T00:00:00Z');

        $this->assertSame(3, $count);
        $this->assertSame('2026-11-17T10:02:00Z', $maxDate);
    }
}

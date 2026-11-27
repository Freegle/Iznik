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
    use CapturesRoutedLokiEntries;

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

    public function test_writing_logs_one_routed_entry_per_record_and_notes_skips(): void
    {
        $this->enableLokiCapture();
        $poster = $this->createTestUser();
        $member = $this->createTestUser(['tnuserid' => random_int(50_000_000, 99_999_999)]);
        $chat = $this->createTestChatRoom($poster, $member);
        Http::fake(['*/chat-messages*' => Http::response(['chat_messages' => [
            ['id' => 'w1', 'date' => '2026-11-17T10:00:00Z', 'tn_user_id' => $member->tnuserid, 'kind' => 'chat_reply', 'fd_chat_id' => $chat->id, 'text' => 'Thanks.'],
            ['id' => 'w2', 'date' => '2026-11-17T10:00:01Z', 'tn_user_id' => 1, 'kind' => 'chat_reply', 'fd_chat_id' => $chat->id, 'text' => 'Hello.'],
        ]], 200)]);

        $syncer = new ChatMessageSyncer(false, false, 'k', 'https://tn.example.invalid/fd/api', app(LokiService::class), write: true);
        $syncer->sync('2026-11-17T09:00:00Z', '2026-11-17T11:00:00Z');

        $entries = $this->routedEntries();
        $this->assertCount(2, $entries);
        $this->assertSame('tn_api', $entries[0]['labels']['source']);
        $this->assertSame('ToUser', $entries[0]['labels']['subtype']);
        $this->assertSame('w1', $entries[0]['message']['tn_message_id']);
        $this->assertSame("notify-{$chat->id}-{$member->id}@" . config('freegle.mail.user_domain'), $entries[0]['message']['envelope_to']);
        $this->assertSame('Dropped', $entries[1]['labels']['subtype']);
        $this->assertSame('tn-sender-unknown', $entries[1]['message']['routing_reason']);
        $this->assertSame('tn-sender-unknown', \App\Services\TrashNothing\Ingestion\DeliberateSkips::reasonFor('chat:w2'));

        // A window read again writes nothing and logs nothing more.
        $syncer->sync('2026-11-17T09:00:00Z', '2026-11-17T11:00:00Z');
        $this->assertCount(2, $this->routedEntries());
        $this->assertSame(1, \App\Models\ChatMessage::where('chatid', $chat->id)->count());

        $this->tearDownLokiCapture();
    }

    public function test_cut_over_kinds_come_from_config_and_ignore_unknown_names(): void
    {
        config(['freegle.trashnothing.ingest_chat_kinds' => ' chat_reply, report ,bogus']);

        $this->assertSame(['chat_reply', 'report'], ChatMessageSyncer::cutOverKinds());
    }
}

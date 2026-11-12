<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Models\ChatMessage;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\TusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * A reply held because the post has not rippled to the replier yet is held with its photos. The delivery gate reads
 * rippling_held_replies by chat message id, so a photo that has no row of its own is mailed to the poster at once.
 */
class IncomingMailPhotoRippleHoldTest extends TestCase
{
    use EmailFixtures;
    use \Tests\Support\SeedsReachCells;

    private IncomingMailService $service;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(MailParserService::class);
        $this->service = app(IncomingMailService::class);
    }

    protected function tearDown(): void
    {
        TusService::clearFake();
        parent::tearDown();
    }

    public function test_a_photo_sent_with_a_held_reply_is_held_with_it(): void
    {
        $poster = $this->createTestUser(['email_preferred' => $this->uniqueEmail('poster')]);
        // The replier is outside the post's reach (it covers about 51.5,-0.1; the replier is at 52.0,1.0).
        $replier = $this->createTestUser([
            'email_preferred' => $this->uniqueEmail('replier'),
            'lastlocation' => DB::table('locations')->insertGetId([
                'name' => 'Test Location '.uniqid(),
                'type' => 'Polygon',
                'lat' => 52.0,
                'lng' => 1.0,
            ]),
        ]);
        $group = $this->createTestGroup();
        $this->createMembership($poster, $group);
        $message = $this->createTestMessage($poster, $group);

        DB::statement('DELETE FROM rippling_reach WHERE msgid = ?', [$message->id]);
        DB::insert(
            "INSERT INTO rippling_reach (msgid, lat, lng, polygon_cells, outer_bound, arrival, mode, tick, total_ticks,
                total_freeglers, max_drive_min, schedule, rejected_groups, status, created_at, updated_at)
             VALUES (?, 51.5, -0.1, ?, ST_Envelope(ST_GeomFromText(?, 3857)), NOW(), 'drive', 1, 3, 0, 30, NULL, NULL, 'expanding', NOW(), NOW())",
            [$message->id, $this->reachCellsFor('POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))'), 'POLYGON((-0.2 51.4, 0.0 51.4, 0.0 51.6, -0.2 51.6, -0.2 51.4))']
        );

        // The routing server refuses this replier, so the reply is held.
        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'reach-eval')) {
                return null;
            }

            return Http::response([
                'results' => array_map(
                    fn ($id) => ['msgid' => (int) $id, 'verdict' => 'out'],
                    $request['msgids'] ?? []
                ),
            ]);
        });

        // The photo goes to the upload server in three steps.
        TusService::fake([
            ['status' => 201, 'headers' => ['Location' => 'http://tus.test/files/photo-hold'], 'body' => ''],
            ['status' => 200, 'headers' => [], 'body' => ''],
            ['status' => 204, 'headers' => [], 'body' => ''],
        ]);

        $replierEmail = $replier->emails->first()->email;
        $to = "replyto-{$message->id}-{$replier->id}@users.ilovefreegle.org";
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFBQIAX8jx0gAAAABJRU5ErkJggg==';
        $boundary = 'b-photo-hold';
        $body = "--{$boundary}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nI would love this item!\r\n"
            ."--{$boundary}\r\nContent-Type: image/png; name=\"pic.png\"\r\nContent-Transfer-Encoding: base64\r\n"
            ."Content-Disposition: attachment; filename=\"pic.png\"\r\n\r\n{$png}\r\n--{$boundary}--";
        $raw = $this->createMinimalEmail([
            'From' => $replierEmail,
            'To' => $to,
            'Subject' => 'Re: '.$message->subject,
            'Content-Type' => "multipart/mixed; boundary=\"{$boundary}\"",
        ], $body);

        $this->service->route($this->parser->parse($raw, $replierEmail, $to));

        $sent = DB::table('chat_messages')->where('userid', $replier->id)->orderBy('id')->get();
        $this->assertTrue($sent->contains('type', ChatMessage::TYPE_INTERESTED), 'the reply text was written');
        $this->assertTrue($sent->contains('type', ChatMessage::TYPE_IMAGE), 'the photo was written as its own chat message');

        foreach ($sent as $chatMessage) {
            $this->assertTrue(
                DB::table('rippling_held_replies')->where('chatmsgid', $chatMessage->id)->where('status', 'held')->exists(),
                "chat message {$chatMessage->id} ({$chatMessage->type}) of a held reply is held, or the poster is mailed it at once"
            );
        }
    }
}

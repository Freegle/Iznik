<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\RoutingResult;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * Regression test for the weight-stats bug: incoming group posts (e.g. the
 * TrashNothing posts that arrive by email) must create a messages_items link,
 * exactly as V1 Message::save() did. Without it, the Weight stat's
 * INNER JOIN messages_items drops the message and reports zero kg even though
 * the item was given away.
 *
 * @see https://github.com/Freegle/iznik-server include/message/Message.php (item-extraction on save)
 */
class IncomingMailItemLinkTest extends TestCase
{
    use EmailFixtures;

    private IncomingMailService $service;

    private MailParserService $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = app(MailParserService::class);
        $this->service = app(IncomingMailService::class);
    }

    private function createLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'Test Location '.uniqid(),
            'type' => 'Polygon',
            'lat' => $lat,
            'lng' => $lng,
        ]);
    }

    public function test_group_post_creates_messages_items_link(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('member')]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $userEmail = $user->emails->first()->email;
        $to = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $to,
            'Subject' => 'OFFER: Distinctive Velvet Armchair (London)',
        ], 'Free, collection only.');

        $parsed = $this->parser->parse($email, $userEmail, $to);
        $result = $this->service->route($parsed);

        $this->assertEquals(RoutingResult::APPROVED, $result);

        $msg = DB::table('messages')
            ->where('subject', 'OFFER: Distinctive Velvet Armchair (London)')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull($msg, 'group post message should be created');

        $link = DB::table('messages_items')->where('msgid', $msg->id)->first();
        $this->assertNotNull($link, 'group post must create a messages_items link for weight stats');

        $itemName = DB::table('items')->where('id', $link->itemid)->value('name');
        $this->assertSame('Distinctive Velvet Armchair', $itemName);
    }
}

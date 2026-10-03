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
 * @see the legacy V1 PHP Message::save() (item-extraction on save)
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

    /**
     * Create a partner area: the geographic area a TrashNothing post is
     * addressed to. `partner_areas.id` has no AUTO_INCREMENT, so the test
     * picks its own id, spread by pid and a per-process counter to keep it
     * clear of anything another test process is using right now.
     */
    private function createTestGroup(array $attributes = []): object
    {
        static $counter = 0;
        $counter++;
        $id = 900000000000 + (getmypid() * 100000) + $counter;

        $nameshort = $attributes['nameshort'] ?? 'testarea'.$counter.uniqid();
        $lat = $attributes['lat'] ?? 51.5;
        $lng = $attributes['lng'] ?? -0.1;

        DB::table('partner_areas')->insert([
            'id' => $id,
            'nameshort' => $nameshort,
            'namefull' => $attributes['namefull'] ?? ('Test Area '.$nameshort),
            'lat' => $lat,
            'lng' => $lng,
            'polyindex' => DB::raw("ST_GeomFromText('POINT({$lng} {$lat})', 3857)"),
        ]);

        return DB::table('partner_areas')->where('id', $id)->first();
    }

    /**
     * Posting eligibility and moderator role live on `users` now, not on a
     * per-group membership row - there is no memberships table. This applies
     * the old membership attributes straight to the user; $group is accepted
     * for call-site compatibility but otherwise unused.
     */
    private function createMembership($user, $group, array $attributes = []): void
    {
        $update = [];

        if (array_key_exists('ourPostingStatus', $attributes)) {
            $update['postingstatus'] = $attributes['ourPostingStatus'];
        }
        if (($attributes['role'] ?? null) === 'Moderator') {
            $update['systemrole'] = \App\Models\User::SYSTEMROLE_MODERATOR;
        }
        foreach (['emailfrequency', 'eventsallowed', 'volunteeringallowed'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $update[$column] = $attributes[$column];
            }
        }

        if ($update !== []) {
            DB::table('users')->where('id', $user->id)->update($update);
        }
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

        // The item link is created regardless of collection; an unmoderated member's
        // post now starts Pending (content-check gated) rather than Approved on arrival.
        $this->assertEquals(RoutingResult::PENDING, $result);

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

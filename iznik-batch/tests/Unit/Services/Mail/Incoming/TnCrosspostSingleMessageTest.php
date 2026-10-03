<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * A TrashNothing item cross-posted to N areas arrives as N separate emails, one per
 * area, all carrying the same X-Trash-Nothing-Post-Id. It is one item, so it is one
 * message: the first email to arrive creates it (see
 * IncomingMailService::createGroupPostMessage()) and every later email for the same
 * post id is a no-op, because a message now carries exactly one location and one
 * moderation state - there is no per-area copy for a second area to attach to.
 */
class TnCrosspostSingleMessageTest extends TestCase
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

    /**
     * Deliver one TN email to one area. Returns the parsed routing result.
     */
    private function deliverTnPost(object $user, object $group, string $subject, string $tnPostId): void
    {
        $userEmail = $user->emails->first()->email;
        $to = $group->nameshort.'@groups.ilovefreegle.org';

        $email = $this->createMinimalEmail([
            'From' => $userEmail,
            'To' => $to,
            'Subject' => $subject,
            'X-Trash-Nothing-Post-Id' => $tnPostId,
        ], 'Collection only please.');

        $parsed = $this->parser->parse($email, $userEmail, $to);
        $this->service->route($parsed);
    }

    public function test_tn_crosspost_to_two_areas_creates_only_one_message(): void
    {
        $areaA = $this->createTestGroup();
        $areaB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $areaA, ['ourPostingStatus' => 'DEFAULT']);
        $this->createMembership($user, $areaB, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Singular Brass Lamp (London)';

        $this->deliverTnPost($user, $areaA, $subject, $tnPostId);
        $this->deliverTnPost($user, $areaB, $subject, $tnPostId);

        $messages = DB::table('messages')
            ->where('tnpostid', $tnPostId)
            ->whereNull('deleted')
            ->get();

        $this->assertCount(
            1,
            $messages,
            'a TN item cross-posted to two areas must be ONE message, not one per area'
        );
    }

    public function test_second_tn_email_for_a_different_area_is_a_no_op(): void
    {
        $areaA = $this->createTestGroup();
        $areaB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $areaA, ['ourPostingStatus' => 'DEFAULT']);
        $this->createMembership($user, $areaB, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Singular Copper Kettle (London)';

        $this->deliverTnPost($user, $areaA, $subject, $tnPostId);

        $msgid = (int) DB::table('messages')
            ->where('tnpostid', $tnPostId)
            ->whereNull('deleted')
            ->value('id');
        $this->assertNotSame(0, $msgid);

        $historyBefore = DB::table('messages_history')->where('msgid', $msgid)->count();
        $postingsBefore = DB::table('messages_postings')->where('msgid', $msgid)->count();
        $receivedLogsBefore = DB::table('logs')
            ->where('msgid', $msgid)->where('type', 'Message')->where('subtype', 'Received')
            ->count();

        // A second area's copy of the same TrashNothing post arrives. The area is only
        // used to resolve routing on the email that creates the message; a message now
        // carries one location and one moderation state, so there is nothing left for a
        // second area to attach to, and the whole email is a no-op.
        $this->deliverTnPost($user, $areaB, $subject, $tnPostId);

        $msgids = DB::table('messages')->where('tnpostid', $tnPostId)->whereNull('deleted')->pluck('id');
        $this->assertCount(1, $msgids, 'the second area\'s email must not create a second message');
        $this->assertSame($msgid, (int) $msgids->first());

        $this->assertSame(
            $historyBefore,
            DB::table('messages_history')->where('msgid', $msgid)->count(),
            'a no-op delivery must not add another messages_history row'
        );
        $this->assertSame(
            $postingsBefore,
            DB::table('messages_postings')->where('msgid', $msgid)->count(),
            'a no-op delivery must not add another messages_postings row'
        );
        $this->assertSame(
            $receivedLogsBefore,
            DB::table('logs')->where('msgid', $msgid)->where('type', 'Message')->where('subtype', 'Received')->count(),
            'a no-op delivery must not log a second receipt'
        );
    }

    /**
     * The same TN email redelivered for the same area must be a recognised no-op. The
     * messages_groups insert used to be INSERT IGNORE for exactly that reason, but the
     * messages_history insert one line later was not, and (msgid, groupid) was unique
     * there too, so it threw 1062 and the whole attach was logged as an error - 8, 13
     * and 7 times on 2026-09-11, 09-12 and 09-13. The message is now looked up by
     * tnpostid before any row is written (see findLiveTnMessage()), so a redelivery
     * writes nothing at all rather than relying on a unique key to swallow the repeat.
     */
    public function test_the_same_tn_email_redelivered_is_a_no_op(): void
    {
        $group = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Redelivered Sushi Mat (Claygate KT10)';

        $this->deliverTnPost($user, $group, $subject, $tnPostId);
        $this->deliverTnPost($user, $group, $subject, $tnPostId);

        $msgids = DB::table('messages')
            ->where('tnpostid', $tnPostId)
            ->whereNull('deleted')
            ->pluck('id');
        $this->assertCount(1, $msgids, 'a redelivery must not create a second message');
        $msgid = (int) $msgids->first();

        // One history row, one receipt log: the second delivery added neither.
        $this->assertSame(1, DB::table('messages_history')->where('msgid', $msgid)->count());
        $this->assertSame(
            1,
            DB::table('logs')->where('msgid', $msgid)->where('type', 'Message')->where('subtype', 'Received')->count()
        );

        // Exactly one posting, from the first delivery. This is the row with no unique
        // key behind it, so the redelivery must not add a second.
        $this->assertSame(1, DB::table('messages_postings')->where('msgid', $msgid)->count());
    }

    public function test_second_tn_email_does_not_revert_the_first_areas_approved_copy(): void
    {
        // TrashNothing sends one email per area, a minute or two apart. Before a
        // message carried a single collection column, promoting the first area's copy
        // to Approved and then routing the second area's email for the same post id
        // reset collection back to Pending on every row of the message, because the
        // update was keyed on the message id alone (Discourse 10142). A message now has
        // exactly one collection value and the second email is a full no-op (see
        // findLiveTnMessage()), so there is no later write left that could revert it -
        // this asserts that stays true.
        $areaA = $this->createTestGroup();
        $areaB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $areaA, ['ourPostingStatus' => 'DEFAULT']);
        $this->createMembership($user, $areaB, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Knitting Book (London)';

        $this->deliverTnPost($user, $areaA, $subject, $tnPostId);
        $msgid = (int) DB::table('messages')->where('tnpostid', $tnPostId)->whereNull('deleted')->value('id');
        $this->assertNotSame(0, $msgid);

        // The content-check job finds the copy clean and promotes it.
        DB::table('messages')->where('id', $msgid)->update(['collection' => 'Approved', 'approvedat' => now()]);

        $this->deliverTnPost($user, $areaB, $subject, $tnPostId);

        $this->assertSame(
            'Approved',
            DB::table('messages')->where('id', $msgid)->value('collection'),
            'routing the second area\'s cross-post must not touch the message\'s collection'
        );
        $this->assertSame(
            1,
            DB::table('messages')->where('tnpostid', $tnPostId)->whereNull('deleted')->count(),
            'the second area\'s email must not create a second message'
        );
    }
}

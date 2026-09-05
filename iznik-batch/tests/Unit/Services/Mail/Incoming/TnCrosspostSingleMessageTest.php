<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * A TrashNothing item cross-posted to N groups arrives as N separate emails, one per
 * group, all carrying the same X-Trash-Nothing-Post-Id. It is one item, so it must be
 * one messages row carrying N messages_groups rows - the same shape as a Freegle-native
 * cross-post, which is what the feed, the badge counts and search expect.
 *
 * Split it into a message per email and the item shows once per copy to anyone whose
 * reach or membership covers more than one of the groups.
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
     * Deliver one TN email to one group. Returns the parsed routing result.
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

    public function test_tn_crosspost_to_two_groups_creates_one_message_on_both_groups(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $groupA, ['ourPostingStatus' => 'DEFAULT']);
        $this->createMembership($user, $groupB, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Singular Brass Lamp (London)';

        $this->deliverTnPost($user, $groupA, $subject, $tnPostId);
        $this->deliverTnPost($user, $groupB, $subject, $tnPostId);

        $messages = DB::table('messages')
            ->where('tnpostid', $tnPostId)
            ->whereNull('deleted')
            ->get();

        $this->assertCount(
            1,
            $messages,
            'a TN item cross-posted to two groups must be ONE message, not one per group'
        );

        $msgid = $messages->first()->id;

        $groupIds = DB::table('messages_groups')
            ->where('msgid', $msgid)
            ->pluck('groupid')
            ->sort()
            ->values()
            ->all();

        $expected = collect([$groupA->id, $groupB->id])->sort()->values()->all();
        $this->assertSame(
            $expected,
            $groupIds,
            'the single message must carry a messages_groups row for each group it was cross-posted to'
        );
    }

    public function test_second_tn_email_records_its_own_per_group_side_effects(): void
    {
        $groupA = $this->createTestGroup();
        $groupB = $this->createTestGroup();
        $user = $this->createTestUser(['email_preferred' => $this->uniqueEmail('tnmember')]);
        $this->createMembership($user, $groupA, ['ourPostingStatus' => 'DEFAULT']);
        $this->createMembership($user, $groupB, ['ourPostingStatus' => 'DEFAULT']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        $tnPostId = 'tn-'.uniqid();
        $subject = 'OFFER: Singular Copper Kettle (London)';

        $this->deliverTnPost($user, $groupA, $subject, $tnPostId);
        $this->deliverTnPost($user, $groupB, $subject, $tnPostId);

        $msgid = DB::table('messages')
            ->where('tnpostid', $tnPostId)
            ->whereNull('deleted')
            ->value('id');
        $this->assertNotNull($msgid);

        // Per-group rows must exist for BOTH groups: the spam-check history and the
        // receipt log are per-group, so attaching a group must still record them.
        $historyGroups = DB::table('messages_history')
            ->where('msgid', $msgid)
            ->pluck('groupid')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $expected = collect([$groupA->id, $groupB->id])->sort()->values()->all();
        $this->assertSame($expected, $historyGroups, 'messages_history must record both groups');

        // Per-message work must NOT be repeated: exactly one item link for the item.
        $itemLinks = DB::table('messages_items')->where('msgid', $msgid)->count();
        $this->assertSame(1, $itemLinks, 'the item link is per message and must not be duplicated');
    }
}

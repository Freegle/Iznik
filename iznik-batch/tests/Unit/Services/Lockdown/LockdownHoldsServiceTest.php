<?php

namespace Tests\Unit\Services\Lockdown;

use App\Models\ChatRoom;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\Lockdown\LockdownHoldsService;
use App\Services\Lockdown\LockdownService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * LockdownHoldsService (plan 2026-09-27-lockdown-switch.md, section 11.11): records what
 * the lockdown is holding so Support can browse it, and unhides held ChitChat once
 * ChitChat is lifted. Chat and post release is covered by the chat processing and content
 * check lockdown tests.
 */
class LockdownHoldsServiceTest extends TestCase
{
    private LockdownService $lockdown;

    private LockdownHoldsService $holds;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
        DB::table('spam_users')->delete();
        // The cache is a static, process-wide property (section 11.6), so a row cached
        // by an earlier test class in this same PHPUnit process would otherwise leak in.
        LockdownService::flushCache();
        $this->lockdown = new LockdownService();
        $this->holds = new LockdownHoldsService($this->lockdown);
    }

    private function makeNewsfeedPost(User $user): int
    {
        $srid = (int) config('freegle.srid', 3857);

        return (int) DB::table('newsfeed')->insertGetId([
            'type' => 'Message',
            'userid' => $user->id,
            'message' => 'Test chitchat post',
            'added' => now(),
            'timestamp' => now(),
            'hidden' => now(),
            'hiddenby' => $user->id,
            'position' => DB::raw("ST_GeomFromText('POINT(0 0)', $srid)"),
        ]);
    }

    private function holdChitChat(int $refid, int $userid): int
    {
        return (int) DB::table('lockdown_holds')->insertGetId([
            'lockdownid' => $this->lockdown->incidentId(),
            'kind' => LockdownHoldsService::KIND_CHITCHAT,
            'refid' => $refid,
            'userid' => $userid,
            'created' => now(),
        ]);
    }

    public function test_nothing_recorded_with_no_lockdown(): void
    {
        $this->assertSame(['chat' => 0, 'post' => 0], $this->holds->createHolds());
    }

    public function test_records_waiting_member_chat_but_not_chat_with_volunteers(): void
    {
        $this->lockdown->press(null, 'wave');

        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $room = $this->createTestChatRoom($member, $other);
        $modRoom = $this->createTestChatRoom($member, $mod, ['chattype' => ChatRoom::TYPE_USER2MOD]);
        $held = $this->createTestChatMessage($room, $member, ['processingrequired' => 1]);
        $this->createTestChatMessage($modRoom, $member, ['processingrequired' => 1]);
        $fromMod = $this->createTestChatMessage($this->createTestChatRoom($mod, $other), $mod, ['processingrequired' => 1]);

        $this->assertSame(1, $this->holds->createHolds()['chat']);
        $this->assertSame(0, $this->holds->createHolds()['chat'], 'a second pass records nothing new');

        $hold = DB::table('lockdown_holds')->where('kind', LockdownHoldsService::KIND_CHAT)->first();
        $this->assertEquals($held->id, $hold->refid);
        $this->assertEquals($member->id, $hold->userid);
        $this->assertNull($hold->outcome);
        $this->assertNull(DB::table('lockdown_holds')->where('refid', $fromMod->id)->first(), 'a volunteer is not held');
    }

    public function test_records_pending_posts_since_the_press(): void
    {
        $this->lockdown->press(null, 'wave');

        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $message = $this->createTestMessage($user, $group);
        DB::table('messages_groups')->where('msgid', $message->id)->update([
            'collection' => MessageGroup::COLLECTION_PENDING,
            'arrival' => now()->addSecond(),
        ]);

        $this->assertSame(1, $this->holds->createHolds()['post']);

        $hold = DB::table('lockdown_holds')->where('kind', LockdownHoldsService::KIND_POST)->first();
        $this->assertEquals($message->id, $hold->refid);
        $this->assertEquals($user->id, $hold->userid);
    }

    public function test_chitchat_stays_hidden_while_held(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();
        $nfid = $this->makeNewsfeedPost($user);
        $this->holdChitChat($nfid, $user->id);

        $this->assertSame(0, $this->holds->releaseChitChatHolds());
        $this->assertNotNull(DB::table('newsfeed')->where('id', $nfid)->value('hidden'));
    }

    public function test_all_held_chitchat_released_in_one_run_once_lifted(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();

        // More than one batch, so the release has to go round more than once.
        $count = LockdownHoldsService::RELEASE_BATCH_SIZE + 10;
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $nfid = $this->makeNewsfeedPost($user);
            $this->holdChitChat($nfid, $user->id);
        }

        $this->lockdown->setSurfaces(['chitchat' => false], null);

        $this->assertSame($count, $this->holds->releaseChitChatHolds());
        $this->assertSame(0, DB::table('newsfeed')->whereIn('id', $ids)->whereNotNull('hidden')->count());
        $this->assertSame($count, DB::table('lockdown_holds')->where('outcome', 'released')->count());
    }

    public function test_spammer_chitchat_stays_hidden_on_release(): void
    {
        $this->lockdown->press(null, 'wave');
        $spammer = $this->createTestUser();
        $genuine = $this->createTestUser();
        $spamPost = $this->makeNewsfeedPost($spammer);
        $okPost = $this->makeNewsfeedPost($genuine);
        $spamHold = $this->holdChitChat($spamPost, $spammer->id);
        $this->holdChitChat($okPost, $genuine->id);

        // Support marks the sender in Support tools while the lockdown is on.
        DB::table('spam_users')->insert(['userid' => $spammer->id, 'collection' => 'Spammer', 'reason' => 'wave']);

        $this->lockdown->setSurfaces(['chitchat' => false], null);

        $this->assertSame(1, $this->holds->releaseChitChatHolds());
        $this->assertNotNull(DB::table('newsfeed')->where('id', $spamPost)->value('hidden'), "the spammer's post stays hidden");
        $this->assertNull(DB::table('newsfeed')->where('id', $okPost)->value('hidden'));
        $this->assertSame('rejected', DB::table('lockdown_holds')->where('id', $spamHold)->value('outcome'));
    }

    public function test_chitchat_release_stops_when_held_again(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser();
        $count = LockdownHoldsService::RELEASE_BATCH_SIZE + 10;
        for ($i = 0; $i < $count; $i++) {
            $this->holdChitChat($this->makeNewsfeedPost($user), $user->id);
        }
        $this->lockdown->setSurfaces(['chitchat' => false], null);

        // Holds ChitChat again after the first batch, standing in for Support pressing
        // Hold again while the release is under way.
        $lockdown = new class extends LockdownService {
            public int $calls = 0;

            public function held(string $surface): bool
            {
                $this->calls++;
                if ($this->calls === 2) {
                    $this->setSurfaces(['chitchat' => true], null);
                }

                return parent::held($surface);
            }
        };

        $released = (new LockdownHoldsService($lockdown))->releaseChitChatHolds();

        $this->assertSame(LockdownHoldsService::RELEASE_BATCH_SIZE, $released, 'stops at the next batch once held again');
        $this->assertSame(10, DB::table('lockdown_holds')->whereNull('outcome')->count());
    }
}

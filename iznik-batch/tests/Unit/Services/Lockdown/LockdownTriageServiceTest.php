<?php

namespace Tests\Unit\Services\Lockdown;

use App\Models\ChatRoom;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\Lockdown\LockdownService;
use App\Services\Lockdown\LockdownTriageService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LockdownTriageServiceTest extends TestCase
{
    private LockdownService $lockdown;

    private LockdownTriageService $triage;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('lockdowns')->delete();
        DB::table('lockdown_holds')->delete();
        DB::table('spam_users')->delete();
        $this->lockdown = new LockdownService();
        $this->triage = new LockdownTriageService($this->lockdown);
    }

    private function markSpammer(User $user): void
    {
        DB::table('spam_users')->insert([
            'userid' => $user->id,
            'collection' => 'Spammer',
            'added' => now(),
        ]);
    }

    // --- classify() ---

    public function test_classify_spam_when_sender_marked(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser(['added' => now()->subDays(60)]);
        $this->markSpammer($user);

        $risk = $this->triage->classify('chat', $user->id, 'Hello there', now());
        $this->assertSame(LockdownTriageService::RISK_SPAM, $risk);
    }

    public function test_classify_spam_new_account_with_link(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser(['added' => now()->subMinutes(2)]);

        $risk = $this->triage->classify('chat', $user->id, 'Check this out https://example.com/voucher', now());
        $this->assertSame(LockdownTriageService::RISK_SPAM, $risk);
    }

    public function test_classify_spam_new_account_matching_incident_phrase(): void
    {
        $this->lockdown->press(null, 'wave');
        $this->lockdown->setPhrases(['gift card'], null);
        $user = $this->createTestUser(['added' => now()->subMinutes(2)]);

        $risk = $this->triage->classify('chat', $user->id, 'Send me a gift card please', now());
        $this->assertSame(LockdownTriageService::RISK_SPAM, $risk);
    }

    public function test_classify_new_account_without_link_or_phrase_is_risky_not_spam(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser(['added' => now()->subMinutes(2)]);

        $risk = $this->triage->classify('chat', $user->id, 'Is this still available', now());
        $this->assertSame(LockdownTriageService::RISK_RISKY, $risk);
    }

    public function test_classify_spam_cluster_of_five_with_a_marked_spammer(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $spammer = $this->createTestUser(['added' => now()->subDays(90)]);
        $this->markSpammer($spammer);

        $line = "Congratulations you have won\nclaim it here";
        for ($i = 0; $i < 4; $i++) {
            $sender = $this->createTestUser(['added' => now()->subDays(90)]);
            $this->createTestChatMessage($room, $sender, ['message' => $line, 'date' => now()]);
        }
        $this->createTestChatMessage($room, $spammer, ['message' => $line, 'date' => now()]);

        $established = $this->createTestUser(['added' => now()->subDays(90)]);
        $risk = $this->triage->classify('chat', $established->id, $line, now());
        $this->assertSame(LockdownTriageService::RISK_SPAM, $risk);
    }

    public function test_classify_small_cluster_with_a_spammer_is_not_enough(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $spammer = $this->createTestUser(['added' => now()->subDays(90)]);
        $this->markSpammer($spammer);

        $line = 'Just three messages the same';
        for ($i = 0; $i < 2; $i++) {
            $sender = $this->createTestUser(['added' => now()->subDays(90)]);
            $this->createTestChatMessage($room, $sender, ['message' => $line, 'date' => now()]);
        }
        $this->createTestChatMessage($room, $spammer, ['message' => $line, 'date' => now()]);

        $established = $this->createTestUser(['added' => now()->subDays(90)]);
        $risk = $this->triage->classify('chat', $established->id, $line, now());
        $this->assertNotSame(LockdownTriageService::RISK_SPAM, $risk);
    }

    public function test_classify_low_established_account_with_earlier_activity(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $user = $this->createTestUser(['added' => now()->subDays(90)]);
        $this->createTestChatMessage($room, $user, ['message' => 'An earlier message', 'date' => now()->subDays(10)]);

        $risk = $this->triage->classify('chat', $user->id, 'Is this still available', now());
        $this->assertSame(LockdownTriageService::RISK_LOW, $risk);
    }

    public function test_classify_risky_established_account_without_earlier_activity(): void
    {
        $this->lockdown->press(null, 'wave');
        $user = $this->createTestUser(['added' => now()->subDays(90)]);

        $risk = $this->triage->classify('chat', $user->id, 'Is this still available', now());
        $this->assertSame(LockdownTriageService::RISK_RISKY, $risk);
    }

    public function test_classify_low_account_with_link_is_not_low(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $user = $this->createTestUser(['added' => now()->subDays(90)]);
        $this->createTestChatMessage($room, $user, ['message' => 'An earlier message', 'date' => now()->subDays(10)]);

        $risk = $this->triage->classify('chat', $user->id, 'See www.example.com', now());
        $this->assertNotSame(LockdownTriageService::RISK_LOW, $risk);
    }

    public function test_classify_high_rate_against_own_history_is_not_low(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $user = $this->createTestUser(['added' => now()->subDays(90)]);
        $this->createTestChatMessage($room, $user, ['message' => 'An earlier message', 'date' => now()->subDays(80)]);
        for ($i = 0; $i < 20; $i++) {
            $this->createTestChatMessage($room, $user, ['message' => "burst $i", 'date' => now()->subMinutes(5)]);
        }

        $risk = $this->triage->classify('chat', $user->id, 'one more in the burst', now());
        $this->assertNotSame(LockdownTriageService::RISK_LOW, $risk);
    }

    // --- createHolds() ---

    public function test_create_holds_for_user2user_messages_since_started_excluding_mods(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $member = $this->createTestUser();
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $held = $this->createTestChatMessage($room, $member, ['processingrequired' => 1, 'date' => now()]);
        $this->createTestChatMessage($room, $mod, ['processingrequired' => 1, 'date' => now()]);
        $this->createTestChatMessage($room, $member, ['processingrequired' => 0, 'date' => now()]);

        $before = $this->createTestChatMessage($room, $member, ['processingrequired' => 1, 'date' => now()->subDay()]);

        $stats = $this->triage->createHolds();

        $this->assertSame(1, $stats['chat']);
        $this->assertSame(1, DB::table('lockdown_holds')->where('kind', 'chat')->where('refid', $held->id)->count());
        $this->assertSame(0, DB::table('lockdown_holds')->where('kind', 'chat')->where('refid', $before->id)->count());
    }

    public function test_create_holds_for_pending_posts_since_started_excluding_moderator_posters(): void
    {
        $this->lockdown->press(null, 'wave');
        $group = $this->createTestGroup();
        $member = $this->createTestUser();
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $pending = $this->createTestMessage($member, $group, ['arrival' => now()]);
        DB::table('messages_groups')->where('msgid', $pending->id)->update(['collection' => MessageGroup::COLLECTION_PENDING]);

        $modPost = $this->createTestMessage($mod, $group, ['arrival' => now()]);
        DB::table('messages_groups')->where('msgid', $modPost->id)->update(['collection' => MessageGroup::COLLECTION_PENDING]);

        $approved = $this->createTestMessage($member, $group, ['arrival' => now()]);

        $stats = $this->triage->createHolds();

        $this->assertSame(1, $stats['post']);
        $this->assertSame(1, DB::table('lockdown_holds')->where('kind', 'post')->where('refid', $pending->id)->count());
        $this->assertSame(0, DB::table('lockdown_holds')->where('kind', 'post')->where('refid', $approved->id)->count());
    }

    public function test_create_holds_is_idempotent(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $this->createTestChatMessage($room, $this->createTestUser(), ['processingrequired' => 1, 'date' => now()]);

        $this->triage->createHolds();
        $this->triage->createHolds();

        $this->assertSame(1, DB::table('lockdown_holds')->where('kind', 'chat')->count());
    }

    // --- classifyPending() ---

    public function test_classify_pending_assigns_risk_to_unclassified_holds(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $spammer = $this->createTestUser();
        $this->markSpammer($spammer);
        $message = $this->createTestChatMessage($room, $spammer, ['processingrequired' => 1, 'date' => now()]);
        $this->triage->createHolds();

        $stats = $this->triage->classifyPending();

        $this->assertSame(1, $stats['classified']);
        $hold = DB::table('lockdown_holds')->where('kind', 'chat')->where('refid', $message->id)->first();
        $this->assertSame(LockdownTriageService::RISK_SPAM, $hold->risk);
    }

    public function test_classify_pending_leaves_already_classified_holds_alone(): void
    {
        $this->lockdown->press(null, 'wave');
        $room = $this->createTestChatRoom($this->createTestUser(), $this->createTestUser());
        $message = $this->createTestChatMessage($room, $this->createTestUser(), ['processingrequired' => 1, 'date' => now()]);
        $this->triage->createHolds();
        DB::table('lockdown_holds')->where('kind', 'chat')->where('refid', $message->id)->update(['risk' => LockdownTriageService::RISK_RISKY, 'outcome' => 'review']);

        $stats = $this->triage->classifyPending();

        $this->assertSame(0, $stats['classified']);
    }
}

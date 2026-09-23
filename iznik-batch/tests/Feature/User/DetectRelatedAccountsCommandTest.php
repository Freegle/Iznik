<?php

namespace Tests\Feature\User;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * users:detect-related links accounts that gave the SAME contact details in chat.
 *
 * The rules are deliberately narrow, because the raw signals are not. Measured on
 * production: 751 distinct mobile numbers were sent by two or more accounts during 2026
 * alone, and matching on postcode alone would produce about 3,100 pairs a year, most of
 * them neighbours rather than duplicates. Hand classification of a random sample showed the
 * overwhelming majority of the surviving pairs really are one person or one household - a
 * real name on one account and a handle on the other - which is exactly what Related
 * Members is for. These tests pin the exclusions that keep the rest out.
 */
class DetectRelatedAccountsCommandTest extends TestCase
{
    private function newChat(int $userid, ?int $otherid = null): int
    {
        return (int) DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User',
            'user1' => $userid,
            'user2' => $otherid ?? $this->createTestUser()->id,
        ]);
    }

    private function say(int $userid, string $body, ?int $chatid = null, string $date = '-2 days'): int
    {
        $chatid ??= $this->newChat($userid);

        DB::table('chat_messages')->insert([
            'chatid' => $chatid,
            'userid' => $userid,
            'message' => $body,
            'date' => date('Y-m-d H:i:s', strtotime($date)),
        ]);

        return $chatid;
    }

    private function pairFor(int $a, int $b): object|null
    {
        return DB::table('users_related')
            ->where('user1', min($a, $b))
            ->where('user2', max($a, $b))
            ->first();
    }

    // ---------------------------------------------------------------- mobile numbers

    public function test_links_two_accounts_that_sent_the_same_mobile_number(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'Happy to collect tomorrow, my number is 07700900123. Thanks!');
        $this->say($b->id, 'Yes please - you can reach me on 07700 900 123 any time.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $pair = $this->pairFor($a->id, $b->id);
        $this->assertNotNull($pair, 'Accounts sharing a mobile number should be linked');
        $this->assertSame('Auto', $pair->detected);
    }

    public function test_reason_tells_the_moderator_what_the_evidence_was(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'My number is 07700900456 if that helps.', null, '-5 days');
        $this->say($b->id, 'Call me on 07700900456 when you set off.', null, '-1 day');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $pair = $this->pairFor($a->id, $b->id);
        $this->assertNotNull($pair);

        // The mod needs to know WHY, not just that something matched.
        $this->assertStringContainsString('mobile number', $pair->reason);
        // Masked: the full number adds nothing a mod cannot get from the chat itself.
        $this->assertStringContainsString('0456', $pair->reason);
        $this->assertStringNotContainsString('07700900456', $pair->reason);
        // Both accounts named, so the mod can tell which side did what.
        $this->assertStringContainsString('#' . $a->id, $pair->reason);
        $this->assertStringContainsString('#' . $b->id, $pair->reason);
        // When it happened, so the mod can judge without opening the chats.
        $this->assertMatchesRegularExpression('/\d{1,2} \w{3} \d{4}/', $pair->reason);
        $this->assertLessThanOrEqual(255, strlen($pair->reason));
    }

    public function test_ignores_a_number_the_sender_says_belongs_to_someone_else(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'My number is 07700900789, see you then.');
        // Real production example: the sender is handing on a third party's number.
        $this->say($b->id, "Hi that's great, my friend Neil is collecting it. His number is 07700900789. Thank you!");

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id),
            'A number the sender attributes to someone else must not link the accounts');
    }

    public function test_ignores_details_circulated_between_many_accounts(): void
    {
        // Details appearing across lots of accounts belong to an organisation, or are being
        // passed around - not evidence that two people are the same person.
        $users = [];
        for ($i = 0; $i < 6; $i++) {
            $u = $this->createTestUser();
            $users[] = $u;
            $this->say($u->id, 'You can ring 07700900999 about it.');
        }

        $this->artisan('users:detect-related --days=30 --max-accounts=4')->assertExitCode(0);

        $this->assertNull($this->pairFor($users[0]->id, $users[1]->id));
    }

    public function test_ignores_landlines_and_other_digit_strings(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        // Not mobiles: a flat number, a time, and a landline. None should link anyone.
        $this->say($a->id, 'Flat 0770090, collect at 10.30, landline 01315550123.');
        $this->say($b->id, 'Flat 0770090, collect at 10.30, landline 01315550123.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id));
    }

    // ---------------------------------------------------------------- addresses

    public function test_links_two_accounts_that_gave_the_same_street_address(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'You can collect from 61 Jackson Road, Oxford OX2 7TS any time.');
        $this->say($b->id, 'I am at 61 Jackson Road, OX2 7TS - just knock.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $pair = $this->pairFor($a->id, $b->id);
        $this->assertNotNull($pair, 'Accounts giving the same street address should be linked');
        $this->assertStringContainsString('61 Jackson Road', $pair->reason);
        $this->assertStringContainsString('OX2 7TS', $pair->reason);
    }

    public function test_does_not_link_neighbours_who_merely_share_a_postcode(): void
    {
        // A UK unit postcode covers around fifteen addresses. Production has 138 and 140
        // Coulston Road both on LA1 3AB: two households, not one person.
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, "it's 138 Coulston Road LA1 3AB. please text before you come!");
        $this->say($b->id, 'Hi my address is 140 Coulston Road, Lancaster, LA1 3AB.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id),
            'Sharing a postcode is not sharing an address');
    }

    public function test_does_not_link_the_same_street_name_in_a_different_town(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'Collect from 12 High Street, OX2 7TS.');
        $this->say($b->id, 'Collect from 12 High Street, LA1 3AB.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id));
    }

    // ---------------------------------------------------------------- shared exclusions

    public function test_ignores_details_repeated_back_within_the_same_chat(): void
    {
        // The offerer gives an address, the collector repeats it to confirm. That tells us
        // nothing about who they are.
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $chatid = $this->newChat($a->id, $b->id);
        $this->say($a->id, 'Collect from 61 Jackson Road, OX2 7TS.', $chatid);
        $this->say($b->id, 'Great - 61 Jackson Road, OX2 7TS, see you at 3.', $chatid);

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id),
            'Quoting the other person back must not link the accounts');
    }

    public function test_does_not_link_volunteers(): void
    {
        // Volunteers hand their details out as part of the role, so them recurring across
        // accounts is expected rather than suspicious.
        $mod = $this->createTestUser(['systemrole' => 'Moderator']);
        $member = $this->createTestUser();

        $this->say($mod->id, 'Any problems, ring me on 07700900222.');
        $this->say($member->id, 'Thanks, my number is 07700900222.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($mod->id, $member->id));
    }

    public function test_does_not_duplicate_a_pair_already_recorded_in_either_direction(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        // Already found by the browser-session detector, stored the other way round.
        DB::table('users_related')->insert([
            'user1' => max($a->id, $b->id),
            'user2' => min($a->id, $b->id),
            'detected' => 'Auto',
        ]);

        $this->say($a->id, 'My number is 07700900333.');
        $this->say($b->id, 'Mine is 07700900333.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $count = DB::table('users_related')
            ->whereIn('user1', [$a->id, $b->id])
            ->whereIn('user2', [$a->id, $b->id])
            ->count();
        $this->assertSame(1, $count, 'Should not add a second row for a pair already known');
    }

    public function test_dry_run_writes_nothing(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'Ring 07700900555 please.');
        $this->say($b->id, 'My mobile is 07700900555.');

        $this->artisan('users:detect-related --days=30 --dry-run')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id));
    }

    public function test_note_says_when_the_pair_also_replied_to_the_same_post(): void
    {
        // This is what separates an ordinary duplicate from somebody putting themselves
        // forward twice for the same item, so the mod should be told.
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $offerer = $this->createTestUser();
        $group = $this->createTestGroup();
        $post = $this->createTestMessage($offerer, $group);

        $chatA = $this->newChat($a->id, $offerer->id);
        $chatB = $this->newChat($b->id, $offerer->id);

        DB::table('chat_messages')->insert([
            ['chatid' => $chatA, 'userid' => $a->id, 'message' => 'May I have this? 07700900888',
             'refmsgid' => $post->id, 'date' => date('Y-m-d H:i:s', strtotime('-3 days'))],
            ['chatid' => $chatB, 'userid' => $b->id, 'message' => 'Please may I have this? 07700900888',
             'refmsgid' => $post->id, 'date' => date('Y-m-d H:i:s', strtotime('-2 days'))],
        ]);

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $pair = $this->pairFor($a->id, $b->id);
        $this->assertNotNull($pair);
        $this->assertStringContainsString('both replied to the same post', $pair->reason);
        $this->assertLessThanOrEqual(255, strlen($pair->reason));
    }

    public function test_note_leaves_out_shared_posts_when_there_are_none(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'My number is 07700900444.');
        $this->say($b->id, 'Mine is 07700900444.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $pair = $this->pairFor($a->id, $b->id);
        $this->assertNotNull($pair);
        $this->assertStringNotContainsString('same post', $pair->reason);
    }

    public function test_ignores_a_postcode_with_no_street_address(): void
    {
        // Two people saying which area they are in. Without a house number there is no
        // reason to think they live together.
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, "I'm over in OX2 7TS if that's not too far.");
        $this->say($b->id, 'Happy to collect, I am near OX2 7TS.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id));
    }

    public function test_stops_at_the_limit(): void
    {
        // The cap is a safety valve for a first run over a long window.
        $a = $this->createTestUser();
        $b = $this->createTestUser();
        $c = $this->createTestUser();
        $d = $this->createTestUser();

        $this->say($a->id, 'My number is 07700900111.');
        $this->say($b->id, 'Mine too, 07700900111.');
        $this->say($c->id, 'My number is 07700900222.');
        $this->say($d->id, 'Mine too, 07700900222.');

        $this->artisan('users:detect-related --days=30 --limit=1')->assertExitCode(0);

        $made = DB::table('users_related')
            ->whereIn('user1', [$a->id, $b->id, $c->id, $d->id])
            ->whereIn('user2', [$a->id, $b->id, $c->id, $d->id])
            ->count();
        $this->assertSame(1, $made, 'Should stop once the limit is reached');
    }

    public function test_ignores_details_only_one_account_ever_sent(): void
    {
        $a = $this->createTestUser();
        $b = $this->createTestUser();

        $this->say($a->id, 'My number is 07700900777.');
        $this->say($a->id, 'Again, 07700900777.');
        $this->say($b->id, 'No contact details here at all.');

        $this->artisan('users:detect-related --days=30')->assertExitCode(0);

        $this->assertNull($this->pairFor($a->id, $b->id));
    }
}

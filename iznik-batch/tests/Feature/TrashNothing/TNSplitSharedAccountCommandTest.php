<?php

namespace Tests\Feature\TrashNothing;

use App\Models\ChatRoom;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * tn:split-shared-account moves one Trash Nothing member out of a Freegle account that holds two.
 *
 * The fixture has the shape of the real cases: two TN usernames on one account, each with
 * per-community aliases and posts, memberships that belong to one or both, and chats that the
 * evidence settles one way, the other, or not at all.
 */
class TNSplitSharedAccountCommandTest extends TestCase
{
    private const TN_USER_ID_BASE = 990500000;

    private array $f = [];

    private string $rollbackFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rollbackFile = sys_get_temp_dir() . '/tn-split-test-' . uniqid('', true) . '.sql';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->rollbackFile)) {
            unlink($this->rollbackFile);
        }
        parent::tearDown();
    }

    public function test_dry_run_reports_the_split_and_writes_nothing(): void
    {
        $f = $this->buildSharedAccount();
        $before = $this->snapshot();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('users_emails: 3 to move')
            ->expectsOutputToContain('messages: 2 to move')
            ->expectsOutputToContain('memberships: 2 to move, 1 to copy, 1 to stay, 1 with no evidence')
            ->expectsOutputToContain('chat_rooms: 2 to move, 1 to stay, 1 undecided')
            ->expectsOutputToContain("chat {$f['chatZ']}: both members could have written in it")
            ->expectsOutputToContain('Dry run: nothing written')
            ->assertExitCode(0);

        $this->assertSame($before, $this->snapshot(), 'a dry run changes nothing');
        $this->assertNull(User::where('tnuserid', $f['tnUserId'])->first());
    }

    public function test_dry_run_output_names_nobody(): void
    {
        $f = $this->buildSharedAccount();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->doesntExpectOutputToContain($f['moveName'])
            ->doesntExpectOutputToContain($f['otherName'])
            ->doesntExpectOutputToContain('@')
            ->assertExitCode(0);
    }

    public function test_commit_moves_exactly_the_evidenced_rows(): void
    {
        $f = $this->buildSharedAccount();
        $this->commit($f);

        $new = User::where('tnuserid', $f['tnUserId'])->first();
        $this->assertNotNull($new, 'a new account holds the TN user id');
        $a = $f['account'];
        $n = $new->id;

        // Addresses: the username's three, and the preferred one moves with a new preferred left behind.
        $this->assertEqualsCanonicalizing($f['moveAliases'], DB::table('users_emails')->where('userid', $n)->pluck('id')->all());
        $this->assertSame(1, (int) DB::table('users_emails')->where('userid', $n)->sum('preferred'));
        $this->assertSame(1, (int) DB::table('users_emails')->where('userid', $a)->sum('preferred'));
        $this->assertTrue(DB::table('users_emails')->where('id', $f['otherAliasG1'])->value('preferred') == 1, 'the other member keeps a preferred address');

        // Posts by sender address.
        $this->assertEqualsCanonicalizing([$f['pMove1'], $f['pMove2']], DB::table('messages')->where('fromuser', $n)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$f['pOther1'], $f['pOther3']], DB::table('messages')->where('fromuser', $a)->pluck('id')->all());

        // Memberships: g2 and g4 move, g1 is copied, g3 and g5 stay.
        $this->assertSame($n, (int) DB::table('memberships')->where('id', $f['memG2'])->value('userid'));
        $this->assertSame($n, (int) DB::table('memberships')->where('id', $f['memG4'])->value('userid'));
        $this->assertSame($a, (int) DB::table('memberships')->where('id', $f['memG1'])->value('userid'));
        $copy = DB::table('memberships')->where('userid', $n)->where('groupid', $f['g1'])->first();
        $this->assertNotNull($copy, 'a community both belonged to is copied');
        $this->assertSame('2017-08-02 09:45:44', \Carbon\Carbon::parse($copy->added, 'UTC')->format('Y-m-d H:i:s'), 'dated when the moved alias for it first reached us');
        $this->assertSame(Membership::ROLE_MEMBER, $copy->role);
        $this->assertSame($a, (int) DB::table('memberships')->where('id', $f['memG3'])->value('userid'));
        $this->assertSame($a, (int) DB::table('memberships')->where('id', $f['memG5'])->value('userid'));

        // History by join time.
        $this->assertSame($n, (int) DB::table('memberships_history')->where('id', $f['histG2'])->value('userid'));
        $this->assertSame($a, (int) DB::table('memberships_history')->where('id', $f['histG3'])->value('userid'));

        // Chats: X (on a moved post) and Y (a reply on a community only the moved member was in)
        // move with their rosters and the account's messages; W stays; Z is undecided and stays.
        $this->assertSame($n, (int) DB::table('chat_rooms')->where('id', $f['chatX'])->value('user2'));
        $this->assertSame($n, (int) DB::table('chat_rooms')->where('id', $f['chatY'])->value('user1'));
        $this->assertSame($a, (int) DB::table('chat_rooms')->where('id', $f['chatW'])->value('user1'));
        $this->assertSame($a, (int) DB::table('chat_rooms')->where('id', $f['chatZ'])->value('user1'));
        foreach ([$f['chatX'], $f['chatY']] as $chat) {
            $this->assertSame(0, DB::table('chat_messages')->where('chatid', $chat)->where('userid', $a)->count());
            $this->assertSame(0, DB::table('chat_roster')->where('chatid', $chat)->where('userid', $a)->count());
            $this->assertSame(1, DB::table('chat_roster')->where('chatid', $chat)->where('userid', $n)->count());
        }
        $this->assertSame(1, DB::table('chat_messages')->where('chatid', $f['chatZ'])->where('userid', $a)->count());

        // Rows that follow a moved chat.
        $this->assertSame($n, (int) DB::table('messages_promises')->where('id', $f['promiseY'])->value('userid'));
        $this->assertSame($a, (int) DB::table('messages_promises')->where('id', $f['promiseZ'])->value('userid'));
        $this->assertSame($n, (int) DB::table('messages_by')->where('id', $f['byY'])->value('userid'));
        $this->assertSame($n, (int) DB::table('ratings')->where('id', $f['ratingY'])->value('ratee'));
        $this->assertSame($a, (int) DB::table('ratings')->where('id', $f['ratingW'])->value('ratee'));
        $this->assertSame($n, (int) DB::table('trysts')->where('id', $f['trystY'])->value('user2'));
        $this->assertSame($n, (int) DB::table('users_expected')->where('id', $f['expectedY'])->value('expectee'));
        $this->assertSame($a, (int) DB::table('users_expected')->where('id', $f['expectedW'])->value('expectee'));

        // Logs: the moved post's, the join at the moved alias's time, and the edit made on a moved post.
        $this->assertSame($n, (int) DB::table('logs')->where('id', $f['logPost'])->value('user'));
        $this->assertSame($n, (int) DB::table('logs')->where('id', $f['logJoin'])->value('user'));
        $this->assertSame($n, (int) DB::table('logs')->where('id', $f['logBy'])->value('byuser'));
        $this->assertSame($a, (int) DB::table('logs')->where('id', $f['logOther'])->value('user'));

        // A split log on each account.
        $this->assertSame(1, DB::table('logs')->where('type', 'User')->where('subtype', 'Split')->where('user', $n)->count());
        $this->assertSame(1, DB::table('logs')->where('type', 'User')->where('subtype', 'Split')->where('user', $a)->count());
        $this->assertFileExists($this->rollbackFile);
    }

    public function test_an_undecided_chat_moves_only_when_named(): void
    {
        $f = $this->buildSharedAccount();
        $this->commit($f, ['--move-chats' => (string) $f['chatZ']]);

        $n = (int) User::where('tnuserid', $f['tnUserId'])->value('id');
        $this->assertSame($n, (int) DB::table('chat_rooms')->where('id', $f['chatZ'])->value('user1'));
        $this->assertSame(0, DB::table('chat_messages')->where('chatid', $f['chatZ'])->where('userid', $f['account'])->count());
        $this->assertSame($n, (int) DB::table('messages_promises')->where('id', $f['promiseZ'])->value('userid'));
    }

    public function test_naming_a_chat_that_is_not_undecided_is_refused(): void
    {
        $f = $this->buildSharedAccount();
        $before = $this->snapshot();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--commit' => true,
            '--move-chats' => (string) $f['chatW'],
            '--rollback-file' => $this->rollbackFile,
        ])
            ->expectsOutputToContain("chat {$f['chatW']} is not undecided")
            ->assertExitCode(1);

        $this->assertSame($before, $this->snapshot());
    }

    public function test_refuses_when_the_account_does_not_hold_the_username(): void
    {
        $f = $this->buildSharedAccount();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => 'nobody' . str_replace('.', '', uniqid('', true)),
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('does not hold that TN username')
            ->assertExitCode(1);
    }

    public function test_refuses_when_another_account_holds_the_tn_user_id(): void
    {
        $f = $this->buildSharedAccount();
        $this->createTestUser(['tnuserid' => $f['tnUserId']]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('already held by account')
            ->assertExitCode(1);
    }

    public function test_refuses_when_the_tn_user_id_is_the_accounts_own(): void
    {
        $f = $this->buildSharedAccount();
        DB::table('users')->where('id', $f['account'])->update(['tnuserid' => $f['tnUserId']]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain("is this account's own")
            ->assertExitCode(1);
    }

    public function test_refuses_when_the_username_is_the_accounts_only_one(): void
    {
        $f = $this->buildSharedAccount();
        DB::table('users_emails')->whereIn('id', $f['otherAliases'])->delete();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('only TN username')
            ->assertExitCode(1);
    }

    public function test_the_rollback_file_restores_the_original_state(): void
    {
        $f = $this->buildSharedAccount();
        $before = $this->snapshot();

        $this->commit($f);
        $this->assertNotSame($before, $this->snapshot());

        // The file wraps its statements in a transaction of its own; the test runs inside one already.
        $sql = collect(file($this->rollbackFile, FILE_IGNORE_NEW_LINES))
            ->reject(fn ($line) => in_array(trim($line), ['START TRANSACTION;', 'COMMIT;'], true))
            ->implode("\n");
        DB::unprepared($sql);

        $this->assertSame($before, $this->snapshot(), 'the rollback puts every row back');
        $this->assertNull(User::where('tnuserid', $f['tnUserId'])->first(), 'and removes the new account');
    }

    public function test_a_second_run_finds_nothing_to_move(): void
    {
        $f = $this->buildSharedAccount();
        $this->commit($f);
        $after = $this->snapshot();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--commit' => true,
            '--rollback-file' => $this->rollbackFile . '.second',
        ])
            ->expectsOutputToContain('Already split')
            ->assertExitCode(0);

        $this->assertSame($after, $this->snapshot());
        $this->assertSame(1, User::where('tnuserid', $f['tnUserId'])->count());
        $this->assertFileDoesNotExist($this->rollbackFile . '.second');
    }

    /**
     * A member can have an account of their own before anyone splits them out: the posts API makes
     * one at their first post, and a partner call at their next join. The split moves what is theirs
     * onto it.
     */
    public function test_moves_the_member_onto_the_account_they_already_have(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $preferred = DB::table('users_emails')->where('userid', $own)->where('preferred', 1)->value('id');

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain("to account {$own}, which already holds TN user")
            ->expectsOutputToContain('Dry run: nothing written')
            ->assertExitCode(0);

        $this->commit($f);

        $this->assertSame(1, User::where('tnuserid', $f['tnUserId'])->count(), 'no second account');
        foreach ($f['moveAliases'] as $id) {
            $this->assertSame($own, (int) DB::table('users_emails')->where('id', $id)->value('userid'));
        }
        $this->assertSame($preferred, DB::table('users_emails')->where('userid', $own)->where('preferred', 1)->value('id'), 'their preferred address stays');
        $this->assertSame(1, (int) DB::table('users_emails')->where('userid', $own)->sum('preferred'));
        $this->assertSame($own, (int) DB::table('messages')->where('id', $f['pMove1'])->value('fromuser'));
        $this->assertSame($own, (int) DB::table('memberships')->where('id', $f['memG2'])->value('userid'));
        $this->assertSame(1, DB::table('memberships')->where('userid', $own)->where('groupid', $f['g1'])->count(), 'the shared community is copied');
        $this->assertSame($f['account'], (int) DB::table('messages')->where('id', $f['pOther1'])->value('fromuser'));
    }

    /** Partner calls move addresses one at a time, and move nothing else, so posts are left behind. */
    public function test_a_split_after_every_alias_has_moved_still_moves_the_rest(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        DB::table('users_emails')->whereIn('id', $f['moveAliases'])->update(['userid' => $own, 'preferred' => 0]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->doesntExpectOutputToContain('Already split')
            ->expectsOutputToContain('messages: 2 to move')
            ->assertExitCode(0);

        $this->commit($f);

        $this->assertSame($own, (int) DB::table('messages')->where('id', $f['pMove2'])->value('fromuser'));
        $this->assertSame($own, (int) DB::table('memberships')->where('id', $f['memG2'])->value('userid'));
        $this->assertSame($own, (int) DB::table('memberships_history')->where('id', $f['histG2'])->value('userid'));
    }

    public function test_a_second_run_onto_the_account_they_already_have_finds_nothing_to_move(): void
    {
        $f = $this->buildSharedAccount();
        $this->accountTheyAlreadyHave($f);
        $this->commit($f);
        $after = $this->snapshot();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--commit' => true,
            '--rollback-file' => $this->rollbackFile . '.second',
        ])
            ->expectsOutputToContain('Already split')
            ->assertExitCode(0);

        $this->assertSame($after, $this->snapshot());
        $this->assertFileDoesNotExist($this->rollbackFile . '.second');
    }

    /** An address move elsewhere can take the account's preferred address when nothing else is left to move. */
    public function test_a_run_with_only_a_preferred_address_to_fix_is_not_already_split(): void
    {
        $f = $this->buildSharedAccount();
        $this->accountTheyAlreadyHave($f);
        $this->commit($f);
        DB::table('users_emails')->where('userid', $f['account'])->update(['preferred' => 0]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--commit' => true,
            '--rollback-file' => $this->rollbackFile . '.second',
        ])
            ->doesntExpectOutputToContain('Already split')
            ->assertExitCode(0);

        $this->assertSame(1, (int) DB::table('users_emails')->where('userid', $f['account'])->sum('preferred'));
        $this->assertSame($f['otherAliasG1'], (int) DB::table('users_emails')->where('userid', $f['account'])->where('preferred', 1)->value('id'), 'the latest TN address left is preferred');
    }

    public function test_the_rollback_keeps_the_account_they_already_have(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $before = $this->snapshot();

        $this->commit($f);
        $this->assertNotSame($before, $this->snapshot());

        $sql = collect(file($this->rollbackFile, FILE_IGNORE_NEW_LINES))
            ->reject(fn ($line) => in_array(trim($line), ['START TRANSACTION;', 'COMMIT;'], true))
            ->implode("\n");
        DB::unprepared($sql);

        $this->assertSame($before, $this->snapshot(), 'the rollback puts every row back');
        $this->assertSame($own, (int) User::where('tnuserid', $f['tnUserId'])->value('id'));
    }

    /** memberships has a unique key on the pair, so the row cannot move. It must not stop the split. */
    public function test_a_membership_the_member_already_has_stays_and_is_reported(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $this->createMembership(User::find($own), Group::find($f['groups'][1]));

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('Already on the account they have, so staying: memberships ' . $f['memG2'])
            ->assertExitCode(0);

        $this->commit($f);

        $this->assertSame($f['account'], (int) DB::table('memberships')->where('id', $f['memG2'])->value('userid'));
        $this->assertSame($own, (int) DB::table('messages')->where('id', $f['pMove2'])->value('fromuser'), 'the rest of the split goes ahead');
    }

    /** chat_rooms has a unique key on the pair too, and a person has two chats to read, not one. */
    public function test_a_chat_with_someone_the_member_already_chats_with_stays_and_is_reported(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $other = (int) DB::table('chat_rooms')->where('id', $f['chatX'])->value('user1');
        ChatRoom::create(['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $own, 'user2' => $other, 'created' => now()]);

        $this->commit($f);

        $this->assertSame($f['account'], (int) DB::table('chat_rooms')->where('id', $f['chatX'])->value('user2'));
    }

    public function test_naming_a_chat_the_member_already_has_is_refused(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $other = (int) DB::table('chat_rooms')->where('id', $f['chatZ'])->value('user2');
        ChatRoom::create(['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $other, 'user2' => $own, 'created' => now()]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--move-chats' => (string) $f['chatZ'],
        ])
            ->expectsOutputToContain('is not undecided')
            ->assertExitCode(1);
    }

    /** ratings has a unique key on the pair as well, and a clash would abort the whole split. */
    public function test_a_rating_the_member_already_has_stays(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        $rater = (int) DB::table('ratings')->where('id', $f['ratingY'])->value('rater');
        DB::table('ratings')->insert(['rater' => $rater, 'ratee' => $own, 'rating' => 'Up', 'timestamp' => now()]);

        $this->commit($f);

        $this->assertSame($f['account'], (int) DB::table('ratings')->where('id', $f['ratingY'])->value('ratee'));
    }

    public function test_refuses_when_a_deleted_account_holds_the_tn_user_id(): void
    {
        $f = $this->buildSharedAccount();
        $own = $this->accountTheyAlreadyHave($f);
        DB::table('users')->where('id', $own)->update(['deleted' => now()]);

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('deleted account')
            ->assertExitCode(1);
    }

    /** The account's own username, so this would be moving a whole account: that is a merge. */
    public function test_refuses_when_the_username_is_the_accounts_only_one_and_they_have_an_account(): void
    {
        $f = $this->buildSharedAccount();
        $this->accountTheyAlreadyHave($f);
        DB::table('users_emails')->whereIn('id', $f['otherAliases'])->delete();

        $this->artisan('tn:split-shared-account', [
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
        ])
            ->expectsOutputToContain('only TN username')
            ->assertExitCode(1);
    }

    /** @return int the account, holding the member's TN id and their bare address */
    private function accountTheyAlreadyHave(array $f): int
    {
        $own = $this->createTestUser(['fullname' => 'Their Own Account', 'tnuserid' => $f['tnUserId']]);
        $email = "{$f['moveName']}@user.trashnothing.com";
        DB::table('users_emails')->insert([
            'userid' => $own->id, 'email' => $email, 'backwards' => strrev($email), 'preferred' => 0, 'added' => now(),
        ]);

        return (int) $own->id;
    }

    private function commit(array $f, array $options = []): void
    {
        $this->artisan('tn:split-shared-account', array_merge([
            'userid' => $f['account'],
            'username' => $f['moveName'],
            'tnuserid' => $f['tnUserId'],
            '--commit' => true,
            '--rollback-file' => $this->rollbackFile,
        ], $options))->assertExitCode(0);
    }

    /**
     * Every row the fixture made, as it is now, so a test can say "nothing changed" or "all
     * put back" exactly. Excludes the split's own log rows and the new account, which the
     * callers check directly.
     */
    private function snapshot(): array
    {
        $f = $this->f;
        $users = [$f['account']];
        $out = [];
        $out['users_emails'] = DB::table('users_emails')->whereIn('userid', $users)->orWhereIn('id', array_merge($f['moveAliases'], $f['otherAliases']))->orderBy('id')->get(['id', 'userid', 'preferred'])->toArray();
        $out['messages'] = DB::table('messages')->whereIn('id', [$f['pMove1'], $f['pMove2'], $f['pOther1'], $f['pOther3']])->orderBy('id')->get(['id', 'fromuser'])->toArray();
        $out['memberships'] = DB::table('memberships')->whereIn('groupid', $f['groups'])->orderBy('id')->get(['id', 'userid', 'groupid', 'added'])->toArray();
        $out['memberships_history'] = DB::table('memberships_history')->whereIn('id', [$f['histG2'], $f['histG3']])->orderBy('id')->get(['id', 'userid'])->toArray();
        $chats = [$f['chatX'], $f['chatY'], $f['chatZ'], $f['chatW']];
        $out['chat_rooms'] = DB::table('chat_rooms')->whereIn('id', $chats)->orderBy('id')->get(['id', 'user1', 'user2'])->toArray();
        $out['chat_roster'] = DB::table('chat_roster')->whereIn('chatid', $chats)->orderBy('id')->get(['id', 'chatid', 'userid'])->toArray();
        $out['chat_messages'] = DB::table('chat_messages')->whereIn('chatid', $chats)->orderBy('id')->get(['id', 'userid'])->toArray();
        $out['messages_promises'] = DB::table('messages_promises')->whereIn('id', [$f['promiseY'], $f['promiseZ']])->orderBy('id')->get(['id', 'userid'])->toArray();
        $out['messages_by'] = DB::table('messages_by')->whereIn('id', [$f['byY']])->get(['id', 'userid'])->toArray();
        $out['ratings'] = DB::table('ratings')->whereIn('id', [$f['ratingY'], $f['ratingW']])->orderBy('id')->get(['id', 'rater', 'ratee'])->toArray();
        $out['trysts'] = DB::table('trysts')->whereIn('id', [$f['trystY']])->get(['id', 'user1', 'user2'])->toArray();
        $out['users_expected'] = DB::table('users_expected')->whereIn('id', [$f['expectedY'], $f['expectedW']])->orderBy('id')->get(['id', 'expecter', 'expectee'])->toArray();
        $out['logs'] = DB::table('logs')->whereIn('id', [$f['logPost'], $f['logJoin'], $f['logBy'], $f['logOther']])->orderBy('id')->get(['id', 'user', 'byuser'])->toArray();
        $out['split_logs'] = DB::table('logs')->where('type', 'User')->where('subtype', 'Split')->whereIn('user', $users)->count();

        return json_decode(json_encode($out), true);
    }

    /**
     * One account, two TN usernames:
     * - the one to move out has aliases for communities g1, g2 and g4, posts on g1 and g2,
     *   and joined g4 without posting;
     * - the other has aliases for g1 (from 2019) and g3, and posts on both;
     * - g5 is a membership nothing ties to either.
     * Chats: X replied to a moved post; the account replied to Y on g2 in 2018 (only the
     * moved member could have), to W on g3 (only the other could have), and to Z on g1 in
     * 2022, when both had an alias for g1.
     */
    private function buildSharedAccount(): array
    {
        $u = str_replace('.', '', uniqid('', true));
        $moveName = 'lx' . $u;
        $otherName = 'jx' . $u;
        $tnUserId = self::TN_USER_ID_BASE + random_int(1, 9999999);

        $account = $this->createTestUser(['fullname' => 'Test User']);
        DB::table('users_emails')->where('userid', $account->id)->update(['preferred' => 0]);

        $g = [];
        foreach (['g1', 'g2', 'g3', 'g4', 'g5'] as $key) {
            $g[$key] = $this->createTestGroup();
        }

        $alias = function (string $name, string $suffix, string $added, int $preferred = 0) use ($account): int {
            $email = "{$name}-{$suffix}@user.trashnothing.com";

            return DB::table('users_emails')->insertGetId([
                'userid' => $account->id, 'email' => $email, 'backwards' => strrev($email),
                'preferred' => $preferred, 'added' => $added,
            ]);
        };
        $moveG1 = $alias($moveName, 'g101', '2017-08-02 09:45:44', 1);
        $moveG2 = $alias($moveName, 'g202', '2017-08-02 09:45:45');
        $moveG4 = $alias($moveName, 'g404', '2017-08-02 09:46:30');
        $otherG3 = $alias($otherName, 'g303', '2017-09-13 19:02:02');
        $otherG1 = $alias($otherName, 'g101', '2019-01-10 10:00:00');

        $post = function (User $user, Group $group, string $at, ?string $fromaddr = null) {
            return $this->createTestMessage($user, $group, ['arrival' => $at, 'date' => $at, 'fromaddr' => $fromaddr])->id;
        };
        $pMove1 = $post($account, $g['g1'], '2018-01-01 10:00:00', "{$moveName}-g101@user.trashnothing.com");
        $pMove2 = $post($account, $g['g2'], '2018-02-01 10:00:00', "{$moveName}-g202@user.trashnothing.com");
        $pOther1 = $post($account, $g['g1'], '2021-03-01 10:00:00', "{$otherName}-g101@user.trashnothing.com");
        $pOther3 = $post($account, $g['g3'], '2018-05-01 10:00:00', "{$otherName}-g303@user.trashnothing.com");

        $mem = fn (Group $group, string $added) => $this->createMembership($account, $group, ['added' => $added])->id;
        $memG1 = $mem($g['g1'], '2017-08-02 09:45:44');
        $memG2 = $mem($g['g2'], '2017-08-02 09:45:45');
        $memG3 = $mem($g['g3'], '2017-09-13 19:02:02');
        $memG4 = $mem($g['g4'], '2017-08-02 09:46:30');
        $memG5 = $mem($g['g5'], '2016-01-01 00:00:00');

        $hist = fn (Group $group, string $added) => DB::table('memberships_history')->insertGetId([
            'userid' => $account->id, 'groupid' => $group->id, 'collection' => 'Approved', 'added' => $added,
        ]);
        $histG2 = $hist($g['g2'], '2017-08-02 09:45:45');
        $histG3 = $hist($g['g3'], '2017-09-13 19:02:02');

        $x = $this->createTestUser();
        $y = $this->createTestUser();
        $z = $this->createTestUser();
        $w = $this->createTestUser();
        $yPost = $post($y, $g['g2'], '2018-03-01 10:00:00');
        $zPost = $post($z, $g['g1'], '2022-06-01 10:00:00');
        $wPost = $post($w, $g['g3'], '2018-06-01 10:00:00');

        $chat = function (User $user1, int $user2) {
            $room = ChatRoom::create(['chattype' => ChatRoom::TYPE_USER2USER, 'user1' => $user1->id, 'user2' => $user2, 'created' => now()]);
            foreach ([$user1->id, $user2] as $member) {
                DB::table('chat_roster')->insert(['chatid' => $room->id, 'userid' => $member]);
            }

            return $room;
        };
        $msg = fn (ChatRoom $room, int $userid, string $at, ?int $ref = null) => DB::table('chat_messages')->insertGetId([
            'chatid' => $room->id, 'userid' => $userid, 'message' => 'Test message', 'type' => 'Default',
            'refmsgid' => $ref, 'date' => $at, 'processingsuccessful' => 1,
        ]);

        $chatX = $chat($x, $account->id);
        $msg($chatX, $x->id, '2018-02-02 10:00:00', $pMove2);
        $msg($chatX, $account->id, '2018-02-03 10:00:00');

        $accountUser = User::find($account->id);
        $chatY = $chat($accountUser, $y->id);
        $msg($chatY, $account->id, '2018-03-02 10:00:00', $yPost);
        $yMsg = $msg($chatY, $y->id, '2018-03-03 10:00:00');

        $chatZ = $chat($accountUser, $z->id);
        $msg($chatZ, $account->id, '2022-06-02 10:00:00', $zPost);

        $chatW = $chat($accountUser, $w->id);
        $wMsg = $msg($chatW, $account->id, '2018-06-02 10:00:00', $wPost);

        $promiseY = DB::table('messages_promises')->insertGetId(['msgid' => $yPost, 'userid' => $account->id, 'promisedat' => '2018-03-04 10:00:00']);
        $promiseZ = DB::table('messages_promises')->insertGetId(['msgid' => $zPost, 'userid' => $account->id, 'promisedat' => '2022-06-03 10:00:00']);
        $byY = DB::table('messages_by')->insertGetId(['msgid' => $yPost, 'userid' => $account->id, 'timestamp' => '2018-03-05 10:00:00', 'count' => 1]);
        $ratingY = DB::table('ratings')->insertGetId(['rater' => $y->id, 'ratee' => $account->id, 'rating' => 'Up', 'timestamp' => '2018-03-06 10:00:00']);
        $ratingW = DB::table('ratings')->insertGetId(['rater' => $w->id, 'ratee' => $account->id, 'rating' => 'Up', 'timestamp' => '2018-06-03 10:00:00']);
        $trystY = DB::table('trysts')->insertGetId(['user1' => $y->id, 'user2' => $account->id, 'arrangedfor' => '2018-03-07 10:00:00']);
        $expectedY = DB::table('users_expected')->insertGetId(['expecter' => $y->id, 'expectee' => $account->id, 'chatmsgid' => $yMsg, 'value' => 1]);
        $expectedW = DB::table('users_expected')->insertGetId(['expecter' => $w->id, 'expectee' => $account->id, 'chatmsgid' => $wMsg, 'value' => 1]);

        $log = fn (array $row) => DB::table('logs')->insertGetId($row);
        $logPost = $log(['timestamp' => '2018-01-01 10:00:00', 'type' => 'Message', 'subtype' => 'Received', 'user' => $account->id, 'msgid' => $pMove1, 'groupid' => $g['g1']->id]);
        $logJoin = $log(['timestamp' => '2017-08-02 09:45:45', 'type' => 'Group', 'subtype' => 'Joined', 'user' => $account->id, 'groupid' => $g['g2']->id]);
        $logBy = $log(['timestamp' => '2018-02-01 11:00:00', 'type' => 'Message', 'subtype' => 'Edit', 'user' => $x->id, 'byuser' => $account->id, 'msgid' => $pMove2]);
        $logOther = $log(['timestamp' => '2021-03-01 10:00:00', 'type' => 'Message', 'subtype' => 'Received', 'user' => $account->id, 'msgid' => $pOther1, 'groupid' => $g['g1']->id]);

        return $this->f = [
            'account' => $account->id, 'moveName' => $moveName, 'otherName' => $otherName, 'tnUserId' => $tnUserId,
            'moveAliases' => [$moveG1, $moveG2, $moveG4], 'otherAliases' => [$otherG3, $otherG1], 'otherAliasG1' => $otherG1,
            'g1' => $g['g1']->id, 'groups' => array_map(fn ($group) => $group->id, array_values($g)),
            'pMove1' => $pMove1, 'pMove2' => $pMove2, 'pOther1' => $pOther1, 'pOther3' => $pOther3,
            'memG1' => $memG1, 'memG2' => $memG2, 'memG3' => $memG3, 'memG4' => $memG4, 'memG5' => $memG5,
            'histG2' => $histG2, 'histG3' => $histG3,
            'chatX' => $chatX->id, 'chatY' => $chatY->id, 'chatZ' => $chatZ->id, 'chatW' => $chatW->id,
            'promiseY' => $promiseY, 'promiseZ' => $promiseZ, 'byY' => $byY, 'ratingY' => $ratingY, 'ratingW' => $ratingW,
            'trystY' => $trystY, 'expectedY' => $expectedY, 'expectedW' => $expectedW,
            'logPost' => $logPost, 'logJoin' => $logJoin, 'logBy' => $logBy, 'logOther' => $logOther,
        ];
    }
}

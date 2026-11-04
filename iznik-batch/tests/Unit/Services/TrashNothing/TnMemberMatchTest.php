<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\User;
use App\Services\TrashNothing\Sync\TnMemberMatch;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Which account TN means by the two ids a feed row gives for a member. What to do about the
 * answer is the feed's own business, and is tested with each syncer.
 */
class TnMemberMatchTest extends TestCase
{
    private function userWithTnId(?int $tnUserId): User
    {
        $user = $this->createTestUser();

        if ($tnUserId !== null) {
            $user->tnuserid = $tnUserId;
            $user->save();
        }

        return $user;
    }

    public function test_both_ids_pointing_at_one_account_give_that_account(): void
    {
        $user = $this->userWithTnId(99150001);

        $match = TnMemberMatch::find($user->id, 99150001);

        $this->assertSame($user->id, $match->account()?->id);
        $this->assertFalse($match->heldElsewhere());
    }

    public function test_tn_id_alone_gives_the_account_holding_it(): void
    {
        $user = $this->userWithTnId(99150002);

        $this->assertSame($user->id, TnMemberMatch::find(0, 99150002)->account()?->id);
    }

    /** With no TN id to compare, the account's own TN id says nothing about the row. */
    public function test_our_id_alone_gives_the_account_it_names(): void
    {
        $user = $this->userWithTnId(99150003);

        $this->assertSame($user->id, TnMemberMatch::find($user->id, 0)->account()?->id);
    }

    public function test_an_account_that_has_gone_gives_way_to_the_holder(): void
    {
        $holder = $this->userWithTnId(99150004);
        $gone = (int) DB::table('users')->max('id') + 100000;

        $match = TnMemberMatch::find($gone, 99150004);

        $this->assertNull($match->named);
        $this->assertSame($holder->id, $match->account()?->id);
    }

    public function test_an_account_holding_another_tn_id_gives_way_to_the_holder(): void
    {
        $named = $this->userWithTnId(99150005);
        $holder = $this->userWithTnId(99150006);

        $match = TnMemberMatch::find($named->id, 99150006);

        $this->assertSame($holder->id, $match->account()?->id);
        $this->assertFalse($match->heldElsewhere());
    }

    /** Nobody holds this TN id, and the account named is someone else's: no account is meant. */
    public function test_an_account_holding_another_tn_id_is_not_used_when_nobody_holds_this_one(): void
    {
        $named = $this->userWithTnId(99150007);

        $match = TnMemberMatch::find($named->id, 99150008);

        $this->assertNull($match->account());
        $this->assertSame($named->id, $match->named?->id);
    }

    public function test_an_account_with_no_tn_id_is_used_when_nobody_holds_this_one(): void
    {
        $named = $this->userWithTnId(null);

        $match = TnMemberMatch::find($named->id, 99150009);

        $this->assertSame($named->id, $match->account()?->id);
        $this->assertFalse($match->heldElsewhere());
    }

    /** It could be a twin of the holder, so the caller is told as well as given the holder. */
    public function test_an_account_with_no_tn_id_gives_way_to_the_holder_and_says_so(): void
    {
        $named = $this->userWithTnId(null);
        $holder = $this->userWithTnId(99150010);

        $match = TnMemberMatch::find($named->id, 99150010);

        $this->assertSame($holder->id, $match->account()?->id);
        $this->assertTrue($match->heldElsewhere());
    }

    public function test_no_ids_mean_no_account(): void
    {
        $this->assertNull(TnMemberMatch::find(0, 0)->account());
    }
}

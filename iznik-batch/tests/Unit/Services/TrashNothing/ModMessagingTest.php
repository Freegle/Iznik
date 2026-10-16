<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\TrashNothing\ModMessaging;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The per-community half of ModMessaging: whether someone's only tie to ONE community is
 * TN posts placed there without their choosing. That is what ingestion's silent join
 * stands for, so it is barred from what a rippled membership is barred from.
 */
class ModMessagingTest extends TestCase
{
    public function test_only_unaddressed_posts_on_the_community_make_it_unaddressed(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createPost($user, $group, 0);

        $this->assertTrue(ModMessaging::isUnaddressedOn($user->id, $group->id));
    }

    public function test_an_addressed_post_on_the_same_community_is_a_real_tie(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createPost($user, $group, 0);
        $this->createPost($user, $group, 1);

        $this->assertFalse(ModMessaging::isUnaddressedOn($user->id, $group->id));
    }

    public function test_an_addressed_post_elsewhere_does_not_make_this_community_chosen(): void
    {
        // A "mixed" poster is a real member where they chose to be, and only there.
        $user = $this->createTestUser();
        $placed = $this->createTestGroup();
        $chosen = $this->createTestGroup();
        $this->createPost($user, $placed, 0);
        $this->createPost($user, $chosen, 1);

        $this->assertTrue(ModMessaging::isUnaddressedOn($user->id, $placed->id));
        $this->assertFalse(ModMessaging::isUnaddressedOn($user->id, $chosen->id));
        $this->assertFalse(ModMessaging::userIsUnaddressedOnly($user->id));
    }

    public function test_no_posts_on_the_community_is_not_unaddressed(): void
    {
        // An ordinary member who has never posted there is unrestricted.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createPost($user, $this->createTestGroup(), 0);

        $this->assertFalse(ModMessaging::isUnaddressedOn($user->id, $group->id));
    }

    public function test_a_rippled_in_copy_is_not_read_as_a_post_there(): void
    {
        // Rippled copies take the column default (allowed). Reading them would make every
        // community an unaddressed post rippled into look chosen - and the reverse is
        // also wrong: the copy is not the poster's tie to that community at all.
        $user = $this->createTestUser();
        $origin = $this->createTestGroup();
        $rippledInto = $this->createTestGroup();
        $msgId = $this->createPost($user, $origin, 0);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $rippledInto->id,
            'collection' => 'Approved',
            'rippled_in' => 1,
            'mod_messaging_allowed' => 1,
        ]);

        $this->assertFalse(ModMessaging::isUnaddressedOn($user->id, $rippledInto->id));
        $this->assertTrue(ModMessaging::isUnaddressedOn($user->id, $origin->id));
    }

    public function test_a_deleted_unaddressed_post_still_counts(): void
    {
        // As userIsUnaddressedOnly: a post taken down has not made its poster contactable.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $msgId = $this->createPost($user, $group, 0);
        DB::table('messages')->where('id', $msgId)->update(['deleted' => now()]);
        DB::table('messages_groups')->where('msgid', $msgId)->update(['deleted' => 1]);

        $this->assertTrue(ModMessaging::isUnaddressedOn($user->id, $group->id));
    }

    public function test_missing_ids_read_as_unrestricted(): void
    {
        $this->assertFalse(ModMessaging::isUnaddressedOn(0, 1));
        $this->assertFalse(ModMessaging::isUnaddressedOn(1, 0));
        $this->assertFalse(ModMessaging::isUnaddressedOn(-1, -1));
    }

    public function test_the_query_scope_agrees_with_the_method_inside_a_membership_query(): void
    {
        // Mailers filter members with the scope; it must drop exactly the memberships
        // the method calls unaddressed, keyed on each row's own user and group.
        $group = $this->createTestGroup();
        $placed = $this->createTestUser();
        $chose = $this->createTestUser();
        $neverPosted = $this->createTestUser();
        $this->createPost($placed, $group, 0);
        $this->createPost($chose, $group, 0);
        $this->createPost($chose, $group, 1);
        foreach ([$placed, $chose, $neverPosted] as $user) {
            $this->createMembership($user, $group);
        }

        $kept = ModMessaging::excludeUnaddressedMemberships(
            DB::table('memberships')->where('groupid', $group->id)
        )
            ->pluck('userid')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = [(int) $chose->id, (int) $neverPosted->id];
        sort($expected);
        $this->assertSame($expected, $kept);
    }

    /** A post by $user whose origin row on $group carries the given mod_messaging_allowed. */
    private function createPost($user, $group, int $allowed): int
    {
        $msgId = DB::table('messages')->insertGetId([
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test item ' . uniqid(),
            'date' => now(),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgId,
            'groupid' => $group->id,
            'collection' => 'Approved',
            'rippled_in' => 0,
            'mod_messaging_allowed' => $allowed,
        ]);

        return $msgId;
    }
}

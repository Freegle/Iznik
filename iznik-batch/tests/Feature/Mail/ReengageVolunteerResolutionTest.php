<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Services\ReengageContentService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The onboarding tip sign-off must come from the member's HOME community - the
 * one whose catchment actually contains where they live - not merely the group
 * whose stored centre is nearest. Centre distance is the Bristol problem: a big
 * catchment's centre can sit far from much of its own area, so a member well
 * inside it can be closer to a smaller neighbour's centre and get signed off by
 * the wrong community.
 *
 * These tests pin the containment-first behaviour and the fallbacks, exercising
 * the real MySQL spatial functions (ST_Contains on groups.polyindex, SRID 3857).
 */
class ReengageVolunteerResolutionTest extends TestCase
{
    private const SRID = 3857;

    /** Give a group a real catchment polygon (WKT is "lng lat", SRID 3857). */
    private function setCatchment(int $groupId, string $polygonWkt): void
    {
        DB::statement(
            'UPDATE `groups` SET polyindex = ST_GeomFromText(?, ?) WHERE id = ?',
            [$polygonWkt, self::SRID, $groupId]
        );
    }

    private function createGroupAt(float $lat, float $lng): Group
    {
        return Group::create([
            'nameshort' => 'TestVol_' . uniqid(),
            'type'      => Group::TYPE_FREEGLE,
            'publish'   => 1,
            'onmap'     => 1,
            'onhere'    => 1,
            'lat'       => $lat,
            'lng'       => $lng,
        ]);
    }

    /** A member who genuinely joined (Approved, not rippled), located at lat/lng. */
    private function createMemberAt(int $groupId, float $lat, float $lng): User
    {
        $user = $this->createTestUser(['lastaccess' => now()]);
        DB::table('memberships')->insert([
            'userid'     => $user->id,
            'groupid'    => $groupId,
            'role'       => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'rippled'    => 0,
            'added'      => now(),
        ]);
        DB::table('users')->where('id', $user->id)->update([
            'settings' => json_encode(['mylocation' => ['lat' => $lat, 'lng' => $lng]]),
        ]);

        return $user->fresh();
    }

    /** Join an existing user to another group (Approved, not rippled). */
    private function joinGroup(int $userId, int $groupId): void
    {
        DB::table('memberships')->insert([
            'userid'     => $userId,
            'groupid'    => $groupId,
            'role'       => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'rippled'    => 0,
            'added'      => now(),
        ]);
    }

    /** An eligible sign-off volunteer: active, shown, Moderator/Owner of the group. */
    private function addModerator(int $groupId, string $firstname): void
    {
        $mod = $this->createTestUser(['firstname' => $firstname, 'fullname' => $firstname, 'lastaccess' => now()]);
        DB::table('memberships')->insert([
            'userid'     => $mod->id,
            'groupid'    => $groupId,
            'role'       => Membership::ROLE_MODERATOR,
            'collection' => Membership::COLLECTION_APPROVED,
            'added'      => now(),
        ]);
    }

    public function test_home_catchment_beats_a_nearer_group_centre(): void
    {
        // Member lives here (Bristol-ish).
        $lat = 51.45;
        $lng = -2.60;

        // HOME: catchment contains the member, but its centre is ~28km east.
        $home = $this->createGroupAt(51.45, -2.20);
        $this->setCatchment(
            $home->id,
            'POLYGON((-2.75 51.35, -2.45 51.35, -2.45 51.55, -2.75 51.55, -2.75 51.35))'
        );
        $this->addModerator($home->id, 'Home');

        // NEAR: centre almost on top of the member, but its catchment is far away
        // and does NOT contain them. Centre distance alone would pick this group.
        $near = $this->createGroupAt(51.45, -2.61);
        $this->setCatchment(
            $near->id,
            'POLYGON((-2.00 51.00, -1.90 51.00, -1.90 51.10, -2.00 51.10, -2.00 51.00))'
        );
        $this->addModerator($near->id, 'Near');

        $member = $this->createMemberAt($home->id, $lat, $lng);
        $this->joinGroup($member->id, $near->id);

        $volunteer = (new ReengageContentService())->resolveVolunteer($member);

        $this->assertNotNull($volunteer);
        $this->assertSame($home->id, $volunteer['groupid'], 'should sign off from the containing catchment, not the nearer centre');
        $this->assertSame('home', $volunteer['source']);
        $this->assertSame('Home', $volunteer['name']);
    }

    public function test_smallest_catchment_wins_when_several_contain_the_member(): void
    {
        // Member sits inside BOTH catchments; the smaller (more local) one should
        // sign off. This is the overlap tie-break, distinct from the containment-
        // vs-centre case above.
        $lat = 51.45;
        $lng = -2.60;

        // Large catchment containing the member (~0.6 x 0.5 degrees).
        $big = $this->createGroupAt(51.45, -2.60);
        $this->setCatchment(
            $big->id,
            'POLYGON((-2.90 51.20, -2.30 51.20, -2.30 51.70, -2.90 51.70, -2.90 51.20))'
        );
        $this->addModerator($big->id, 'Big');

        // Small catchment also containing the member (~0.1 x 0.1 degrees).
        $small = $this->createGroupAt(51.45, -2.60);
        $this->setCatchment(
            $small->id,
            'POLYGON((-2.65 51.40, -2.55 51.40, -2.55 51.50, -2.65 51.50, -2.65 51.40))'
        );
        $this->addModerator($small->id, 'Small');

        $member = $this->createMemberAt($big->id, $lat, $lng);
        $this->joinGroup($member->id, $small->id);

        $volunteer = (new ReengageContentService())->resolveVolunteer($member);

        $this->assertNotNull($volunteer);
        $this->assertSame($small->id, $volunteer['groupid'], 'the smaller containing catchment is the more local home group');
        $this->assertSame('home', $volunteer['source']);
    }

    public function test_falls_back_to_nearest_centre_when_no_catchment_contains(): void
    {
        $lat = 51.45;
        $lng = -2.60;

        // Neither group's catchment contains the member; the nearer centre wins.
        $far = $this->createGroupAt(53.48, -2.24); // Manchester-ish, far
        $this->setCatchment(
            $far->id,
            'POLYGON((-2.40 53.30, -2.00 53.30, -2.00 53.60, -2.40 53.60, -2.40 53.30))'
        );
        $this->addModerator($far->id, 'Far');

        $nearer = $this->createGroupAt(51.50, -2.55); // closer to the member
        $this->setCatchment(
            $nearer->id,
            'POLYGON((-2.00 51.00, -1.90 51.00, -1.90 51.10, -2.00 51.10, -2.00 51.00))'
        );
        $this->addModerator($nearer->id, 'Nearer');

        $member = $this->createMemberAt($far->id, $lat, $lng);
        $this->joinGroup($member->id, $nearer->id);

        $volunteer = (new ReengageContentService())->resolveVolunteer($member);

        $this->assertNotNull($volunteer);
        $this->assertSame($nearer->id, $volunteer['groupid']);
        $this->assertSame('nearest', $volunteer['source']);
    }

    public function test_source_is_unknown_when_member_has_no_location(): void
    {
        $group = $this->createGroupAt(51.45, -2.20);
        $this->setCatchment(
            $group->id,
            'POLYGON((-2.75 51.35, -2.45 51.35, -2.45 51.55, -2.75 51.55, -2.75 51.35))'
        );
        $this->addModerator($group->id, 'Anyone');

        // Member of the group but with no resolvable location.
        $member = $this->createTestUser(['lastaccess' => now()]);
        DB::table('memberships')->insert([
            'userid'     => $member->id,
            'groupid'    => $group->id,
            'role'       => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'rippled'    => 0,
            'added'      => now(),
        ]);

        $volunteer = (new ReengageContentService())->resolveVolunteer($member->fresh());

        $this->assertNotNull($volunteer);
        $this->assertSame('unknown', $volunteer['source']);
        $this->assertSame($group->id, $volunteer['groupid']);
    }

    public function test_rippled_membership_is_never_a_sign_off_community(): void
    {
        $lat = 51.45;
        $lng = -2.60;

        // The member's real, genuinely-joined group.
        $real = $this->createGroupAt(51.45, -2.20);
        $this->setCatchment(
            $real->id,
            'POLYGON((-2.75 51.35, -2.45 51.35, -2.45 51.55, -2.75 51.55, -2.75 51.35))'
        );
        $this->addModerator($real->id, 'Real');

        $member = $this->createMemberAt($real->id, $lat, $lng);

        // A Rippling-Out auto-join whose catchment also contains the member and
        // whose centre is nearer - it must be ignored because rippled = 1.
        $rippled = $this->createGroupAt(51.45, -2.60);
        $this->setCatchment(
            $rippled->id,
            'POLYGON((-2.70 51.40, -2.50 51.40, -2.50 51.50, -2.70 51.50, -2.70 51.40))'
        );
        $this->addModerator($rippled->id, 'Rippled');
        DB::table('memberships')->insert([
            'userid'     => $member->id,
            'groupid'    => $rippled->id,
            'role'       => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'rippled'    => 1,
            'added'      => now(),
        ]);

        $volunteer = (new ReengageContentService())->resolveVolunteer($member);

        $this->assertNotNull($volunteer);
        $this->assertSame($real->id, $volunteer['groupid'], 'a rippled-in group must never sign off');
    }
}

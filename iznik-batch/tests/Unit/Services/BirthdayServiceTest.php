<?php

namespace Tests\Unit\Services;

use App\Services\BirthdayService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BirthdayServiceTest extends TestCase
{
    /**
     * A Rippling Out auto-join (memberships.rippled = 1) must never make the member a birthday-
     * appeal recipient - the appeal would appear to come "from" a community they never knowingly
     * joined. Only the genuine member is counted.
     */
    public function test_rippled_members_are_excluded_from_birthday_appeals(): void
    {
        $service = new BirthdayService();

        // A group whose birthday is today (founded same month-day, a year ago).
        $group = $this->createTestGroup();
        DB::table('groups')->where('id', $group->id)->update([
            'type' => 'Freegle',
            'publish' => 1,
            'onmap' => 1,
            'founded' => now()->subYear()->format('Y-m-d H:i:s'),
        ]);

        $genuine = $this->makeBirthdayRecipient();
        $rippled = $this->makeBirthdayRecipient();

        // Genuine member (rippled=0) and a rippled auto-join (rippled=1).
        DB::table('memberships')->insert([
            'userid' => $genuine->id, 'groupid' => $group->id,
            'role' => 'Member', 'collection' => 'Approved', 'rippled' => 0, 'added' => now(),
        ]);
        DB::table('memberships')->insert([
            'userid' => $rippled->id, 'groupid' => $group->id,
            'role' => 'Member', 'collection' => 'Approved', 'rippled' => 1, 'added' => now(),
        ]);

        $count = $service->sendBirthdayEmails(null, [$group->id], dryRun: true);

        $this->assertSame(1, $count, 'only the genuine member receives the birthday appeal');
    }

    /**
     * The volunteer list must match the Go API's group volunteer query, which has
     * no lastaccess filter. lastaccess only updates on web/app login, so a mod who
     * moderates purely by email must still appear in the birthday email's volunteer
     * line (an Oxford mod was silently dropped every year by a lastaccess filter).
     */
    public function test_email_only_mod_included_in_birthday_volunteers(): void
    {
        $group = $this->createTestGroup();

        $webMod = $this->makeBirthdayVolunteer($group->id, 'Web Mod', now());
        $emailOnlyMod = $this->makeBirthdayVolunteer($group->id, 'Email Mod', now()->subYears(2));
        $hiddenMod = $this->makeBirthdayVolunteer($group->id, 'Hidden Mod', now());
        DB::table('users')->where('id', $hiddenMod->id)->update([
            'settings' => json_encode(['showmod' => false]),
        ]);

        $method = new \ReflectionMethod(BirthdayService::class, 'getActiveVolunteers');
        $volunteers = $method->invoke(new BirthdayService(), $group->id);

        $names = array_column($volunteers, 'displayname');
        $this->assertContains('Web Mod', $names);
        $this->assertContains('Email Mod', $names, 'mod who only moderates by email must be included');
        $this->assertNotContains('Hidden Mod', $names, 'showmod=false must still be respected');
    }

    /** A moderator on the group with the given display name and lastaccess. */
    private function makeBirthdayVolunteer(int $groupId, string $name, $lastaccess): object
    {
        $user = $this->createTestUser();
        DB::table('users')->where('id', $user->id)->update([
            'fullname' => $name,
            'deleted' => null,
            'lastaccess' => $lastaccess,
        ]);
        DB::table('memberships')->insert([
            'userid' => $user->id, 'groupid' => $groupId,
            'role' => 'Moderator', 'collection' => 'Approved', 'added' => now(),
        ]);

        return $user;
    }

    /** A user who passes every birthday gate: consenting, active, contactable, no recent appeal. */
    private function makeBirthdayRecipient(): object
    {
        $user = $this->createTestUser();
        $this->createTestUserEmail($user);
        DB::table('users')->where('id', $user->id)->update([
            'marketingconsent' => 1,
            'bouncing' => 0,
            'deleted' => null,
            'onholidaytill' => null,
            'lastaccess' => now(),
            'settings' => json_encode(['notifications' => ['email' => true]]),
        ]);

        return $user;
    }
}

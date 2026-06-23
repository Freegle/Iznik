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

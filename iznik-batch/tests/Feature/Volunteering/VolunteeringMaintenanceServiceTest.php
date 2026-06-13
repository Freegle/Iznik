<?php

namespace Tests\Feature\Volunteering;

use App\Mail\Volunteering\VolunteeringRenewMail;
use App\Services\VolunteeringMaintenanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Covers VolunteeringMaintenanceService, ported from iznik-server
 * Volunteering::askRenew() and Volunteering::expire().
 */
class VolunteeringMaintenanceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The service queries volunteering / users globally. Hide any rows that
        // leak from parallel test classes by deleting inside this test's
        // transaction (rolled back on tearDown). Mirrors VolunteeringDigestCommandTest.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['volunteering_dates', 'volunteering_images', 'volunteering_groups', 'volunteering', 'memberships', 'users_emails', 'users', 'groups'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    private function service(): VolunteeringMaintenanceService
    {
        return app(VolunteeringMaintenanceService::class);
    }

    /**
     * Insert a volunteering opportunity. Defaults to a dateless opportunity in
     * the renewal-ask window (added 25 days ago, never renewed or asked).
     */
    private function makeOpportunity(array $attrs = []): int
    {
        return DB::table('volunteering')->insertGetId(array_merge([
            'title' => 'Dateless Opportunity',
            'location' => 'Community Centre',
            'description' => 'Ongoing help needed.',
            'pending' => 0,
            'deleted' => 0,
            'expired' => 0,
            'renewed' => null,
            'askedtorenew' => null,
            'added' => now()->subDays(25),
        ], $attrs));
    }

    private function addEndDate(int $volId, string $end): void
    {
        DB::table('volunteering_dates')->insert([
            'volunteeringid' => $volId,
            'end' => $end,
        ]);
    }

    // ---- askRenew() -------------------------------------------------------

    public function test_asks_owner_of_dateless_opportunity_past_ask_age(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $id = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25)]);

        $count = $this->service()->askRenew();

        $this->assertSame(1, $count);
        Mail::assertSent(VolunteeringRenewMail::class, fn (VolunteeringRenewMail $m) =>
            $m->recipientEmail === $owner->email_preferred && $m->title === 'Dateless Opportunity');
        // Intentional improvement over V1: askedtorenew is stamped after asking.
        $this->assertNotNull(DB::table('volunteering')->where('id', $id)->value('askedtorenew'));
    }

    public function test_does_not_ask_recent_opportunity(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(10)]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_does_not_ask_opportunity_renewed_recently(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(40), 'renewed' => now()->subDays(5)]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_does_not_ask_dated_opportunity(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $id = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(40)]);
        // A date with an end makes the opportunity "dated" — renewal does not apply.
        $this->addEndDate($id, now()->addDays(5)->format('Y-m-d H:i:s'));

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_does_not_reask_when_recently_asked(): void
    {
        // Intentional improvement over V1: with askedtorenew set, a daily run must
        // not re-email an owner who was asked inside the current renewal cycle.
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25), 'askedtorenew' => now()->subDays(3)]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_reasks_when_asked_long_ago(): void
    {
        // Once a full ask-cycle (24 days) has elapsed since the last ask, ask again.
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(60), 'askedtorenew' => now()->subDays(30)]);

        $this->assertSame(1, $this->service()->askRenew());
        Mail::assertSent(VolunteeringRenewMail::class);
    }

    public function test_skips_deleted_opportunity(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25), 'deleted' => 1]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_skips_expired_opportunity_for_ask(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25), 'expired' => 1]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
    }

    public function test_skips_opportunity_with_no_owner(): void
    {
        Mail::fake();
        $id = $this->makeOpportunity(['userid' => null, 'added' => now()->subDays(25)]);

        $this->assertSame(0, $this->service()->askRenew());
        Mail::assertNothingSent();
        // Nothing was emailed, so askedtorenew must remain NULL for a retry next run.
        $this->assertNull(DB::table('volunteering')->where('id', $id)->value('askedtorenew'));
    }

    public function test_ask_dry_run_sends_nothing_and_records_nothing(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $id = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25)]);

        $count = $this->service()->askRenew(null, true);

        $this->assertSame(1, $count, 'Dry run reports who WOULD be asked');
        Mail::assertNothingSent();
        $this->assertNull(DB::table('volunteering')->where('id', $id)->value('askedtorenew'));
    }

    public function test_renewal_email_uses_associated_group_name(): void
    {
        Mail::fake();
        $owner = $this->createTestUser();
        $group = $this->createTestGroup();
        $id = $this->makeOpportunity(['userid' => $owner->id, 'added' => now()->subDays(25)]);
        DB::table('volunteering_groups')->insert(['volunteeringid' => $id, 'groupid' => $group->id]);

        $this->service()->askRenew();

        Mail::assertSent(VolunteeringRenewMail::class, fn (VolunteeringRenewMail $m) =>
            $m->groupName === $group->namefull);
    }

    // ---- expire() ---------------------------------------------------------

    public function test_expires_dateless_opportunity_older_than_expire_age(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(32), 'renewed' => null]);

        $count = $this->service()->expire();

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_does_not_expire_dateless_recently_renewed(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(40), 'renewed' => now()->subDays(5)]);

        $this->assertSame(0, $this->service()->expire());
        $this->assertSame(0, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_does_not_expire_dateless_younger_than_expire_age(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(20)]);

        $this->assertSame(0, $this->service()->expire());
        $this->assertSame(0, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_expires_dated_opportunity_with_all_dates_past(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(5)]);
        $this->addEndDate($id, now()->subDays(1)->format('Y-m-d H:i:s'));

        $this->assertSame(1, $this->service()->expire());
        $this->assertSame(1, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_does_not_expire_dated_opportunity_with_a_future_date(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(5)]);
        $this->addEndDate($id, now()->subDays(1)->format('Y-m-d H:i:s'));   // past
        $this->addEndDate($id, now()->addDays(7)->format('Y-m-d H:i:s'));   // future

        $this->assertSame(0, $this->service()->expire());
        $this->assertSame(0, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_does_not_reexpire_already_expired_opportunity(): void
    {
        $this->makeOpportunity(['added' => now()->subDays(40), 'expired' => 1]);

        $this->assertSame(0, $this->service()->expire());
    }

    public function test_expire_dry_run_counts_without_changing_rows(): void
    {
        $id = $this->makeOpportunity(['added' => now()->subDays(32)]);

        $count = $this->service()->expire(null, true);

        $this->assertSame(1, $count);
        $this->assertSame(0, (int) DB::table('volunteering')->where('id', $id)->value('expired'));
    }

    public function test_id_scope_limits_expire_to_one_opportunity(): void
    {
        $target = $this->makeOpportunity(['added' => now()->subDays(40)]);
        $other = $this->makeOpportunity(['added' => now()->subDays(40)]);

        $count = $this->service()->expire($target);

        $this->assertSame(1, $count);
        $this->assertSame(1, (int) DB::table('volunteering')->where('id', $target)->value('expired'));
        $this->assertSame(0, (int) DB::table('volunteering')->where('id', $other)->value('expired'));
    }
}

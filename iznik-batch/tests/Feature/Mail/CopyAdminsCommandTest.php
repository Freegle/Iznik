<?php

namespace Tests\Feature\Mail;

use App\Models\Group;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CopyAdminsCommandTest extends TestCase
{
    /**
     * Test: Suggested admin copied to each Freegle group.
     */
    public function test_suggested_admin_copied_to_groups(): void
    {
        $group1 = $this->createTestGroup();
        $group2 = $this->createTestGroup();

        $suggestedId = DB::table('admins')->insertGetId([
            'groupid' => null,
            'subject' => 'Suggested Subject',
            'text' => 'Suggested body text',
            'ctalink' => 'https://example.com',
            'ctatext' => 'Click Me',
            'pending' => 0,
            'essential' => true,
            'activeonly' => true,
            'created' => now(),
        ]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        // Per-group copies should exist for each group.
        $this->assertDatabaseHas('admins', [
            'parentid' => $suggestedId,
            'groupid' => $group1->id,
        ]);
        $this->assertDatabaseHas('admins', [
            'parentid' => $suggestedId,
            'groupid' => $group2->id,
        ]);

        // Copies should be pending.
        $copies = DB::table('admins')
            ->where('parentid', $suggestedId)
            ->get();

        foreach ($copies as $copy) {
            $this->assertEquals(1, $copy->pending, 'Copies should be pending for mod approval.');
            $this->assertEquals('Suggested Subject', $copy->subject);
            $this->assertEquals(1, $copy->activeonly);
            $this->assertEquals(1, $copy->essential);
        }
    }

    /**
     * Test: Groups with autoadmins=0 are skipped.
     */
    public function test_groups_with_autoadmins_disabled_skipped(): void
    {
        $enabledGroup = $this->createTestGroup();
        $disabledGroup = $this->createTestGroup([
            'settings' => ['autoadmins' => 0],
        ]);

        $suggestedId = DB::table('admins')->insertGetId([
            'groupid' => null,
            'subject' => 'Test',
            'text' => 'Test body',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
            'created' => now(),
        ]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        // Only the enabled group should have a copy.
        $this->assertDatabaseHas('admins', [
            'parentid' => $suggestedId,
            'groupid' => $enabledGroup->id,
        ]);
        $this->assertDatabaseMissing('admins', [
            'parentid' => $suggestedId,
            'groupid' => $disabledGroup->id,
        ]);
    }

    /**
     * Test: Suggested admin marked complete after copying.
     */
    public function test_suggested_admin_marked_complete(): void
    {
        $this->createTestGroup();

        $suggestedId = DB::table('admins')->insertGetId([
            'groupid' => null,
            'subject' => 'Test',
            'text' => 'Test body',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
            'created' => now(),
        ]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        $suggested = DB::table('admins')->where('id', $suggestedId)->first();
        $this->assertNotNull($suggested->complete, 'Suggested admin should be marked complete after copying.');
    }

    /**
     * Test: Per-group copy has parentid set to original.
     */
    public function test_copy_has_parentid(): void
    {
        $group = $this->createTestGroup();

        $suggestedId = DB::table('admins')->insertGetId([
            'groupid' => null,
            'subject' => 'Parent Admin',
            'text' => 'Parent text',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
            'created' => now(),
        ]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        $copy = DB::table('admins')
            ->where('groupid', $group->id)
            ->where('parentid', $suggestedId)
            ->first();

        $this->assertNotNull($copy, 'Copy should exist with parentid pointing to original.');
        $this->assertEquals($suggestedId, $copy->parentid);
    }

    /**
     * Test: Pending admins older than 31 days are deleted.
     */
    public function test_stale_pending_admins_deleted(): void
    {
        $group = $this->createTestGroup();

        // Create old pending admin.
        $oldId = DB::table('admins')->insertGetId([
            'groupid' => $group->id,
            'subject' => 'Old Pending',
            'text' => 'Old pending text',
            'pending' => 1,
            'essential' => true,
            'activeonly' => false,
            'created' => now()->subDays(32),
        ]);

        // Create recent pending admin.
        $recentId = DB::table('admins')->insertGetId([
            'groupid' => $group->id,
            'subject' => 'Recent Pending',
            'text' => 'Recent pending text',
            'pending' => 1,
            'essential' => true,
            'activeonly' => false,
            'created' => now()->subDays(5),
        ]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        // Old pending admin should be deleted.
        $this->assertDatabaseMissing('admins', ['id' => $oldId]);

        // Recent pending admin should still exist.
        $this->assertDatabaseHas('admins', ['id' => $recentId]);
    }

    /**
     * Test: Duplicate copies not created on re-run.
     */
    public function test_no_duplicate_copies(): void
    {
        $group = $this->createTestGroup();

        $suggestedId = DB::table('admins')->insertGetId([
            'groupid' => null,
            'subject' => 'Test',
            'text' => 'Test body',
            'pending' => 0,
            'essential' => true,
            'activeonly' => false,
            'created' => now(),
        ]);

        // Run copy twice.
        $this->artisan('mail:admin:copy')->assertSuccessful();

        // Reset the suggested admin's complete flag to simulate re-run.
        DB::table('admins')->where('id', $suggestedId)->update(['complete' => null]);

        $this->artisan('mail:admin:copy')->assertSuccessful();

        // Should still only have 1 copy per group.
        $copies = DB::table('admins')
            ->where('parentid', $suggestedId)
            ->where('groupid', $group->id)
            ->count();

        $this->assertEquals(1, $copies, 'Should not create duplicate copies.');
    }
}

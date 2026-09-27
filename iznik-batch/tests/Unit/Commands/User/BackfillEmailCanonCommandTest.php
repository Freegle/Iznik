<?php

namespace Tests\Unit\Commands\User;

use App\Models\UserEmail;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * users_emails.canon and .backwards have to mean one thing. canon is canonMail(email)
 * and backwards is its reverse, which is what V1 wrote at both its insert sites.
 * Production holds three other shapes; this command brings them back.
 */
class BackfillEmailCanonCommandTest extends TestCase
{
    /** Insert a row exactly as given, bypassing the model hook that would fix it. */
    private function rawRow(int $userid, string $email, ?string $canon, ?string $backwards): int
    {
        return (int) DB::table('users_emails')->insertGetId([
            'userid' => $userid,
            'email' => $email,
            'canon' => $canon,
            'backwards' => $backwards,
            'preferred' => 0,
            'added' => now(),
        ]);
    }

    public function test_a_dry_run_reports_the_work_and_writes_nothing(): void
    {
        $user = $this->createTestUser();
        $base = 'bfdry'.str_replace('.', '', uniqid('', true));
        $id = $this->rawRow($user->id, "{$base}-g202@user.trashnothing.com", null, null);

        $exit = Artisan::call('users:backfill-email-canon', ['--chunk' => 500]);
        $this->assertSame(0, $exit, Artisan::output());

        $row = DB::table('users_emails')->where('id', $id)->first();
        $this->assertNull($row->canon, 'a dry run must not write');
        $this->assertNull($row->backwards, 'a dry run must not write');
    }

    public function test_apply_rewrites_the_shapes_that_disagree_and_leaves_the_rest(): void
    {
        $user = $this->createTestUser();
        $base = 'bfap'.str_replace('.', '', uniqid('', true));
        $canon = "{$base}@usertrashnothingcom";

        // Already right: must be left exactly as it is.
        $ok = $this->rawRow($user->id, "{$base}-g101@user.trashnothing.com", $canon, strrev($canon));
        // backwards derived from the address rather than the canon.
        $addr = $this->rawRow($user->id, "{$base}b-g202@user.trashnothing.com", "{$base}b@usertrashnothingcom", strrev("{$base}b-g202@user.trashnothing.com"));
        // Nothing at all, which no prefix search can reach.
        $none = $this->rawRow($user->id, "{$base}c-g303@user.trashnothing.com", null, null);

        $exit = Artisan::call('users:backfill-email-canon', ['--chunk' => 500, '--apply' => true]);
        $this->assertSame(0, $exit, Artisan::output());

        foreach ([$ok, $addr, $none] as $id) {
            $row = DB::table('users_emails')->where('id', $id)->first();
            $this->assertSame(
                strrev((string) $row->canon),
                (string) $row->backwards,
                'every row must end up with backwards as the reverse of its canon'
            );
        }

        $this->assertSame($canon, DB::table('users_emails')->where('id', $ok)->value('canon'));
        $this->assertSame("{$base}c@usertrashnothingcom", DB::table('users_emails')->where('id', $none)->value('canon'));
    }

    /**
     * The point of the canon: every per-group alias of one Trash Nothing member
     * reduces to the same value, which is what stops a second alias creating a
     * second account.
     */
    public function test_every_alias_of_one_member_reduces_to_one_canon(): void
    {
        $user = $this->createTestUser();
        $base = 'bfsame'.str_replace('.', '', uniqid('', true));

        $ids = [];
        foreach (['g101', 'g202', 'g303'] as $g) {
            $ids[] = $this->rawRow($user->id, "{$base}-{$g}@user.trashnothing.com", null, null);
        }

        Artisan::call('users:backfill-email-canon', ['--chunk' => 500, '--apply' => true]);

        $canons = DB::table('users_emails')->whereIn('id', $ids)->pluck('canon')->unique();
        $this->assertCount(1, $canons, 'the aliases must all canonicalise to one value');
        $this->assertSame("{$base}@usertrashnothingcom", $canons->first());
    }

    /**
     * The hook that every Eloquent write goes through. It derives both columns, and it
     * is authoritative for backwards - passing one at the call site does nothing, which
     * is worth a test because it looks like it should work.
     */
    public function test_the_model_hook_derives_both_columns_and_ignores_a_passed_backwards(): void
    {
        $user = $this->createTestUser();
        $base = 'bfhook'.str_replace('.', '', uniqid('', true));
        $email = "{$base}-g404@user.trashnothing.com";

        UserEmail::create([
            'userid' => $user->id,
            'email' => $email,
            'preferred' => 0,
            'added' => now(),
            'backwards' => 'this value is discarded',
        ]);

        $row = DB::table('users_emails')->where('email', $email)->first();
        $this->assertSame("{$base}@usertrashnothingcom", $row->canon);
        $this->assertSame(strrev("{$base}@usertrashnothingcom"), $row->backwards);
    }
}

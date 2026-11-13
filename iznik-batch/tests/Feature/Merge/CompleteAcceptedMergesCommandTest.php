<?php

namespace Tests\Feature\Merge;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CompleteAcceptedMergesCommandTest extends TestCase
{
    private const URL = 'http://apiv2.test/api/merge';

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.apiv2_merge_url', self::URL);
        $this->file = tempnam(sys_get_temp_dir(), 'accepts');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function offer(array $extra = [], ?int $u1 = null, ?int $u2 = null): array
    {
        $u1 ??= $this->createTestUser()->id;
        $u2 ??= $this->createTestUser()->id;
        $uid = bin2hex(random_bytes(8));
        $id = DB::table('merges')->insertGetId(array_merge([
            'user1' => $u1,
            'user2' => $u2,
            'offeredby' => $u1,
            'uid' => $uid,
        ], $extra));

        return [$id, $u1, $u2, $uid];
    }

    private function run_(string $lines, bool $apply): \Illuminate\Testing\PendingCommand
    {
        file_put_contents($this->file, $lines);

        return $this->artisan('merges:complete-accepted', array_filter([
            '--file' => $this->file,
            '--apply' => $apply,
        ]));
    }

    public function test_dry_run_reports_and_calls_nothing(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer();

        $this->run_("{$id},{$u2},{$u1}\n", false)
            ->expectsOutputToContain("would merge account {$u1} into {$u2}")
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_apply_sends_the_accept_the_member_made(): void
    {
        Http::fake([self::URL => Http::response(['ret' => 0], 200)]);
        [$id, $u1, $u2, $uid] = $this->offer();

        // The member chose to keep the second account of the offer.
        $this->run_("{$id}, {$u2}, {$u1}\n", true)
            ->expectsOutputToContain("merged account {$u1} into {$u2}")
            ->assertSuccessful();

        Http::assertSent(fn ($r) => $r->url() === self::URL
            && $r['id'] === $id && $r['uid'] === $uid
            && $r['user1'] === $u2 && $r['user2'] === $u1 && $r['action'] === 'Accept');
    }

    public function test_an_accepted_offer_is_not_sent_again(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer(['accepted' => now()]);

        $this->run_("{$id},{$u1},{$u2}\n", true)
            ->expectsOutputToContain('already accepted')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_rejected_offer_is_refused(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer(['rejected' => now()]);

        $this->run_("{$id},{$u1},{$u2}\n", true)->expectsOutputToContain('was rejected');

        Http::assertNothingSent();
    }

    public function test_an_unknown_offer_is_refused(): void
    {
        Http::fake();

        $this->run_("999999999,1,2\n", true)->expectsOutputToContain('does not exist');

        Http::assertNothingSent();
    }

    public function test_accounts_that_are_not_the_offered_ones_are_refused(): void
    {
        Http::fake();
        [$id, $u1] = $this->offer();
        $stranger = $this->createTestUser()->id;

        $this->run_("{$id},{$u1},{$stranger}\n", true)->expectsOutputToContain('not the ones offered');

        Http::assertNothingSent();
    }

    public function test_the_same_account_twice_is_refused(): void
    {
        Http::fake();
        [$id, $u1] = $this->offer();

        $this->run_("{$id},{$u1},{$u1}\n", true)->expectsOutputToContain('not the ones offered');

        Http::assertNothingSent();
    }

    public function test_a_missing_account_is_refused(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer();
        DB::table('users')->where('id', $u2)->delete();

        $this->run_("{$id},{$u1},{$u2}\n", true)->expectsOutputToContain('no longer exists');

        Http::assertNothingSent();
    }

    public function test_a_trashnothing_account_is_refused(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer();
        DB::table('users')->where('id', $u2)->update(['tnuserid' => 991234]);

        $this->run_("{$id},{$u1},{$u2}\n", true)->expectsOutputToContain('TrashNothing');

        Http::assertNothingSent();
    }

    public function test_a_member_who_opted_out_of_merging_is_refused(): void
    {
        Http::fake();
        [$id, $u1, $u2] = $this->offer();
        DB::table('users')->where('id', $u1)->update(['settings' => json_encode(['canmerge' => false])]);

        $this->run_("{$id},{$u1},{$u2}\n", true)->expectsOutputToContain('opted out of merging');

        Http::assertNothingSent();
    }

    public function test_a_bad_line_is_refused_and_the_run_carries_on(): void
    {
        Http::fake([self::URL => Http::response(['ret' => 0], 200)]);
        [$id, $u1, $u2] = $this->offer();

        $this->run_("# a comment\n\nnot,a,line\n{$id},{$u1},{$u2}\n", true)
            ->expectsOutputToContain('refused, not "offer id')
            ->expectsOutputToContain("merged account {$u2} into {$u1}")
            ->assertSuccessful();

        Http::assertSentCount(1);
    }

    public function test_an_api_refusal_is_reported_and_the_run_carries_on(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push(['message' => 'One of these accounts has asked not to be merged.'], 400)
            ->push(['ret' => 0], 200)]);
        [$id1, $a1, $a2] = $this->offer();
        [$id2, $b1, $b2] = $this->offer();

        $this->run_("{$id1},{$a1},{$a2}\n{$id2},{$b1},{$b2}\n", true)
            ->expectsOutputToContain('the API refused (400)')
            ->expectsOutputToContain("merged account {$b2} into {$b1}")
            ->assertSuccessful();
    }

    public function test_an_api_failure_makes_the_run_fail(): void
    {
        Http::fake([self::URL => Http::response('boom', 500)]);
        [$id, $u1, $u2] = $this->offer();

        $this->run_("{$id},{$u1},{$u2}\n", true)
            ->expectsOutputToContain('the API failed (500)')
            ->assertFailed();
    }

    public function test_a_missing_file_fails(): void
    {
        $this->artisan('merges:complete-accepted', ['--file' => '/nonexistent/x'])->assertFailed();
    }
}

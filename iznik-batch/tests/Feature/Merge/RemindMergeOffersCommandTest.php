<?php

namespace Tests\Feature\Merge;

use App\Mail\Session\MergeOfferMail;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RemindMergeOffersCommandTest extends TestCase
{
    /** @var array<int, MergeOfferMail> */
    private array $spooled = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spooled = [];
        $this->mock(EmailSpoolerService::class, function ($mock) {
            $mock->shouldReceive('spool')->andReturnUsing(function ($mail) {
                $this->spooled[] = $mail;

                return 'id';
            });
        });
    }

    private function offer(array $extra = [], array $u1attrs = [], array $u2attrs = []): array
    {
        $u1 = $this->createTestUser($u1attrs);
        $u2 = $this->createTestUser($u2attrs);
        $id = DB::table('merges')->insertGetId(array_merge([
            'user1' => $u1->id,
            'user2' => $u2->id,
            'offeredby' => $u1->id,
            'uid' => bin2hex(random_bytes(8)),
            'offered' => '2030-06-01 10:00:00',
        ], $extra));

        return [$id, $u1, $u2];
    }

    private function reminded(int $id): mixed
    {
        return DB::table('merges')->where('id', $id)->value('reminded');
    }

    public function test_dry_run_prints_counts_and_sends_nothing(): void
    {
        [$id] = $this->offer();

        $this->artisan('merges:remind', ['--since' => '2030-05-01'])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Eligible for a reminder:')
            ->assertSuccessful();

        $this->assertSame([], $this->spooled);
        $this->assertNull($this->reminded($id));
    }

    public function test_apply_sends_to_both_accounts_with_the_intro_and_marks_it_reminded(): void
    {
        [$id, $u1, $u2] = $this->offer();

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertSuccessful();

        $this->assertCount(2, $this->spooled);
        $this->assertContains(
            $this->spooled[0]->recipientUserId,
            [$u1->id, $u2->id]
        );
        $this->assertSame(\App\Console\Commands\Merge\RemindMergeOffersCommand::INTRO, $this->spooled[0]->intro);
        $this->assertStringContainsString("/merge?id={$id}&uid=", $this->spooled[0]->mergeUrl);
        $this->assertNotNull($this->reminded($id));
    }

    public function test_the_email_carries_the_intro_line_only_when_given(): void
    {
        $data = [
            'name1' => 'A', 'email1' => 'a***@test.com', 'name2' => 'B', 'email2' => 'b***@test.com',
            'mergeUrl' => 'https://x/merge?id=1&uid=u', 'email' => 'a@test.com',
        ];

        $with = view('emails.mjml.session.merge-offer', $data + ['intro' => 'Intro line here'])->render();
        $without = view('emails.mjml.session.merge-offer', $data + ['intro' => null])->render();

        $this->assertStringContainsString('Intro line here', $with);
        $this->assertStringNotContainsString('Intro line here', $without);
    }

    public function test_a_second_run_sends_nothing(): void
    {
        $this->offer();

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertSuccessful();
        $first = count($this->spooled);
        $this->assertGreaterThanOrEqual(2, $first);

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertSuccessful();
        $this->assertCount($first, $this->spooled);
    }

    public function test_offers_that_are_answered_or_already_reminded_or_too_old_are_left_alone(): void
    {
        [$accepted] = $this->offer(['accepted' => now()]);
        [$rejected] = $this->offer(['rejected' => now()]);
        [$reminded] = $this->offer(['reminded' => '2026-07-01 00:00:00']);
        [$old] = $this->offer(['offered' => '2030-03-01 00:00:00']);

        $this->artisan('merges:remind', ['--since' => '2030-04-02', '--apply' => true])->assertSuccessful();

        $this->assertSame([], $this->spooled);
        $this->assertNull($this->reminded($accepted));
        $this->assertNull($this->reminded($rejected));
        $this->assertNull($this->reminded($old));
    }

    public function test_a_missing_account_a_trashnothing_account_and_an_opt_out_are_left_alone(): void
    {
        [$gone, , $u2] = $this->offer();
        DB::table('users')->where('id', $u2->id)->delete();
        [$tn, , $tn2] = $this->offer();
        DB::table('users')->where('id', $tn2->id)->update(['tnuserid' => 991235]);
        [$optout] = $this->offer([], ['settings' => ['canmerge' => false]]);

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertSuccessful();

        $this->assertSame([], $this->spooled);
        foreach ([$gone, $tn, $optout] as $id) {
            $this->assertNull($this->reminded($id));
        }
    }

    public function test_email_off_and_bouncing_accounts_are_left_alone(): void
    {
        [$off] = $this->offer([], ['settings' => ['simplemail' => 'None']]);
        [$bounce] = $this->offer([], [], ['bouncing' => 1]);

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertSuccessful();

        $this->assertSame([], $this->spooled);
        $this->assertNull($this->reminded($off));
        $this->assertNull($this->reminded($bounce));
    }

    public function test_limit_caps_the_offers_handled(): void
    {
        $this->offer();
        $this->offer();

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true, '--limit' => 1])
            ->assertSuccessful();

        $this->assertCount(2, $this->spooled, 'one offer, two accounts');
    }

    public function test_a_send_failure_releases_the_offer_for_another_run(): void
    {
        $this->mock(EmailSpoolerService::class, function ($mock) {
            $mock->shouldReceive('spool')->andThrow(new \RuntimeException('spool down'));
        });
        [$id] = $this->offer();

        $this->artisan('merges:remind', ['--since' => '2030-05-01', '--apply' => true])->assertFailed();

        $this->assertNull($this->reminded($id));
    }

    public function test_a_bad_since_fails(): void
    {
        $this->artisan('merges:remind', ['--since' => 'not a date'])->assertFailed();
    }
}

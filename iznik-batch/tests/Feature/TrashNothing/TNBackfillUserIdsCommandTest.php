<?php

namespace Tests\Feature\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class TNBackfillUserIdsCommandTest extends TestCase
{
    private const TN_ID = 99150101;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freegle.trashnothing.api_key' => 'test-key',
            'freegle.trashnothing.api_base_url' => 'https://trashnothing.com/fd/api',
        ]);

        $this->user = User::create(['fullname' => 'Test Member', 'added' => now()]);
        UserEmail::create([
            'userid' => $this->user->id,
            'email' => 'backfill' . uniqid() . '-g1@user.trashnothing.com',
            'preferred' => 1,
        ]);
    }

    private function fakeFeed(): void
    {
        Http::fake(['*/user-changes*' => Http::response(['changes' => [[
            'fd_user_id' => $this->user->id,
            'tn_user_id' => self::TN_ID,
            'date' => '2026-02-01T10:00:00',
        ]]], 200)]);
    }

    public function test_it_reports_and_writes_nothing_without_commit(): void
    {
        $this->fakeFeed();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01'])
            ->expectsOutputToContain('stamped: 1')
            ->expectsOutputToContain('Dry run: nothing written')
            ->assertExitCode(0);

        $this->assertNull($this->user->fresh()->tnuserid);
    }

    public function test_it_stamps_the_account_with_commit(): void
    {
        $this->fakeFeed();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01', '--commit' => true])
            ->assertExitCode(0);

        $this->assertSame(self::TN_ID, (int) $this->user->fresh()->tnuserid);
    }

    /** How much the feed left is what decides whether it is safe to go on. */
    public function test_it_says_how_many_accounts_are_left_with_no_tn_id(): void
    {
        $this->fakeFeed();

        $left = function (array $options): int {
            Artisan::call('tn:backfill-user-ids', ['--from' => '2026-01-01'] + $options);
            preg_match('/accounts with a TN address and no TN id: (\d+)/', Artisan::output(), $found);

            return (int) $found[1];
        };

        $before = $left([]);
        $after = $left(['--commit' => true]);

        $this->assertGreaterThan(0, $before);
        $this->assertSame($before - 1, $after);
    }

    public function test_it_needs_a_start_date(): void
    {
        $this->artisan('tn:backfill-user-ids')->assertExitCode(1);
    }

    public function test_it_fails_when_the_feed_cannot_be_read(): void
    {
        Http::fake(['*/user-changes*' => Http::response('Internal Server Error', 500)]);

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01'])->assertExitCode(1);
    }

    /** TN refuses dates more than a day apart, and --from can be weeks back. */
    public function test_it_reads_a_window_tn_would_refuse_in_one_request(): void
    {
        Http::fake(function (Request $request) {
            if (strtotime($request['date_max']) - strtotime($request['date_min']) > 86400) {
                return Http::response('Invalid parameters date_min and date_max - date_min and date_max must be at most one day apart.', 400);
            }

            return Http::response(['changes' => !str_starts_with($request['date_min'], '2026-10-03') ? [] : [[
                'fd_user_id' => $this->user->id,
                'tn_user_id' => self::TN_ID,
                'date' => '2026-10-03T16:00:00',
            ]]], 200);
        });

        $this->artisan('tn:backfill-user-ids', [
            '--from' => '2026-10-01T14:49:12Z',
            '--to' => '2026-10-04T14:49:12Z',
            '--commit' => true,
        ])->assertExitCode(0);

        $this->assertSame(self::TN_ID, (int) $this->user->fresh()->tnuserid);
    }

    /** Status alone hid why TN refused, and "run it again" could never help with that. */
    public function test_it_logs_why_tn_refused_a_page_and_never_the_key(): void
    {
        Http::fake(['*/user-changes*' => Http::response('Bad request for test-key', 400)]);
        Log::spy();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01T00:00:00Z', '--to' => '2026-01-01T12:00:00Z'])
            ->expectsOutputToContain('so nothing was written')
            ->doesntExpectOutputToContain('Run it again')
            ->assertExitCode(1);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'user-changes API failed on page 1')
                && $context['status'] === 400
                && $context['body'] === 'Bad request for [key]'
                && $context['date_min'] === '2026-01-01T00:00:00Z'
                && $context['date_max'] === '2026-01-01T12:00:00Z'
            );
    }

    public function test_it_logs_what_tn_sent_back_when_asked_and_never_the_key(): void
    {
        Http::fake(['*/user-changes*' => Http::response('{"error":"forbidden"}', 403, ['X-Reason' => 'blocked'])]);
        Log::spy();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01', '--log-responses' => true])
            ->assertExitCode(1);

        Log::shouldHaveReceived('info')
            ->withArgs(fn ($message, $context = []) =>
                str_contains((string) $message, 'user-changes API response on page 1')
                && $context['status'] === 403
                && $context['headers']['X-Reason'] === ['blocked']
                && $context['body'] === '{"error":"forbidden"}'
                && !str_contains((string) json_encode([$message, $context]), 'test-key')
            );
    }

    public function test_it_does_not_log_what_tn_sent_back_unless_asked(): void
    {
        $this->fakeFeed();
        Log::spy();

        $this->artisan('tn:backfill-user-ids', ['--from' => '2026-01-01'])->assertExitCode(0);

        Log::shouldNotHaveReceived('info', [
            Mockery::on(fn ($message) => str_contains((string) $message, 'user-changes API response')),
            Mockery::any(),
        ]);
    }
}

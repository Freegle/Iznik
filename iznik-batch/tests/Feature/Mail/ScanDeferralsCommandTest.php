<?php

namespace Tests\Feature\Mail;

use App\Monitoring\HostCommandRunner;
use App\Services\Mail\Deferrals\DeferralProbe;
use App\Services\Mail\MailSuppressionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * End to end, replaying the incident this exists for.
 *
 * The acceptance test the work was specified against: with a provider
 * blocking, one scan cycle marks the relay family suppressed and stops mail
 * generation for the affected addresses. The queue stops growing.
 */
class ScanDeferralsCommandTest extends TestCase
{
    private array $scripts = [];

    private ?string $canned = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'freegle.mail.deferrals.enabled' => true,
            'freegle.mail.deferrals.host' => 'deferrals@relay.test',
            'freegle.mail.deferrals.max_queue_bytes' => 1024 * 1024,
            'freegle.mail.deferrals.mxgroup_min_deferred' => 500,
            'freegle.mail.deferrals.mxgroup_max_delivered_per_hour' => 10,
            'freegle.mail.deferrals.address_min_deferred' => 5,
            'freegle.mail.deferrals.address_min_hours' => 24,
            'freegle.mail.deferrals.release_max_deferred' => 100,
            'freegle.mail.deferrals.release_clear_scans' => 2,
            'freegle.mail.deferrals.stale_after_hours' => 24,
        ]);

        // Swap in a fake relay so nothing here goes near ssh.
        $test = $this;
        $this->app->when(DeferralProbe::class)
            ->needs(HostCommandRunner::class)
            ->give(function () use ($test) {
                return new class($test) implements HostCommandRunner
                {
                    public function __construct(private $test)
                    {
                    }

                    public function run(string $target, string $script): ?string
                    {
                        return $this->test->relayResponds($target, $script);
                    }
                };
            });

        app(MailSuppressionService::class)->flushCache();
    }

    public function relayResponds(string $target, string $script): ?string
    {
        $this->scripts[] = $script;

        return $this->canned;
    }

    private function relayReturns(string $queue, string $delivered = ''): void
    {
        $this->canned = DeferralProbe::MARK_QUEUE . "\n" . $queue . "\n"
            . DeferralProbe::MARK_DELIVERED . "\n" . $delivered . "\n"
            . DeferralProbe::MARK_END . "\n";
    }

    /**
     * The queue as it looked on 2026-08-16, scaled down.
     */
    private function yahooBlockedQueue(int $messages = 600): string
    {
        $lines = [];
        $domains = ['yahoo.co.uk', 'yahoo.com', 'sky.com', 'aol.com'];

        for ($i = 0; $i < $messages; $i++) {
            $lines[] = json_encode([
                'queue_name' => 'deferred',
                'queue_id' => 'QID' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                'arrival_time' => strtotime('2026-08-15 16:38:00') + $i,
                'sender' => 'noreply@ilovefreegle.org',
                'recipients' => [[
                    'address' => 'member' . $i . '@' . $domains[$i % 4],
                    'delay_reason' => 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 '
                        . '[TSS04] Messages from 185.53.57.161 temporarily deferred due to '
                        . 'unexpected volume or user complaints (in reply to MAIL FROM command)',
                ]],
            ]);
        }

        return implode("\n", $lines);
    }

    /** Gmail is still taking our mail; Yahoo has taken one message all hour. */
    private function deliveredLog(): string
    {
        $lines = [];
        for ($i = 0; $i < 500; $i++) {
            $lines[] = "Aug 18 09:00:00 relay postfix/smtp[$i]: G$i: to=<a$i@gmail.com>, "
                . 'relay=gmail-smtp-in.l.google.com[1.2.3.4]:25, delay=1, status=sent (250 ok)';
        }
        $lines[] = 'Aug 18 09:30:00 relay postfix/smtp[999]: Y1: to=<lucky@yahoo.co.uk>, '
            . 'relay=mta7.am0.yahoodns.net[67.195.228.94]:25, delay=8000, status=sent (250 ok)';

        return implode("\n", $lines);
    }

    public function test_one_scan_suppresses_the_blocked_provider_and_all_its_domains(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->assertDatabaseHas('mail_suppressions', [
            'scope' => 'mxgroup', 'value' => 'yahoodns.net', 'provider' => 'Yahoo', 'released_at' => null,
        ]);

        // Every domain behind that one relay, including Sky, which is
        // Yahoo-hosted and unguessable from its name.
        $domains = DB::table('mail_suppressions')->where('scope', 'domain')->pluck('value')->all();
        $this->assertEqualsCanonicalizing(
            ['yahoo.co.uk', 'yahoo.com', 'sky.com', 'aol.com'],
            $domains
        );

        $suppressions = app(MailSuppressionService::class);
        $suppressions->flushCache();
        $this->assertTrue($suppressions->isSuppressed('someone@sky.com'));
        $this->assertFalse($suppressions->isSuppressed('someone@gmail.com'));
    }

    public function test_the_delayed_since_date_is_when_the_member_stopped_receiving_mail(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $row = DB::table('mail_suppressions')->where('scope', 'mxgroup')->first();
        $this->assertSame('2026-08-15 16:38:00', (string) $row->deferred_since);
    }

    public function test_gmail_keeps_getting_mail_while_yahoo_is_blocked(): void
    {
        // The failure that would matter most: over-suppressing and taking out
        // providers that are perfectly happy.
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('mail_suppressions')->where('value', 'like', '%google%')->count()
        );
    }

    public function test_does_nothing_when_the_feature_is_off(): void
    {
        config(['freegle.mail.deferrals.enabled' => false]);
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->assertDatabaseCount('mail_suppressions', 0);
        $this->assertSame([], $this->scripts, 'a disabled feature must not touch the relay at all');
    }

    public function test_does_nothing_when_no_relay_is_configured(): void
    {
        // Dev and CI, where the topology is deliberately absent.
        config(['freegle.mail.deferrals.host' => '']);

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_fails_loudly_when_the_relay_cannot_be_read(): void
    {
        // Running green while blind is exactly how the original incident
        // stayed invisible for three days.
        $this->canned = null;

        $this->artisan('mail:deferrals:scan')->assertFailed();

        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan --dry-run')->assertSuccessful();

        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_purge_is_dry_run_without_force(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan --purge')->assertSuccessful();

        foreach ($this->scripts as $script) {
            $this->assertStringNotContainsString(
                'postsuper',
                $script,
                'purge must not delete anything without --force'
            );
        }
    }

    public function test_purge_with_force_deletes_only_the_suppressed_relays_queue(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan --purge --force')->assertSuccessful();

        $purges = array_values(array_filter(
            $this->scripts,
            fn ($s) => str_contains($s, 'postsuper')
        ));

        $this->assertNotEmpty($purges, 'purge --force should have asked the relay to delete');
        $this->assertStringContainsString('QID0000000', $purges[0]);
        $this->assertStringNotContainsString(
            ' ALL',
            $purges[0],
            'never postsuper -d ALL: that would delete mail to providers that are fine'
        );
    }

    public function test_purge_does_nothing_when_nothing_is_suppressed(): void
    {
        // A small backlog is normal traffic, not a block.
        $this->relayReturns($this->yahooBlockedQueue(10), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan --purge --force')->assertSuccessful();

        foreach ($this->scripts as $script) {
            $this->assertStringNotContainsString('postsuper', $script);
        }
    }

    public function test_dry_run_outranks_force_and_deletes_nothing(): void
    {
        // Someone reaching for --dry-run is asking what would happen.
        // Answering that by deleting mail off a production relay would be
        // the worst possible reply.
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan --dry-run --purge --force')->assertSuccessful();

        foreach ($this->scripts as $script) {
            $this->assertStringNotContainsString('postsuper', $script);
        }
        $this->assertDatabaseCount('mail_suppressions', 0);
    }

    public function test_an_unreachable_relay_still_lets_a_stuck_suppression_time_out(): void
    {
        // A broken probe is exactly when a suppression is most likely to
        // stick on for ever, because the normal release path needs a
        // snapshot it never gets.
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());
        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        DB::table('mail_suppressions')->update(['last_seen' => now()->subDays(3)]);
        $this->canned = null;

        $this->artisan('mail:deferrals:scan')->assertFailed();

        $this->assertSame(
            0,
            DB::table('mail_suppressions')->whereNull('released_at')->count(),
            'a suppression nobody can confirm any more must fail open'
        );
    }

    public function test_an_unreachable_relay_does_not_release_a_recent_suppression(): void
    {
        // An absent snapshot is not evidence that anything recovered. Two
        // failed probes in a row must not release everything we have.
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());
        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->canned = null;
        $this->artisan('mail:deferrals:scan')->assertFailed();
        $this->artisan('mail:deferrals:scan')->assertFailed();

        $this->assertDatabaseHas('mail_suppressions', [
            'scope' => 'mxgroup', 'value' => 'yahoodns.net', 'released_at' => null,
        ]);
    }

    public function test_a_domain_first_seen_mid_episode_is_gated_too(): void
    {
        // An episode lasting days keeps turning up domains that were not in
        // the queue when it started, because someone at that provider only
        // becomes due an email later.
        $this->relayReturns($this->yahooBlockedQueue(), $this->deliveredLog());
        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $extra = json_encode([
            'queue_name' => 'deferred',
            'queue_id' => 'LATECOMER1',
            'arrival_time' => strtotime('2026-08-17 09:00:00'),
            'recipients' => [[
                'address' => 'someone@ymail.com',
                'delay_reason' => 'host mta7.am0.yahoodns.net[67.195.228.94] said: 421 4.7.0 '
                    . '[TSS04] temporarily deferred',
            ]],
        ]);
        $this->relayReturns($this->yahooBlockedQueue() . "\n" . $extra, $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $suppressions = app(MailSuppressionService::class);
        $suppressions->flushCache();
        $this->assertTrue($suppressions->isSuppressed('someone@ymail.com'));
    }
    public function test_the_probe_reads_the_queue_without_writing_to_it(): void
    {
        $this->relayReturns($this->yahooBlockedQueue(10), $this->deliveredLog());

        $this->artisan('mail:deferrals:scan')->assertSuccessful();

        $this->assertNotEmpty($this->scripts);
        $this->assertStringContainsString('postqueue -j', $this->scripts[0]);
        $this->assertStringNotContainsString('postsuper', $this->scripts[0]);
        // mailq takes minutes over a queue this size; -j is the whole point.
        $this->assertStringNotContainsString('mailq', $this->scripts[0]);
    }
}

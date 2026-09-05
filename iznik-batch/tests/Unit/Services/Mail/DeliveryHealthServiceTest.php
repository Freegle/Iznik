<?php

namespace Tests\Unit\Services\Mail;

use App\Services\Mail\DeliveryHealthService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryHealthServiceTest extends TestCase
{
    private DeliveryHealthService $service;

    /** Fixed "now" so the windows are deterministic. */
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DeliveryHealthService;
        $this->now = Carbon::parse('2026-08-18 13:00:00');

        // The service aggregates every row in the window, so rows left by other tests would
        // shift the rates it computes. Start from a known-empty table.
        DB::table('email_tracking')->delete();
    }

    /**
     * Seed $sent emails at $daysAgo, of which $openedPercent were opened.
     *
     * Not seed() - Laravel's TestCase already has a public one for database seeders, and
     * PHP will not let a subclass narrow it to private.
     *
     * $hoursAgo lets a test place mail inside the settle window instead.
     */
    private function seedSends(string $domain, int $daysAgo, int $sent, float $openedPercent, ?int $hoursAgo = null): void
    {
        // An hour inside the settle boundary, not exactly on it: the service's window is
        // half-open (sent_at < recentEnd), so rows placed exactly on the boundary fall out of
        // both windows and every assertion here would pass for the wrong reason.
        $sentAt = $hoursAgo !== null
            ? $this->now->copy()->subHours($hoursAgo)
            : $this->now->copy()->subHours(DeliveryHealthService::SETTLE_HOURS + 1)->subDays($daysAgo);

        $toOpen = (int) round($sent * $openedPercent / 100);

        $rows = [];
        for ($i = 0; $i < $sent; $i++) {
            $rows[] = [
                'tracking_id' => bin2hex(random_bytes(16)),
                'email_type' => 'Digest',
                'recipient_email' => "member{$i}@{$domain}",
                'sent_at' => $sentAt->toDateTimeString(),
                'opened_at' => $i < $toOpen ? $sentAt->copy()->addHour()->toDateTimeString() : null,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('email_tracking')->insert($chunk);
        }
    }

    private function domains(): array
    {
        return array_column($this->service->collapsedDomains(1, 14, $this->now), 'domain');
    }

    public function test_flags_a_domain_whose_open_rate_has_collapsed(): void
    {
        // Healthy through the baseline, then effectively nothing - the Yahoo shape.
        $this->seedSends('yahoo.example', 5, 600, 30.0);
        $this->seedSends('yahoo.example', 0, 600, 0.2);

        $collapsed = $this->service->collapsedDomains(1, 14, $this->now);

        $this->assertCount(1, $collapsed);
        $this->assertSame('yahoo.example', $collapsed[0]['domain']);
        $this->assertSame(600, $collapsed[0]['recent_sent']);
        $this->assertEqualsWithDelta(30.0, $collapsed[0]['baseline_open_percent'], 0.5);
        $this->assertLessThan(DeliveryHealthService::COLLAPSE_RATIO, $collapsed[0]['ratio']);
    }

    public function test_leaves_a_steady_domain_alone(): void
    {
        $this->seedSends('steady.example', 5, 600, 30.0);
        $this->seedSends('steady.example', 0, 600, 28.0);

        $this->assertSame([], $this->domains());
    }

    public function test_ignores_a_domain_that_never_reported_opens(): void
    {
        // A forwarder: our pixel is never fetched from the real recipient, so its rate is
        // always zero. There is no working delivery to have lost, and it must not alert daily.
        $this->seedSends('forwarder.example', 5, 600, 0.0);
        $this->seedSends('forwarder.example', 0, 600, 0.0);

        $this->assertSame([], $this->domains());
    }

    public function test_ignores_a_domain_below_the_volume_floor(): void
    {
        $small = DeliveryHealthService::MIN_RECENT_SENDS - 100;
        $this->seedSends('tiny.example', 5, $small, 30.0);
        $this->seedSends('tiny.example', 0, $small, 0.0);

        $this->assertSame([], $this->domains());
    }

    public function test_ignores_mail_too_recent_to_have_been_opened(): void
    {
        // Mail sent an hour ago is unopened because nobody has looked yet, not because it did
        // not arrive. Counting it would make every run report an outage.
        $this->seedSends('settling.example', 5, 600, 30.0);
        $this->seedSends('settling.example', 0, 600, 30.0);
        $this->seedSends('settling.example', 0, 5000, 0.0, hoursAgo: 1);

        $this->assertSame([], $this->domains());
    }
}

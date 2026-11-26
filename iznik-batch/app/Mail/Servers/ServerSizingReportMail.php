<?php

namespace App\Mail\Servers;

use App\Mail\MjmlMailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The weekly server sizing report: what each rented server costs, how hard it worked, and whether
 * a different package would suit it. Sent by servers:rightsize-report.
 *
 * All the wording of the figures is done here, in cards(), so the two views only lay it out and
 * the HTML and plain-text copies cannot disagree.
 */
class ServerSizingReportMail extends MjmlMailable
{
    /**
     * Transactional - an internal report to the person who runs the servers - so it carries no
     * List-Unsubscribe.
     */
    protected function unsubscribeType(): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $analysis  from ServerSizingAnalyser::analyse()
     * @param  array{changes: list<string>, watch: list<string>, caveats: list<string>}|null  $review
     */
    public function __construct(
        public readonly array $analysis,
        public readonly ?array $review,
        public readonly string $recipient,
    ) {
        parent::__construct();
    }

    protected function getSubject(): string
    {
        $changes = 0;
        $flagged = 0;
        foreach ($this->analysis['servers'] as $server) {
            if (in_array($server['verdict'], ['step_down', 'step_up'], true)) {
                $changes++;
            }
            foreach ($server['disks'] as $disk) {
                if ($disk['flag']) {
                    $flagged++;
                }
            }
        }

        $parts = [$changes === 0 ? 'no size changes suggested' : "{$changes} size change".($changes === 1 ? '' : 's').' suggested'];
        if ($flagged > 0) {
            $parts[] = "{$flagged} disk".($flagged === 1 ? '' : 's').' filling';
        }

        return 'Server sizing: '.implode(', ', $parts);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('freegle.mail.geeks_addr', 'geeks@ilovefreegle.org'),
                config('freegle.branding.name', 'Freegle')
            ),
            to: [new Address($this->recipient)],
            subject: $this->getSubject(),
        );
    }

    public function build(): static
    {
        $fleet = $this->analysis['fleet'];
        $discount = (float) $this->analysis['discount'];

        return $this->mjmlView('emails.mjml.servers.sizing-report', [
            'previewText' => $this->getSubject(),
            'cards' => $this->cards(),
            'review' => $this->review,
            'monthlyPaid' => self::money($fleet['monthly_paid']),
            'monthlyList' => self::money($fleet['monthly_list']),
            'possibleSaving' => $fleet['possible_saving_paid'] > 0 ? self::money($fleet['possible_saving_paid']) : null,
            'possibleCost' => ($fleet['possible_cost_paid'] ?? 0) > 0 ? self::money($fleet['possible_cost_paid']) : null,
            'unmatchedHosts' => $this->analysis['unmatched_hosts'] ?? [],
            'discountPct' => (int) round($discount * 100),
            'historyDays' => (int) $this->analysis['history_days'],
            'minHistoryDays' => (int) $this->analysis['min_history_days'],
            'unattached' => array_map(
                fn (array $disk) => sprintf(
                    '%s, %d GB, %s',
                    $disk['name'],
                    $disk['size_gb'],
                    $disk['machine_id'] === null ? 'not attached to any server' : 'attached but '.str_replace('_', ' ', $disk['state'])
                ),
                $this->analysis['unattached_disks']
            ),
        ], 'emails.text.servers.sizing-report');
    }

    /**
     * One card per server, every value already worded.
     *
     * @return list<array{name: string, verdict: string, tone: string, package: string, cost: string, lines: list<array{0: string, 1: string}>, reasons: list<string>, trend: list<string>}>
     */
    public function cards(): array
    {
        $cards = [];

        foreach ($this->analysis['servers'] as $server) {
            $package = $server['package'];
            $week = $server['week'];
            $lines = [];

            if ($week !== null) {
                $lines[] = ['Load, busy end of the week', sprintf(
                    '%s on %d processor%s (%s each)',
                    self::number($week['load_p95']),
                    $week['cpus'],
                    $week['cpus'] === 1 ? '' : 's',
                    self::number($week['load_per_cpu_p95'])
                )];
                if ($week['cpu_busy_p95'] !== null) {
                    $lines[] = ['Processor time in use', sprintf(
                        '%s at the busy end, %s on average',
                        self::percent($week['cpu_busy_p95']),
                        self::percent($week['cpu_busy_avg'])
                    )];
                }
                $lines[] = ['Memory in use', sprintf(
                    '%s of %s GB (%s)%s',
                    self::number($week['mem_used_gb_p95']),
                    self::number($week['mem_total_gb']),
                    self::percent($week['mem_used_pct_p95']),
                    $week['swap_used_mb_max'] >= 100 ? sprintf(', swap up to %s GB', self::number($week['swap_used_mb_max'] / 1024)) : ''
                )];
            }

            foreach ($server['disks'] as $disk) {
                $growth = $disk['growth_gb_per_week'] === null
                    ? 'growth not known yet'
                    : sprintf('%s%s GB a week', $disk['growth_gb_per_week'] >= 0 ? '+' : '', self::number($disk['growth_gb_per_week']));
                if ($disk['weeks_to_90'] !== null) {
                    $growth .= $disk['weeks_to_90'] <= 0
                        ? ', already past 90%'
                        : sprintf(', 90%% full in about %s weeks', self::number($disk['weeks_to_90']));
                }
                $lines[] = [($disk['flag'] ? 'Disk filling: ' : 'Disk ').$disk['mount'], sprintf(
                    '%s of %s GB (%s), %s',
                    self::number($disk['used_gb']),
                    self::number($disk['size_gb']),
                    self::percent($disk['used_pct']),
                    $growth
                )];
            }

            if ($server['extra_disk_gb'] > 0) {
                $lines[] = ['Disk rented', sprintf(
                    '%d GB, of which %d GB is beyond the package and charged for',
                    $server['disk_total_gb'],
                    $server['extra_disk_gb']
                )];
            }

            $steps = [];
            foreach (['down' => 'Next size down', 'up' => 'Next size up'] as $direction => $label) {
                $step = $server['steps'][$direction] ?? null;
                if ($step !== null) {
                    $steps[] = sprintf(
                        '%s: %s (%d processor%s, %d GB) %s',
                        $label,
                        $step['name'],
                        $step['cpus'],
                        $step['cpus'] === 1 ? '' : 's',
                        $step['memory_gb'],
                        self::money($step['cost_paid'])
                    );
                }
            }
            if ($steps !== [] && ! in_array($server['verdict'], ['stopped'], true)) {
                $lines[] = ['Steps available', implode('; ', $steps)];
            }

            $trend = [];
            if (count($server['trend']) > 1) {
                foreach ($server['trend'] as $row) {
                    $trend[] = sprintf(
                        '%s: load %s per processor, memory %s%s',
                        $row['week_ending'],
                        self::number($row['load_per_cpu_p95']),
                        self::percent($row['mem_used_pct_p95']),
                        $row['disk_used_gb'] === null ? '' : sprintf(', disk %s GB', self::number($row['disk_used_gb']))
                    );
                }
            }

            [$verdict, $tone] = $this->verdict($server);

            $cards[] = [
                'name' => $server['name'],
                'verdict' => $verdict,
                'tone' => $tone,
                'package' => $package === null
                    ? (string) ($server['package_name'] ?? 'unknown package')
                    : sprintf('%s: %d processor%s, %d GB memory, %d GB disk included', $package['name'], $package['cpus'], $package['cpus'] === 1 ? '' : 's', $package['memory_gb'], $package['disk_gb']),
                'cost' => self::money($server['cost']['paid']).' a month',
                'lines' => $lines,
                'reasons' => $server['reasons'],
                'trend' => $trend,
            ];
        }

        return $cards;
    }

    /**
     * The verdict in words, and whether it wants attention ("act"), is fine ("ok") or is only
     * information ("info").
     *
     * @param  array<string, mixed>  $server
     * @return array{0: string, 1: string}
     */
    private function verdict(array $server): array
    {
        $candidate = $server['candidate'];
        $saving = $candidate === null ? 0.0 : (float) $candidate['saving_paid'];
        $extra = $saving < 0 ? 'costs '.self::money(-$saving).' more a month' : 'costs no more';

        return match ($server['verdict']) {
            'step_down' => [sprintf('Move down to %s: saves %s a month', $candidate['name'], self::money($saving)), 'act'],
            'step_up' => [$candidate === null
                ? 'Too small, and no bigger package of this kind fits'
                : sprintf('Move up to %s: %s', $candidate['name'], $extra), 'act'],
            'wait' => match ($server['indication']) {
                'step_down' => [sprintf('Too early to say. So far: could move down to %s, saving %s a month', $candidate['name'], self::money($saving)), 'info'],
                // An overloaded server is worth a look now, even before a move is suggested.
                'step_up' => [$candidate === null
                    ? 'Too early to say. So far: looks too small'
                    : sprintf('Too early to say. So far: looks too small; %s %s', $candidate['name'], $extra), 'act'],
                default => ['Too early to say. So far: the size looks right', 'info'],
            },
            'stopped' => ['Stopped', 'info'],
            'unmeasured' => ['Not measured', 'info'],
            default => ['Keep as it is', 'ok'],
        };
    }

    private static function money(float|int $pounds): string
    {
        return '£'.number_format((float) $pounds, 2);
    }

    private static function percent(float|int $share): string
    {
        return (int) round($share * 100).'%';
    }

    private static function number(float|int $value): string
    {
        $text = number_format((float) $value, abs($value) < 10 ? 2 : 1);

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}

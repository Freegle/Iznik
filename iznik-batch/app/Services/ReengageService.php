<?php

namespace App\Services;

use App\Mail\Reengage\ReengageMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use App\Support\ExperimentBucket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Drives the localised 3-stage re-engagement sequence for lapsed users.
 *
 * Cadence (research-backed for a weekly-digest community sender):
 *   - candidate once inactive >= trigger_days and < max_days
 *   - stage 1 "nearby"  → stage 2 "impact" → stage 3 "preferences"
 *   - stages spaced >= stage_gap_days apart
 *   - the sequence stops (Suppressed) after stage 3 with no re-engagement
 *   - it also stops, and resets, the moment the user logs in: any auto-login
 *     CTA (or any site visit) bumps users.lastaccess past our sends, so the
 *     "rows newer than lastaccess" view of the sequence empties out.
 *
 * Experiment layer (dark until FREEGLE_REENGAGE_EXPERIMENT_ROLLOUT_PCT > 0):
 *   - each user gets a stable arm ('control' | 'a' | 'b') from a deterministic
 *     CRC32 bucket, held constant across all three stages
 *   - 'control' is a true holdout — a reengage row is recorded (so it progresses
 *     and is provably drawn from the same eligible pool) but NO mail is sent,
 *     making the lift of the treatment arms measurable
 *   - the journey segment (offer/wanted/replier/other — the user's first action
 *     when they joined) is recorded per send so copy and effectiveness can be
 *     cut by it.
 *
 * Dark by default: gated by BOTH the FREEGLE_MAIL_ENABLED_TYPES kill-switch
 * (type "Reengage") and FREEGLE_REENGAGE_ALLOWLIST (empty = nobody).
 */
class ReengageService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'Reengage';

    /**
     * Stage number → arm → template basename. The 'a' arm is the current copy;
     * 'b' is the alternate variant. Control users record the position but nothing
     * is rendered/sent for them.
     */
    private const STAGE_TEMPLATE = [
        1 => ['a' => 'nearby', 'b' => 'nearby'],
        2 => ['a' => 'impact', 'b' => 'impact'],
        3 => ['a' => 'preferences', 'b' => 'preferences'],
    ];

    public function __construct(
        private readonly ReengageContentService $content = new ReengageContentService(),
    ) {
    }

    /**
     * Process the whole candidate cohort.
     *
     * @return array{stage1: int, stage2: int, stage3: int, control: int, suppressed: int}
     */
    public function processReengageEmails(bool $dryRun = false): array
    {
        $counts = ['stage1' => 0, 'stage2' => 0, 'stage3' => 0, 'control' => 0, 'suppressed' => 0];

        $allowlist = $this->allowlist();

        // Dark unless an operator has both enabled the type and opted at least
        // one recipient in. Keeps the feature inert until deliberately rolled out.
        if ($allowlist === [] || ! static::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return $counts;
        }

        $triggerDays = (int) config('freegle.reengage.trigger_days', 30);
        $maxDays = (int) config('freegle.reengage.max_days', 175);
        $gapDays = (int) config('freegle.reengage.stage_gap_days', 45);

        // Inactive between max_days (oldest) and trigger_days (newest) ago.
        $oldest = now()->subDays($maxDays)->toDateTimeString();
        $newest = now()->subDays($triggerDays)->toDateTimeString();

        $candidateIds = DB::table('users')
            ->whereNull('deleted')
            ->where('bouncing', 0)
            // Honour the win-back / "relevant" marketing opt-out, exactly as the
            // sibling engage flow does (EngageEmailService).
            ->where('relevantallowed', 1)
            ->whereBetween('lastaccess', [$oldest, $newest])
            ->where(function ($q) {
                $q->whereNull('onholidaytill')->orWhere('onholidaytill', '<', now());
            })
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(users.settings, '$.simplemail') IS NULL")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(users.settings, '$.simplemail')) != ?", [User::SIMPLE_MAIL_NONE]);
            })
            ->select('id')
            ->orderBy('id')
            ->lazyById(1000)
            ->pluck('id');

        $lowerAllowlist = $allowlist === ['*'] ? ['*'] : array_map('strtolower', $allowlist);

        foreach ($candidateIds as $userId) {
            $result = $this->processUser((int) $userId, $lowerAllowlist, $gapDays, $dryRun);
            if ($result !== null && isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * Decide and (unless dry-run) send the next stage for one user.
     *
     * @return string|null 'stage1'|'stage2'|'stage3'|'control'|'suppressed', or null if skipped.
     */
    private function processUser(int $userId, array $lowerAllowlist, int $gapDays, bool $dryRun): ?string
    {
        $user = User::find($userId);
        if (! $user) {
            return null;
        }

        $email = $user->email_preferred;
        if (! $email) {
            return null;
        }

        if ($lowerAllowlist !== ['*'] && ! in_array(strtolower($email), $lowerAllowlist, true)) {
            return null;
        }

        // Must belong to a real Freegle group (approved member).
        $hasMembership = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->exists();

        if (! $hasMembership) {
            return null;
        }

        // Respect the per-group engagement opt-out (same key the engage flow uses).
        // Scope to APPROVED memberships so a stale non-approved row can't flip the
        // MAX() back to enabled and silently override a deliberate opt-out.
        $engagementEnabled = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('memberships.collection', Membership::COLLECTION_APPROVED)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->selectRaw('MAX(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(memberships.settings, "$.engagement")), 1)) AS enabled')
            ->value('enabled');

        if ($engagementEnabled === '0' || $engagementEnabled === 0) {
            return null;
        }

        // Sends made during the *current* lapse = those newer than lastaccess.
        // (A login resets lastaccess past them, naturally ending the sequence.)
        $rows = DB::table('reengage')
            ->where('userid', $userId)
            ->where('sentat', '>', $user->lastaccess)
            ->orderBy('sentat')
            ->get();

        $stagesSent = $rows->count();
        $lastSentAt = $rows->max('sentat');

        // Don't send two stages closer than the configured gap.
        if ($lastSentAt && now()->diffInDays($lastSentAt, false) > -$gapDays) {
            return null;
        }

        // Sequence already ran its course → suppress (record terminal state once).
        if ($stagesSent >= count(self::STAGE_TEMPLATE)) {
            $latest = $rows->last();
            if ($latest && $latest->outcome === null && ! $dryRun) {
                DB::table('reengage')->where('id', $latest->id)->update(['outcome' => 'Suppressed']);
            }

            return 'suppressed';
        }

        $stage = $stagesSent + 1;

        // Stable experiment assignment + journey segment, recorded on every row.
        [$experiment, $arm, $bucket] = $this->assignArm($userId);
        $segment = $this->journeySegment($userId);

        // Control arm: record the send position but mail nothing (holdout).
        if ($arm === 'control') {
            if (! $dryRun) {
                DB::table('reengage')->insert([
                    'userid' => $userId,
                    'stage' => $stage,
                    'template' => null,
                    'experiment' => $experiment,
                    'arm' => 'control',
                    'bucket' => $bucket,
                    'segment' => $segment,
                    'sentat' => now(),
                ]);
            }

            return 'control';
        }

        $template = self::STAGE_TEMPLATE[$stage][$arm] ?? self::STAGE_TEMPLATE[$stage]['a'];

        $content = $this->content->buildContent($user, self::STAGE_TEMPLATE[$stage]['a']);
        // Drive per-arm / per-journey copy differences inside the shared template.
        $content['arm'] = $arm;
        $content['segment'] = $segment;
        $subject = $this->subjectFor($stage, $content, $arm, $segment);

        if (! $dryRun) {
            $mail = new ReengageMail(
                recipientName: $user->display_name,
                recipientEmail: $email,
                emailSubject: $subject,
                template: $template,
                userId: $userId,
                content: $content,
            );

            // The tracking row is created in the mailable's constructor; capture
            // its id so opens/clicks join back to this exact send.
            $trackingId = $mail->getTrackingId();

            app(EmailSpoolerService::class)->spool($mail, $email, 'reengage');

            DB::table('reengage')->insert([
                'userid' => $userId,
                'stage' => $stage,
                'template' => $template,
                'experiment' => $experiment,
                'arm' => $arm,
                'bucket' => $bucket,
                'segment' => $segment,
                'email_tracking_id' => $trackingId,
                'sentat' => now(),
            ]);
        }

        return 'stage' . $stage;
    }

    /**
     * Resolve the stable experiment arm for a user.
     *
     * Two independent gates, both dark by default:
     *   - rollout_pct decides whether the user is in the experiment at all;
     *     outside it they get arm 'a' (today's single-template behaviour, no holdout).
     *   - within the experiment, a second stable bucket picks control/a/b.
     *
     * @return array{0: string, 1: string, 2: int} [experimentName, arm, bucket]
     */
    private function assignArm(int $userId): array
    {
        $name = (string) config('freegle.reengage.experiment.name', 'reengage-v1');
        $rolloutPct = (int) config('freegle.reengage.experiment.rollout_pct', 0);
        $arms = (array) config('freegle.reengage.experiment.arms', []);

        // Not in the experiment → default arm 'a', no holdout, no experiment tag.
        if ($rolloutPct <= 0 || $arms === []) {
            return ['', 'a', ExperimentBucket::bucket($userId, $name)];
        }

        $inBucket = ExperimentBucket::bucket($userId, $name);
        if ($inBucket >= $rolloutPct) {
            return ['', 'a', $inBucket];
        }

        // Second, decorrelated bucket for the arm split within the experiment.
        $resolved = ExperimentBucket::resolveArm($userId, $name . ':arm', $arms);
        $arm = $resolved['arm'] !== '' ? $resolved['arm'] : 'a';

        return [$name, $arm, $resolved['bucket']];
    }

    /**
     * Classify the user by their FIRST action on Freegle (used to tailor copy
     * and to cut effectiveness by journey). Cheap: two indexed point queries.
     *   - first post was an Offer  → 'offer'
     *   - first post was a Wanted  → 'wanted'
     *   - first action was a chat reply (no earlier post) → 'replier'
     *   - never posted or chatted   → 'other'
     */
    public function journeySegment(int $userId): string
    {
        $firstPost = DB::table('messages')
            ->where('fromuser', $userId)
            ->whereIn('type', ['Offer', 'Wanted'])
            ->whereNull('deleted')
            ->orderBy('arrival')
            ->limit(1)
            ->first(['type', 'arrival']);

        $firstChatAt = DB::table('chat_messages')
            ->where('userid', $userId)
            ->where('type', 'Default')
            ->min('date');

        if ($firstPost && (! $firstChatAt || $firstPost->arrival <= $firstChatAt)) {
            return $firstPost->type === 'Offer' ? 'offer' : 'wanted';
        }

        if ($firstChatAt !== null) {
            return 'replier';
        }

        return 'other';
    }

    /**
     * Detect and record REAL re-engagement per send, from the precise event trail
     * (logs Login/Replied + a new post) rather than the mutable `lastaccess`.
     * Writes reengaged_at / reengaged_via and sets outcome='Reengaged' for any
     * reengage row (treatment OR control-holdout) whose user came back within the
     * outcome window. Idempotent: skips rows already resolved. This is what makes
     * per-stage/arm/segment effectiveness (and control lift) measurable.
     *
     * @return array{checked: int, reengaged: int}
     */
    public function updateOutcomes(?int $windowDays = null): array
    {
        $windowDays = $windowDays ?? (int) config('freegle.reengage.experiment.outcome_window_days', 14);
        $stats = ['checked' => 0, 'reengaged' => 0];

        $rows = DB::table('reengage')
            ->whereNull('reengaged_at')
            ->where(function ($q) {
                $q->whereNull('outcome')->orWhere('outcome', '!=', 'Reengaged');
            })
            // Only rows recent enough that their window is open or recently closed.
            ->where('sentat', '>=', now()->subDays($windowDays + 60)->toDateTimeString())
            ->select('id', 'userid', 'sentat')
            ->orderBy('id')
            ->lazyById(1000);

        foreach ($rows as $row) {
            $stats['checked']++;
            $windowEnd = \Illuminate\Support\Carbon::parse($row->sentat)->addDays($windowDays)->toDateTimeString();

            $candidates = array_filter([
                'login' => DB::table('logs')->where('user', $row->userid)->where('subtype', 'Login')
                    ->whereBetween('timestamp', [$row->sentat, $windowEnd])->min('timestamp'),
                'reply' => DB::table('logs')->where('user', $row->userid)->where('subtype', 'Replied')
                    ->whereBetween('timestamp', [$row->sentat, $windowEnd])->min('timestamp'),
                'post' => DB::table('messages')->where('fromuser', $row->userid)
                    ->whereIn('type', ['Offer', 'Wanted'])->whereNull('deleted')
                    ->whereBetween('arrival', [$row->sentat, $windowEnd])->min('arrival'),
            ]);

            if ($candidates === []) {
                continue;
            }

            asort($candidates); // earliest datetime string first
            $via = (string) array_key_first($candidates);

            DB::table('reengage')->where('id', $row->id)->update([
                'reengaged_at' => $candidates[$via],
                'reengaged_via' => $via,
                'outcome' => 'Reengaged',
            ]);
            $stats['reengaged']++;
        }

        return $stats;
    }

    /**
     * Send one of each stage to a single address with sample data, for visual
     * review in mailpit. Bypasses the dark-ship gates (operator-triggered).
     * Renders both the 'a' and 'b' variant of every stage so both are reviewable.
     */
    public function sendPreview(string $email): int
    {
        $sent = 0;

        foreach (self::STAGE_TEMPLATE as $stage => $arms) {
            foreach ($arms as $arm => $template) {
                $content = $this->content->previewContent(self::STAGE_TEMPLATE[$stage]['a'], $email);
                $subject = '[Preview ' . strtoupper((string) $arm) . '] ' . $this->subjectFor($stage, $content, (string) $arm, 'offer');

                Mail::to($email)->send(new ReengageMail(
                    recipientName: $content['name'] ?? 'there',
                    recipientEmail: $email,
                    emailSubject: $subject,
                    template: $template,
                    userId: 0,
                    content: $content,
                ));

                $sent++;
            }
        }

        return $sent;
    }

    /**
     * Build the (semi-localised) subject line for a stage, varying by experiment
     * arm and journey segment. The 'b' arm leads with a warmer, more personal
     * framing; the segment tweaks the framing for how the user first used Freegle.
     */
    public function subjectFor(int $stage, array $c, string $arm = 'a', string $segment = 'other'): string
    {
        $count = (int) ($c['offerCount'] ?? 0);
        $noun = $count === 1 ? 'thing' : 'things';

        if ($arm === 'b') {
            return match ($stage) {
                1 => $count > 0
                    ? 'Your neighbours are giving away ' . number_format($count) . " {$noun} nearby"
                    : 'Your Freegle neighbourhood is busy again',
                2 => 'The people near you have been freegling',
                3 => 'Before we lose touch…',
                default => 'We miss you on Freegle',
            };
        }

        // Arm 'a' (current copy), with the singular fixed.
        return match ($stage) {
            1 => $count > 0
                ? number_format($count) . " free {$noun} near you this week"
                : "See what's being given away near you",
            2 => "See who's been freegling near you",
            3 => 'Shall we stay in touch?',
            default => 'We miss you on Freegle',
        };
    }

    /**
     * Parse FREEGLE_REENGAGE_ALLOWLIST (mirrors the digest daily allowlist):
     * [] = nobody, ['*'] = everyone, else the opted-in addresses.
     *
     * @return array<int, string>
     */
    private function allowlist(): array
    {
        $raw = trim((string) config('freegle.reengage.allowlist', ''));
        if ($raw === '') {
            return [];
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $raw)), fn ($s) => $s !== ''));

        if (in_array('*', $parts, true)) {
            return ['*'];
        }

        return $parts;
    }
}

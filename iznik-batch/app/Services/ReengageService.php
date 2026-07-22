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
 * Drives the FIRST-WEEK ONBOARDING tip sequence for NEW members: one short,
 * friendly tip a day for the first five days, following the welcome mail. Kept
 * under the historical "reengage" name (table/classes/effectiveness dashboard)
 * because the sequencing, tracking and experiment machinery are identical; only
 * the trigger and content changed from the earlier lapsed-user idea.
 *
 * Cadence:
 *   - a member becomes a candidate once their account is >= start_day old and
 *     while it is young enough to still be in (or start) the sequence;
 *   - tip N is sent when the account reaches (start_day + (N-1)*gap) days old,
 *     one tip per day, up to `stages` tips;
 *   - a fresh member gets tip 1 only if the account is <= max_start_days old, so
 *     first enabling the feature can't back-blast everyone who joined last month.
 *
 * Excluded: TrashNothing and LoveJunk proxy accounts (they onboard through their
 * own platform), plus the usual bounce / holiday / marketing-opt-out gates.
 *
 * Personalised: every tip is signed off by a real local volunteer from a
 * community the member genuinely joined (see ReengageContentService), or the
 * plain Freegle voice when there's no such volunteer.
 *
 * Experiment/instrumentation layer (dark until rollout_pct > 0): each member
 * gets a stable arm ('control'|'a'|'b') and a journey segment, recorded per send
 * so tip effectiveness (opens/clicks/actions) is measurable in the sysadmin
 * dashboard, joined back via the email_tracking id.
 *
 * Dark by default: gated by BOTH the FREEGLE_MAIL_ENABLED_TYPES kill-switch
 * (type "Reengage") and FREEGLE_REENGAGE_ALLOWLIST (empty = nobody).
 */
class ReengageService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'Reengage';

    /** Number of tips in the onboarding sequence (day 1..N). */
    public const TIPS = 5;

    /**
     * Stage (day) number → arm → template basename. Every day shares the one
     * `tip` template, whose per-day copy comes from ReengageContentService. The
     * 'a'/'b' arms exist for future copy experiments; today both render `tip`.
     */
    private const STAGE_TEMPLATE = [
        1 => ['a' => 'tip', 'b' => 'tip'],
        2 => ['a' => 'tip', 'b' => 'tip'],
        3 => ['a' => 'tip', 'b' => 'tip'],
        4 => ['a' => 'tip', 'b' => 'tip'],
        5 => ['a' => 'tip', 'b' => 'tip'],
    ];

    public function __construct(
        private readonly ReengageContentService $content = new ReengageContentService(),
    ) {
    }

    /**
     * Process the whole candidate cohort of new members.
     *
     * @return array{sent: int, completed: int, control: int, skipped: int}
     */
    public function processReengageEmails(bool $dryRun = false): array
    {
        $counts = ['sent' => 0, 'completed' => 0, 'control' => 0, 'skipped' => 0];

        $allowlist = $this->allowlist();

        // Dark unless an operator has both enabled the type and opted at least
        // one recipient in. Keeps the feature inert until deliberately rolled out.
        if ($allowlist === [] || ! static::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return $counts;
        }

        $startDay = (int) config('freegle.reengage.start_day', 1);
        $gapDays = max(1, (int) config('freegle.reengage.stage_gap_days', 1));
        $maxStartDays = (int) config('freegle.reengage.max_start_days', 7);

        // The window of account ages that can still be in the sequence: young
        // enough to have started (>= start_day) up to old enough to be finishing
        // the last tip (or a late first-start capped by max_start_days).
        $windowDays = $maxStartDays + (self::TIPS - 1) * $gapDays;
        $oldest = now()->subDays($windowDays)->toDateTimeString();
        $newest = now()->subDays($startDay)->toDateTimeString();

        $candidateIds = DB::table('users')
            ->whereNull('deleted')
            ->where('bouncing', 0)
            // Honour the marketing opt-out, exactly as the sibling flows do.
            ->where('relevantallowed', 1)
            // New members only: keyed off account creation date.
            ->whereBetween('added', [$oldest, $newest])
            // TrashNothing / LoveJunk proxy accounts onboard through their own
            // platform - never send them Freegle onboarding tips. (Emails live in
            // users_emails, not users.email, so the domain case for a TN account
            // without tnuserid set is caught per-user by isTN() in processUser.)
            ->whereNull('tnuserid')
            ->whereNull('ljuserid')
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
            $result = $this->processUser((int) $userId, $lowerAllowlist, $startDay, $gapDays, $maxStartDays, $dryRun);
            if ($result !== null && isset($counts[$result])) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * Decide and (unless dry-run) send the next due tip for one new member.
     *
     * @return string|null 'sent'|'completed'|'control'|'skipped', or null if not a candidate.
     */
    private function processUser(int $userId, array $lowerAllowlist, int $startDay, int $gapDays, int $maxStartDays, bool $dryRun): ?string
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

        // Belt-and-braces: skip TN/LJ even if they slipped through the SQL gate
        // (e.g. a domain the query didn't catch).
        if ($user->isTN() || $user->isLJ()) {
            return null;
        }

        // Must have genuinely joined a real Freegle group (approved member).
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

        // How far through the sequence this member is: one reengage row per tip.
        $rows = DB::table('reengage')
            ->where('userid', $userId)
            ->orderBy('sentat')
            ->get();

        $stagesSent = $rows->count();

        // Sequence already ran its course → record the terminal state once.
        if ($stagesSent >= self::TIPS) {
            $latest = $rows->last();
            if ($latest && $latest->outcome === null && ! $dryRun) {
                DB::table('reengage')->where('id', $latest->id)->update(['outcome' => 'Suppressed']);
            }

            return 'completed';
        }

        $stage = $stagesSent + 1;
        $ageDays = $user->added ? now()->diffInDays($user->added, false) * -1 : 0;

        // Not yet due for this tip (account too young for its day threshold).
        $requiredAge = $startDay + ($stage - 1) * $gapDays;
        if ($ageDays < $requiredAge) {
            return 'skipped';
        }

        // Don't start the sequence for an account that's already too old.
        if ($stage === 1 && $ageDays > $maxStartDays) {
            return 'skipped';
        }

        // Never send two tips the same day.
        $lastSentAt = $rows->max('sentat');
        if ($lastSentAt && now()->diffInDays($lastSentAt, false) > -$gapDays) {
            return 'skipped';
        }

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

        $content = $this->content->buildContent($user, $stage);
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
                // Which community the sign-off came from, and how it was chosen
                // (home catchment / nearest centre / unknown / none). Only the
                // sent arm resolves a volunteer; control holdouts stay NULL, so
                // the sysadmin breakdown measures the mailed population.
                'volunteer_groupid' => $content['volunteerGroupId'] ?? null,
                'volunteer_source' => $content['volunteerSource'] ?? 'none',
                'sentat' => now(),
            ]);
        }

        return 'sent';
    }

    /**
     * Resolve the stable experiment arm for a user. Two independent gates, both
     * dark by default: rollout_pct decides whether the user is in the experiment
     * at all (outside it they get arm 'a', no holdout); within it a second stable
     * bucket picks control/a/b.
     *
     * @return array{0: string, 1: string, 2: int} [experimentName, arm, bucket]
     */
    private function assignArm(int $userId): array
    {
        $name = (string) config('freegle.reengage.experiment.name', 'reengage-v1');
        $rolloutPct = (int) config('freegle.reengage.experiment.rollout_pct', 0);
        $arms = (array) config('freegle.reengage.experiment.arms', []);

        if ($rolloutPct <= 0 || $arms === []) {
            return ['', 'a', ExperimentBucket::bucket($userId, $name)];
        }

        $inBucket = ExperimentBucket::bucket($userId, $name);
        if ($inBucket >= $rolloutPct) {
            return ['', 'a', $inBucket];
        }

        $resolved = ExperimentBucket::resolveArm($userId, $name . ':arm', $arms);
        $arm = $resolved['arm'] !== '' ? $resolved['arm'] : 'a';

        return [$name, $arm, $resolved['bucket']];
    }

    /**
     * Classify the member by their FIRST action on Freegle (to cut effectiveness
     * by journey, and available to tailor copy later). Cheap indexed lookups.
     *   - first post was an Offer  → 'offer'
     *   - first post was a Wanted  → 'wanted'
     *   - first action was a chat reply (no earlier post) → 'replier'
     *   - nothing yet (common for brand-new members) → 'other'
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
     * Detect and record whether a tip drove a real ACTION (login/reply/post)
     * within the outcome window, for any reengage row (treatment OR control
     * holdout). Writes reengaged_at / reengaged_via and outcome='Reengaged'.
     * Idempotent. This is what makes per-tip/arm/segment effectiveness (and the
     * control lift) measurable.
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

            asort($candidates);
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
     * Send every tip in the sequence to a single address with sample data, for
     * visual review in mailpit. Bypasses the dark-ship gates (operator-triggered).
     */
    public function sendPreview(string $email): int
    {
        $sent = 0;

        for ($day = 1; $day <= self::TIPS; $day++) {
            $content = $this->content->previewContent($day, $email);
            $subject = '[Preview] ' . $this->subjectFor($day, $content, 'a', 'other');

            Mail::to($email)->send(new ReengageMail(
                recipientName: $content['name'] ?? 'there',
                recipientEmail: $email,
                emailSubject: $subject,
                template: self::STAGE_TEMPLATE[$day]['a'],
                userId: 0,
                content: $content,
            ));

            $sent++;
        }

        return $sent;
    }

    /**
     * Subject line for a given day's tip. Mirrors the tip heading so the inbox
     * and the email agree.
     */
    public function subjectFor(int $stage, array $c, string $arm = 'a', string $segment = 'other'): string
    {
        return match ($stage) {
            1 => 'Welcome to Freegle - your first tip',
            2 => 'The stuff you think nobody wants',
            3 => 'Need something? Just ask',
            4 => 'Did you know you can search Freegle?',
            5 => "You're a freegler now 🎉",
            default => 'Getting started on Freegle',
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

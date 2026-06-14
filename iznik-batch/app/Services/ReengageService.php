<?php

namespace App\Services;

use App\Mail\Reengage\ReengageMail;
use App\Mail\Traits\FeatureFlags;
use App\Models\Group;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Drives the localised 3-stage re-engagement sequence for lapsed users.
 *
 * Cadence (research-backed for a weekly-digest community sender):
 *   - candidate once inactive >= trigger_days (default 90)
 *   - stage 1 "nearby"  → stage 2 "impact" → stage 3 "preferences"
 *   - stages spaced >= stage_gap_days (default 10) apart
 *   - the sequence stops (Suppressed) after stage 3 with no re-engagement
 *   - it also stops, and resets, the moment the user logs in: any auto-login
 *     CTA (or any site visit) bumps users.lastaccess past our sends, so the
 *     "rows newer than lastaccess" view of the sequence empties out.
 *
 * Dark by default: gated by BOTH the FREEGLE_MAIL_ENABLED_TYPES kill-switch
 * (type "Reengage") and FREEGLE_REENGAGE_ALLOWLIST (empty = nobody).
 */
class ReengageService
{
    use FeatureFlags;

    public const EMAIL_TYPE = 'Reengage';

    /** Stage number → template basename. */
    private const STAGE_TEMPLATE = [
        1 => 'nearby',
        2 => 'impact',
        3 => 'preferences',
    ];

    public function __construct(
        private readonly ReengageContentService $content = new ReengageContentService(),
    ) {
    }

    /**
     * Process the whole candidate cohort.
     *
     * @return array{stage1: int, stage2: int, stage3: int, suppressed: int}
     */
    public function processReengageEmails(bool $dryRun = false): array
    {
        $counts = ['stage1' => 0, 'stage2' => 0, 'stage3' => 0, 'suppressed' => 0];

        $allowlist = $this->allowlist();

        // Dark unless an operator has both enabled the type and opted at least
        // one recipient in. Keeps the feature inert until deliberately rolled out.
        if ($allowlist === [] || ! static::isEmailTypeEnabled(self::EMAIL_TYPE)) {
            return $counts;
        }

        $triggerDays = (int) config('freegle.reengage.trigger_days', 90);
        $maxDays = (int) config('freegle.reengage.max_days', 175);
        $gapDays = (int) config('freegle.reengage.stage_gap_days', 10);

        // Inactive between max_days (oldest) and trigger_days (newest) ago.
        $oldest = now()->subDays($maxDays)->toDateTimeString();
        $newest = now()->subDays($triggerDays)->toDateTimeString();

        $candidateIds = DB::table('users')
            ->whereNull('deleted')
            ->where('bouncing', 0)
            ->whereBetween('lastaccess', [$oldest, $newest])
            ->where(function ($q) {
                $q->whereNull('onholidaytill')->orWhere('onholidaytill', '<', now());
            })
            ->where(function ($q) {
                $q->whereRaw("JSON_EXTRACT(users.settings, '$.simplemail') IS NULL")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(users.settings, '$.simplemail')) != ?", [User::SIMPLE_MAIL_NONE]);
            })
            ->orderBy('id')
            ->lazyById(1000)
            ->pluck('id');

        $lowerAllowlist = $allowlist === ['*'] ? ['*'] : array_map('strtolower', $allowlist);

        foreach ($candidateIds as $userId) {
            $result = $this->processUser((int) $userId, $lowerAllowlist, $gapDays, $dryRun);
            if ($result !== null) {
                $counts[$result]++;
            }
        }

        return $counts;
    }

    /**
     * Decide and (unless dry-run) send the next stage for one user.
     *
     * @return string|null One of 'stage1'|'stage2'|'stage3'|'suppressed', or null if skipped.
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
        $engagementEnabled = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
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
        $template = self::STAGE_TEMPLATE[$stage];

        $content = $this->content->buildContent($user, $template);
        $subject = $this->subjectFor($stage, $content);

        if (! $dryRun) {
            app(EmailSpoolerService::class)->spool(
                new ReengageMail(
                    recipientName: $user->display_name,
                    recipientEmail: $email,
                    emailSubject: $subject,
                    template: $template,
                    userId: $userId,
                    content: $content,
                ),
                $email,
                'reengage',
            );

            DB::table('reengage')->insert([
                'userid' => $userId,
                'stage' => $stage,
                'template' => $template,
                'sentat' => now(),
            ]);
        }

        return 'stage' . $stage;
    }

    /**
     * Send one of each stage to a single address with sample data, for visual
     * review in mailpit. Bypasses the dark-ship gates (operator-triggered).
     */
    public function sendPreview(string $email): int
    {
        $sent = 0;

        foreach (self::STAGE_TEMPLATE as $stage => $template) {
            $content = $this->content->previewContent($template, $email);
            $subject = '[Preview] ' . $this->subjectFor($stage, $content);

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

        return $sent;
    }

    /**
     * Build the (semi-localised) subject line for a stage from its content.
     */
    public function subjectFor(int $stage, array $c): string
    {
        $area = $c['areaName'] ?? null;
        $count = (int) ($c['offerCount'] ?? 0);

        return match ($stage) {
            1 => $count > 0 && $area
                ? number_format($count) . " free things near {$area} this week"
                : ($area ? "See what's free near {$area}" : "See what's being given away near you"),
            2 => $area
                ? "Your neighbours near {$area} have been freegling"
                : "See who's been freegling near you",
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

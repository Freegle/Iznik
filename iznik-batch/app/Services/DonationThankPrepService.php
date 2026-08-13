<?php

namespace App\Services;

use App\Mail\Donation\DonationThankPrepMail;
use App\Models\Group;
use App\Support\EmojiUtils;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily *thank-prep* mails — one email PER donation, aimed at
 * the person composing thank-you replies (currently Jacky). Each card
 * contains the data she'd otherwise gather by hand from Modtools, info@,
 * support@, giftaid@ and the Gift Aid register: donor identity, donation
 * history, GA status, group memberships, mod notes, recent member↔mod chat,
 * deep links.
 *
 * Deliberately a *separate* path from {@see DonationSummaryService}, which
 * sends the simple V1-parity status table. The status mail tells the
 * finance team what landed in the bank; this digest tells the thanker who
 * still needs a personal reply. Conflating them produced PR #571's confused
 * "Donations today" email; the split keeps each concern legible.
 *
 * Dedup is by a config-table high-water-mark (CONFIG_KEY_LAST_ID): each run
 * examines only donations with id > the stored mark, then advances the mark
 * to the max id *examined* (thanked or skipped) so a continuation or
 * sub-threshold gift is never reconsidered. First run initialises the mark
 * to MAX(id) so the historical backlog is not dumped. (The per-row
 * users_donations.thanked column is display-only here and is not used for
 * filtering.)
 *
 * `--today` resend mode bypasses the mark entirely: it selects today's
 * donations and does not read or advance the mark, so a normal cron run is
 * unaffected. `--recipient=` routes that resend to an ad-hoc address.
 */
class DonationThankPrepService
{
    /**
     * Config-table key for the high-water-mark donation ID. Same pattern used
     * by {@see GitSummaryService} (key `git_summary_last_run`) — single row
     * in the existing config(key,value) table, no schema change required.
     * First run initialises to MAX(users_donations.id) so the historical
     * backlog isn't dumped into Jacky's inbox on day one.
     */
    private const CONFIG_KEY_LAST_ID = 'donation_thank_prep_last_id';

    private const RECURRING_TYPES = ['recurring_payment', 'subscr_payment'];

    private const SOURCE_LABELS = [
        'DonateWithPayPal' => 'PayPal',
        'PayPalGivingFund' => 'PayPal Giving Fund',
        'Facebook'         => 'Facebook',
        'eBay'             => 'eBay',
        'BankTransfer'     => 'Bank transfer',
        'Stripe'           => 'Stripe',
    ];

    /**
     * @param  bool         $dryRun            Build the digest but don't send or advance the mark.
     * @param  string|null  $recipientOverride Send to this address instead of thanks_addr (for ad-hoc resends).
     * @param  bool         $todayOnly         Select today's donations and ignore/preserve the high-water mark
     *                                         (for resends — does not advance the mark).
     * @return array{donations: int, examined: int, total: float, sent: bool, last_id: int}
     */
    public function sendDailyThankPrep(bool $dryRun = false, ?string $recipientOverride = null, bool $todayOnly = false): array
    {
        // thanks_addr always resolves: its env default falls back to the
        // fundraising address (see config/freegle.php), so no runtime ??
        // fallback is needed.
        $recipient = $recipientOverride ?: config('freegle.mail.thanks_addr');

        if ($todayOnly) {
            // Resend / ad-hoc mode: select today's donations and neither read
            // nor advance the high-water mark, so a normal cron run is
            // unaffected. Server time is UTC; donation timestamps are UTC.
            $lastId    = null;
            $donations = DB::table('users_donations')
                ->where('timestamp', '>=', today()->toDateString())
                ->orderBy('id')
                ->get();
        } else {
            // High-water mark: the largest donation id we've already examined.
            // On first run the row doesn't exist — initialise to MAX(id) so the
            // historical backlog (~137k rows in prod as of deploy) isn't dumped
            // into the thanker's inbox. In a dry run we compute that mark but
            // must NOT persist it (a dry run has no side effects).
            $lastId    = $this->getLastSentId($dryRun);
            $donations = DB::table('users_donations')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->get();
        }

        $examined = $donations->count();
        $total    = 0.0;
        $cards    = [];
        $maxId    = (int) ($lastId ?? 0);
        foreach ($donations as $donation) {
            // Always advance the high-water mark, even for donations we skip,
            // so a continuation or sub-threshold gift isn't re-examined every
            // run and never re-surfaces tomorrow.
            if ((int) $donation->id > $maxId) {
                $maxId = (int) $donation->id;
            }

            $reason = $this->thankReason($donation);
            if ($reason === null) {
                continue;
            }

            $total  += (float) $donation->GrossAmount;
            $cards[] = $this->buildDonationCard($donation, $reason);
        }

        // Nothing in this batch warranted a thank-you (all continuations,
        // sub-threshold one-offs, or excluded payers). Advance the mark so the
        // skipped rows don't return, but send no email. In --today mode we
        // never touch the mark.
        if (empty($cards)) {
            if (!$todayOnly && !$dryRun && $maxId > (int) ($lastId ?? 0)) {
                $this->setLastSentId($maxId);
            }
            return ['donations' => 0, 'examined' => $examined, 'total' => 0.0, 'sent' => false, 'last_id' => $maxId];
        }

        if (!$dryRun) {
            // One email PER donation (not a combined digest) so each donor is a
            // separate thread in the thanker's inbox, with the donor's name,
            // email and amount in the subject line. Spool through
            // EmailSpoolerService so a transient mail-host failure doesn't lose
            // any of them — same pattern as the simple status mail in
            // DonationSummaryService.
            $spooler = app(\App\Services\EmailSpoolerService::class);
            foreach ($cards as $card) {
                $spooler->spool(
                    new DonationThankPrepMail(
                        recipientEmail: (string) $recipient,
                        cards: [$card],
                        total: (float) $card['donation']['amount'],
                    ),
                    (string) $recipient,
                );
            }

            // Advance the mark only after every spool returns, and only in
            // normal (high-water) mode. If a spool throws we leave the mark in
            // place and the next cron tick retries the same range — at-least-
            // once delivery (some donors may be re-mailed), the same trade-off
            // the EmailSpoolerService callers already accept. --today mode
            // never touches the mark.
            if (!$todayOnly) {
                $this->setLastSentId($maxId);
            }
        }

        return [
            'donations' => count($cards),
            'examined'  => $examined,
            'total'     => $total,
            'sent'      => !$dryRun,
            'last_id'   => $maxId,
        ];
    }

    /**
     * Why this donation warrants a thank-you, or null to skip. Mirrors V1
     * donateipn.php's trigger so we don't re-thank established donors:
     *
     *   - never for an excluded payer (PayPal Giving Fund / Tipalti);
     *   - for recurring donations, only the donor's first-ever payment (a new
     *     sign-up), never the monthly continuations;
     *   - for one-off donations, only at or above the manual-thanks threshold.
     *
     * The reason is surfaced on the card so the thanker can see at a glance
     * which kind of thank-you is due.
     *
     * @return array{key: string, text: string}|null
     */
    private function thankReason(object $donation): ?array
    {
        if ($this->isExcludedPayer((string) ($donation->Payer ?? ''))
            || (string) $donation->source === 'PayPalGivingFund') {
            return null;
        }

        $recurring = in_array((string) $donation->TransactionType, self::RECURRING_TYPES, true);

        if ($recurring) {
            return $this->isFirstDonation($donation)
                ? ['key' => 'new-recurring', 'text' => 'New recurring donation just set up']
                : null;
        }

        $amount = (float) $donation->GrossAmount;

        // Bank-transfer / manually-recorded external donations are deliberate
        // gifts (someone keyed them in), so they always warrant a thank-you
        // regardless of amount. This replaces the per-donation "please thank
        // them" email the Go AddDonation handler used to send.
        if ($this->isExternalDonation($donation)) {
            return [
                'key'  => 'external',
                'text' => 'External donation of £' . number_format($amount, 2)
                          . ' (bank transfer / manually recorded)',
            ];
        }

        $threshold = (float) config('freegle.donations.manual_thanks', 20);
        if ($amount >= $threshold) {
            return [
                'key'  => 'large-oneoff',
                'text' => 'One-off donation of £' . number_format($amount, 2)
                          . ' (£' . number_format($threshold, 0) . ' or more)',
            ];
        }

        return null;
    }

    /**
     * A deliberately-recorded external donation (bank transfer keyed in via the
     * AddDonation API), as opposed to an automatic PayPal/Stripe one-off.
     */
    private function isExternalDonation(object $donation): bool
    {
        return (string) ($donation->source ?? '') === 'BankTransfer'
            || (string) ($donation->type ?? '') === 'External';
    }

    /**
     * True when there is no earlier donation from the same donor — matched by
     * userid where known, otherwise by Payer email so a continuation from an
     * as-yet-unmatched recurring donor is still recognised as not-first.
     */
    private function isFirstDonation(object $donation): bool
    {
        $query = DB::table('users_donations')->where('id', '<', (int) $donation->id);

        if ($donation->userid) {
            $query->where('userid', (int) $donation->userid);
        } else {
            $query->where('Payer', (string) ($donation->Payer ?? ''));
        }

        return !$query->exists();
    }

    private function isExcludedPayer(string $payer): bool
    {
        if ($payer === '') {
            return false;
        }

        $list = array_filter(array_map(
            'trim',
            explode(',', (string) config('freegle.donations.excluded_payers', ''))
        ));

        foreach ($list as $excluded) {
            if (strcasecmp($payer, $excluded) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the high-water mark, lazily initialising to the current MAX(id)
     * so the first run after deploy sends nothing and subsequent runs only
     * pick up genuinely new donations.
     */
    private function getLastSentId(bool $dryRun = false): int
    {
        $row = DB::table('config')->where('key', self::CONFIG_KEY_LAST_ID)->first();
        if ($row && $row->value !== '' && $row->value !== null) {
            return (int) $row->value;
        }

        // First run: initialise to MAX(id) so the backlog isn't dumped. A dry
        // run computes the same mark but must NOT persist it — otherwise a
        // `--dry-run` on a fresh deploy would silently set the high-water mark.
        $maxId = (int) (DB::table('users_donations')->max('id') ?? 0);
        if (!$dryRun) {
            $this->setLastSentId($maxId);
        }
        return $maxId;
    }

    private function setLastSentId(int $id): void
    {
        DB::table('config')->upsert(
                [['key' => self::CONFIG_KEY_LAST_ID, 'value' => (string) $id]],
                ['key'],
                ['value']
            );
    }

    /**
     * Build the per-donation context block. Shaped around the fields the
     * person composing a thank-you actually reads: who the donor is, what
     * else they've given, where they sit in the Gift Aid register, what
     * mods have noted, the most recent member↔mod chat.
     */
    private function buildDonationCard(object $donation, array $reason): array
    {
        $recurring = in_array((string) $donation->TransactionType, self::RECURRING_TYPES, true);

        $localTime = Carbon::parse($donation->timestamp, 'UTC')
            ->setTimezone('Europe/London');

        $card = [
            // Why this donation is in the digest — shown on the card so the
            // thanker can see at a glance what kind of thank-you is due.
            'thankReason'    => (string) $reason['text'],
            'thankReasonKey' => (string) $reason['key'],
            'donation' => [
                'id'             => (int) $donation->id,
                'amount'         => (float) $donation->GrossAmount,
                'time'           => $localTime->format('H:i T'),
                'date'           => $localTime->format('D j M Y'),
                'source'         => self::SOURCE_LABELS[$donation->source] ?? (string) $donation->source,
                'sourceKey'      => (string) $donation->source,
                'payer'          => (string) ($donation->Payer ?? ''),
                'payerName'      => (string) ($donation->PayerDisplayName ?: $donation->Payer ?: ''),
                'recurring'      => $recurring,
                'transaction'    => (string) ($donation->TransactionID ?? ''),
                'thanked'        => $donation->thanked ? Carbon::parse($donation->thanked) : null,
                'giftaidConsent' => (int) $donation->giftaidconsent === 1,
                'giftaidClaimed' => $donation->giftaidclaimed ? Carbon::parse($donation->giftaidclaimed) : null,
            ],
            'user'            => null,
            'aliases'         => [],
            'donationHistory' => [],
            'giftaid'         => null,
            'memberships'     => [],
            'modNotes'        => [],
            'modChats'        => [],
            'birthdayHint'    => false,
            'flags'           => [],
            'candidates'      => [],
            'links'           => $this->buildLinks($donation),
        ];

        if ($donation->userid) {
            $card = $this->enrichMatchedDonor((int) $donation->userid, $donation, $card, $recurring);
        } else {
            $card['flags'][] = 'Unmatched donor — needs sleuthing';
            $card['candidates'] = $this->gatherCandidates($donation);
        }

        if ($recurring) {
            $card['flags'][] = 'Recurring';
        }
        if ($card['donation']['sourceKey'] === 'PayPalGivingFund') {
            $card['flags'][] = 'PGF — Gift Aid claimed by PayPal';
        }

        return $card;
    }

    private function enrichMatchedDonor(int $userId, object $donation, array $card, bool $recurring): array
    {
        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) {
            $card['flags'][] = 'User row missing (deleted?)';
            return $card;
        }

        $displayName = trim(($user->firstname ?? '') . ' ' . ($user->lastname ?? ''));
        if ($displayName === '') {
            $displayName = (string) ($user->fullname ?? '');
        }

        $card['user'] = [
            'id'          => (int) $user->id,
            'firstname'   => (string) ($user->firstname ?? ''),
            'lastname'    => (string) ($user->lastname ?? ''),
            'fullname'    => (string) ($user->fullname ?? ''),
            'displayName' => $displayName ?: 'Unknown',
            'added'       => $user->added ? Carbon::parse($user->added) : null,
            'deleted'     => $user->deleted !== null,
            'systemrole'  => (string) ($user->systemrole ?? 'User'),
        ];

        // Just the preferred external email. Drop Freegle-internal synthetic
        // addresses (e.g. `*@users.ilovefreegle.org` chat aliases) — the
        // thanker writes to one real address, not the chat shim.
        $preferred = DB::table('users_emails')
            ->where('userid', $userId)
            ->where('email', 'not like', '%@users.ilovefreegle.org')
            ->where('email', 'not like', '%@users.trash-nothing.com')
            ->orderByDesc('preferred')
            ->orderBy('added')
            ->value('email');
        $card['aliases'] = $preferred ? [$preferred] : [];

        $card['donationHistory'] = DB::table('users_donations')
            ->where('userid', $userId)
            ->where('id', '<>', $donation->id)
            ->orderByDesc('timestamp')
            ->limit(8)
            ->get(['GrossAmount', 'timestamp', 'source', 'thanked'])
            ->map(fn($d) => [
                'amount'  => (float) $d->GrossAmount,
                'date'    => Carbon::parse($d->timestamp, 'UTC')->setTimezone('Europe/London')->format('j M Y'),
                'source'  => self::SOURCE_LABELS[$d->source] ?? (string) $d->source,
                'thanked' => $d->thanked ? Carbon::parse($d->thanked) : null,
            ])
            ->all();

        $giftaid = DB::table('giftaid')->where('userid', $userId)->first();
        if ($giftaid) {
            $card['giftaid'] = [
                'period'            => (string) $giftaid->period,
                'declined'          => $giftaid->period === 'Declined',
                'reviewed'          => $giftaid->reviewed ? Carbon::parse($giftaid->reviewed) : null,
                'postcode'          => (string) ($giftaid->postcode ?? ''),
                'housenameornumber' => (string) ($giftaid->housenameornumber ?? ''),
                'homeaddress'       => (string) ($giftaid->homeaddress ?? ''),
                'updated'           => $giftaid->updated ? Carbon::parse($giftaid->updated) : null,
            ];
        }

        $card['memberships'] = DB::table('memberships')
            ->join('groups', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('memberships.collection', 'Approved')
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->orderBy('memberships.added')
            ->limit(10)
            ->get(['groups.id as groupid', 'groups.nameshort', 'groups.namefull', 'memberships.role', 'memberships.added'])
            ->map(fn($m) => [
                'groupid'     => (int) $m->groupid,
                'name'        => (string) ($m->namefull ?: $m->nameshort),
                'role'        => (string) $m->role,
                'memberSince' => $m->added ? Carbon::parse($m->added)->format('M Y') : '',
            ])
            ->all();

        $card['modNotes'] = DB::table('users_comments')
            ->where('userid', $userId)
            ->orderByDesc('date')
            ->limit(3)
            ->get(['id', 'date', 'byuserid', 'user1', 'user2', 'flag'])
            ->map(function ($c) {
                return [
                    'date'    => Carbon::parse($c->date)->format('j M Y'),
                    'flag'    => (int) $c->flag,
                    'snippet' => $this->cleanSnippet((string) ($c->user2 ?? $c->user1 ?? ''), 160),
                ];
            })
            ->all();

        $card['modChats']     = $this->fetchRecentModChats($userId);
        $card['birthdayHint'] = $this->donorHasBirthdayHint($userId, $donation, $recurring);

        return $card;
    }

    private function fetchRecentModChats(int $userId): array
    {
        $rooms = DB::table('chat_rooms')
            ->where('chattype', 'User2Mod')
            ->where(function ($q) use ($userId) {
                $q->where('user1', $userId)->orWhere('user2', $userId);
            })
            ->orderByDesc('latestmessage')
            ->limit(3)
            ->pluck('id');

        if ($rooms->isEmpty()) {
            return [];
        }

        return DB::table('chat_messages')
            ->whereIn('chatid', $rooms)
            ->whereNotIn('type', ['System', 'Schedule', 'ScheduleUpdated', 'Reminder', 'Prompt'])
            ->whereNotNull('message')
            ->orderByDesc('date')
            ->limit(5)
            ->get(['date', 'message', 'userid', 'chatid'])
            ->map(function ($m) use ($userId) {
                return [
                    'date'       => Carbon::parse($m->date)->setTimezone('Europe/London')->format('j M Y'),
                    'fromMember' => (int) $m->userid === $userId,
                    'chatid'     => (int) $m->chatid,
                    'snippet'    => $this->cleanSnippet((string) $m->message, 140),
                ];
            })
            ->all();
    }

    /**
     * Mirror the cleaning pipeline used in ChatNotification::getSubjectSnippet():
     * - Decode \u{codepoints}\u emoji escapes (Freegle stores emojis that way
     *   for legacy DB compatibility — without this step they render as
     *   literal `ὠ0\u` in the email).
     * - Strip HTML tags and decode entities so Blade's escape pass produces
     *   clean output instead of `&amp;amp;`.
     * - Collapse whitespace.
     * - Multibyte-safe truncate so emoji are never cut mid-codepoint.
     */
    private function cleanSnippet(string $raw, int $maxChars): string
    {
        $clean = (string) EmojiUtils::decodeEmojis($raw);
        $clean = html_entity_decode(strip_tags($clean), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $clean = trim((string) preg_replace('/\s+/', ' ', $clean));
        if (mb_strlen($clean, 'UTF-8') > $maxChars) {
            $clean = mb_substr($clean, 0, $maxChars - 1, 'UTF-8') . '…';
        }
        return $clean;
    }

    private function donorHasBirthdayHint(int $userId, object $donation, bool $recurring): bool
    {
        $skipBirthdayCheck = false;
        if ($recurring) {
            $lastMonth = DB::table('users_donations')
                ->where('userid', $userId)
                ->where('GrossAmount', $donation->GrossAmount)
                ->whereIn('TransactionType', self::RECURRING_TYPES)
                ->whereDate('timestamp', '>=', today()->subMonth()->toDateString())
                ->whereDate('timestamp', '<', today()->toDateString())
                ->count();
            $skipBirthdayCheck = $lastMonth > 0;
        }
        if ($skipBirthdayCheck) {
            return false;
        }
        return $this->donorHasBirthdayGroup($userId);
    }

    private function donorHasBirthdayGroup(int $userId): bool
    {
        $today      = date('m-d');
        $yesterday  = date('m-d', strtotime('-1 day'));
        $twoDaysAgo = date('m-d', strtotime('-2 days'));

        $count = DB::table('groups')
            ->join('memberships', 'groups.id', '=', 'memberships.groupid')
            ->where('memberships.userid', $userId)
            ->where('groups.type', Group::TYPE_FREEGLE)
            ->where('groups.publish', 1)
            ->where('groups.onmap', 1)
            ->where(function ($q) use ($today, $yesterday, $twoDaysAgo) {
                foreach ([$today, $yesterday, $twoDaysAgo] as $md) {
                    [$m, $d] = explode('-', $md);
                    $q->orWhere(function ($q2) use ($m, $d) {
                        $q2->whereMonth('groups.founded', (int) $m)->whereDay('groups.founded', (int) $d);
                    });
                }
            })
            ->whereYear('groups.founded', '<', now()->year)
            ->count();

        return $count > 0;
    }

    private function buildLinks(object $donation): array
    {
        $modBase = rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/');
        $userId  = $donation->userid ? (int) $donation->userid : null;

        $links = [
            // Real Modtools GA moderator page. Doesn't take a ?search= query
            // today — opens the form ready to paste the donor's name.
            'giftaidRegister' => "{$modBase}/giftaid",
        ];

        if ($userId) {
            // Real Modtools support tools page for the user (opens on User tab).
            $links['modtoolsUser'] = "{$modBase}/support/{$userId}";
        }

        return $links;
    }

    /**
     * For an unmatched donation, surface a few plausible Freegle accounts the
     * thanker can check — mirroring the manual sleuthing (same email prefix on
     * a different provider; same display name). These are read-only suggestions
     * shown on the card; nothing is auto-linked. Capped so the card stays
     * scannable.
     *
     * @return array<int, array{userid:int, name:string, email:?string, reason:string, link:string}>
     */
    private function gatherCandidates(object $donation): array
    {
        $payer      = (string) ($donation->Payer ?? '');
        $candidates = [];
        $seen       = [];

        // 1. Same email local-part on any provider — people reuse the prefix
        //    across gmail/btinternet/etc. The leading-anchored LIKE uses the
        //    email index, so this stays cheap.
        $at = strpos($payer, '@');
        if ($at > 2) {
            $local      = substr($payer, 0, $at);
            $likePrefix = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $local) . '@%';

            $rows = DB::table('users_emails')
                ->join('users', 'users.id', '=', 'users_emails.userid')
                ->whereNull('users.deleted')
                ->whereNotNull('users_emails.userid')
                ->where('users_emails.email', 'like', $likePrefix)
                ->where('users_emails.email', '<>', $payer)
                ->where('users_emails.email', 'not like', '%@users.ilovefreegle.org')
                ->where('users_emails.email', 'not like', '%@users.trash-nothing.com')
                ->orderBy('users_emails.userid')
                ->limit(5)
                ->get(['users.id as userid', 'users.firstname', 'users.lastname', 'users.fullname', 'users_emails.email']);

            foreach ($rows as $r) {
                $key = (int) $r->userid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $candidates[] = $this->candidateRow($r, "same email prefix ({$local}@…)");
            }
        }

        // 2. Exact display-name match (PayPal/Stripe give us the payer's name).
        $name = trim((string) ($donation->PayerDisplayName ?? ''));
        if ($name !== '' && strpos($name, '@') === false && count($candidates) < 5) {
            $rows = DB::table('users')
                ->whereNull('deleted')
                ->where('fullname', $name)
                ->orderBy('id')
                ->limit(5)
                ->get(['id as userid', 'firstname', 'lastname', 'fullname']);

            foreach ($rows as $r) {
                $key = (int) $r->userid;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $r->email = null;
                $candidates[] = $this->candidateRow($r, 'name match');
            }
        }

        return array_slice($candidates, 0, 5);
    }

    private function candidateRow(object $r, string $reason): array
    {
        $display = trim(((string) ($r->firstname ?? '')) . ' ' . ((string) ($r->lastname ?? '')));
        if ($display === '') {
            $display = (string) ($r->fullname ?? '');
        }
        $modBase = rtrim((string) config('freegle.sites.mod', 'https://modtools.org'), '/');

        return [
            'userid' => (int) $r->userid,
            'name'   => $display !== '' ? $display : 'Unknown',
            'email'  => isset($r->email) ? $r->email : null,
            'reason' => $reason,
            'link'   => "{$modBase}/support/" . (int) $r->userid,
        ];
    }
}

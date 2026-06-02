<?php

namespace App\Services;

use App\Mail\Donation\DonationThankPrepMail;
use App\Models\Group;
use App\Support\EmojiUtils;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Builds the daily *thank-prep* digest — a card-per-donation email aimed at
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
 * shows only donations with id > the stored mark, then advances the mark to
 * the max id mailed. First run initialises the mark to MAX(id) so the
 * historical backlog is not dumped. (The per-row users_donations.thanked
 * column is display-only here and is not used for filtering.)
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
     * @return array{donations: int, total: float, sent: bool, last_id: int}
     */
    public function sendDailyThankPrep(bool $dryRun = false): array
    {
        // thanks_addr always resolves: its env default falls back to the
        // fundraising address (see config/freegle.php), so no runtime ??
        // fallback is needed.
        $recipient = config('freegle.mail.thanks_addr');

        // High-water mark: the largest donation id we've already shown.
        // On first run the row doesn't exist — initialise to MAX(id) so the
        // historical backlog (~137k rows in prod as of deploy) isn't dumped
        // into Jacky's inbox. In a dry run we compute that mark but must NOT
        // persist it (a dry run has no side effects).
        $lastId = $this->getLastSentId($dryRun);

        $donations = DB::table('users_donations')
            ->where('id', '>', $lastId)
            ->orderBy('id')
            ->get();

        if ($donations->isEmpty()) {
            return ['donations' => 0, 'total' => 0.0, 'sent' => false, 'last_id' => $lastId];
        }

        $total  = 0.0;
        $cards  = [];
        $maxId  = $lastId;
        foreach ($donations as $donation) {
            $total += (float) $donation->GrossAmount;
            $cards[] = $this->buildDonationCard($donation);
            if ((int) $donation->id > $maxId) {
                $maxId = (int) $donation->id;
            }
        }

        if (!$dryRun) {
            // Spool through EmailSpoolerService so a transient mail-host
            // failure doesn't lose the digest — same pattern as the simple
            // status mail in DonationSummaryService.
            app(\App\Services\EmailSpoolerService::class)->spool(
                new DonationThankPrepMail(
                    recipientEmail: (string) $recipient,
                    cards: $cards,
                    total: $total,
                ),
                (string) $recipient,
            );

            // Advance the mark only after spool returns. If spool throws we
            // leave the mark in place and the next cron tick retries the
            // same range — at-least-once delivery, the same trade-off the
            // EmailSpoolerService callers already accept.
            $this->setLastSentId($maxId);
        }

        return [
            'donations' => $donations->count(),
            'total'     => $total,
            'sent'      => !$dryRun,
            'last_id'   => $maxId,
        ];
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
        DB::statement(
            "INSERT INTO config (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = ?",
            [self::CONFIG_KEY_LAST_ID, (string) $id, (string) $id]
        );
    }

    /**
     * Build the per-donation context block. Shaped around the fields the
     * person composing a thank-you actually reads: who the donor is, what
     * else they've given, where they sit in the Gift Aid register, what
     * mods have noted, the most recent member↔mod chat.
     */
    private function buildDonationCard(object $donation): array
    {
        $recurring = in_array((string) $donation->TransactionType, self::RECURRING_TYPES, true);

        $localTime = Carbon::parse($donation->timestamp, 'UTC')
            ->setTimezone('Europe/London');

        $card = [
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
            'links'           => $this->buildLinks($donation),
        ];

        if ($donation->userid) {
            $card = $this->enrichMatchedDonor((int) $donation->userid, $donation, $card, $recurring);
        } else {
            $card['flags'][] = 'Unmatched donor — needs sleuthing';
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
            ->whereNotIn('type', ['System', 'Schedule', 'ScheduleUpdated', 'Reminder'])
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
                ->whereRaw('DATE(timestamp) >= DATE_SUB(CURDATE(), INTERVAL 1 MONTH)')
                ->whereRaw('DATE(timestamp) < CURDATE()')
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
            ->whereRaw("DATE_FORMAT(groups.founded, '%m-%d') IN (?, ?, ?)", [$today, $yesterday, $twoDaysAgo])
            ->whereRaw("YEAR(NOW()) - YEAR(groups.founded) > 0")
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
}

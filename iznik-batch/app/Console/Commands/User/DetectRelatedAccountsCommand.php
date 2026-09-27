<?php

namespace App\Console\Commands\User;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Links accounts that gave the same contact details in chat, so moderators see them in
 * Related Members and can ask the member whether to merge.
 *
 * Until now users_related was written only by handleRelated() in iznik-server-go, when one
 * browser was seen signed in as more than one account. That misses anyone who keeps their
 * accounts in separate browsers or on separate devices, which is most people with an old
 * account whose password they have forgotten.
 *
 * This is NOT a spam or fraud check and it blocks nothing. A duplicate account is usually
 * innocent - somebody re-registered - and merging it is a kindness, because replies to the
 * dead account otherwise go unread. So the exclusions below aim at "these are not actually
 * the same household" rather than at "this person looks dodgy".
 *
 * Two kinds of evidence, both taken from chat text:
 *
 *  - A UK MOBILE number. Landlines are excluded: they are far more likely to be shared by
 *    unrelated people (a shop, an office, a communal hall).
 *
 *  - A full street address: house number, street name and street type, keyed together with
 *    the postcode in the same message. NOT the postcode on its own. A UK unit postcode
 *    covers roughly fifteen addresses, so matching on it links neighbours: measured on
 *    production, "138 Coulston Road" and "140 Coulston Road" share LA1 3AB and are plainly
 *    two different households. Requiring the house number cuts the yield from about 3,100
 *    pairs a year to about 480, and what is left really is one address.
 *
 * Measured on production. Over 2026 the mobile rule produces ~770 pairs (~90 a month) after
 * exclusions. Hand classification of a random sample found the large majority genuinely one
 * person - a real name on one account and a handle on the other - or one household. The one
 * recurring false positive was somebody passing on a third party's number ("my friend Neil
 * is collecting it, his number is ..."), which isThirdParty() excludes.
 *
 * Note ContentCheckService deliberately does NOT flag phone numbers in chat at all (see the
 * comment at ContentCheckService.php ~line 227): giving your number to arrange a handover is
 * normal. That decision is untouched. Nothing here looks at contact details on their own -
 * only at the same details turning up under two different accounts.
 */
class DetectRelatedAccountsCommand extends Command
{
    protected $signature = 'users:detect-related
        {--days=7 : How many days of chat to scan}
        {--max-accounts=4 : Details used by more accounts than this are treated as circulated, not personal}
        {--limit=500 : Safety cap on pairs created in a single run}
        {--dry-run : Report what would be linked without writing}';

    protected $description = 'Link accounts that gave the same mobile number or street address in chat, for moderator review in Related Members';

    /**
     * UK mobiles only, allowing the spaces, hyphens and brackets people actually type.
     * (?<!\d) / (?!\d) rather than \b, because \b does not fire before a literal "+".
     */
    private const MOBILE_RE = '/(?<!\d)(?:\+44[\s-]?|0044[\s-]?|0)7(?:[\s-]?\d){9}(?!\d)/';

    /** A full UK unit postcode, used to disambiguate identical street names in other towns. */
    private const POSTCODE_RE = '/\b([A-Z]{1,2}[0-9][A-Z0-9]?)\s?([0-9][A-Z]{2})\b/i';

    /** House number, street name, street type. The house number is what makes it a home. */
    private const ADDRESS_RE = '/\b(\d+[A-Za-z]?)[,\s]+((?:[A-Z][A-Za-z\']+\s+){1,3}'
        . '(?:Road|Rd|Street|St|Avenue|Ave|Lane|Ln|Close|Drive|Dr|Way|Crescent|Cres|Grove'
        . '|Place|Terrace|Court|Gardens|Gdns|Park|Walk|Rise|View|Hill))\b/';

    /**
     * The sender is explicitly saying the details are somebody else's. Kept narrow on
     * purpose: "my friend is collecting it" on its own does not mean the number in the
     * message is not the sender's own, so only an explicit possessive of a contact noun
     * counts.
     */
    private const THIRD_PARTY_RE = '/\b(?:his|her|their)\s+(?:mobile|phone|number|tel|contact|address|no\b)'
        . '|\bmy\s+(?:friend|husband|wife|partner|son|daughter|mum|mother|dad|father|neighbou?r'
        . '|colleague|carer|sister|brother|driver)(?:\'s|s\')\s+(?:mobile|phone|number|tel|contact|address)/i';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $maxAccounts = max(2, (int) $this->option('max-accounts'));
        $limit = max(1, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));

        // "phone|07700900123" or "address|61 JACKSON ROAD|OX27TS"
        //   => userid => ['n'=>int,'first'=>ts,'last'=>ts,'chats'=>[chatid=>true],'label'=>string]
        $seen = [];

        DB::table('chat_messages')
            ->select('id', 'chatid', 'userid', 'message', 'date')
            ->where('date', '>=', $since)
            ->whereNotNull('message')
            // Cheap prefilter so the regexes only run on messages that could carry a mobile
            // number or a postcode; the index on date bounds the scan itself.
            ->where('message', 'REGEXP', '0?7[0-9][0-9 ()-]{8,}|[A-Za-z]{1,2}[0-9][A-Za-z0-9]?[ ]?[0-9][A-Za-z]{2}')
            // chunkById, not chunk: chunk pages with OFFSET, which walks further into the
            // table on every page and can skip rows as new chat arrives mid-run.
            ->chunkById(2000, function ($rows) use (&$seen) {
                foreach ($rows as $row) {
                    if ($this->isThirdParty($row->message)) {
                        continue;
                    }

                    foreach ($this->evidenceIn($row->message) as $key => $label) {
                        $u = (int) $row->userid;
                        if (!isset($seen[$key][$u])) {
                            $seen[$key][$u] = [
                                'n' => 0,
                                'first' => $row->date,
                                'last' => $row->date,
                                'chats' => [],
                                'label' => $label,
                            ];
                        }
                        $seen[$key][$u]['n']++;
                        $seen[$key][$u]['chats'][(int) $row->chatid] = true;
                        if ($row->date < $seen[$key][$u]['first']) {
                            $seen[$key][$u]['first'] = $row->date;
                        }
                        if ($row->date > $seen[$key][$u]['last']) {
                            $seen[$key][$u]['last'] = $row->date;
                        }
                    }
                }
            });

        // Drop evidence that cannot tell us anything: only one account, or so many accounts
        // that it is plainly being passed around rather than belonging to one household.
        $candidates = [];
        foreach ($seen as $key => $users) {
            $count = count($users);
            if ($count >= 2 && $count <= $maxAccounts) {
                $candidates[$key] = $users;
            }
        }

        if (!$candidates) {
            $this->info('No shared contact details found.');
            return self::SUCCESS;
        }

        $eligible = $this->eligibleUsers(array_unique(array_merge(
            ...array_map('array_keys', array_values($candidates))
        )));

        $created = 0;
        $skippedExisting = 0;
        $skippedSameChat = 0;

        foreach ($candidates as $key => $users) {
            $ids = array_values(array_filter(array_keys($users), fn ($id) => isset($eligible[$id])));
            sort($ids);

            for ($i = 0; $i < count($ids); $i++) {
                for ($j = $i + 1; $j < count($ids); $j++) {
                    if ($created >= $limit) {
                        $this->warn("Reached limit of {$limit} pairs; stopping.");
                        break 3;
                    }

                    [$u1, $u2] = [$ids[$i], $ids[$j]];

                    // Both wrote the same details in a chat they were BOTH in: one of them
                    // is repeating the other back, which says nothing about who they are.
                    if (array_intersect_key($users[$u1]['chats'], $users[$u2]['chats'])) {
                        $skippedSameChat++;
                        continue;
                    }

                    if ($this->alreadyRelated($u1, $u2)) {
                        $skippedExisting++;
                        continue;
                    }

                    $reason = $this->reasonFor(
                        (string) $key,
                        $u1,
                        $users[$u1],
                        $u2,
                        $users[$u2],
                        $this->sharedPostCount($u1, $u2)
                    );

                    if ($dryRun) {
                        $this->line("Would link {$u1} + {$u2}: {$reason}");
                        $created++;
                        continue;
                    }

                    DB::table('users_related')->insertOrIgnore([
                        'user1' => $u1,
                        'user2' => $u2,
                        'detected' => 'Auto',
                        'reason' => $reason,
                    ]);

                    $created++;
                }
            }
        }

        $verb = $dryRun ? 'Would link' : 'Linked';
        $this->info("{$verb} {$created} account pairs by shared contact details "
            . "({$skippedExisting} already known, {$skippedSameChat} were quoting each other).");

        if (!$dryRun && $created > 0) {
            Log::info('Related members detected by shared contact details', [
                'pairs' => $created,
                'days' => $days,
            ]);
        }

        return self::SUCCESS;
    }

    /**
     * Shared-detail keys found in a message, mapped to the label shown to the moderator.
     *
     * @return array<string,string>
     */
    private function evidenceIn(string $message): array
    {
        $out = [];

        foreach ($this->mobilesIn($message) as $number) {
            // Masked: a mod can read the full number in the chat itself, so repeating it
            // in a stored note only spreads it further.
            $out['phone|' . $number] = 'mobile number (ending ' . substr($number, -4) . ')';
        }

        foreach ($this->addressesIn($message) as $key => $label) {
            $out[$key] = $label;
        }

        return $out;
    }

    /**
     * Distinct normalised UK mobile numbers in a message, as 07xxxxxxxxx.
     *
     * @return list<string>
     */
    private function mobilesIn(string $message): array
    {
        if (!preg_match_all(self::MOBILE_RE, $message, $m)) {
            return [];
        }

        $out = [];
        foreach ($m[0] as $raw) {
            $digits = preg_replace('/\D/', '', $raw);
            $digits = preg_replace('/^(?:0{2})?44/', '', $digits);
            $digits = ltrim($digits, '0');
            if (strlen($digits) === 10 && str_starts_with($digits, '7')) {
                $out['0' . $digits] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Full street addresses in a message, keyed with the postcode from the same message so
     * that the same street name in another town is a different key.
     *
     * @return array<string,string>
     */
    private function addressesIn(string $message): array
    {
        if (!preg_match(self::POSTCODE_RE, $message, $pc)) {
            return [];
        }
        $postcode = strtoupper($pc[1] . $pc[2]);

        if (!preg_match_all(self::ADDRESS_RE, $message, $m, PREG_SET_ORDER)) {
            return [];
        }

        $out = [];
        foreach ($m as $match) {
            $street = preg_replace('/\s+/', ' ', trim($match[2]));
            $house = strtoupper($match[1]);
            $key = 'address|' . strtoupper($house . ' ' . $street) . '|' . $postcode;
            $out[$key] = sprintf(
                'address (%s %s, %s)',
                $house,
                $street,
                strtoupper($pc[1]) . ' ' . strtoupper($pc[2])
            );
        }

        return $out;
    }

    private function isThirdParty(string $message): bool
    {
        return (bool) preg_match(self::THIRD_PARTY_RE, $message);
    }

    /**
     * Ordinary, live accounts only.
     *
     * Volunteers are excluded because handing contact details to members is part of the
     * role, so the same details showing up under a volunteer account and a member account
     * is expected rather than evidence of anything.
     *
     * @param list<int> $ids
     * @return array<int,true>
     */
    private function eligibleUsers(array $ids): array
    {
        if (!$ids) {
            return [];
        }

        $out = [];
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = DB::table('users')
                ->select('id')
                ->whereIn('id', $chunk)
                ->whereNull('deleted')
                ->where('systemrole', 'User')
                ->pluck('id');

            foreach ($rows as $id) {
                $out[(int) $id] = true;
            }
        }

        return $out;
    }

    /**
     * The pair may already be known, and the existing detector stores rows in whichever
     * direction it happened to see them, so check both.
     */
    private function alreadyRelated(int $u1, int $u2): bool
    {
        return DB::table('users_related')
            ->where(function ($q) use ($u1, $u2) {
                $q->where('user1', $u1)->where('user2', $u2);
            })
            ->orWhere(function ($q) use ($u1, $u2) {
                $q->where('user1', $u2)->where('user2', $u1);
            })
            ->exists();
    }

    /**
     * The note the moderator reads on the Related Members card. It has to stand on its own:
     * what matched, how much of it there was, and when, so the mod can judge the pair
     * without opening both chat histories.
     *
     * Where the two have also replied to the same posts, that is said last, because it is
     * what separates an ordinary duplicate from somebody working the system. Two accounts
     * belonging to one person will normally reply to different posts; replying to the same
     * one means either the person forgot which account they were in, or they are putting
     * themselves forward twice for the same item.
     *
     * @param array{n:int,first:string,last:string,chats:array,label:string} $a
     * @param array{n:int,first:string,last:string,chats:array,label:string} $b
     */
    private function reasonFor(string $key, int $u1, array $a, int $u2, array $b, int $sharedPosts = 0): string
    {
        $reason = sprintf(
            'Both accounts gave the same %s in chat. #%d: %s. #%d: %s.',
            $a['label'],
            $u1,
            $this->describeUse($a),
            $u2,
            $this->describeUse($b)
        );

        if ($sharedPosts > 0) {
            $reason .= $sharedPosts === 1
                ? ' They have also both replied to the same post.'
                : " They have also both replied to the same {$sharedPosts} posts.";
        }

        return mb_substr($reason, 0, 255);
    }

    /**
     * How many posts both accounts have replied to.
     *
     * Deliberately not a linking signal on its own: a popular offer gets replies from many
     * unrelated people, so on its own this says nothing. It only means something once the
     * two accounts are already tied together by contact details, which is why it is
     * computed here, per pair, rather than scanned for.
     */
    private function sharedPostCount(int $u1, int $u2): int
    {
        return (int) DB::table('chat_messages AS a')
            ->join('chat_messages AS b', function ($join) {
                $join->on('b.refmsgid', '=', 'a.refmsgid');
            })
            ->where('a.userid', $u1)
            ->where('b.userid', $u2)
            ->whereNotNull('a.refmsgid')
            ->distinct()
            ->count('a.refmsgid');
    }

    /**
     * @param array{n:int,first:string,last:string} $use
     */
    private function describeUse(array $use): string
    {
        $first = date('j M Y', strtotime($use['first']));
        $last = date('j M Y', strtotime($use['last']));
        $msgs = $use['n'] === 1 ? '1 message' : "{$use['n']} messages";

        return $first === $last ? "{$msgs} on {$first}" : "{$msgs}, {$first} to {$last}";
    }
}

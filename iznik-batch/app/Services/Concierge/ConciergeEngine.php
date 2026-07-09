<?php

namespace App\Services\Concierge;

/**
 * Concierge FSM — deterministic decision core for a bulk-reuse clearance.
 *
 * Pure logic, no I/O and no PII: it is fed item availability, repliers and the
 * commitment ledger (what we've actually told each org), and returns the set of
 * ACTIONS to take. It never sends anything — a driver turns actions into drafts
 * and queues them for human approval. This is the part that is unit/replay
 * tested; prose drafting and mailbox/DB I/O live outside.
 *
 * Load-bearing rules (proven on a real office-furniture clearance, 2026):
 *  - Honour prior commitments FIRST; never reallocate what we've promised.
 *  - Then allocate the genuinely-unpromised remainder by NEED (not first-come).
 *  - Never double-promise one item to two orgs.
 *  - When availability isn't freshly confirmed, hold (a "checking with the donor"
 *    note) rather than offer.
 */
class ConciergeEngine
{
    // Inbound classification
    public const IN_REPLY = 'reply';
    public const IN_AUTO = 'auto';
    public const IN_BOUNCE = 'bounce';

    // Commitment types (from what we actually SENT)
    public const C_FIRM = 'firm';     // promised a specific item ("consider it yours")
    public const C_MENU = 'menu';     // invited to pick from a set ("which would suit?")
    public const C_NOTED = 'noted';   // "noted your interest, can't promise yet"
    public const C_HOLDING = 'holding'; // holding note sent

    // Action kinds
    public const A_CONFIRM_COLLECTION = 'CONFIRM_COLLECTION';
    public const A_APOLOGISE_SHORTFALL = 'APOLOGISE_SHORTFALL';
    public const A_RENEGE_ALERT = 'RENEGE_ALERT';
    public const A_OFFER_MENU = 'OFFER_MENU';
    public const A_OFFER_ALT = 'OFFER_ALT';
    public const A_HOLD = 'HOLD';
    public const A_HOLDING_NOTE = 'HOLDING_NOTE';
    public const A_WAITLIST = 'WAITLIST';
    public const A_DECLINE_ACK = 'DECLINE_ACK';

    /**
     * Classify an inbound message from its headers + subject (deterministic).
     * Mirrors the mailbox scanner: bounce > auto > genuine reply.
     */
    public function classifyInbound(array $headers, string $subject): string
    {
        $h = [];
        foreach ($headers as $k => $v) {
            $h[strtolower($k)] = $v;
        }
        $from = strtolower($h['from'] ?? '');
        if (str_contains($from, 'mailer-daemon') || str_contains($from, 'postmaster')
            || preg_match('/delivery status notification|undeliverable|mail delivery failed|failure notice|could not be delivered|returned to sender|delivery incomplete|delivery has failed/i', $subject)) {
            return self::IN_BOUNCE;
        }
        $as = strtolower($h['auto-submitted'] ?? 'no');
        $auto = ($as !== 'no' && $as !== '')
            || isset($h['x-autoreply']) || isset($h['x-autorespond']) || isset($h['x-autoresponder'])
            || preg_match('/^(automatic reply|auto[- ]?reply|auto[- ]?response|out of office|out-of-office|away from the office|undelivered)/i', trim($subject));

        return $auto ? self::IN_AUTO : self::IN_REPLY;
    }

    /** Functional kind of an item, from its name. */
    public function itemKind(string $name): string
    {
        $n = strtolower($name);
        if (str_contains($n, 'cabinet')) return 'cabinet';
        if (str_contains($n, 'cupboard')) return 'cupboard';
        if (str_contains($n, 'table')) return 'table';
        if (str_contains($n, 'chair') || str_contains($n, 'armchair')) return 'seating';
        if (str_contains($n, 'desk')) return 'desk';

        return 'other';
    }

    /**
     * The core. Given the world state, return the actions to take.
     *
     * @param array<int,array{num:int,name:string,qty:int,available:bool}> $items keyed by item number
     * @param array<int,array<string,mixed>> $repliers
     * @param array<int,array<string,mixed>> $commitments
     * @param array{availabilityConfident?:bool} $opts
     * @return array<int,array<string,mixed>> actions
     */
    public function reconcile(array $items, array $repliers, array $commitments, array $opts = []): array
    {
        $confident = $opts['availabilityConfident'] ?? true;
        $actions = [];

        $byId = [];
        foreach ($repliers as $r) {
            $byId[$r['id']] = $r;
        }

        // What each replier has been committed (firm/menu), for "has an offer" checks.
        $firmByReplier = [];
        $menuByReplier = [];
        foreach ($commitments as $c) {
            if ($c['type'] === self::C_FIRM) $firmByReplier[$c['replier']][] = $c;
            if ($c['type'] === self::C_MENU) $menuByReplier[$c['replier']][] = $c;
        }

        // available multiset (num => qty) for items still present
        $avail = [];
        foreach ($items as $it) {
            if (!empty($it['available']) && ($it['qty'] ?? 0) > 0) {
                $avail[$it['num']] = $it['qty'];
            }
        }

        // If availability isn't freshly confirmed, we can't make offers — hold anyone
        // whose wanted items aren't clearly settled, with a "checking with donor" note.
        if (!$confident) {
            foreach ($repliers as $r) {
                if (!empty($r['declined'])) {
                    $actions[] = ['kind' => self::A_DECLINE_ACK, 'replier' => $r['id']];
                    continue;
                }
                $actions[] = ['kind' => self::A_HOLDING_NOTE, 'replier' => $r['id']];
            }

            return $this->sortActions($actions);
        }

        $reserved = [];       // itemNum => replierId (firm)
        $menuItems = [];       // itemNum => true (spoken-for via an open menu offer)

        // --- Pass 1: honour firm commitments ---
        foreach ($commitments as $c) {
            if ($c['type'] !== self::C_FIRM) continue;
            $r = $byId[$c['replier']] ?? null;
            if (!$r) continue;
            foreach ($c['items'] as $num) {
                $promised = $c['qty'] ?? 1;
                if (!isset($avail[$num])) {
                    $actions[] = ['kind' => self::A_RENEGE_ALERT, 'replier' => $r['id'], 'item' => $num];
                    continue;
                }
                if ($avail[$num] < $promised) {
                    $actions[] = ['kind' => self::A_APOLOGISE_SHORTFALL, 'replier' => $r['id'],
                        'item' => $num, 'have' => $avail[$num], 'promised' => $promised];
                }
                $reserved[$num] = $r['id'];
                if (!empty($r['collect'])) {
                    $days = $r['collect'];
                    sort($days);
                    $actions[] = ['kind' => self::A_CONFIRM_COLLECTION, 'replier' => $r['id'],
                        'item' => $num, 'day' => $days[0]];
                }
            }
        }

        // --- Pass 2: honour open-menu offers (re-offer the still-available subset) ---
        foreach ($commitments as $c) {
            if ($c['type'] !== self::C_MENU) continue;
            $r = $byId[$c['replier']] ?? null;
            if (!$r) continue;
            $availMenu = [];
            $goneMenu = [];
            foreach ($c['items'] as $num) {
                if (isset($avail[$num]) && !isset($reserved[$num])) {
                    $availMenu[] = $num;
                    $menuItems[$num] = true;
                } else {
                    $goneMenu[] = $num;
                }
            }
            $actions[] = ['kind' => self::A_OFFER_MENU, 'replier' => $r['id'],
                'items' => $availMenu, 'gone' => $goneMenu];
        }

        // --- Pass 3: allocate the genuinely-free remainder by NEED (not first-come) ---
        $freeItems = [];
        foreach ($avail as $num => $qty) {
            if (!isset($reserved[$num]) && !isset($menuItems[$num])) {
                $freeItems[$num] = $num;
            }
        }

        // needers: not declined, no firm/menu offer, still have an unmet want
        $needers = [];
        foreach ($repliers as $r) {
            if (!empty($r['declined'])) continue;
            if (!empty($firmByReplier[$r['id']]) || !empty($menuByReplier[$r['id']])) continue;
            if (empty($r['wants'])) continue;
            // wanted item still obtainable? (unmet = at least one want not reserved-to-someone-else/gone-for-them)
            $needers[] = $r;
        }
        // sort by need desc, then org before individual, then earliest reply (stable, mild)
        usort($needers, function ($a, $b) {
            $an = $a['need'] ?? 0;
            $bn = $b['need'] ?? 0;
            if ($an !== $bn) return $bn <=> $an;
            $ak = ($a['kind'] ?? 'org') === 'individual' ? 1 : 0;
            $bk = ($b['kind'] ?? 'org') === 'individual' ? 1 : 0;
            if ($ak !== $bk) return $ak <=> $bk;

            return strcmp((string) ($a['firstAt'] ?? ''), (string) ($b['firstAt'] ?? ''));
        });

        $menuPending = !empty($menuItems);
        foreach ($needers as $r) {
            $pick = $this->bestFit($freeItems, $items, $r);
            if ($pick !== null) {
                $actions[] = ['kind' => self::A_OFFER_ALT, 'replier' => $r['id'], 'item' => $pick];
                unset($freeItems[$pick]);
                continue;
            }
            // nothing free fits right now
            if ($menuPending && ($r['kind'] ?? 'org') === 'org') {
                // a menu item may free up once the invited orgs pick — hold, don't waitlist
                $actions[] = ['kind' => self::A_HOLD, 'replier' => $r['id'], 'reason' => 'pending menu picks'];
            } else {
                $actions[] = ['kind' => self::A_WAITLIST, 'replier' => $r['id'], 'reason' => 'wanted items gone/allocated'];
            }
        }

        return $this->sortActions($actions);
    }

    /** Pick the best-fitting free item for a replier, or null. */
    private function bestFit(array $freeItems, array $items, array $r): ?int
    {
        $wantKinds = $this->wantedKinds($r, $items);
        // exact-kind match first
        foreach ($freeItems as $num) {
            $k = $this->itemKind($items[$num]['name'] ?? '');
            if (in_array($k, $wantKinds, true)) return $num;
        }
        // flexible replier ('any') takes anything
        if (in_array('any', $wantKinds, true) && $freeItems) {
            return (int) array_key_first($freeItems);
        }

        return null;
    }

    /** The functional kinds a replier is after (from their wanted item numbers + flags). */
    private function wantedKinds(array $r, array $items): array
    {
        $kinds = [];
        foreach (($r['wants'] ?? []) as $w) {
            if (is_string($w)) {
                $kinds[] = $w; // token e.g. 'any','table'
                continue;
            }
            if (isset($items[$w])) {
                $kinds[] = $this->itemKind($items[$w]['name']);
            }
        }
        if (!empty($r['flexible'])) $kinds[] = 'any';

        return array_values(array_unique($kinds));
    }

    /** Deterministic ordering so replay comparisons are stable. */
    private function sortActions(array $actions): array
    {
        usort($actions, function ($a, $b) {
            $c = strcmp($a['kind'], $b['kind']);
            if ($c !== 0) return $c;

            return strcmp((string) $a['replier'], (string) $b['replier']);
        });

        return $actions;
    }
}

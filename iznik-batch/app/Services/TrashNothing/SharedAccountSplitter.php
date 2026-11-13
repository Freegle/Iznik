<?php

namespace App\Services\TrashNothing;

use App\Database\Sql;
use App\Models\ChatRoom;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves one Trash Nothing member out of a Freegle account that holds two, onto a new account
 * with that member's TN user id. tn:split-shared-account is the way in.
 *
 * The member may have an account already: the posts API makes one at their first post, and a
 * partner call at their next join. Then everything moves onto that one instead. A row it already
 * has the twin of stays where it is and is reported, because the table's unique key would refuse
 * it, and that would stop the whole split.
 *
 * plan() only reads, and says for every row which member the evidence gives it to. apply()
 * writes that plan in one transaction of single-row updates, each re-asserting the current
 * owner, and writes the SQL that undoes it before committing.
 *
 * The evidence, as in the hand splits it automates:
 * - An address belongs to the member whose TN username it carries.
 * - A post belongs to the member whose address sent it.
 * - A member's alias for a Trash Nothing community stands for a Freegle community when the
 *   alias's posts went there, or when the alias first reached us within a couple of seconds of
 *   the account joining it (TN added both at once).
 * - A membership belongs to the members whose aliases stand for its community. Both: the moved
 *   member gets a copy, dated when their alias for it first reached us.
 * - A chat belongs to whoever could have written in it: a reply comes from the replier's alias
 *   for the post's community, so only a member whose alias for it existed by then. When both
 *   could have, or nothing says, the chat is undecided and stays unless named.
 * - Promises, collections, ratings, arrangements and expected replies follow the chat they
 *   came from; logs follow the post they are about, or the join made at the moved alias's time.
 */
class SharedAccountSplitter
{
    /** How close an alias's arrival and a join must be to count as TN adding both at once. */
    public const SAME_MOMENT_SECONDS = 2;

    private const UNKNOWN = '?';

    /**
     * Work out the split, reading only.
     *
     * @param  list<int>  $moveChats  undecided chats to move as well
     * @return array the plan, with 'state' of 'ready' or 'done' (already split)
     *
     * @throws SplitRefused
     */
    public function plan(int $accountId, string $username, int $tnUserId, array $moveChats = []): array
    {
        $username = strtolower(trim($username));
        $account = DB::table('users')->where('id', $accountId)->first(['id', 'tnuserid', 'deleted']);

        if (!$account || $account->deleted) {
            throw new SplitRefused("Account {$accountId} does not exist or is deleted.");
        }
        if ((int) $account->tnuserid === $tnUserId) {
            throw new SplitRefused("TN user id {$tnUserId} is this account's own. Move the other username out instead.");
        }

        $aliases = $this->tnAddresses($accountId);
        $moving = array_filter($aliases, fn ($a) => $a['username'] === $username);

        $holder = DB::table('users')->where('tnuserid', $tnUserId)->where('id', '!=', $accountId)->first(['id', 'deleted']);
        $target = $holder ? (int) $holder->id : NULL;
        $targetAliases = [];
        if ($holder) {
            if ($holder->deleted) {
                throw new SplitRefused("TN user id {$tnUserId} is held by deleted account {$target}.");
            }

            $targetAliases = array_values(array_filter($this->tnAddresses($target), fn ($a) => $a['username'] === $username));
            if ($targetAliases === []) {
                throw new SplitRefused("TN user id {$tnUserId} is already held by account {$target}, which does not hold that TN username.");
            }
        }

        if ($moving === [] && $target === NULL) {
            throw new SplitRefused("Account {$accountId} does not hold that TN username.");
        }

        // With no addresses left to move, what is left is their posts. Otherwise it needs another member.
        $usernames = array_values(array_unique(array_map(fn ($a) => $a['username'], $aliases)));
        if ($moving !== [] && count($usernames) < 2) {
            throw new SplitRefused("That is account {$accountId}'s only TN username, so there is nothing to split.");
        }

        $plan = [
            'state' => 'ready',
            'account' => $accountId,
            'target' => $target,
            'username' => $username,
            'tnUserId' => $tnUserId,
            'emails' => array_values(array_map(fn ($a) => $a['id'], $moving)),
            'leftBehind' => [],
        ];

        // Posts by sender address.
        $posts = DB::table('messages')->where('fromuser', $accountId)->get(['id', 'fromaddr', 'arrival']);
        $postOwner = [];
        foreach ($posts as $post) {
            $postOwner[(int) $post->id] = $post->fromaddr ? (User::tnUsernameFromEmail($post->fromaddr) ?? self::UNKNOWN) : self::UNKNOWN;
        }
        $plan['messages'] = array_keys(array_filter($postOwner, fn ($owner) => $owner === $username));

        $memberships = DB::table('memberships')->where('userid', $accountId)
            ->get(['id', 'groupid', 'added', 'rippled', 'collection', 'emailfrequency', 'eventsallowed', 'volunteeringallowed', 'ourPostingStatus']);
        $history = DB::table('memberships_history')->where('userid', $accountId)->get(['id', 'groupid', 'added', 'rippled']);

        // An alias already moved to the member's account is still evidence of when they joined.
        $evidence = array_merge($aliases, $targetAliases);

        [$firstSeen, $rippledInto] = $this->communitiesByMember($evidence, $posts, $postOwner, $memberships, $history);

        $this->planMemberships($plan, $memberships, $evidence, $firstSeen, $rippledInto);
        $this->planHistory($plan, $history, $evidence, $rippledInto);
        $this->planChats($plan, $accountId, $postOwner, $firstSeen, $moveChats);
        $this->planFollowers($plan, $accountId);
        $this->planLogs($plan, $accountId, array_merge($moving, $targetAliases));
        $this->planPreferred($plan, $aliases, $moving);

        if ($target !== NULL) {
            return $this->nothingToMove($plan)
                ? ['state' => 'done', 'account' => $accountId, 'newAccount' => $target]
                : $plan;
        }

        $movedPostDates = $posts->whereIn('id', $plan['messages'])->pluck('arrival')->all();
        $chatDates = DB::table('chat_messages')->whereIn('id', $plan['chatMessages'])->pluck('date')->all();
        $added = min(array_map(fn ($a) => $a['added'], $moving));
        $plan['newUser'] = [
            'fullname' => User::tnDisplayName($username),
            'added' => $added,
            'lastaccess' => max(array_merge([$added], $movedPostDates, $chatDates)),
        ];

        return $plan;
    }

    /**
     * Write a plan. One transaction; any update touching other than exactly one row undoes
     * all of it. The rollback SQL is written before the commit, so a split that commits always
     * has one.
     *
     * @return int the id of the account the member is now on
     */
    public function apply(array $plan, string $rollbackPath): int
    {
        return DB::transaction(function () use ($plan, $rollbackPath) {
            $a = $plan['account'];
            $undo = [];

            $n = $plan['target'];
            if ($n === NULL) {
                $n = (int) DB::table('users')->insertGetId([
                    'fullname' => $plan['newUser']['fullname'],
                    'added' => $plan['newUser']['added'],
                    'lastaccess' => $plan['newUser']['lastaccess'],
                    'systemrole' => User::SYSTEMROLE_USER,
                    'tnuserid' => $plan['tnUserId'],
                ]);
                $undo[] = "DELETE FROM users WHERE id = {$n} AND tnuserid = {$plan['tnUserId']};";
            }

            $move = function (string $table, string $column, int $id, int $from, int $to) use (&$undo) {
                $changed = DB::table($table)->where('id', $id)->where($column, $from)->update([$column => $to]);
                if ($changed !== 1) {
                    throw new RuntimeException("{$table} {$id}: expected one row with {$column} = {$from}, found {$changed}; nothing written");
                }
                // The column is quoted: logs.user is a reserved word on PostgreSQL, and quoting is harmless on MySQL.
                $quoted = Sql::col($column);
                $undo[] = "UPDATE {$table} SET {$quoted} = {$from} WHERE id = {$id} AND {$quoted} = {$to};";
            };

            foreach ($plan['emails'] as $id) {
                $move('users_emails', 'userid', $id, $a, $n);
            }
            foreach ($plan['preferred'] as [$id, $from, $to]) {
                $move('users_emails', 'preferred', $id, $from, $to);
            }
            foreach ($plan['messages'] as $id) {
                $move('messages', 'fromuser', $id, $a, $n);
            }
            foreach ($plan['membershipsMove'] as $id) {
                $move('memberships', 'userid', $id, $a, $n);
            }
            foreach ($plan['membershipsCopy'] as $copy) {
                $id = (int) DB::table('memberships')->insertGetId(array_merge($copy['row'], ['userid' => $n]));
                $undo[] = "DELETE FROM memberships WHERE id = {$id} AND userid = {$n};";
            }
            foreach ($plan['historyMove'] as $id) {
                $move('memberships_history', 'userid', $id, $a, $n);
            }
            foreach ($plan['chatsMove'] as [$id, $column]) {
                $move('chat_rooms', $column, $id, $a, $n);
            }
            foreach ($plan['roster'] as $id) {
                $move('chat_roster', 'userid', $id, $a, $n);
            }
            foreach ($plan['chatMessages'] as $id) {
                $move('chat_messages', 'userid', $id, $a, $n);
            }
            foreach (['promises' => 'messages_promises', 'takenBy' => 'messages_by', 'reneged' => 'messages_reneged'] as $key => $table) {
                foreach ($plan[$key] as $id) {
                    $move($table, 'userid', $id, $a, $n);
                }
            }
            foreach (['ratings' => 'ratings', 'trysts' => 'trysts', 'expected' => 'users_expected'] as $key => $table) {
                foreach ($plan[$key] as [$id, $column]) {
                    $move($table, $column, $id, $a, $n);
                }
            }
            foreach ($plan['logsUser'] as $id) {
                $move('logs', 'user', $id, $a, $n);
            }
            foreach ($plan['logsByuser'] as $id) {
                $move('logs', 'byuser', $id, $a, $n);
            }

            foreach ([
                [$n, "Split from account {$a}: TrashNothing user {$plan['tnUserId']}"],
                [$a, "TrashNothing user {$plan['tnUserId']} split out into account {$n}"],
            ] as [$user, $text]) {
                $id = (int) DB::table('logs')->insertGetId([
                    'timestamp' => now(), 'type' => 'User', 'subtype' => 'Split', 'user' => $user, 'text' => $text,
                ]);
                $undo[] = "DELETE FROM logs WHERE id = {$id} AND type = 'User' AND subtype = 'Split';";
            }

            $this->writeRollback($rollbackPath, $a, $n, array_reverse($undo));

            return $n;
        });
    }

    /** @return list<array{id: int, username: string, added: string, preferred: int}> */
    private function tnAddresses(int $userId): array
    {
        $out = [];
        foreach (DB::table('users_emails')->where('userid', $userId)->orderBy('id')->get(['id', 'email', 'added', 'preferred']) as $row) {
            $username = User::tnUsernameFromEmail((string) $row->email);
            if ($username !== null) {
                $out[] = ['id' => (int) $row->id, 'username' => $username, 'added' => (string) $row->added, 'preferred' => (int) $row->preferred];
            }
        }

        return $out;
    }

    /**
     * Which Freegle communities each member's aliases stand for, and from when: the first time
     * one of that member's aliases for the community reached us. Also which communities each
     * member's posts rippled into.
     *
     * @return array{0: array<string, array<int, string>>, 1: array<string, array<int, true>>}
     */
    private function communitiesByMember(array $aliases, $posts, array $postOwner, $memberships, $history): array
    {
        $firstSeen = [];
        $note = function (string $member, int $groupId, string $at) use (&$firstSeen) {
            if (!isset($firstSeen[$member][$groupId]) || $at < $firstSeen[$member][$groupId]) {
                $firstSeen[$member][$groupId] = $at;
            }
        };

        $aliasAdded = [];
        foreach (DB::table('users_emails')->whereIn('id', array_column($aliases, 'id'))->get(['email', 'added']) as $row) {
            $aliasAdded[strtolower((string) $row->email)] = (string) $row->added;
        }

        $rippledInto = [];
        $postIds = $posts->pluck('id')->all();
        foreach (array_chunk($postIds, 500) as $chunk) {
            foreach (DB::table('messages_groups')->whereIn('msgid', $chunk)->get(['msgid', 'groupid', 'rippled_in']) as $row) {
                $member = $postOwner[(int) $row->msgid] ?? self::UNKNOWN;
                if ($member === self::UNKNOWN) {
                    continue;
                }
                if ($row->rippled_in) {
                    $rippledInto[$member][(int) $row->groupid] = TRUE;

                    continue;
                }
                $post = $posts->firstWhere('id', $row->msgid);
                $at = $aliasAdded[strtolower((string) $post->fromaddr)] ?? (string) $post->arrival;
                $note($member, (int) $row->groupid, $at);
            }
        }

        foreach ($aliases as $alias) {
            foreach ([$memberships, $history] as $rows) {
                foreach ($rows as $row) {
                    if (!$row->rippled && $this->sameMoment($alias['added'], (string) $row->added)) {
                        $note($alias['username'], (int) $row->groupid, $alias['added']);
                    }
                }
            }
        }

        return [$firstSeen, $rippledInto];
    }

    private function planMemberships(array &$plan, $memberships, array $aliases, array $firstSeen, array $rippledInto): void
    {
        $u = $plan['username'];
        $plan['membershipsMove'] = $plan['membershipsCopy'] = $plan['membershipsStay'] = $plan['membershipsUnattributed'] = [];

        foreach ($memberships as $m) {
            $g = (int) $m->groupid;
            if ($m->rippled) {
                $owners = array_keys(array_filter($rippledInto, fn ($groups) => isset($groups[$g])));
            } else {
                $owners = array_keys(array_filter($firstSeen, fn ($groups) => isset($groups[$g])));
                foreach ($aliases as $alias) {
                    if ($this->sameMoment($alias['added'], (string) $m->added)) {
                        $owners[] = $alias['username'];
                    }
                }
                $owners = array_values(array_unique($owners));
            }

            if ($owners === []) {
                $plan['membershipsUnattributed'][] = (int) $m->id;
            } elseif ($owners === [$u]) {
                if (!$this->staysBehind($plan, 'memberships', (int) $m->id, ['userid' => $plan['target'], 'groupid' => $g])) {
                    $plan['membershipsMove'][] = (int) $m->id;
                }
            } elseif (in_array($u, $owners, TRUE) && !$m->rippled && $this->targetHas($plan, 'memberships', ['userid' => $plan['target'], 'groupid' => $g])) {
                $plan['membershipsStay'][] = (int) $m->id;
            } elseif (in_array($u, $owners, TRUE) && !$m->rippled) {
                $plan['membershipsCopy'][] = [
                    'source' => (int) $m->id,
                    'row' => [
                        'groupid' => $g,
                        'role' => Membership::ROLE_MEMBER,
                        'collection' => $m->collection,
                        'added' => $firstSeen[$u][$g] ?? (string) $m->added,
                        'emailfrequency' => $m->emailfrequency,
                        'eventsallowed' => $m->eventsallowed,
                        'volunteeringallowed' => $m->volunteeringallowed,
                        'ourPostingStatus' => $m->ourPostingStatus,
                        'rippled' => 0,
                        'implicit' => 0,
                        'added_origin' => 'unknown',
                    ],
                ];
            } else {
                $plan['membershipsStay'][] = (int) $m->id;
            }
        }
        $plan['copiedGroups'] = array_map(fn ($c) => $c['row']['groupid'], $plan['membershipsCopy']);
        $plan['movedGroups'] = $memberships->whereIn('id', $plan['membershipsMove'])->pluck('groupid')->map(fn ($g) => (int) $g)->all();
    }

    private function planHistory(array &$plan, $history, array $aliases, array $rippledInto): void
    {
        $plan['historyMove'] = [];
        foreach ($history as $h) {
            if ($h->rippled) {
                $owners = array_keys(array_filter($rippledInto, fn ($groups) => isset($groups[(int) $h->groupid])));
            } else {
                $owners = [];
                foreach ($aliases as $alias) {
                    if ($this->sameMoment($alias['added'], (string) $h->added)) {
                        $owners[] = $alias['username'];
                    }
                }
            }
            if (array_values(array_unique($owners)) === [$plan['username']]) {
                $plan['historyMove'][] = (int) $h->id;
            }
        }
    }

    private function planChats(array &$plan, int $a, array $postOwner, array $firstSeen, array $moveChats): void
    {
        $u = $plan['username'];
        $plan['chatsMove'] = $plan['chatsStay'] = $plan['chatsUndecided'] = [];
        $others = [];

        $rooms = DB::table('chat_rooms')
            ->where(fn ($q) => $q->where('user1', $a)->orWhere('user2', $a))
            ->whereIn('chattype', [ChatRoom::TYPE_USER2USER, ChatRoom::TYPE_USER2MOD])
            ->get(['id', 'chattype', 'user1', 'user2', 'groupid']);

        foreach ($rooms as $room) {
            $id = (int) $room->id;
            $column = (int) $room->user1 === $a ? 'user1' : 'user2';
            $others[$id] = $room->chattype === ChatRoom::TYPE_USER2USER ? (int) ($column === 'user1' ? $room->user2 : $room->user1) : null;

            $refs = DB::table('chat_messages')->where('chatid', $id)->whereNotNull('refmsgid')->distinct()->pluck('refmsgid')->map(fn ($r) => (int) $r)->all();
            $groups = DB::table('messages_groups')->whereIn('msgid', $refs ?: [0])->pluck('groupid')->map(fn ($g) => (int) $g)->all();
            if ($room->chattype === ChatRoom::TYPE_USER2MOD && $room->groupid) {
                $groups[] = (int) $room->groupid;
            }
            $groups = array_values(array_unique($groups));

            $candidates = [];
            foreach ($refs as $ref) {
                if (isset($postOwner[$ref])) {
                    $candidates[] = $postOwner[$ref];
                }
            }
            foreach (DB::table('chat_messages')->where('chatid', $id)->where('userid', $a)->pluck('date') as $date) {
                foreach ($firstSeen as $member => $seen) {
                    foreach ($groups as $g) {
                        if (isset($seen[$g]) && $seen[$g] <= (string) $date) {
                            $candidates[] = $member;
                        }
                    }
                }
            }
            $candidates = array_values(array_unique($candidates));
            $theOtherMembers = $candidates !== [] && !in_array($u, $candidates, TRUE) && !in_array(self::UNKNOWN, $candidates, TRUE);

            if (!$theOtherMembers && $this->targetHasChat($plan, $room, $others[$id])) {
                $plan['leftBehind']['chat_rooms'][] = $id;
            } elseif (in_array(self::UNKNOWN, $candidates, TRUE)) {
                $plan['chatsUndecided'][$id] = 'a post in it was not sent from a TN address';
            } elseif ($candidates === []) {
                $plan['chatsUndecided'][$id] = 'nothing ties it to either member';
            } elseif ($candidates === [$u]) {
                $plan['chatsMove'][$id] = [$id, $column];
            } elseif (!in_array($u, $candidates, TRUE)) {
                $plan['chatsStay'][] = $id;
            } else {
                $plan['chatsUndecided'][$id] = 'both members could have written in it';
            }
            if (isset($plan['chatsUndecided'][$id])) {
                $plan['undecidedColumn'][$id] = $column;
            }
        }

        foreach ($moveChats as $chat) {
            if (!isset($plan['chatsUndecided'][$chat])) {
                throw new SplitRefused("Refusing: chat {$chat} is not undecided. Name only chats the dry run lists as undecided.");
            }
            $plan['chatsMove'][$chat] = [$chat, $plan['undecidedColumn'][$chat]];
            unset($plan['chatsUndecided'][$chat]);
        }

        $moved = array_keys($plan['chatsMove']);
        $plan['chatsMove'] = array_values($plan['chatsMove']);
        $plan['roster'] = DB::table('chat_roster')->whereIn('chatid', $moved ?: [0])->where('userid', $a)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $plan['chatMessages'] = DB::table('chat_messages')->whereIn('chatid', $moved ?: [0])->where('userid', $a)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $plan['movedChatRefs'] = DB::table('chat_messages')->whereIn('chatid', $moved ?: [0])->whereNotNull('refmsgid')->distinct()->pluck('refmsgid')->map(fn ($r) => (int) $r)->all();

        $staying = array_diff(array_keys($others), $moved);
        $plan['movedParties'] = array_values(array_filter(array_unique(array_map(fn ($c) => $others[$c], $moved))));
        $plan['stayingParties'] = array_values(array_filter(array_unique(array_map(fn ($c) => $others[$c], $staying))));
        unset($plan['undecidedColumn']);
    }

    private function planFollowers(array &$plan, int $a): void
    {
        $refs = $plan['movedChatRefs'] ?: [0];
        $pluck = function (string $table) use (&$plan, $a, $refs): array {
            $ids = [];
            foreach (DB::table($table)->where('userid', $a)->whereIn('msgid', $refs)->get(['id', 'msgid']) as $row) {
                if (!$this->staysBehind($plan, $table, (int) $row->id, ['userid' => $plan['target'], 'msgid' => $row->msgid])) {
                    $ids[] = (int) $row->id;
                }
            }

            return $ids;
        };
        $plan['promises'] = $pluck('messages_promises');
        $plan['takenBy'] = $pluck('messages_by');
        $plan['reneged'] = $pluck('messages_reneged');

        // Between the account and someone it now only chats with as the moved member.
        $onlyMoved = array_values(array_diff($plan['movedParties'], $plan['stayingParties'])) ?: [0];
        $plan['ratings'] = $plan['trysts'] = $plan['expected'] = [];
        foreach ([['ratings', 'rater', 'ratee', []], ['trysts', 'user1', 'user2', ['arrangedfor']]] as [$table, $c1, $c2, $alsoUnique]) {
            foreach ([[$c1, $c2], [$c2, $c1]] as [$mine, $theirs]) {
                foreach (DB::table($table)->where($mine, $a)->whereIn($theirs, $onlyMoved)->get() as $row) {
                    $twin = [$mine => $plan['target'], $theirs => $row->$theirs];
                    foreach ($alsoUnique as $column) {
                        $twin[$column] = $row->$column;
                    }

                    if (!$this->staysBehind($plan, $table, (int) $row->id, $twin)) {
                        $plan[$table][] = [(int) $row->id, $mine];
                    }
                }
            }
        }
        $movedChatIds = array_column($plan['chatsMove'], 0) ?: [0];
        $inMovedChats = DB::table('chat_messages')->whereIn('chatid', $movedChatIds)->pluck('id')->all() ?: [0];
        foreach (['expecter', 'expectee'] as $column) {
            foreach (DB::table('users_expected')->where($column, $a)->whereIn('chatmsgid', $inMovedChats)->pluck('id') as $id) {
                $plan['expected'][] = [(int) $id, $column];
            }
        }
    }

    private function planLogs(array &$plan, int $a, array $moving): void
    {
        $posts = $plan['messages'] ?: [0];
        $plan['logsUser'] = DB::table('logs')->where('user', $a)->whereIn('msgid', $posts)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $plan['logsByuser'] = DB::table('logs')->where('byuser', $a)->whereIn('msgid', $posts)->pluck('id')->map(fn ($i) => (int) $i)->all();

        $groups = array_merge($plan['movedGroups'], $plan['copiedGroups']) ?: [0];
        foreach (DB::table('logs')->where('user', $a)->where('type', 'Group')->whereIn('groupid', $groups)->get(['id', 'timestamp']) as $log) {
            foreach ($moving as $alias) {
                if ($this->sameMoment($alias['added'], (string) $log->timestamp)) {
                    $plan['logsUser'][] = (int) $log->id;
                    break;
                }
            }
        }
        $plan['logsUser'] = array_values(array_unique($plan['logsUser']));
    }

    /**
     * Each account keeps exactly one preferred address: the moved one that was preferred, or
     * the latest moved alias; and for the account, if its preferred one moved, the latest TN
     * address left on it. An account the member already has keeps the one it has.
     */
    private function planPreferred(array &$plan, array $aliases, array $moving): void
    {
        $plan['preferred'] = [];
        $movingIds = array_column($moving, 'id');
        $latest = fn (array $rows) => collect($rows)->sortBy([['added', 'desc'], ['id', 'desc']])->first();

        $keep = NULL;
        if ($moving !== [] && !$this->targetHas($plan, 'users_emails', ['userid' => $plan['target'], 'preferred' => 1])) {
            $keep = collect($moving)->firstWhere('preferred', 1) ?? $latest($moving);
        }
        foreach ($moving as $alias) {
            $want = $alias['id'] === ($keep['id'] ?? NULL) ? 1 : 0;
            if ($alias['preferred'] !== $want) {
                $plan['preferred'][] = [$alias['id'], $alias['preferred'], $want];
            }
        }

        $remaining = DB::table('users_emails')->where('userid', $plan['account'])->whereNotIn('id', $movingIds)->get(['id', 'preferred']);
        if ($remaining->isNotEmpty() && (int) $remaining->sum('preferred') === 0) {
            $left = array_values(array_filter($aliases, fn ($a) => !in_array($a['id'], $movingIds, TRUE)));
            $choice = $left ? $latest($left)['id'] : (int) $remaining->max('id');
            $plan['preferred'][] = [$choice, 0, 1];
        }
    }

    /** Whether the account the member already has holds a row like this. */
    private function targetHas(array $plan, string $table, array $row): bool
    {
        return $plan['target'] !== NULL && DB::table($table)->where($row)->exists();
    }

    /** A row whose twin the member's account holds cannot move. It stays, and the report says so. */
    private function staysBehind(array &$plan, string $table, int $id, array $twin): bool
    {
        if (!$this->targetHas($plan, $table, $twin)) {
            return FALSE;
        }

        $plan['leftBehind'][$table][] = $id;

        return TRUE;
    }

    /**
     * Whether the member's account already chats with the same person, in either order, as the
     * unique key does not see the reverse. A person has to read two chats, which a split cannot do.
     * A chat between the two accounts cannot move either: it would be a chat with oneself.
     */
    private function targetHasChat(array $plan, object $room, ?int $other): bool
    {
        $target = $plan['target'];
        if ($target === NULL) {
            return FALSE;
        }

        if ($other === $target) {
            return TRUE;
        }

        $rooms = DB::table('chat_rooms')->where('chattype', $room->chattype);

        return $other === NULL
            ? $rooms->where('user1', $target)->where('groupid', $room->groupid)->exists()
            : $rooms->where(fn ($q) => $q->where(['user1' => $target, 'user2' => $other])->orWhere(fn ($r) => $r->where(['user1' => $other, 'user2' => $target])))->exists();
    }

    /**
     * Undecided chats and rows left behind do not count: neither moves unless a person acts.
     * A preferred-address fix does, as it can be wanted when nothing else moves: an address
     * move elsewhere can take the account's preferred one and leave it with none.
     */
    private function nothingToMove(array $plan): bool
    {
        foreach (['emails', 'preferred', 'messages', 'membershipsMove', 'membershipsCopy', 'historyMove', 'chatsMove'] as $key) {
            if ($plan[$key] !== []) {
                return FALSE;
            }
        }

        return TRUE;
    }

    private function sameMoment(string $a, string $b): bool
    {
        return abs(Carbon::parse($a)->getTimestamp() - Carbon::parse($b)->getTimestamp()) <= self::SAME_MOMENT_SECONDS;
    }

    private function writeRollback(string $path, int $a, int $n, array $statements): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0700, TRUE) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create {$dir} for the rollback file; nothing written");
        }

        $sql = "-- Undoes tn:split-shared-account of account {$a} into account {$n}, run " . now()->toDateTimeString() . ".\n"
            . "-- Every statement re-asserts the state the split left, so one that changes no row means\n"
            . "-- something has changed since: stop and look.\n"
            . "START TRANSACTION;\n" . implode("\n", $statements) . "\nCOMMIT;\n";

        if (file_put_contents($path, $sql) === FALSE) {
            throw new RuntimeException("Cannot write the rollback file {$path}; nothing written");
        }
    }
}

<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupStatsService
{
    private const SRID = 3857;
    private const STATS_TYPE_APPROVED_MESSAGE_COUNT = 'ApprovedMessageCount';
    private const STATS_TYPE_OUTCOMES = 'Outcomes';
    private const GROUP_TYPE_FREEGLE = 'Freegle';
    private const DONATION_TARGET = 2000;
    private const ACTIVE_DAYS = 30;
    private const OUTCOMES_MONTHS = 13;

    public function updateGroupStats(bool $dryRun = false): array
    {
        $repostsFixed = $this->fixRepostSettings($dryRun);
        $polyindexFixed = $this->fixPolyindex($dryRun);
        $activityUpdated = $this->updateActivityAndFunding($dryRun);
        $lastModeratedUpdated = $this->updateLastModerated($dryRun);
        $lastAutoApproveUpdated = $this->updateLastAutoApprove($dryRun);
        $modCountsUpdated = $this->updateActiveOwnerModCounts($dryRun);
        $statsOutcomesUpdated = $this->updateStatsOutcomes($dryRun);

        Log::info("GroupStats: reposts={$repostsFixed}, polyindex={$polyindexFixed}, outcomes={$statsOutcomesUpdated}");

        return [
            'reposts_fixed' => $repostsFixed,
            'polyindex_fixed' => $polyindexFixed,
            'activity_updated' => $activityUpdated,
            'last_moderated_updated' => $lastModeratedUpdated,
            'last_autoapprove_updated' => $lastAutoApproveUpdated,
            'mod_counts_updated' => $modCountsUpdated,
            'stats_outcomes_updated' => $statsOutcomesUpdated,
        ];
    }

    private function fixRepostSettings(bool $dryRun = false): int
    {
        $groups = DB::table('groups')->select('id', 'nameshort', 'settings')->get();
        $count = 0;

        foreach ($groups as $group) {
            if (!$group->settings) {
                continue;
            }

            $settings = json_decode($group->settings, true);
            if (!isset($settings['reposts'])) {
                continue;
            }

            $changed = false;
            foreach ($settings['reposts'] as $key => $val) {
                if (!is_int($val)) {
                    $settings['reposts'][$key] = (int) $val;
                    $changed = true;
                }
            }

            if ($changed) {
                if (!$dryRun) {
                    DB::table('groups')->where('id', $group->id)->update(['settings' => json_encode($settings)]);
                }
                $count++;
            }
        }

        return $count;
    }

    private function fixPolyindex(bool $dryRun = false): int
    {
        $count = 0;

        $groups = DB::table('groups')->select('id', 'nameshort')->get();

        foreach ($groups as $group) {
            $row = DB::selectOne(
                "SELECT ST_AsText(polyindex) AS current, ST_AsText(ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'), ?)) AS geomtext FROM `groups` WHERE id = ?",
                [self::SRID, $group->id]
            );

            if ($row && $row->current !== $row->geomtext) {
                if (!$dryRun) {
                    DB::statement(
                        "UPDATE `groups` SET polyindex = ST_GeomFromText(?, ?) WHERE id = ?",
                        [$row->geomtext, self::SRID, $group->id]
                    );
                }
                $count++;
            }
        }

        return $count;
    }

    private function updateActivityAndFunding(bool $dryRun = false): int
    {
        $cutoff = date('Y-m-d', strtotime(self::ACTIVE_DAYS . ' days ago'));

        $totalResult = DB::table('stats')
            ->join('groups', 'stats.groupid', '=', 'groups.id')
            ->where('stats.type', self::STATS_TYPE_APPROVED_MESSAGE_COUNT)
            ->where('groups.type', self::GROUP_TYPE_FREEGLE)
            ->where('groups.publish', 1)
            ->where('groups.onhere', 1)
            ->where('stats.date', '>=', $cutoff)
            ->sum('stats.count');

        if (!$totalResult) {
            return 0;
        }

        $groups = DB::table('groups')
            ->where('type', self::GROUP_TYPE_FREEGLE)
            ->where('publish', 1)
            ->where('onhere', 1)
            ->orderByRaw('LOWER(nameshort) ASC')
            ->pluck('id');

        $count = 0;
        foreach ($groups as $groupId) {
            $groupCount = DB::table('stats')
                ->where('type', self::STATS_TYPE_APPROVED_MESSAGE_COUNT)
                ->where('groupid', $groupId)
                ->where('date', '>=', $cutoff)
                ->sum('count');

            $pc = 100 * $groupCount / $totalResult;

            $portion = (int) ceil($pc * self::DONATION_TARGET / 100) * 10;
            $portion = max(50, $portion);

            if (!$dryRun) {
                DB::table('groups')->where('id', $groupId)->update([
                    'activitypercent' => $pc,
                    'fundingtarget' => $portion,
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function updateLastModerated(bool $dryRun = false): int
    {
        $groups = DB::table('groups')->pluck('id');
        $count = 0;

        foreach ($groups as $groupId) {
            $max = DB::table('messages_groups')
                ->where('groupid', $groupId)
                ->whereNotNull('approvedby')
                ->max('approvedat');

            if (!$dryRun) {
                DB::table('groups')->where('id', $groupId)->update(['lastmoderated' => $max]);
            }
            $count++;
        }

        return $count;
    }

    private function updateLastAutoApprove(bool $dryRun = false): int
    {
        $groups = DB::table('groups')->select('id', 'lastautoapprove')->get();
        $count = 0;

        foreach ($groups as $group) {
            $max = DB::table('logs')
                ->where('groupid', $group->id)
                ->where('type', 'Message')
                ->where('subtype', 'Autoapproved')
                ->when($group->lastautoapprove, fn ($q) => $q->where('timestamp', '>=', $group->lastautoapprove))
                ->max('timestamp');

            if ($max) {
                if (!$dryRun) {
                    DB::table('groups')
                        ->where('id', $group->id)
                        ->where(function ($q) use ($max) {
                            $q->whereNull('lastautoapprove')->orWhere('lastautoapprove', '<', $max);
                        })
                        ->update(['lastautoapprove' => $max]);
                }
                $count++;
            }
        }

        return $count;
    }

    private function updateActiveOwnerModCounts(bool $dryRun = false): int
    {
        $start = date('Y-m-d', strtotime(self::ACTIVE_DAYS . ' days ago'));
        $groups = DB::table('groups')->pluck('id');
        $count = 0;

        foreach ($groups as $groupId) {
            $ownerCount = DB::table('users')
                ->join('memberships', 'memberships.userid', '=', 'users.id')
                ->where('memberships.groupid', $groupId)
                ->where('memberships.role', 'Owner')
                ->where('users.lastaccess', '>', $start)
                ->distinct()
                ->count('users.id');

            $modCount = DB::table('users')
                ->join('memberships', 'memberships.userid', '=', 'users.id')
                ->where('memberships.groupid', $groupId)
                ->where('memberships.role', 'Moderator')
                ->where('users.lastaccess', '>', $start)
                ->distinct()
                ->count('users.id');

            // Owners active on OTHER groups but not this one.
            $backupOwners = DB::table('memberships')
                ->where('memberships.groupid', $groupId)
                ->whereIn('memberships.role', ['Owner'])
                ->whereNotIn('memberships.userid', function ($sub) use ($groupId, $start) {
                    $sub->select('approvedby')->from('messages_groups')
                        ->where('groupid', $groupId)->where('arrival', '>', $start)->whereNotNull('approvedby');
                })
                ->whereIn('memberships.userid', function ($sub) use ($groupId, $start) {
                    $sub->select('approvedby')->from('messages_groups')
                        ->where('groupid', '!=', $groupId)->where('arrival', '>', $start)->whereNotNull('approvedby');
                })
                ->distinct('userid')
                ->count('userid');

            $backupMods = DB::table('memberships')
                ->where('memberships.groupid', $groupId)
                ->whereIn('memberships.role', ['Moderator'])
                ->whereNotIn('memberships.userid', function ($sub) use ($groupId, $start) {
                    $sub->select('approvedby')->from('messages_groups')
                        ->where('groupid', $groupId)->where('arrival', '>', $start)->whereNotNull('approvedby');
                })
                ->whereIn('memberships.userid', function ($sub) use ($groupId, $start) {
                    $sub->select('approvedby')->from('messages_groups')
                        ->where('groupid', '!=', $groupId)->where('arrival', '>', $start)->whereNotNull('approvedby');
                })
                ->distinct('userid')
                ->count('userid');

            if (!$dryRun) {
                DB::table('groups')->where('id', $groupId)->update([
                    'activeownercount' => $ownerCount,
                    'activemodcount' => $modCount,
                    'backupownersactive' => $backupOwners,
                    'backupmodsactive' => $backupMods,
                ]);
            }
            $count++;
        }

        return $count;
    }

    private function updateStatsOutcomes(bool $dryRun = false): int
    {
        $cutoff = date('Y-m-01', strtotime(self::OUTCOMES_MONTHS . ' months ago'));

        $stats = DB::table('stats')
            ->where('type', self::STATS_TYPE_OUTCOMES)
            ->where('date', '>', $cutoff)
            ->selectRaw("groupid, SUM(count) AS count, CONCAT(YEAR(date), '-', LPAD(MONTH(date), 2, '0')) AS month_date")
            ->groupByRaw('groupid, YEAR(date), MONTH(date)')
            ->get();

        $count = 0;
        foreach ($stats as $stat) {
            if ($stat->count) {
                if (!$dryRun) {
                    DB::table('stats_outcomes')->upsert(
                        ['groupid' => $stat->groupid, 'count' => $stat->count, 'date' => $stat->month_date],
                        ['groupid', 'date'],
                        ['count']
                    );
                }
                $count++;
            }
        }

        return $count;
    }
}

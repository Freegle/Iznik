#!/usr/bin/env php
<?php
/**
 * Spiralling-out parameter analysis
 *
 * Replaces the three earlier scripts (spiralling_curve_analysis.php,
 * spiralling_response_time_analysis.php, spiralling_transport_categorise.php)
 * with a single coherent analysis that:
 *
 *  1. Measures SPATIAL reach: how far away are first-repliers and takers
 *     (straight-line km, p50/p75/p90 over the full message corpus).
 *
 *  2. Measures TEMPORAL hazard: for messages that DID receive a reply, how
 *     long did they wait (wall-clock hours)?  We fit a simple step-hazard to
 *     find where the reply rate drops, which is the right signal for when to
 *     expand — not a fixed 4/6/8h assumption.
 *
 *  3. Measures SILENCE rate: what fraction of messages receive NO reply at
 *     all within 1, 2, 3... days?  This is the population the algorithm
 *     most needs to help.
 *
 *  4. Joins spatial and temporal data to recommend:
 *     - Initial isochrone size (km, then converted to travel minutes)
 *     - Expansion timing derived from the empirical hazard curve
 *     - Maximum isochrone size
 *
 * Robustness notes vs old scripts:
 *  - No weighted-percentile averaging (stats computed on individual rows)
 *  - Includes messages with no replies (survival denominator)
 *  - Wall-clock time throughout (matches expansion curve units)
 *  - No dependency on transport_postcode_classification table
 *  - Weekday vs weekend breakdown
 *
 * Usage:
 *   php spiralling_analysis.php [--months=6] [--output=/tmp/spiralling.json]
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');

// Allow DB connection to be overridden via environment variables so this
// script can be pointed at production without modifying the container config.
if (getenv('SPIRALLING_DB_HOST')) {
    $dbHost = getenv('SPIRALLING_DB_HOST') . ':' . (getenv('SPIRALLING_DB_PORT') ?: '3306');
    define('SQLHOSTS_READ', $dbHost);
    define('SQLHOSTS_MOD',  $dbHost);
    define('SQLDB',         getenv('SPIRALLING_DB_NAME') ?: 'iznik');
    define('SQLUSER',       getenv('SPIRALLING_DB_USER') ?: 'root');
    define('SQLPASSWORD',   getenv('SPIRALLING_DB_PASS') ?: '');
}

require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');
global $dbhr, $dbhm;

class SpirallingAnalysis {
    private $dbhr;
    private $months;

    public function __construct($dbhr, $months = 6) {
        $this->dbhr  = $dbhr;
        $this->months = $months;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Entry point
    // ──────────────────────────────────────────────────────────────────────

    public function run() {
        echo "Spiralling-out analysis\n";
        echo "=======================\n";
        echo "Period: last {$this->months} months\n\n";

        echo "Step 1: Active-hour windows from last week's data...\n";
        $activeHours = $this->detectActiveHours();

        echo "\nStep 2: Spatial reach (first-replier and taker distances)...\n";
        $spatial = $this->spatialReach();

        echo "\nStep 3: Temporal hazard (wall-clock hours to first reply)...\n";
        $temporal = $this->temporalHazard($activeHours);

        echo "\nStep 4: Silence rate (messages with no reply)...\n";
        $silence = $this->silenceRate();

        echo "\nStep 5: Recommendations...\n";
        $recs = $this->recommend($spatial, $temporal, $silence);

        $this->printReport($spatial, $temporal, $silence, $recs, $activeHours);

        return [
            'active_hours'  => $activeHours,
            'spatial'       => $spatial,
            'temporal'      => $temporal,
            'silence'       => $silence,
            'recommendations' => $recs,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step 1: detect active hours (the hours that have ≥20 % of peak
    //         activity, computed separately for weekdays and weekends)
    // ──────────────────────────────────────────────────────────────────────

    private function detectActiveHours() {
        $since = date('Y-m-d H:i:s', strtotime('-7 days'));

        $rows = $this->dbhr->preQuery("
            SELECT HOUR(ts) AS h,
                   DAYOFWEEK(ts) IN (1,7) AS is_weekend,
                   COUNT(*) AS n
            FROM (
                SELECT arrival AS ts FROM messages WHERE arrival >= ?
                UNION ALL
                SELECT date    AS ts FROM chat_messages
                WHERE date >= ? AND type = 'Interested'
            ) ev
            GROUP BY h, is_weekend
        ", [$since, $since]);

        $byDay = ['weekday' => array_fill(0, 24, 0), 'weekend' => array_fill(0, 24, 0)];
        foreach ($rows as $r) {
            $key = $r['is_weekend'] ? 'weekend' : 'weekday';
            $byDay[$key][(int)$r['h']] += (int)$r['n'];
        }

        $result = [];
        foreach ($byDay as $type => $counts) {
            $peak = max($counts) ?: 1;
            $threshold = $peak * 0.20;
            $active = array_keys(array_filter($counts, fn($c) => $c >= $threshold));
            $result[$type] = [
                'start' => $active ? min($active) : 7,
                'end'   => $active ? max($active) + 1 : 23,
                'hours' => $active,
            ];
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step 2: spatial reach
    //   For each message that got a reply, how far away (haversine km) was:
    //     (a) the first replier
    //     (b) the taker (messages_outcomes.outcome = 'Taken')
    //   Uses users.lastlocation → locations for user home position.
    // ──────────────────────────────────────────────────────────────────────

    private function spatialReach() {
        $since = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));

        // ── first repliers ────────────────────────────────────────────────
        $replierRows = $this->dbhr->preQuery("
            SELECT
                msg_loc.lat AS mlat, msg_loc.lng AS mlng,
                ul.lat      AS rlat, ul.lng      AS rlng
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            INNER JOIN `groups` g         ON g.id = mg.groupid AND g.type = 'Freegle'
            INNER JOIN locations msg_loc  ON msg_loc.id = m.locationid
                                         AND msg_loc.type = 'Postcode'
            INNER JOIN (
                SELECT refmsgid, MIN(userid) AS userid
                FROM chat_messages
                WHERE type = 'Interested'
                GROUP BY refmsgid
            ) fr ON fr.refmsgid = m.id
            INNER JOIN users u          ON u.id = fr.userid
            INNER JOIN locations ul     ON ul.id = u.lastlocation
            WHERE m.arrival >= ?
              AND m.type IN ('Offer','Wanted')
              AND msg_loc.lat IS NOT NULL AND ul.lat IS NOT NULL
              AND (g.defaultlocation IS NULL OR u.lastlocation != g.defaultlocation)
        ", [$since]);

        $replierDists = [];
        foreach ($replierRows as $r) {
            $replierDists[] = $this->haversine($r['mlat'], $r['mlng'], $r['rlat'], $r['rlng']);
        }
        sort($replierDists);
        echo "  First-replier distances: " . count($replierDists) . " messages\n";

        // Taker distance analysis not available: messages_outcomes has no
        // userid column and there is no efficient proxy in the current schema.
        $takerDists  = [];
        $takerDists2d = [];
        echo "  Taker distances: not available (messages_outcomes has no userid)\n";

        return [
            'replier' => [
                'n'   => count($replierDists),
                'p50' => $this->pct($replierDists, 50),
                'p75' => $this->pct($replierDists, 75),
                'p90' => $this->pct($replierDists, 90),
                'p95' => $this->pct($replierDists, 95),
            ],
            'taker_all' => [
                'n'   => count($takerDists),
                'p50' => $this->pct($takerDists, 50),
                'p75' => $this->pct($takerDists, 75),
                'p90' => $this->pct($takerDists, 90),
                'p95' => $this->pct($takerDists, 95),
            ],
            'taker_48h' => [
                'n'   => count($takerDists2d),
                'p50' => $this->pct($takerDists2d, 50),
                'p75' => $this->pct($takerDists2d, 75),
                'p90' => $this->pct($takerDists2d, 90),
                'p95' => $this->pct($takerDists2d, 95),
            ],
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step 3: temporal hazard
    //
    // For each message that received at least one reply, record the wall-clock
    // hours elapsed.  Then build a step-hazard table: for each 1-hour bucket,
    // what fraction of still-unreplied messages received their first reply in
    // that hour?  This tells us where reply probability drops sharply.
    //
    // We also compute weekday vs weekend curves because they differ.
    // ──────────────────────────────────────────────────────────────────────

    private function temporalHazard(array $activeHours) {
        $since = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));

        // All messages (denominator for silence rate too)
        $allMsgs = $this->dbhr->preQuery("
            SELECT m.id, m.arrival,
                   DAYOFWEEK(m.arrival) IN (1,7) AS is_weekend,
                   MIN(cm.date) AS first_reply
            FROM messages m
            INNER JOIN messages_groups mg ON mg.msgid = m.id
            INNER JOIN `groups` g         ON g.id = mg.groupid AND g.type = 'Freegle'
            LEFT JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested'
            WHERE m.arrival >= ?
              AND m.type IN ('Offer','Wanted')
            GROUP BY m.id
        ", [$since]);

        // Build hourly hazard (wall-clock, up to 72 h) for weekday / weekend
        $maxH = 72;
        $buckets = ['weekday' => array_fill(0, $maxH + 1, ['replied' => 0, 'at_risk' => 0]),
                    'weekend' => array_fill(0, $maxH + 1, ['replied' => 0, 'at_risk' => 0])];

        foreach ($allMsgs as $msg) {
            $dayType = $msg['is_weekend'] ? 'weekend' : 'weekday';
            if (!$msg['first_reply']) {
                // censored at 72 h: contributes to at_risk for all buckets up to 72h
                for ($h = 0; $h < $maxH; $h++) {
                    $buckets[$dayType][$h]['at_risk']++;
                }
                continue;
            }
            $hoursToReply = (strtotime($msg['first_reply']) - strtotime($msg['arrival'])) / 3600;
            $hoursToReply = min($hoursToReply, $maxH);
            $bucket = (int) floor($hoursToReply);
            for ($h = 0; $h <= min($bucket, $maxH - 1); $h++) {
                $buckets[$dayType][$h]['at_risk']++;
            }
            if ($bucket < $maxH) {
                $buckets[$dayType][$bucket]['replied']++;
            }
        }

        // Compute hazard rate per hour for each day type
        $result = [];
        foreach ($buckets as $dayType => $bkts) {
            $hazard = [];
            for ($h = 0; $h < $maxH; $h++) {
                $atRisk  = $bkts[$h]['at_risk'];
                $replied = $bkts[$h]['replied'];
                $hazard[$h] = $atRisk > 0 ? round($replied / $atRisk, 5) : 0;
            }

            // Find natural breakpoints: hours where hazard drops to ≤50% of
            // the rolling average of the preceding 3 hours.
            $breakpoints = [];
            for ($h = 3; $h < $maxH; $h++) {
                $recent = ($hazard[$h-1] + $hazard[$h-2] + $hazard[$h-3]) / 3;
                if ($recent > 0.001 && $hazard[$h] <= $recent * 0.5) {
                    $breakpoints[] = $h;
                }
            }

            // Time percentiles for messages that did reply (individual-level)
            $replyTimes = [];
            foreach ($allMsgs as $msg) {
                if ($msg['is_weekend'] == ($dayType === 'weekend') && $msg['first_reply']) {
                    $h = (strtotime($msg['first_reply']) - strtotime($msg['arrival'])) / 3600;
                    $replyTimes[] = $h;
                }
            }
            sort($replyTimes);

            $result[$dayType] = [
                'n_with_reply'  => count($replyTimes),
                'hazard_by_hour' => $hazard,
                'breakpoints'   => $breakpoints,
                'reply_time_p50' => $this->pct($replyTimes, 50),
                'reply_time_p75' => $this->pct($replyTimes, 75),
                'reply_time_p90' => $this->pct($replyTimes, 90),
            ];
        }

        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step 4: silence rate — what fraction of messages get no reply within
    //   1 / 2 / 3 / 7 days?  This is the population the algorithm helps most.
    // ──────────────────────────────────────────────────────────────────────

    private function silenceRate() {
        $since = date('Y-m-d H:i:s', strtotime("-{$this->months} months"));
        // Only include messages old enough for the observation window
        $cutoff7d = date('Y-m-d H:i:s', strtotime('-7 days'));

        $rows = $this->dbhr->preQuery("
            SELECT
                SUM(1)                                                  AS total,
                SUM(first_reply IS NULL)                                AS no_reply,
                SUM(first_reply IS NULL OR first_reply > arrival + INTERVAL 1 DAY)  AS no_reply_1d,
                SUM(first_reply IS NULL OR first_reply > arrival + INTERVAL 2 DAY)  AS no_reply_2d,
                SUM(first_reply IS NULL OR first_reply > arrival + INTERVAL 3 DAY)  AS no_reply_3d,
                SUM(first_reply IS NULL OR first_reply > arrival + INTERVAL 7 DAY)  AS no_reply_7d
            FROM (
                SELECT m.id, m.arrival, MIN(cm.date) AS first_reply
                FROM messages m
                INNER JOIN messages_groups mg ON mg.msgid = m.id
                INNER JOIN `groups` g ON g.id = mg.groupid AND g.type = 'Freegle'
                LEFT JOIN chat_messages cm ON cm.refmsgid = m.id AND cm.type = 'Interested'
                WHERE m.arrival >= ? AND m.arrival < ?
                  AND m.type IN ('Offer','Wanted')
                GROUP BY m.id
            ) sub
        ", [$since, $cutoff7d]);

        $r = $rows[0];
        $total = max((int)$r['total'], 1);
        return [
            'total'       => (int)$r['total'],
            'pct_no_reply'    => round(100 * $r['no_reply']    / $total, 1),
            'pct_no_reply_1d' => round(100 * $r['no_reply_1d'] / $total, 1),
            'pct_no_reply_2d' => round(100 * $r['no_reply_2d'] / $total, 1),
            'pct_no_reply_3d' => round(100 * $r['no_reply_3d'] / $total, 1),
            'pct_no_reply_7d' => round(100 * $r['no_reply_7d'] / $total, 1),
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Step 5: recommendations
    //   - Initial isochrone: p90 of first-replier distances → converted to
    //     travel minutes using simple speed assumptions (5/15/50 km/h)
    //   - Expansion intervals: derived from the hazard breakpoints, not
    //     assumed.  If breakpoints cluster around hour X, expand then.
    //   - Maximum isochrone: p90 of 48h-taker distances
    // ──────────────────────────────────────────────────────────────────────

    private function recommend(array $spatial, array $temporal, array $silence) {
        $initialKm = $spatial['replier']['p90'];
        $maxKm     = $spatial['taker_48h']['p90'] ?: $spatial['taker_all']['p90'];

        // km → travel minutes (straight-line approximation; actual travel time
        // will be longer, but consistent with how we use isochrones)
        $toMinutes = fn($km, $kmh) => $km > 0 ? round($km / $kmh * 60) : null;

        // Use weekday breakpoints (most traffic)
        $wdBreakpoints = $temporal['weekday']['breakpoints'] ?? [];

        // Deduplicate breakpoints that are within 1h of each other (keep first)
        $filtered = [];
        $last = -99;
        foreach ($wdBreakpoints as $bp) {
            if ($bp - $last > 1) {
                $filtered[] = $bp;
                $last = $bp;
            }
        }

        // Build expansion schedule from data: expand at each breakpoint up to
        // 72h, merging any that are within 2h of each other.
        $schedule = [];
        $prev = 0;
        foreach ($filtered as $bp) {
            if ($bp > 72) break;
            $schedule[] = ['at_hour' => $bp, 'wait_h' => $bp - $prev];
            $prev = $bp;
        }
        // If we found fewer than 2 breakpoints, fall back to conservative
        // fixed spacing (reply-time p75 as the interval, but capped at 8h)
        if (count($schedule) < 2) {
            $interval = min(8, max(2, round($temporal['weekday']['reply_time_p75'] ?? 4)));
            $schedule = [];
            for ($h = $interval; $h <= 48; $h += $interval) {
                $schedule[] = ['at_hour' => $h, 'wait_h' => $interval];
            }
        }

        return [
            'initial_km'     => round($initialKm, 1),
            'max_km'         => round($maxKm, 1),
            'initial_walk_min'  => $toMinutes($initialKm, 5),
            'initial_cycle_min' => $toMinutes($initialKm, 15),
            'initial_drive_min' => $toMinutes($initialKm, 50),
            'max_walk_min'      => $toMinutes($maxKm, 5),
            'max_cycle_min'     => $toMinutes($maxKm, 15),
            'max_drive_min'     => $toMinutes($maxKm, 50),
            'expansion_schedule' => $schedule,
            'derived_from_data'  => count($wdBreakpoints) >= 2,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────
    // Output
    // ──────────────────────────────────────────────────────────────────────

    private function printReport(array $sp, array $tm, array $sl, array $rc, array $ah) {
        echo "\n";
        echo "═══════════════════════════════════════════════\n";
        echo " SPIRALLING ANALYSIS RESULTS\n";
        echo "═══════════════════════════════════════════════\n\n";

        echo "ACTIVE HOURS (from last week's data)\n";
        echo "─────────────────────────────────────\n";
        foreach ($ah as $type => $data) {
            echo "  {$type}: {$data['start']}:00 – {$data['end']}:00\n";
        }

        echo "\nSPATIAL REACH\n";
        echo "─────────────────────────────────────\n";
        printf("  First repliers (n=%d):\n", $sp['replier']['n']);
        printf("    p50=%.1f km  p75=%.1f km  p90=%.1f km  p95=%.1f km\n",
            $sp['replier']['p50'], $sp['replier']['p75'],
            $sp['replier']['p90'], $sp['replier']['p95']);
        printf("  All takers (n=%d):\n", $sp['taker_all']['n']);
        printf("    p50=%.1f km  p75=%.1f km  p90=%.1f km  p95=%.1f km\n",
            $sp['taker_all']['p50'], $sp['taker_all']['p75'],
            $sp['taker_all']['p90'], $sp['taker_all']['p95']);
        printf("  Takers within 48 h (n=%d):\n", $sp['taker_48h']['n']);
        printf("    p50=%.1f km  p75=%.1f km  p90=%.1f km  p95=%.1f km\n",
            $sp['taker_48h']['p50'], $sp['taker_48h']['p75'],
            $sp['taker_48h']['p90'], $sp['taker_48h']['p95']);

        echo "\nSILENCE RATE (messages with no reply)\n";
        echo "─────────────────────────────────────\n";
        printf("  Total messages analysed: %d\n", $sl['total']);
        printf("  No reply at all: %s%%\n",  $sl['pct_no_reply']);
        printf("  No reply within 1 day: %s%%\n",  $sl['pct_no_reply_1d']);
        printf("  No reply within 2 days: %s%%\n", $sl['pct_no_reply_2d']);
        printf("  No reply within 3 days: %s%%\n", $sl['pct_no_reply_3d']);
        printf("  No reply within 7 days: %s%%\n", $sl['pct_no_reply_7d']);

        echo "\nTEMPORAL HAZARD (hours to first reply)\n";
        echo "─────────────────────────────────────\n";
        foreach (['weekday', 'weekend'] as $dt) {
            $t = $tm[$dt];
            printf("  %s (n=%d messages with reply):\n", ucfirst($dt), $t['n_with_reply']);
            printf("    p50=%.1fh  p75=%.1fh  p90=%.1fh\n",
                $t['reply_time_p50'], $t['reply_time_p75'], $t['reply_time_p90']);
            echo  "    Hazard drops (natural expansion points): ";
            echo  implode('h, ', array_slice($t['breakpoints'], 0, 8)) . "h\n";
        }

        echo "\nRECOMMENDATIONS\n";
        echo "─────────────────────────────────────\n";
        printf("  Initial isochrone: %.1f km\n", $rc['initial_km']);
        printf("    = %d min walk  /  %d min cycle  /  %d min drive\n",
            $rc['initial_walk_min'], $rc['initial_cycle_min'], $rc['initial_drive_min']);
        printf("  Maximum isochrone: %.1f km\n", $rc['max_km']);
        printf("    = %d min walk  /  %d min cycle  /  %d min drive\n",
            $rc['max_walk_min'], $rc['max_cycle_min'], $rc['max_drive_min']);

        echo "\n  Expansion schedule";
        if ($rc['derived_from_data']) {
            echo " (derived from hazard breakpoints in the data):\n";
        } else {
            echo " (fallback — insufficient breakpoints detected, using p75 interval):\n";
        }
        foreach ($rc['expansion_schedule'] as $step) {
            printf("    Expand at hour %-3d  (wait %dh since previous)\n",
                $step['at_hour'], $step['wait_h']);
        }

        echo "\nNote: speed assumptions for km→minutes are straight-line\n";
        echo "      (walk 5 km/h, cycle 15 km/h, drive 50 km/h). Actual\n";
        echo "      isochrone travel minutes will differ by area type.\n";
    }

    // ──────────────────────────────────────────────────────────────────────
    // Utilities
    // ──────────────────────────────────────────────────────────────────────

    private function haversine($lat1, $lng1, $lat2, $lng2) {
        $R    = 6371;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a    = sin($dLat/2)**2
              + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    private function pct(array $sorted, int $p) {
        $n = count($sorted);
        if ($n === 0) return 0;
        $idx = ($p / 100) * ($n - 1);
        $lo  = (int) floor($idx);
        $hi  = (int) ceil($idx);
        if ($lo === $hi) return $sorted[$lo];
        return $sorted[$lo] + ($idx - $lo) * ($sorted[$hi] - $sorted[$lo]);
    }
}

// ── CLI ───────────────────────────────────────────────────────────────────

$months = 6;
$outputFile = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--months='))  $months     = (int) substr($arg, 9);
    if (str_starts_with($arg, '--output='))  $outputFile = substr($arg, 9);
}

$analysis = new SpirallingAnalysis($dbhr, $months);
$results  = $analysis->run();

if ($outputFile) {
    file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "\nResults saved to: $outputFile\n";
}

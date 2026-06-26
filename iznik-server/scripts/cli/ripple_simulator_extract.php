#!/usr/bin/env php
<?php
/**
 * Ripple-algorithm simulator: extract historical post + reply data.
 *
 * One-off production-DB query that gathers, for each post in the last N months
 * that received at least one reply:
 *   - post location (lat, lng) and arrival time
 *   - post outcome (taken / promised / withdrawn / expired) and outcome time
 *   - every replier's location and reply time
 *
 * The output JSON is the input to the simulator: nothing about the new
 * algorithm is needed here, only facts about what users did historically.
 * Because of the cost of running this against the production DB it is
 * deliberately a one-shot script; the simulator then operates on the cached
 * JSON and can be re-run cheaply against different curve definitions.
 *
 * Usage:
 *   php ripple_simulator_extract.php [--months=12] [--limit=10000] \
 *                                    [--output=/tmp/ripple_sim_data.json]
 *
 * --months  How far back to pull post arrivals (default 12).
 * --limit   Maximum number of posts to sample (default 10000; the script
 *           samples by post-id modulo to spread evenly across the period).
 * --output  Path to write the JSON file (default /tmp/ripple_sim_data.json).
 */

namespace Freegle\Iznik;

define('BASE_DIR', dirname(__FILE__) . '/../..');
require_once(BASE_DIR . '/include/config.php');
require_once(IZNIK_BASE . '/include/db.php');

global $dbhr, $dbhm;

// ----- args -----
$months = 12;
$limit  = 10000;
$output = '/tmp/ripple_sim_data.json';
foreach ($argv as $arg) {
    if (strpos($arg, '--months=') === 0) {
        $months = (int) substr($arg, 9);
    } elseif (strpos($arg, '--limit=') === 0) {
        $limit = (int) substr($arg, 8);
    } elseif (strpos($arg, '--output=') === 0) {
        $output = substr($arg, 9);
    }
}

$cutoff = date('Y-m-d 00:00:00', strtotime("-{$months} months"));
echo "Ripple simulator data extraction\n";
echo "  Period      : last {$months} months (from {$cutoff})\n";
echo "  Max posts   : {$limit}\n";
echo "  Output file : {$output}\n\n";

// ----- 1. enumerate candidate posts with at least one reply -----
echo "Step 1: enumerating candidate posts...\n";
$idSql = "SELECT m.id
            FROM messages m
            INNER JOIN messages_spatial ms ON ms.msgid = m.id
            WHERE m.arrival >= ?
              AND m.type IN ('Offer', 'Wanted')
              AND ms.point IS NOT NULL
              AND EXISTS (
                  SELECT 1 FROM chat_messages cm
                   WHERE cm.refmsgid = m.id
                     AND cm.type = 'Interested'
                     AND cm.reviewrejected = 0
                     AND cm.reviewrequired = 0
              )";
$ids = $dbhr->preQuery($idSql, [$cutoff]);
$total = count($ids);
echo "  ✓ {$total} candidate posts with replies\n";

if ($total === 0) {
    echo "Nothing to extract.\n";
    exit(0);
}

// Even sample by stride so we don't bias toward one part of the year.
if ($total > $limit) {
    $stride  = (int) ceil($total / $limit);
    $sampled = [];
    foreach ($ids as $i => $row) {
        if ($i % $stride === 0) {
            $sampled[] = $row['id'];
        }
        if (count($sampled) >= $limit) {
            break;
        }
    }
    echo "  ✓ sampled {$limit} of {$total} (stride {$stride})\n";
} else {
    $sampled = array_column($ids, 'id');
    echo "  ✓ using all {$total}\n";
}

// ----- 2. fetch post metadata in batches -----
echo "\nStep 2: fetching post metadata...\n";
$posts        = [];
$batchSize    = 500;
$batches      = array_chunk($sampled, $batchSize);
$totalBatches = count($batches);

foreach ($batches as $bi => $batch) {
    $ph  = implode(',', array_fill(0, count($batch), '?'));
    $sql = "SELECT m.id,
                   ST_Y(ms.point) AS lat,
                   ST_X(ms.point) AS lng,
                   m.arrival,
                   m.type,
                   m.fromuser,
                   mo.outcome,
                   mo.timestamp AS outcome_at
              FROM messages m
              INNER JOIN messages_spatial ms ON ms.msgid = m.id
              LEFT JOIN messages_outcomes mo
                ON mo.msgid = m.id
               AND mo.outcome IN ('Taken','Promised','Withdrawn','Repost')
              WHERE m.id IN ({$ph})";
    $rows = $dbhr->preQuery($sql, $batch);
    foreach ($rows as $r) {
        $posts[(int) $r['id']] = [
            'post_id'    => (int) $r['id'],
            'lat'        => (float) $r['lat'],
            'lng'        => (float) $r['lng'],
            'arrival'    => $r['arrival'],
            'type'       => $r['type'],
            'fromuser'   => (int) $r['fromuser'],
            'outcome'    => $r['outcome'],
            'outcome_at' => $r['outcome_at'],
            'repliers'   => [],
        ];
    }
    if (($bi + 1) % 4 === 0 || $bi + 1 === $totalBatches) {
        echo "  ...post metadata batch " . ($bi + 1) . "/{$totalBatches}\n";
    }
}

// ----- 3. fetch repliers per post -----
echo "\nStep 3: fetching repliers...\n";
$replierCount = 0;
$noLocation   = 0;
foreach ($batches as $bi => $batch) {
    $ph  = implode(',', array_fill(0, count($batch), '?'));
    // We use approxlocs for the replier location.  Users with no approxloc
    // are still counted (in noLocation) but excluded from the simulator
    // because we can't compute their drive-time.
    $sql = "SELECT cm.refmsgid AS post_id,
                   cm.userid,
                   cm.date AS reply_time,
                   ual.lat AS replier_lat,
                   ual.lng AS replier_lng
              FROM chat_messages cm
              LEFT JOIN users_approxlocs ual ON ual.userid = cm.userid
              WHERE cm.refmsgid IN ({$ph})
                AND cm.type = 'Interested'
                AND cm.reviewrejected = 0
                AND cm.reviewrequired = 0";
    $rows = $dbhr->preQuery($sql, $batch);
    foreach ($rows as $r) {
        $pid = (int) $r['post_id'];
        if (!isset($posts[$pid])) {
            continue;
        }
        if ($r['replier_lat'] === null || $r['replier_lng'] === null) {
            $noLocation++;
            continue;
        }
        $posts[$pid]['repliers'][] = [
            'user_id'    => (int) $r['userid'],
            'lat'        => (float) $r['replier_lat'],
            'lng'        => (float) $r['replier_lng'],
            'reply_time' => $r['reply_time'],
        ];
        $replierCount++;
    }
    if (($bi + 1) % 4 === 0 || $bi + 1 === $totalBatches) {
        echo "  ...reply batch " . ($bi + 1) . "/{$totalBatches} ({$replierCount} replies so far)\n";
    }
}

// Drop posts that lost all their repliers (e.g. all repliers had no location).
$kept = [];
foreach ($posts as $pid => $p) {
    if (!empty($p['repliers'])) {
        $kept[] = $p;
    }
}

echo "\nSummary:\n";
echo "  Posts kept       : " . count($kept) . "\n";
echo "  (post,replier) pairs : {$replierCount}\n";
echo "  Repliers with no location (dropped) : {$noLocation}\n";

// ----- 4. write JSON -----
$tmp = $output . '.tmp';
$f   = fopen($tmp, 'w');
fwrite($f, "[\n");
$n = count($kept);
foreach ($kept as $i => $p) {
    fwrite($f, json_encode($p, JSON_UNESCAPED_SLASHES));
    fwrite($f, $i + 1 < $n ? ",\n" : "\n");
}
fwrite($f, "]\n");
fclose($f);
rename($tmp, $output);
$bytes = filesize($output);
echo "\nWrote " . number_format($bytes) . " bytes to {$output}\n";

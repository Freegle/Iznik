<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rolls one day of unified digest emails into email_digest_positions_daily: per email type and post
 * position, how many digests showed a post there and how many were clicked there. The Go handler
 * DigestClickPositions (iznik-server-go/emailtracking/emailtracking.go) sums these rows for the
 * ModTools chart, and computes the same figures live for the days that have none.
 *
 * The two MUST agree, so the cohort and counting rules here are a port of that handler's SQL:
 *
 * - Cohort: email_type LIKE 'UnifiedDigest%', recipient not a Trash Nothing account (users.tnuserid
 *   NULL), metadata present, and at least one post in metadata.post_msgids.
 * - Shown: a digest with K posts displayed positions 0..K-1, so position N is shown by every digest
 *   with more than N posts.
 * - Clicks: email_tracking_clicks.link_position of the form post_N (legacy card link) or pN (current
 *   compact card link). Summary links (yN) and image links (iN) are left out because they say nothing
 *   about a post's vertical position. emails_clicked counts distinct digests per label; a digest that
 *   clicked both schemes at one position is counted once per scheme, as the Go handler does.
 *
 * A day is one statement window [00:00:00, 23:59:59] on email_tracking.sent_at, read through the
 * sent_at index; email_tracking is millions of rows and a wider window stalls the cluster.
 *
 * Every rolled day also gets a marker row (email_type '', position 0, all zero), so the API can tell a
 * day with no digests from a day not yet rolled.
 */
class DigestPositionsRollupService
{
    public const MARKER_TYPE = '';

    /**
     * Compute and upsert one day. Returns the number of position rows written, markers excluded.
     */
    public function rollupDay(Carbon $day): int
    {
        $date = $day->toDateString();
        $start = $date.' 00:00:00';
        $end = $date.' 23:59:59';

        $cohort = "e.email_type LIKE 'UnifiedDigest%'
            AND u.tnuserid IS NULL
            AND e.metadata IS NOT NULL
            AND JSON_LENGTH(e.metadata, '$.post_msgids') > 0
            AND e.sent_at BETWEEN ? AND ?";

        // keep-raw: FORCE INDEX (sent_at) and JSON_LENGTH grouping on a computed alias cannot be rendered by the builder.
        $sizes = DB::select("
            SELECT e.email_type AS email_type, JSON_LENGTH(e.metadata, '$.post_msgids') AS num_posts, COUNT(*) AS cnt
            FROM email_tracking e FORCE INDEX (sent_at)
            LEFT JOIN users u ON e.userid = u.id
            WHERE $cohort
            GROUP BY e.email_type, num_posts", [$start, $end]);

        // keep-raw: REGEXP on link_position plus COUNT(DISTINCT) over the shared cohort fragment; the builder cannot render it.
        $clicks = DB::select("
            SELECT e.email_type AS email_type, c.link_position AS link_position,
                   COUNT(DISTINCT c.email_tracking_id) AS emails_clicked,
                   COUNT(*) AS clicks
            FROM email_tracking_clicks c
            JOIN email_tracking e ON c.email_tracking_id = e.id
            LEFT JOIN users u ON e.userid = u.id
            WHERE $cohort
              AND c.link_position REGEXP '^(post_[0-9]+|p[0-9]+)\$'
            GROUP BY e.email_type, c.link_position", [$start, $end]);

        /** @var array<string, array<int, array{shown:int, emails_clicked:int, clicks:int}>> $byType */
        $byType = [];

        foreach ($sizes as $row) {
            $numPosts = (int) $row->num_posts;
            for ($n = 0; $n < $numPosts; $n++) {
                $byType[$row->email_type][$n] ??= ['shown' => 0, 'emails_clicked' => 0, 'clicks' => 0];
                $byType[$row->email_type][$n]['shown'] += (int) $row->cnt;
            }
        }

        foreach ($clicks as $row) {
            // The position is the trailing integer, whichever scheme the label uses.
            if (! preg_match('/(\d+)$/', (string) $row->link_position, $m)) {
                continue;
            }
            $n = (int) $m[1];
            $byType[$row->email_type][$n] ??= ['shown' => 0, 'emails_clicked' => 0, 'clicks' => 0];
            $byType[$row->email_type][$n]['emails_clicked'] += (int) $row->emails_clicked;
            $byType[$row->email_type][$n]['clicks'] += (int) $row->clicks;
        }

        $rows = [];
        foreach ($byType as $type => $positions) {
            foreach ($positions as $n => $counts) {
                $rows[] = ['day' => $date, 'email_type' => $type, 'position' => $n] + $counts;
            }
        }
        $written = count($rows);

        $rows[] = ['day' => $date, 'email_type' => self::MARKER_TYPE, 'position' => 0,
            'shown' => 0, 'emails_clicked' => 0, 'clicks' => 0];

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('email_digest_positions_daily')
                ->upsert($chunk, ['day', 'email_type', 'position'], ['shown', 'emails_clicked', 'clicks']);
        }

        return $written;
    }
}

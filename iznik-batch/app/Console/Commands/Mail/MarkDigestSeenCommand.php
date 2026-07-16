<?php

namespace App\Console\Commands\Mail;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Turn "the member opened/clicked a digest" into a per-(member, post) "seen" signal.
 *
 * A digest mail records the msgids it showed in email_tracking.metadata.post_msgids
 * (see UnifiedDigest). When email_tracking marks that mail opened or clicked, the
 * member has HAD A CHANCE to see every post it contained - so we write a
 * messages_likes 'View' row for each (msgid, userid). That is the same signal the
 * browse feed already sorts unseen-first on (message/bounds.go), and that the daily
 * digest now down-ranks on (UnifiedDigestService::getPostsForUser seen_by_user) - so
 * both surfaces stop re-showing posts the member has already seen.
 *
 * We deliberately key off open/click, not send: sending a digest doesn't mean it was
 * read, and we must never sink a post for someone who never had a chance to see it.
 * insertOrIgnore makes re-processing the overlapping look-back window harmless.
 */
class MarkDigestSeenCommand extends Command
{
    protected $signature = 'mail:digest:mark-seen
        {--hours=3 : Look back this many hours over digest opens/clicks (overlap the schedule for safety)}
        {--limit=20000 : Max digest emails to process in one run}';

    protected $description = 'Mark digest posts as seen for members who opened/clicked the digest that contained them';

    /** Digest email types whose metadata carries post_msgids. */
    private const DIGEST_TYPES = ['UnifiedDigestImmediate', 'UnifiedDigestDaily'];

    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));
        $limit = (int) $this->option('limit');

        // Two index-driven reads rather than one `opened_at OR clicked_at` scan:
        // email_tracking.opened_at is indexed but clicked_at is NOT, so an OR across
        // them can't use the opened_at index and degrades to a backward PRIMARY(id)
        // scan of the whole (multi-million row) table. Opens come from the opened_at
        // range; clicks come via email_tracking_clicks (its clicked_at IS indexed).
        $opens = DB::table('email_tracking')
            ->whereIn('email_type', self::DIGEST_TYPES)
            ->whereNotNull('userid')
            ->whereNotNull('metadata')
            ->where('opened_at', '>=', $since)
            ->limit($limit)
            ->get(['userid', 'metadata']);

        $clicks = DB::table('email_tracking_clicks as etc')
            ->join('email_tracking as et', 'et.id', '=', 'etc.email_tracking_id')
            ->whereIn('et.email_type', self::DIGEST_TYPES)
            ->whereNotNull('et.userid')
            ->whereNotNull('et.metadata')
            ->where('etc.clicked_at', '>=', $since)
            ->limit($limit)
            ->get(['et.userid as userid', 'et.metadata as metadata']);

        // A digest in both sets is harmless - insertOrIgnore dedupes the markers.
        $rows = $opens->concat($clicks);

        $emails = 0;
        $marked = 0;

        foreach ($rows as $row) {
            $meta = json_decode((string) $row->metadata, true);
            $msgids = is_array($meta) ? ($meta['post_msgids'] ?? []) : [];
            if (empty($msgids)) {
                continue;
            }
            $emails++;

            $userid = (int) $row->userid;
            foreach (array_chunk($msgids, 500) as $chunk) {
                $insert = [];
                foreach ($chunk as $mid) {
                    $mid = (int) $mid;
                    if ($mid > 0) {
                        // count=0: existence marks "seen" (browse + digest read the ROW,
                        // not the count) without inflating the global 'View' engagement
                        // sum used for scoring. A later real in-app view still upserts its
                        // own count via the app's own path.
                        $insert[] = ['msgid' => $mid, 'userid' => $userid, 'type' => 'View', 'count' => 0];
                    }
                }
                if ($insert) {
                    $marked += DB::table('messages_likes')->insertOrIgnore($insert);
                }
            }
        }

        $this->info("mail:digest:mark-seen: {$emails} opened/clicked digests, {$marked} new seen markers.");

        return self::SUCCESS;
    }
}

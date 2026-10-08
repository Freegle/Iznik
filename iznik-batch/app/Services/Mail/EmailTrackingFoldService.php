<?php

namespace App\Services\Mail;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies the email tracking journal to email_tracking and email_tracking_images.
 *
 * WHY THERE IS A JOURNAL. The Go delivery handlers used to write every image load straight away:
 * an INSERT into email_tracking_images and an UPDATE of the parent email_tracking row (opened_at,
 * scroll_depth_percent). The images of one email load together, so those writes queued on the
 * parent row's lock - 2.2 million inserts and 1.8 million updates a day, 40.7% of db3's statement
 * time and almost all of it lock wait. Now the handlers append to email_tracking_journal (a bare
 * append-only table, one multi-row INSERT about a second) and this service folds it in overnight.
 *
 * WHAT TOLERATES THE DELAY. Nothing reads tracking the minute it happens: the ModTools email
 * stats and time series, the per-member email list, the re-engagement and community-news
 * reports, the user data dump and the delivery-health check are all daily or slower, and
 * scroll_depth_percent has no reader at all. The two that were more sensitive are handled here:
 * the hourly digest "seen" marker (mail:digest:mark-seen only looks at the last few hours of
 * opened_at, so the fold command drives it for the opens it applies) and the delivery-health check
 * (it stops its window at the oldest unfolded journal row, see DeliveryHealthService).
 *
 * THE RULES IT KEEPS, the ones the handlers kept inline:
 *  - opened_at / opened_via are the FIRST open. A journal event earlier than a stored opened_at
 *    (a click stamped it first, the image load was still waiting here) moves it back.
 *  - scroll_depth_percent is the MAXIMUM ever seen and never decreases.
 *  - one email_tracking_images row per image load.
 *
 * SAFE TO RE-RUN. Each chunk (updates, image rows, journal delete) is one transaction, so a crash
 * leaves a chunk either entirely applied and gone from the journal, or untouched. The updates are
 * conditional in SQL, so they are also harmless if something else wrote the row meanwhile. Events
 * with an id above the ceiling taken at the start are left for the next run, so the fold never
 * chases the live tail.
 */
class EmailTrackingFoldService
{
    public const DEFAULT_CHUNK = 5000;

    /** Journal kinds, as written by iznik-server-go/emailtracking/journal.go. */
    public const KIND_IMAGE = 1;

    public const KIND_PIXEL = 2;

    /** How many image rows to insert per statement. */
    private const IMAGE_INSERT_BATCH = 1000;

    /** @var array<string, array{id:int, opened_at:?string, scroll:?int}|null> ref => resolved row */
    private array $resolved = [];

    /**
     * Fold the journal into the tracking tables.
     *
     * @param int $chunkSize Journal rows per transaction.
     * @param int|null $ceilingId Fold only rows up to this id (default: the highest id now).
     * @return array{events:int, chunks:int, opened:int, scroll_updates:int, images:int, unresolved:int, oldest_event:?string}
     */
    public function fold(int $chunkSize = self::DEFAULT_CHUNK, ?int $ceilingId = null): array
    {
        $stats = [
            'events' => 0,
            'chunks' => 0,
            'opened' => 0,
            'scroll_updates' => 0,
            'images' => 0,
            'unresolved' => 0,
            'oldest_event' => null,
        ];

        $ceiling = $ceilingId ?? (int) DB::table('email_tracking_journal')->max('id');
        $last = 0;
        $this->resolved = [];

        while ($last < $ceiling) {
            $rows = DB::table('email_tracking_journal')
                ->where('id', '>', $last)
                ->where('id', '<=', $ceiling)
                ->orderBy('id')
                ->limit($chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            $high = (int) $rows->last()->id;

            DB::transaction(function () use ($rows, $last, $high, &$stats) {
                $this->applyChunk($rows, $stats);
                DB::table('email_tracking_journal')->where('id', '>', $last)->where('id', '<=', $high)->delete();
            }, 3);

            $stats['events'] += $rows->count();
            $stats['chunks']++;

            $first = (string) $rows->min('loaded_at');
            if ($stats['oldest_event'] === null || $first < $stats['oldest_event']) {
                $stats['oldest_event'] = $first;
            }

            $last = $high;
        }

        $this->resolved = [];

        return $stats;
    }

    /**
     * @param \Illuminate\Support\Collection<int, object> $rows
     */
    private function applyChunk($rows, array &$stats): void
    {
        $imageRows = [];

        foreach ($rows->groupBy('ref') as $ref => $events) {
            $ref = (string) $ref;
            $row = $this->resolve($ref);

            if ($row === null) {
                $stats['unresolved'] += $events->count();

                continue;
            }

            $id = $row['id'];

            // Earliest event decides opened_at and opened_via; ties go to the lower journal id.
            $first = $events->sortBy([['loaded_at', 'asc'], ['id', 'asc']])->first();
            $maxScroll = $events->max('scroll');

            $firstAt = (string) $first->loaded_at;
            $via = ((int) $first->kind === self::KIND_PIXEL) ? 'pixel' : 'image';

            $moveOpen = $row['opened_at'] === null || $firstAt < $row['opened_at'];
            $moveScroll = $maxScroll !== null && ($row['scroll'] === null || (int) $maxScroll > $row['scroll']);

            if ($moveOpen || $moveScroll) {
                $this->updateParent($id, $moveOpen ? $firstAt : null, $via, $moveScroll ? (int) $maxScroll : null);

                if ($moveOpen) {
                    $stats['opened']++;
                    $this->resolved[$ref]['opened_at'] = $firstAt;
                }
                if ($moveScroll) {
                    $stats['scroll_updates']++;
                    $this->resolved[$ref]['scroll'] = (int) $maxScroll;
                }
            }

            foreach ($events as $event) {
                if ((int) $event->kind !== self::KIND_IMAGE) {
                    continue;
                }

                $imageRows[] = [
                    'email_tracking_id' => $id,
                    'image_position' => $event->position ?? 'unknown',
                    'estimated_scroll_percent' => $event->scroll,
                    'loaded_at' => $event->loaded_at,
                ];
            }
        }

        foreach (array_chunk($imageRows, self::IMAGE_INSERT_BATCH) as $batch) {
            DB::table('email_tracking_images')->insert($batch);
        }

        $stats['images'] += count($imageRows);
    }

    /**
     * One UPDATE per email, conditional in SQL so it only ever moves the row the right way.
     */
    private function updateParent(int $id, ?string $openedAt, string $via, ?int $scroll): void
    {
        $set = [];
        $bind = [];

        if ($openedAt !== null) {
            // opened_via is assigned BEFORE opened_at: MySQL evaluates SET left to right and a
            // later assignment sees the earlier one's new value, so the other order would
            // compare against the opened_at it had just overwritten and never change the via.
            $set[] = 'opened_via = IF(opened_at IS NULL OR opened_at > ?, ?, opened_via)';
            array_push($bind, $openedAt, $via);
            $set[] = 'opened_at = IF(opened_at IS NULL OR opened_at > ?, ?, opened_at)';
            array_push($bind, $openedAt, $openedAt);
        }

        if ($scroll !== null) {
            $set[] = 'scroll_depth_percent = GREATEST(COALESCE(scroll_depth_percent, 0), ?)';
            $bind[] = $scroll;
        }

        $set[] = 'updated_at = NOW()';
        $bind[] = $id;

        // keep-raw: the SET clauses read the column's own current value (first open wins, deepest
        // scroll wins) so a re-run or a concurrent writer cannot move it the wrong way; the
        // builder's update() only assigns constants.
        DB::update('UPDATE email_tracking SET ' . implode(', ', $set) . ' WHERE id = ?', $bind);
    }

    /**
     * Find the email_tracking row a journal ref belongs to. A ref is the full 32-character
     * tracking id or the 12-character compact prefix, so it is a prefix match (the same lookup the
     * Go handlers made), cached for the run because one email's loads are spread over the journal.
     *
     * @return array{id:int, opened_at:?string, scroll:?int}|null
     */
    private function resolve(string $ref): ?array
    {
        if (array_key_exists($ref, $this->resolved)) {
            return $this->resolved[$ref];
        }

        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $ref) . '%';
        $row = DB::table('email_tracking')
            ->where('tracking_id', 'like', $like)
            ->orderBy('id')
            ->first(['id', 'opened_at', 'scroll_depth_percent']);

        return $this->resolved[$ref] = $row === null ? null : [
            'id' => (int) $row->id,
            'opened_at' => $row->opened_at === null ? null : Carbon::parse($row->opened_at)->toDateTimeString(),
            'scroll' => $row->scroll_depth_percent === null ? null : (int) $row->scroll_depth_percent,
        ];
    }

    /**
     * When the oldest journal row (the oldest event not yet folded) happened, or null if the
     * journal is empty. Ids are assigned in arrival order, so the lowest id is the oldest.
     */
    public static function oldestUnfolded(): ?Carbon
    {
        $at = DB::table('email_tracking_journal')->orderBy('id')->value('loaded_at');

        return $at === null ? null : Carbon::parse($at);
    }
}

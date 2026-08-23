<?php

namespace App\Console\Commands\Ripple;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ripple:shrink-overflow-bounds — rewrite stored overflow rings at a sane precision.
 *
 * rippling_reach.overflow_bounds holds the overflow rings as WKT TEXT inside JSON, and
 * on 2026-08-23 it was HALF the table: 860KB a row against a 47.7GB total. Most of that
 * is not information. PHP renders a float with 14 significant digits, so every vertex
 * was written like `-2.012234405899 52.537323913574` — a position to about a tenth of a
 * micrometre, for rings whose vertices all sit on an exact 0.0003 degree (~33 m) lattice
 * because they are traced from a raster. Nine orders of magnitude of noise, stored.
 *
 * ReachService::coord() now writes six decimals, so NEW rows are already small. This
 * command is only for the rows written before that. Measured on a production ring:
 * 236,275 bytes to 145,938, a 1.62x saving.
 *
 * WHAT THIS IS NOT. It does not simplify the rings. Collapsing the staircase would save
 * far more (about 4.7x rather than 1.6x) but moves the boundary by up to half a cell,
 * and that is a decision about who a post reaches, not about encoding. This command
 * moves no vertex more than ~4cm and drops none, so the admitted set cannot change.
 *
 * WHY IT IS SAFE TO REWRITE THE COLUMN AT ALL:
 *  - overflow_bounds is not spatially indexed, so this avoids the trap of rewriting two
 *    spatial-indexed columns in one statement.
 *  - updated_at is held still with a self-assignment. A bulk reach backfill once
 *    generated 38k+ notification emails in a morning by bumping it; the reach mailer and
 *    the spatial delta poll both watch that column.
 *  - has_overflow is GENERATED from `overflow_bounds IS NOT NULL`, and a rewrite never
 *    turns a ring into NULL, so the value and its index entry are unchanged.
 *  - every rewritten value is checked coordinate-for-coordinate before it is written,
 *    and the row is skipped if anything disagrees.
 *
 * Bounded and resumable in the same shape as the other Ripple backfills: prod writes go
 * one row at a time, and a run is meant to be repeated until it reports nothing left.
 */
class ShrinkOverflowBoundsCommand extends Command
{
    protected $signature = 'ripple:shrink-overflow-bounds
                            {--limit=500 : Max rows to rewrite this run}
                            {--after= : Start after this msgid instead of the stored mark}
                            {--reset-mark : Start from the beginning again}
                            {--min-saving=1024 : Skip a row unless it sheds at least this many bytes}
                            {--dry-run : Report what would be written without writing}';

    protected $description = 'Rewrite rippling_reach.overflow_bounds ring WKT at 6dp instead of 14 significant digits';

    /** Where the sweep got to, so a bounded run can be repeated until done. */
    private const CONFIG_KEY_MARK = 'ripple_shrink_overflow_bounds_last_msgid';

    /** Must match ReachService::WKT_DECIMALS — the point is that the two agree. */
    private const WKT_DECIMALS = 6;

    /**
     * A vertex may not move further than this, in degrees. Six decimals rounds by at
     * most 5e-7; anything beyond that means the rewrite did something other than round
     * and the row is left exactly as it was.
     */
    private const MAX_SHIFT_DEG = 0.000001;

    /** One pattern for both the rewrite and its verification, so they cannot drift. */
    private const NUMBER_RE = '/-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($this->option('reset-mark')) {
            $this->saveMark(0);
            $this->info('Mark reset.');
        }

        $after = $this->option('after') !== null ? (int) $this->option('after') : $this->mark();
        $limit = max(1, (int) $this->option('limit'));
        $minSaving = max(0, (int) $this->option('min-saving'));

        // has_overflow is indexed, so this finds the rows that carry a ring without
        // reading the ones that do not.
        $rows = DB::table('rippling_reach')
            ->select('msgid', 'overflow_bounds')
            ->where('has_overflow', 1)
            ->where('msgid', '>', $after)
            ->orderBy('msgid')
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->info($after > 0
                ? "Nothing left after msgid {$after}. Sweep complete."
                : 'No rows carry overflow rings.');

            return self::SUCCESS;
        }

        $rewritten = 0;
        $skipped = 0;
        $refused = 0;
        $bytesBefore = 0;
        $bytesAfter = 0;
        $lastMsgid = $after;

        foreach ($rows as $row) {
            $lastMsgid = (int) $row->msgid;

            $original = $row->overflow_bounds;
            if (! is_string($original) || $original === '') {
                $skipped++;

                continue;
            }

            $shrunk = $this->shrink($original);

            if ($shrunk === null) {
                // Say so per row: a silent skip here would look exactly like a row that
                // simply had nothing to save.
                $this->warn("msgid {$row->msgid}: refused (rewrite did not verify) — left unchanged");
                $refused++;

                continue;
            }

            $saving = strlen($original) - strlen($shrunk);
            if ($saving < $minSaving) {
                $skipped++;

                continue;
            }

            $bytesBefore += strlen($original);
            $bytesAfter += strlen($shrunk);

            if (! $dryRun) {
                // keep-raw: updated_at = updated_at suppresses the ON UPDATE CURRENT_TIMESTAMP
                // bump, which the builder cannot express; the reach mailer watches that column.
                DB::update(
                    'UPDATE rippling_reach SET overflow_bounds = ?, updated_at = updated_at WHERE msgid = ?',
                    [$shrunk, $row->msgid]
                );
            }

            $rewritten++;
        }

        // Only advance the stored mark for a real sweep. A --after run is someone
        // looking at a specific range and must not move everyone else's place.
        if (! $dryRun && $this->option('after') === null) {
            $this->saveMark($lastMsgid);
        }

        $this->info(sprintf(
            '%s %d row(s), skipped %d, refused %d. %s -> %s (saved %s%s). Mark now %d.',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $rewritten,
            $skipped,
            $refused,
            $this->bytes($bytesBefore),
            $this->bytes($bytesAfter),
            $this->bytes($bytesBefore - $bytesAfter),
            $bytesAfter > 0 ? sprintf(', %.2fx', $bytesBefore / $bytesAfter) : '',
            $lastMsgid
        ));

        if ($refused > 0) {
            $this->warn("{$refused} row(s) refused verification and were left untouched — investigate before re-running.");
        }

        return self::SUCCESS;
    }

    /**
     * Round every coordinate in the stored JSON to WKT_DECIMALS, or return null if the
     * result cannot be shown to be equivalent to what was there.
     *
     * Works on the decoded structure rather than by regex over the raw JSON, so it can
     * only ever touch coordinates inside a ring string and never a key, the bbox, or the
     * fairness_budget_min float that also lives in this column.
     */
    public function shrink(string $json): ?string
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            return null;
        }

        $changed = false;

        foreach ($data as $lane => $rings) {
            // Lanes are `rural` / `fairness` / `cluster`, each an object of band => WKT.
            // Anything else in here (bbox, fairness_budget_min) is left exactly alone.
            if (! is_array($rings)) {
                continue;
            }

            foreach ($rings as $band => $wkt) {
                if (! is_string($wkt) || ! str_starts_with($wkt, 'POLYGON')) {
                    continue;
                }

                $rounded = $this->roundWkt($wkt);
                if ($rounded === null) {
                    return null;
                }

                if ($rounded !== $wkt) {
                    $data[$lane][$band] = $rounded;
                    $changed = true;
                }
            }
        }

        if (! $changed) {
            return $json;
        }

        $out = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($out) ? $out : null;
    }

    /**
     * Round the coordinates in one WKT polygon, checking as we go.
     *
     * The checking is the point of this method: the rewritten ring must have the same
     * numbers in the same order, each within MAX_SHIFT_DEG of where it was, and the
     * same structural characters around them. If any of that fails we return null and
     * the caller leaves the row alone — a ring that has quietly changed shape is worse
     * than a large one.
     */
    private function roundWkt(string $wkt): ?string
    {
        $originals = [];

        $rounded = preg_replace_callback(
            self::NUMBER_RE,
            function (array $m) use (&$originals): string {
                $v = (float) $m[0];
                $originals[] = $v;

                return $this->coord($v);
            },
            $wkt
        );

        if (! is_string($rounded)) {
            return null;
        }

        preg_match_all(self::NUMBER_RE, $rounded, $after);
        if (count($after[0]) !== count($originals)) {
            return null;
        }

        foreach ($after[0] as $i => $s) {
            if (abs(((float) $s) - $originals[$i]) > self::MAX_SHIFT_DEG) {
                return null;
            }
        }

        // Everything that is not a number must survive untouched, or we have mangled
        // the WKT into something that happens to still contain the right values.
        if (preg_replace(self::NUMBER_RE, '#', $wkt) !== preg_replace(self::NUMBER_RE, '#', $rounded)) {
            return null;
        }

        return $rounded;
    }

    /** Mirrors ReachService::coord so a backfilled row is byte-identical to a fresh one. */
    private function coord(float $v): string
    {
        $s = number_format($v, self::WKT_DECIMALS, '.', '');

        if (str_contains($s, '.')) {
            $s = rtrim(rtrim($s, '0'), '.');
        }

        return $s === '-0' ? '0' : $s;
    }

    private function bytes(int $n): string
    {
        if (abs($n) < 1024) {
            return $n.'B';
        }
        if (abs($n) < 1024 * 1024) {
            return round($n / 1024, 1).'KB';
        }

        return round($n / 1024 / 1024, 1).'MB';
    }

    private function mark(): int
    {
        $row = DB::table('config')->where('key', self::CONFIG_KEY_MARK)->first();

        return $row && $row->value !== null && $row->value !== '' ? (int) $row->value : 0;
    }

    private function saveMark(int $msgid): void
    {
        DB::table('config')->updateOrInsert(
            ['key' => self::CONFIG_KEY_MARK],
            ['value' => (string) $msgid]
        );
    }
}

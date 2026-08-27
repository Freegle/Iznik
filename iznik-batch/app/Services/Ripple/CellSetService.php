<?php

namespace App\Services\Ripple;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The compact cell-set representation of a rippling reach area
 * (plans/2026-08-24-rippling-reach-raster-storage.md), stacked on the
 * content-hash dedup change (plans/2026-08-23-rippling-reach-polygon-
 * dedup.md): where that change stopped duplicating a stored geometry, this
 * stops storing one at all for readers that only need "is this point
 * inside" - a bitmap over the routing server's own 0.0003-degree lattice
 * instead of an ~11k-vertex WKT tracing of it.
 *
 * ENCODING is centralised in ONE place: the spatial server's
 * POST /v1/reach/rasterize (iznik-spatial-go's cellset.FromPolygonWKT).
 * This class calls out to it rather than embedding a second, PHP
 * implementation of the rasteriser. Two
 * independently-written rasterisers could disagree at a boundary cell in
 * ways nothing would ever catch; one writer cannot disagree with itself.
 *
 * DECODING is safe to have in both languages: a decoder only parses a
 * fixed, versioned, self-describing format - there is no ambiguity to
 * introduce, unlike encoding a polygon's boundary. decode()/contains() here
 * are a faithful PHP port of iznik-reach-cellset/cellset's Decode/Contains,
 * proven against real encoder output in CellSetServiceTest, so a reader on
 * the reply-gate hot path never pays a network round trip just to test one
 * point.
 *
 * FORMAT (v1), all little-endian - must stay byte-identical to
 * iznik-reach-cellset/cellset/cellset.go's doc comment:
 *   offset 0   magic  uint32 (0x31534343, "CCS1")
 *   offset 4   MinCol int32
 *   offset 8   MinRow int32
 *   offset 12  Cols   uint32
 *   offset 16  Rows   uint32
 *   offset 20  RLE varint run-lengths, alternating starting with a clear
 *              run (length 0 is valid), row-major, self-terminating.
 */
class CellSetService
{
    private const CELL_DEGREES = 0.0003;
    private const FORMAT_MAGIC = 0x31534343;
    private const HEADER_SIZE = 20;

    /**
     * Refuses a grid too large to be a real shape before building an array for
     * it. Cols and Rows are each unsigned 32-bit, so a corrupt value could ask
     * for 1.8e19 cells and the loop below would never finish; a decode failure
     * has a defined meaning to every caller (fall back to the polygon) where an
     * exhausted process does not.
     *
     * 2^28 cells is a square about 16,384 cells on a side - roughly 4.9 degrees,
     * some 540km, far beyond any drive-time reach or overflow ring the UK can
     * produce (the largest real reach measured was 90x54km, 4.4 million cells).
     * Must stay the SAME limit as iznik-spatial-go's cellset.MaxCells and the Go
     * API's cellSetMaxCells, or a value one language accepts is rejected by
     * another.
     */
    private const MAX_CELLS = 1 << 28;

    /**
     * Rasterise a polygon WKT into its canonical compact bytes, or null on
     * any failure - rasterising is best-effort exactly like every other
     * reach write path here (a routing hiccup must leave the row for the
     * next pass, never throw).
     */
    public function rasterize(string $wkt): ?string
    {
        try {
            $base = rtrim((string) config('freegle.spatial_server_url'), '/');
            $r = Http::timeout(5)
                ->withBody($wkt, 'text/plain')
                ->post($base . '/v1/reach/rasterize');

            if (!$r->successful()) {
                Log::warning('cellset: rasterize failed', ['status' => $r->status()]);

                return null;
            }

            // A 200 is not proof of a cell set. An empty or truncated body -
            // a misrouted request, a proxy's own 200, a server too old to know
            // this endpoint - would otherwise be STORED, and every later
            // reader would then decode-fail and fall back for the life of the
            // row while the column looked populated. Check the header here,
            // once, at the only place bytes enter the system.
            $body = $r->body();
            if (strlen($body) < self::HEADER_SIZE
                || unpack('V', substr($body, 0, 4))[1] !== self::FORMAT_MAGIC) {
                Log::warning('cellset: rasterize returned something that is not a cell set', [
                    'bytes' => strlen($body),
                ]);

                return null;
            }

            return $body;
        } catch (\Throwable $e) {
            Log::warning('cellset: rasterize request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Is (lng, lat) inside this cell set, WITHOUT decoding it?
     *
     * This is the shape every read path actually needs: one point, one answer.
     * It walks the run-length stream counting cells until it reaches the one
     * asked about, so it allocates nothing at all and touches only the runs
     * before the target - typically a few thousand tiny integers.
     *
     * Use this, not decode()+contains(), anywhere a single point is being
     * tested. Measured on a production-sized reach (4,334 x 1,634 = 7.1M
     * cells): decode() costs 317ms and 128MB because it materialises one PHP
     * array entry per set cell, which is far more than the SQL it replaces;
     * this costs neither. decode() remains the right tool for the clip path,
     * which genuinely needs the whole grid.
     *
     * Returns null when the bytes are unusable, so a caller can tell "not in
     * reach" from "cannot say" and fall back to the polygon rather than
     * deciding a reply's fate on a malformed blob.
     */
    public function containsEncoded(string $bytes, float $lng, float $lat): ?bool
    {
        try {
            $header = $this->header($bytes);
        } catch (\Throwable) {
            return null;
        }

        $col = (int) floor($lng / self::CELL_DEGREES) - $header['minCol'];
        $row = (int) floor($lat / self::CELL_DEGREES) - $header['minRow'];
        if ($col < 0 || $row < 0 || $col >= $header['cols'] || $row >= $header['rows']) {
            return false;
        }

        $target = $row * $header['cols'] + $col;
        $total = $header['cols'] * $header['rows'];
        $pos = self::HEADER_SIZE;
        $len = strlen($bytes);
        $cur = false;   // runs alternate, starting with a CLEAR run
        $seen = 0;

        try {
            while ($seen < $total) {
                [$run, $consumed] = $this->readVarint($bytes, $pos, $len);
                $pos += $consumed;
                $seen += $run;
                if ($target < $seen) {
                    return $cur;
                }
                $cur = !$cur;
            }
        } catch (\Throwable) {
            return null;
        }

        // The stream ended before reaching the target: a truncated value, which
        // is "cannot say", not "outside".
        return null;
    }

    /**
     * Header fields only, without walking the run stream.
     *
     * @return array{minCol:int,minRow:int,cols:int,rows:int}
     */
    private function header(string $bytes): array
    {
        if (strlen($bytes) < self::HEADER_SIZE) {
            throw new \InvalidArgumentException('cellset: input too short for a header');
        }

        // Unpacked as unsigned 32-bit little-endian throughout ('l' is PHP's
        // MACHINE-byte-order signed long - not guaranteed 32-bit or
        // little-endian - so MinCol/MinRow are read as unsigned via 'V' and
        // converted to signed by hand, which is exact and portable).
        $h = unpack('VmagicVal/VminColRaw/VminRowRaw/Vcols/Vrows', $bytes);
        if ($h === false || $h['magicVal'] !== self::FORMAT_MAGIC) {
            throw new \InvalidArgumentException('cellset: bad magic');
        }
        if ($h['cols'] === 0 || $h['rows'] === 0) {
            throw new \InvalidArgumentException('cellset: zero-sized grid');
        }
        if ($h['cols'] * $h['rows'] > self::MAX_CELLS) {
            throw new \InvalidArgumentException(
                'cellset: grid too large (' . $h['cols'] . 'x' . $h['rows'] . ' cells)'
            );
        }

        return [
            'minCol' => $this->toSigned32($h['minColRaw']),
            'minRow' => $this->toSigned32($h['minRowRaw']),
            'cols' => $h['cols'],
            'rows' => $h['rows'],
        ];
    }

    /**
     * Decode raw cell-set bytes into a queryable value: [minCol, minRow,
     * cols, rows, set] where `set` holds the SET cell indices (row*cols+col)
     * as array keys.
     *
     * This materialises one array entry per SET cell, so it is proportional to
     * the covered area, not to the compressed size: a production-sized reach
     * measured 317ms and 128MB. That is the right trade only for the clip
     * path, which needs the whole grid to subtract another from it. **For a
     * single point, use containsEncoded()**, which allocates nothing.
     *
     * Throws on malformed input - callers decide whether that means "not
     * populated yet" (a null value) or a real error.
     *
     * @return array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>}
     */
    public function decode(string $bytes): array
    {
        if (strlen($bytes) < self::HEADER_SIZE) {
            throw new \InvalidArgumentException('cellset: input too short for a header');
        }

        // Unpacked as unsigned 32-bit little-endian throughout ('l' is PHP's
        // MACHINE-byte-order signed long - not guaranteed 32-bit or
        // little-endian - so MinCol/MinRow are read as unsigned via 'V' and
        // converted to signed by hand, which is exact and portable).
        $header = unpack('VmagicVal/VminColRaw/VminRowRaw/Vcols/Vrows', $bytes);
        if ($header === false || $header['magicVal'] !== self::FORMAT_MAGIC) {
            throw new \InvalidArgumentException('cellset: bad magic');
        }
        $minCol = $this->toSigned32($header['minColRaw']);
        $minRow = $this->toSigned32($header['minRowRaw']);
        $cols = $header['cols'];
        $rows = $header['rows'];
        if ($cols === 0 || $rows === 0) {
            throw new \InvalidArgumentException('cellset: zero-sized grid');
        }
        if ($cols * $rows > self::MAX_CELLS) {
            throw new \InvalidArgumentException('cellset: grid too large (' . $cols . 'x' . $rows . ' cells)');
        }

        $total = $cols * $rows;
        $pos = self::HEADER_SIZE;
        $len = strlen($bytes);
        $cur = false;
        $i = 0;
        $set = [];

        while ($i < $total) {
            [$run, $consumed] = $this->readVarint($bytes, $pos, $len);
            $pos += $consumed;
            if ($cur) {
                for ($k = 0; $k < $run && $i < $total; $k++) {
                    $set[$i] = true;
                    $i++;
                }
            } else {
                $i += $run;
            }
            $cur = !$cur;
        }

        return [
            'minCol' => $minCol,
            'minRow' => $minRow,
            'cols' => $cols,
            'rows' => $rows,
            'set' => $set,
        ];
    }

    /** Two's-complement reinterpretation of an unsigned 32-bit value. */
    private function toSigned32(int $unsigned): int
    {
        return $unsigned >= 0x80000000 ? $unsigned - 0x100000000 : $unsigned;
    }

    /** @param array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>} $decoded */
    public function contains(array $decoded, float $lng, float $lat): bool
    {
        $col = (int) floor($lng / self::CELL_DEGREES) - $decoded['minCol'];
        $row = (int) floor($lat / self::CELL_DEGREES) - $decoded['minRow'];
        if ($col < 0 || $row < 0 || $col >= $decoded['cols'] || $row >= $decoded['rows']) {
            return false;
        }

        return isset($decoded['set'][$row * $decoded['cols'] + $col]);
    }

    /**
     * $a's cells minus $b's - the secondary-group rejection clip's cell-set
     * equivalent of ST_Difference(polygon, group_area). Both decoded values
     * share the SAME global lattice by construction, so this is set
     * subtraction over global cell indices: no resampling, no ambiguity,
     * safe to have in every language that reads a cell set (see the class
     * doc comment's decode/encode reasoning - this is grid arithmetic, not
     * rasterising a polygon boundary).
     *
     * The result keeps $a's own bounds (a superset of the true tight bbox
     * after clipping, never a wrong cell), matching
     * iznik-spatial-go/cellset's Subtract exactly. Callers that care whether
     * anything is left should check for an empty `set` array, the same way
     * they would check an ST_Difference result for emptiness.
     *
     * @param array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>} $a
     * @param array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>} $b
     * @return array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>}
     */
    public function subtract(array $a, array $b): array
    {
        $result = $a['set'];

        foreach (array_keys($a['set']) as $localIndex) {
            $row = intdiv($localIndex, $a['cols']);
            $col = $localIndex % $a['cols'];

            $globalCol = $a['minCol'] + $col;
            $globalRow = $a['minRow'] + $row;

            $bCol = $globalCol - $b['minCol'];
            $bRow = $globalRow - $b['minRow'];
            if ($bCol < 0 || $bRow < 0 || $bCol >= $b['cols'] || $bRow >= $b['rows']) {
                continue;
            }

            if (isset($b['set'][$bRow * $b['cols'] + $bCol])) {
                unset($result[$localIndex]);
            }
        }

        return [
            'minCol' => $a['minCol'],
            'minRow' => $a['minRow'],
            'cols' => $a['cols'],
            'rows' => $a['rows'],
            'set' => $result,
        ];
    }

    /**
     * Packs an already-decoded value back into wire bytes - as unambiguous
     * as decoding, unlike rasterising a polygon boundary (see the class doc
     * comment), so this is safe here even though rasterisation itself stays
     * server-side. Byte-identical format to the real encoder's Encode().
     *
     * @param array{minCol:int,minRow:int,cols:int,rows:int,set:array<int,bool>} $decoded
     */
    public function encode(array $decoded): string
    {
        $out = pack(
            'VVVVV',
            self::FORMAT_MAGIC,
            $decoded['minCol'] & 0xFFFFFFFF,
            $decoded['minRow'] & 0xFFFFFFFF,
            $decoded['cols'],
            $decoded['rows']
        );

        $total = $decoded['cols'] * $decoded['rows'];
        $cur = false;
        $run = 0;
        for ($i = 0; $i < $total; $i++) {
            $v = isset($decoded['set'][$i]);
            if ($v === $cur) {
                $run++;

                continue;
            }
            $out .= $this->encodeVarint($run);
            $cur = $v;
            $run = 1;
        }
        $out .= $this->encodeVarint($run);

        return $out;
    }

    /**
     * $a with $b's covered cells removed, working DIRECTLY on the run streams
     * - never decode()ing either grid.
     *
     * WHY THIS EXISTS: decode() allocates one PHP array entry per COVERED
     * CELL, so its memory follows the covered AREA, not the compressed size.
     * That is survivable for a reach, but the clip subtracts a REJECTING
     * GROUP's area, and a county-sized group rasterises to ~10M cells - about
     * a gigabyte of PHP arrays. ripple:expand died on exactly that six times
     * in three hours on 2026-08-26 (the first post-drop evening), each crash
     * also wedging its overlap lock, and expansion fell to ~175 advances per
     * half hour with 1,200+ posts overdue. This walk keeps memory
     * proportional to the RUN COUNT (the boundary), a few kilobytes for the
     * same inputs.
     *
     * The result keeps $a's exact frame: subtraction can only clear cells, so
     * $a's bounding box remains valid (possibly loose, exactly like
     * subtract()'s result, whose frame is also left untrimmed). A wholly
     * clipped grid comes back as one clear run - "admits nobody" - matching
     * the documented behaviour of the decode path.
     *
     * Null on unreadable input, mirroring the decode path's failure contract.
     *
     * B is indexed per GLOBAL row as [startCol, endCol) intervals - memory
     * O(B's runs) - then A's stream is re-emitted row by row with those
     * intervals cleared. Both grids sit on the same global lattice by
     * construction, which is what makes the row alignment a plain integer
     * offset.
     */
    public function subtractEncoded(string $a, string $b): ?string
    {
        try {
            $ha = $this->header($a);
            $hb = $this->header($b);
        } catch (\Throwable) {
            return null;
        }

        // Index B's SET runs as per-global-row column intervals.
        $bRows = [];
        $pos = self::HEADER_SIZE;
        $len = strlen($b);
        $total = $hb['cols'] * $hb['rows'];
        $seen = 0;
        $set = false; // runs alternate, starting CLEAR
        while ($seen < $total) {
            try {
                [$run, $n] = $this->readVarint($b, $pos, $len);
            } catch (\Throwable) {
                return null;
            }
            $pos += $n;
            if ($set && $run > 0) {
                // A set run may span row boundaries; split per row.
                $idx = $seen;
                $left = $run;
                while ($left > 0) {
                    $row = intdiv($idx, $hb['cols']);
                    $col = $idx % $hb['cols'];
                    $take = min($left, $hb['cols'] - $col);
                    $gRow = $hb['minRow'] + $row;
                    $bRows[$gRow][] = [$hb['minCol'] + $col, $hb['minCol'] + $col + $take];
                    $idx += $take;
                    $left -= $take;
                }
            }
            $seen += $run;
            $set = !$set;
        }
        if ($seen !== $total) {
            return null;
        }

        // Walk A row by row, clearing B's intervals, re-encoding as we go.
        $out = pack('VVVVV', self::FORMAT_MAGIC,
            $ha['minCol'] & 0xFFFFFFFF, $ha['minRow'] & 0xFFFFFFFF, $ha['cols'], $ha['rows']);
        $emitCur = false; // encoder state: current colour, always starts clear
        $emitRun = 0;
        $emit = function (bool $colour, int $count) use (&$emitCur, &$emitRun, &$out): void {
            if ($count === 0) {
                return;
            }
            if ($colour === $emitCur) {
                $emitRun += $count;

                return;
            }
            $out .= $this->encodeVarint($emitRun);
            $emitCur = $colour;
            $emitRun = $count;
        };

        $pos = self::HEADER_SIZE;
        $len = strlen($a);
        $total = $ha['cols'] * $ha['rows'];
        $seen = 0;
        $set = false;
        while ($seen < $total) {
            try {
                [$run, $n] = $this->readVarint($a, $pos, $len);
            } catch (\Throwable) {
                return null;
            }
            $pos += $n;
            if ($run > 0) {
                if (!$set) {
                    $emit(false, $run);
                } else {
                    // Split the set run per row and clear B's overlap.
                    $idx = $seen;
                    $left = $run;
                    while ($left > 0) {
                        $row = intdiv($idx, $ha['cols']);
                        $col = $idx % $ha['cols'];
                        $take = min($left, $ha['cols'] - $col);
                        $gRow = $ha['minRow'] + $row;
                        $gStart = $ha['minCol'] + $col;      // global column span
                        $gEnd = $gStart + $take;             // [gStart, gEnd)
                        $cursor = $gStart;
                        foreach ($bRows[$gRow] ?? [] as [$bs, $be]) {
                            if ($be <= $cursor || $bs >= $gEnd) {
                                continue;
                            }
                            if ($bs > $cursor) {
                                $emit(true, $bs - $cursor);
                                $cursor = $bs;
                            }
                            $clearTo = min($be, $gEnd);
                            $emit(false, $clearTo - $cursor);
                            $cursor = $clearTo;
                        }
                        if ($cursor < $gEnd) {
                            $emit(true, $gEnd - $cursor);
                        }
                        $idx += $take;
                        $left -= $take;
                    }
                }
            }
            $seen += $run;
            $set = !$set;
        }
        if ($seen !== $total) {
            return null;
        }
        $out .= $this->encodeVarint($emitRun); // flush the final run

        return $out;
    }

    private function encodeVarint(int $v): string
    {
        $out = '';
        while ($v >= 0x80) {
            $out .= chr(($v & 0x7f) | 0x80);
            $v >>= 7;
        }

        return $out . chr($v);
    }

    /**
     * One varint starting at $pos. Returns [value, bytesConsumed].
     *
     * @return array{0:int,1:int}
     */
    private function readVarint(string $bytes, int $pos, int $len): array
    {
        $value = 0;
        $shift = 0;
        $consumed = 0;
        while ($pos + $consumed < $len) {
            $byte = ord($bytes[$pos + $consumed]);
            $consumed++;
            $value |= ($byte & 0x7f) << $shift;
            if (($byte & 0x80) === 0) {
                return [$value, $consumed];
            }
            $shift += 7;
            if ($shift >= 64) {
                throw new \InvalidArgumentException('cellset: varint too long');
            }
        }

        throw new \InvalidArgumentException('cellset: truncated varint');
    }

    /**
     * Trace the covered area's boundary back to a vector, via the spatial
     * server (the ONE place that judgement lives, exactly like rasterize is
     * the one boundary-to-cells point). $toleranceDegrees 0 keeps the exact
     * lattice outline; positive values simplify for display. Returns
     * ['wkt' => ..., 'geojson' => ...] or null on any failure - callers keep
     * a vector-free fallback the same way rasterize's callers do.
     */
    public function vectorize(string $bytes, float $toleranceDegrees = 0): ?array
    {
        try {
            $base = rtrim((string) config('freegle.spatial_server_url'), '/');
            $url = $base . '/v1/reach/vectorize';
            if ($toleranceDegrees > 0) {
                $url .= '?tolerance=' . $toleranceDegrees;
            }
            $r = Http::timeout(10)
                ->withBody($bytes, 'application/octet-stream')
                ->post($url);
            if (!$r->successful()) {
                Log::warning('cellset: vectorize failed', ['status' => $r->status()]);

                return null;
            }
            $out = $r->json();
            if (!is_array($out) || !is_string($out['wkt'] ?? null) || $out['wkt'] === '') {
                Log::warning('cellset: vectorize returned no boundary');

                return null;
            }

            return ['wkt' => $out['wkt'], 'geojson' => $out['geojson'] ?? null];
        } catch (\Throwable $e) {
            Log::warning('cellset: vectorize request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Which groups' areas does this grid touch, and is it entirely inside any
     * of them - the cell form of the ST_Intersects/ST_Within pair the clip,
     * the retraction pass and the crosspost count ask. Answered by the
     * spatial server on the same lattice the reach itself uses. Returns a
     * list of ['id' => int, 'within' => bool], or null on any failure so the
     * caller can distinguish "touches no groups" from "could not ask".
     */
    public function groupsIntersecting(string $bytes): ?array
    {
        try {
            $base = rtrim((string) config('freegle.spatial_server_url'), '/');
            $r = Http::timeout(10)
                ->withBody($bytes, 'application/octet-stream')
                ->post($base . '/v1/groups/intersecting');
            if (!$r->successful()) {
                Log::warning('cellset: groups intersecting failed', ['status' => $r->status()]);

                return null;
            }
            $out = $r->json();
            if (!is_array($out) || !is_array($out['groups'] ?? null)) {
                return null;
            }
            $groups = [];
            foreach ($out['groups'] as $g) {
                if (is_array($g) && isset($g['id'])) {
                    $groups[] = ['id' => (int) $g['id'], 'within' => (bool) ($g['within'] ?? false)];
                }
            }

            return $groups;
        } catch (\Throwable $e) {
            Log::warning('cellset: groups intersecting request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The covered grid's bounding box as a POLYGON WKT, read from the header
     * alone (no run-stream walk) - the cell form of ST_Envelope(polygon), for
     * the bounds fallback ladder. Null on unusable bytes.
     */
    public function boundsWkt(string $bytes): ?string
    {
        try {
            $h = $this->header($bytes);
        } catch (\Throwable) {
            return null;
        }

        $minLng = $h['minCol'] * self::CELL_DEGREES;
        $minLat = $h['minRow'] * self::CELL_DEGREES;
        $maxLng = ($h['minCol'] + $h['cols']) * self::CELL_DEGREES;
        $maxLat = ($h['minRow'] + $h['rows']) * self::CELL_DEGREES;

        return sprintf(
            'POLYGON((%.10F %.10F,%.10F %.10F,%.10F %.10F,%.10F %.10F,%.10F %.10F))',
            $minLng, $minLat, $maxLng, $minLat, $maxLng, $maxLat, $minLng, $maxLat, $minLng, $minLat
        );
    }

    /**
     * Every live post whose committed reach covers this point, as msgids from
     * the spatial index - the same authority the feed, badge and search read,
     * asked the same way RingIndex asks the ring question. Returns null on
     * any failure (fail closed: the digest then mails nothing on the strength
     * of a reach nobody could check). `partial` ids - legacy coarse-raster
     * rows the index cannot decide exactly - are NOT included: treating an
     * undecided post as unreached holds it for a later digest rather than
     * mailing someone the site may turn away.
     *
     * @return array<int,int>|null
     */
    public function reachContaining(float $lat, float $lng): ?array
    {
        try {
            $base = rtrim((string) config('freegle.spatial_server_url'), '/');
            $r = Http::timeout(10)->get($base . '/v1/reach/containing', ['lng' => $lng, 'lat' => $lat]);
            if (!$r->successful()) {
                Log::warning('cellset: reach containing failed', ['status' => $r->status()]);

                return null;
            }
            $in = $r->json('in');
            if (!is_array($in)) {
                return null;
            }

            return array_map('intval', $in);
        } catch (\Throwable $e) {
            Log::warning('cellset: reach containing request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * The greatest great-circle metres from an origin to any covered cell -
     * the reach radius the digest score's 'close' term divides by, previously
     * a walk of the polygon's WKT vertices. Streaming over the run stream: a
     * covered run is a horizontal span of cells, and distance from a fixed
     * origin along a fixed row is maximised at one of the span's two ends, so
     * only run endpoints are ever measured. Allocates nothing. Null when the
     * bytes are unusable.
     */
    public function maxDistanceMetresFrom(string $bytes, float $olng, float $olat): ?float
    {
        $walk = $this->walkRuns($bytes, function (int $row, int $startCol, int $endCol, array $h, float &$best) use ($olng, $olat) {
            $lat = ($h['minRow'] + $row + 0.5) * self::CELL_DEGREES;
            foreach ([$startCol, $endCol] as $col) {
                $lng = ($h['minCol'] + $col + 0.5) * self::CELL_DEGREES;
                $d = $this->haversineMetres($olat, $olng, $lat, $lng);
                if ($d > $best) {
                    $best = $d;
                }
            }
        }, 0.0);

        return $walk;
    }

    /**
     * The least great-circle metres from a point to any covered cell: 0 when
     * the point's own cell is covered, otherwise the distance to the nearest
     * covered cell's centre - the cell form of ST_Distance(point, reach),
     * which RippleReplyService uses to say how far outside the reach a held
     * replier is. Exact at lattice resolution (~33m), well inside the miles
     * rounding it feeds. Streaming, like maxDistanceMetresFrom: within one
     * covered run the nearest cell to the point is at the clamped column, so
     * each run costs O(1). Null when the bytes are unusable.
     */
    public function distanceToNearestCellMetres(string $bytes, float $plng, float $plat): ?float
    {
        // Inside a covered cell = distance zero, matching ST_Distance for a
        // contained point (the walk below measures to cell CENTRES, so a
        // point near its own cell's edge would otherwise read ~20m). Asked
        // first: it is the common case on this path and far cheaper.
        $inside = $this->containsEncoded($bytes, $plng, $plat);
        if ($inside === null) {
            return null;
        }
        if ($inside) {
            return 0.0;
        }

        $pcol = (int) floor($plng / self::CELL_DEGREES);

        $walk = $this->walkRuns($bytes, function (int $row, int $startCol, int $endCol, array $h, float &$best) use ($pcol, $plng, $plat) {
            $col = max($h['minCol'] + $startCol, min($h['minCol'] + $endCol, $pcol));
            $lat = ($h['minRow'] + $row + 0.5) * self::CELL_DEGREES;
            $lng = ($col + 0.5) * self::CELL_DEGREES;
            $d = $this->haversineMetres($plat, $plng, $lat, $lng);
            if ($d < $best) {
                $best = $d;
            }
        }, INF);

        if ($walk === null || $walk === INF) {
            return null;
        }

        return $walk;
    }

    /**
     * Walk every covered run, calling $fn(rowLocal, startColLocal,
     * endColLocal, header, &$acc) per run. Returns the accumulator, or null
     * on unusable bytes. A run may span multiple rows in the raw stream;
     * this splits those so $fn always sees one row's span.
     */
    private function walkRuns(string $bytes, callable $fn, float $initial): ?float
    {
        try {
            $h = $this->header($bytes);
        } catch (\Throwable) {
            return null;
        }

        $total = $h['cols'] * $h['rows'];
        $pos = self::HEADER_SIZE;
        $len = strlen($bytes);
        $cur = false;
        $seen = 0;
        $acc = $initial;

        try {
            while ($seen < $total) {
                [$run, $consumed] = $this->readVarint($bytes, $pos, $len);
                $pos += $consumed;
                if ($cur && $run > 0) {
                    $start = $seen;
                    $end = $seen + $run - 1;
                    $row = intdiv($start, $h['cols']);
                    $lastRow = intdiv($end, $h['cols']);
                    while ($row <= $lastRow) {
                        $s = $row === intdiv($start, $h['cols']) ? $start % $h['cols'] : 0;
                        $e = $row === $lastRow ? $end % $h['cols'] : $h['cols'] - 1;
                        $fn($row, $s, $e, $h, $acc);
                        $row++;
                    }
                }
                $seen += $run;
                $cur = !$cur;
            }
        } catch (\Throwable) {
            return null;
        }

        if ($seen !== $total) {
            return null;
        }

        return $acc;
    }

    /** Great-circle metres between two lat/lng points. */
    private function haversineMetres(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }
}

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
 * implementation of the rasteriser - the same discipline
 * GeomShareService established for content-hash canonicalisation. Two
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

            return $r->body();
        } catch (\Throwable $e) {
            Log::warning('cellset: rasterize request failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Decode raw cell-set bytes into a queryable value: [minCol, minRow,
     * cols, rows, bits] where bits is a plain array of the SET cell indices
     * (row*cols+col) for O(1) lookup via array_key_exists - cheap enough at
     * these sizes (a few thousand set cells for a typical reach) that a
     * bitset packed into a PHP string is not worth the packing/unpacking
     * complexity here. Throws on malformed input - callers decide whether
     * that means "not populated yet" (a null value) or a real error.
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
}

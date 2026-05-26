<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class Job extends Model
{
    protected $table = 'jobs';
    protected $guarded = ['id'];
    public $timestamps = FALSE;

    protected $casts = [
        'posted_at' => 'datetime',
        'seenat' => 'datetime',
        'cpc' => 'decimal:4',
    ];

    /**
     * Minimum CPC (cost per click) to show a job ad.
     */
    public const MINIMUM_CPC = 0.02;

    /**
     * Candidate-pool multiplier used to break the "same N jobs forever"
     * effect for high-frequency recipients (immediate-mode digests, chat
     * notifications). We fetch this many times $limit candidates by CPC,
     * then randomly pick $limit — so the highest-CPC jobs still dominate
     * the pool but each send shows a slightly different mix.
     */
    public const VARIETY_POOL_MULTIPLIER = 3;

    /**
     * Query jobs near a location using bounding box search.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $limit Maximum number of jobs to return
     */
    public static function nearLocation(float $lat, float $lng, int $limit = 4): Collection
    {
        // Use same bounding box approach as iznik-server for efficient spatial index usage.
        // Expand the box iteratively until we find enough jobs. Fetch a
        // larger candidate pool so the random selection below has room to
        // vary the picks across consecutive sends.
        $step = 0.02;
        $ambit = $step;
        $srid = config('freegle.srid', 3857);

        $candidatePool = $limit * self::VARIETY_POOL_MULTIPLIER;

        $results = collect();
        $gotIds = [];

        while ($results->count() < $candidatePool && $ambit < 1) {
            $swlat = $lat - $ambit;
            $nelat = $lat + $ambit;
            $swlng = $lng - $ambit;
            $nelng = $lng + $ambit;

            $poly = "POLYGON(($swlng $swlat, $swlng $nelat, $nelng $nelat, $nelng $swlat, $swlng $swlat))";

            $jobs = static::select('id', 'title', 'canonical_title', 'location', 'company', 'city', 'url', 'cpc')
                ->whereRaw("ST_Within(geometry, ST_GeomFromText(?, ?))", [$poly, $srid])
                ->whereRaw("cpc >= ?", [self::MINIMUM_CPC])
                ->where('visible', 1)
                ->when(count($gotIds) > 0, function ($query) use ($gotIds) {
                    $query->whereNotIn('id', $gotIds);
                })
                ->orderByDesc('cpc')
                ->limit($candidatePool - $results->count())
                ->get();

            foreach ($jobs as $job) {
                $gotIds[] = $job->id;
                $results->push($job);
            }

            $ambit += $step;
        }

        // Randomize the candidate pool so consecutive immediate-mode digests
        // (or chat notifications) to the same user don't show identical job
        // rows every time. The pool is already CPC-biased, so the picks
        // remain weighted toward higher-CPC ads — we're just breaking the
        // exact top-N determinism.
        if ($results->count() > $limit) {
            $results = $results->shuffle();
        }

        // Add images to jobs using the pre-computed canonical_title column.
        // Falls back to a briefcase placeholder rendered in the same
        // AI-image house style (white line drawing on muted-green
        // background) so rows without a per-title illustration still look
        // consistent next to siblings that do have one.
        if ($results->isNotEmpty()) {
            $canonicalTitles = $results->pluck('canonical_title')->filter()->unique()->values()->toArray();
            $images = collect();

            if (count($canonicalTitles)) {
                $images = DB::table('ai_images')
                    ->whereIn('name', $canonicalTitles)
                    ->pluck('externaluid', 'name');
            }

            $placeholderUrl = config('freegle.images.email_assets') . '/briefcase.png';

            foreach ($results as $job) {
                $job->image_url = self::buildImageUrl($images[$job->canonical_title] ?? null) ?? $placeholderUrl;
            }
        }

        return $results->take($limit);
    }

    /**
     * Build image URL from external UID.
     */
    protected static function buildImageUrl(?string $externaluid): ?string
    {
        if (!$externaluid) {
            return null;
        }

        // Extract the file ID from the externaluid (format: freegletusd-{id})
        $p = strrpos($externaluid, 'freegletusd-');
        if ($p === false) {
            return null;
        }

        $fileId = substr($externaluid, $p + strlen('freegletusd-'));
        $tusUploader = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080');
        $deliveryUrl = config('freegle.delivery.base_url');

        // URL format: https://uploads.ilovefreegle.org:8080/{fileId} (no trailing slash)
        $sourceUrl = $tusUploader . '/' . $fileId;

        if ($deliveryUrl) {
            return $deliveryUrl . '?url=' . urlencode($sourceUrl) . '&w=50';
        }

        return $sourceUrl;
    }
}

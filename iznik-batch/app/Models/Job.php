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
     * Query jobs near a location using bounding box search.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $limit Maximum number of jobs to return
     */
    public static function nearLocation(float $lat, float $lng, int $limit = 4): Collection
    {
        // Use same bounding box approach as iznik-server for efficient spatial index usage.
        // Expand the box iteratively until we find enough jobs.
        $step = 0.02;
        $ambit = $step;
        $srid = config('freegle.srid', 3857);

        $results = collect();
        $gotIds = [];

        while ($results->count() < $limit && $ambit < 1) {
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
                ->limit($limit - $results->count())
                ->get();

            foreach ($jobs as $job) {
                $gotIds[] = $job->id;
                $results->push($job);
            }

            $ambit += $step;
        }

        // Add images to jobs using the pre-computed canonical_title column.
        // Only set image_url when there is an actual AI-generated image —
        // the email templates already skip the image cell when image_url is
        // null (`@if($job->image_url ?? null)`), which renders a clean
        // text-only row rather than a low-res briefcase placeholder that
        // looked blocky inline.
        if ($results->isNotEmpty()) {
            $canonicalTitles = $results->pluck('canonical_title')->filter()->unique()->values()->toArray();
            $images = collect();

            if (count($canonicalTitles)) {
                $images = DB::table('ai_images')
                    ->whereIn('name', $canonicalTitles)
                    ->pluck('externaluid', 'name');
            }

            foreach ($results as $job) {
                $job->image_url = self::buildImageUrl($images[$job->canonical_title] ?? null);
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

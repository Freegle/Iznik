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
     * Minimum CPC (cost per click) to show a job ad. Matches the spatial
     * server's "jobs" dataset floor and the public jobs page, so digest job
     * ads are drawn from the same eligible pool as everything else.
     */
    public const MINIMUM_CPC = 0.10;

    /**
     * Candidate-pool multiplier used to break the "same N jobs forever"
     * effect for high-frequency recipients (immediate-mode digests, chat
     * notifications). We fetch this many times $limit candidates by CPC,
     * then randomly pick $limit — so the highest-CPC jobs still dominate
     * the pool but each send shows a slightly different mix.
     */
    public const VARIETY_POOL_MULTIPLIER = 3;

    /**
     * Query jobs near a location via the spatial server's "jobs" KNN dataset.
     *
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $limit Maximum number of jobs to return
     */
    public static function nearLocation(float $lat, float $lng, int $limit = 4): Collection
    {
        // Fetch a larger candidate pool so the random selection below has room
        // to vary the picks across consecutive sends.
        $candidatePool = $limit * self::VARIETY_POOL_MULTIPLIER;

        // The spatial server's "jobs" dataset is the source of truth for which
        // jobs are eligible (visible, cpc >= floor) and where they are: ask it
        // for the nearest candidate ids, then enrich + re-check from MySQL.
        $ids = (new \App\Services\SpatialQueryService())->nearestIds('jobs', $lat, $lng, $candidatePool);
        if (empty($ids)) {
            return collect();
        }

        $results = static::select('id', 'title', 'canonical_title', 'location', 'company', 'city', 'url', 'cpc', 'clickability')
            ->whereIn('id', $ids)
            ->whereRaw('cpc >= ?', [self::MINIMUM_CPC])
            ->where('visible', 1)
            // Rank by expected value (cpc * clickability) discounted by a mild
            // freshness factor, matching the public jobs page (Go job.GetJobs)
            // so digest and web agree on ordering. Older WhatJobs postings are
            // likelier already filled/closed (so a click redirects to a
            // different job and doesn't convert), so decay the score with the
            // posting age: factor 1.0 when fresh, floored at 0.5 by ~7 days.
            // posted_at NULL -> treated as fresh (no penalty).
            ->orderByRaw('cpc * clickability * GREATEST(0.5, 1 - COALESCE(DATEDIFF(NOW(), posted_at), 0) * 0.07) DESC, id ASC')
            ->get();

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

                // The jobs feed stores location names lowercase ("manchester",
                // "stoke-on-trent"). Render them title-cased so they read
                // naturally in email bodies. Split on space, hyphen and
                // apostrophe so "stoke-on-trent" becomes "Stoke-On-Trent"
                // and "o'connell street" becomes "O'Connell Street".
                if (!empty($job->location)) {
                    $job->location = ucwords(strtolower($job->location), " -'");
                }
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

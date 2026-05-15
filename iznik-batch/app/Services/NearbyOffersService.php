<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NearbyOffersService
{
    /**
     * Default search radius in kilometers.
     */
    private const DEFAULT_RADIUS_KM = 10;

    /**
     * Maximum search radius in kilometers.
     */
    private const MAX_RADIUS_KM = 50;

    /**
     * Number of offers to fetch.
     */
    private const OFFER_LIMIT = 6;

    /**
     * Get nearby offers with attachments for a user.
     * Falls back to random offers if no location is set.
     */
    public function getNearbyOffers(User $user, int $limit = self::OFFER_LIMIT): Collection
    {
        $location = $user->lastLocation;

        if (!$location || !$location->lat || !$location->lng) {
            // No location - return random recent offers instead.
            return $this->getRandomOffers($limit);
        }

        return $this->getOffersNearLocation(
            (float) $location->lat,
            (float) $location->lng,
            $limit
        );
    }

    /**
     * Get random recent offers with attachments.
     * Used as fallback when user location is not known.
     */
    public function getRandomOffers(int $limit = self::OFFER_LIMIT): Collection
    {
        $offers = Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->recent(7)
            ->whereHas('attachments')
            ->with(['attachments' => function ($query) {
                $query->orderByDesc('primary')->limit(1);
            }])
            ->inRandomOrder()
            ->limit($limit)
            ->get();

        return $offers->map(fn ($o) => $this->formatOffer($o));
    }

    /**
     * Get offers near a specific lat/lng.
     * Uses the spatial server KNN endpoint when available; falls back to bounding-box query.
     */
    public function getOffersNearLocation(
        float $lat,
        float $lng,
        int $limit = self::OFFER_LIMIT,
        int $radiusKm = self::DEFAULT_RADIUS_KM
    ): Collection {
        $nearbyIds = $this->nearbyMessageIds($lat, $lng, $limit * 5);

        if ($nearbyIds !== null) {
            // Spatial server returned IDs — filter to approved offers with attachments.
            $offers = Message::query()
                ->whereIn('id', $nearbyIds)
                ->offers()
                ->approved()
                ->notDeleted()
                ->recent(7)
                ->whereHas('attachments')
                ->with(['attachments' => function ($query) {
                    $query->orderByDesc('primary')->limit(1);
                }])
                ->orderByDesc('arrival')
                ->limit($limit)
                ->get();

            if ($offers->count() >= $limit) {
                return $offers->map(fn ($o) => $this->formatOffer($o));
            }
            // Fewer than limit after DB filtering (e.g. all pending/deleted) — fall through to legacy.
        }

        // Legacy fallback: expanding bounding-box on messages table.
        return $this->getOffersNearLocationFallback($lat, $lng, $limit, $radiusKm);
    }

    /**
     * Query the spatial server for the nearest message IDs.
     * Returns null if the server is unavailable.
     */
    private function nearbyMessageIds(float $lat, float $lng, int $limit): ?array
    {
        $spatialUrl = config('freegle.spatial_server_url', 'http://localhost:8194');

        try {
            $response = Http::timeout(3)->get("{$spatialUrl}/v1/messages/knn", [
                'lat'   => $lat,
                'lng'   => $lng,
                'limit' => $limit,
            ]);

            if ($response->successful()) {
                return array_column($response->json('results', []), 'id');
            }
        } catch (\Throwable $e) {
            Log::warning('NearbyOffersService spatial server unavailable, falling back to MySQL', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function getOffersNearLocationFallback(
        float $lat,
        float $lng,
        int $limit,
        int $radiusKm
    ): Collection {
        $radiusDegrees = $radiusKm / 111.0;

        $offers = Message::query()
            ->offers()
            ->approved()
            ->notDeleted()
            ->withLocation()
            ->recent(7)
            ->whereHas('attachments')
            ->whereBetween('lat', [$lat - $radiusDegrees, $lat + $radiusDegrees])
            ->whereBetween('lng', [$lng - $radiusDegrees, $lng + $radiusDegrees])
            ->with(['attachments' => function ($query) {
                $query->orderByDesc('primary')->limit(1);
            }])
            ->orderByDesc('arrival')
            ->limit($limit)
            ->get();

        if ($offers->count() < $limit && $radiusKm < self::MAX_RADIUS_KM) {
            return $this->getOffersNearLocationFallback($lat, $lng, $limit, $radiusKm * 2);
        }

        return $offers->map(fn ($o) => $this->formatOffer($o));
    }

    private function formatOffer(Message $offer): array
    {
        return [
            'id'            => $offer->id,
            'subject'       => $this->truncateSubject($offer->subject),
            'thumbnail_url' => $this->getThumbnailUrl($offer),
            'url'           => $this->getOfferUrl($offer),
        ];
    }

    /**
     * Truncate subject to fit in email thumbnail.
     */
    private function truncateSubject(?string $subject): string
    {
        if (!$subject) {
            return 'Item';
        }

        // Remove common prefixes like "OFFER:" or "Offer:".
        $subject = preg_replace('/^(OFFER|Offer|WANTED|Wanted):\s*/i', '', $subject);

        return strlen($subject) > 20 ? substr($subject, 0, 17) . '...' : $subject;
    }

    /**
     * Get thumbnail URL for an offer.
     */
    private function getThumbnailUrl(Message $offer): ?string
    {
        $attachment = $offer->attachments->first();

        if (!$attachment) {
            return NULL;
        }

        // Image URLs are served from the main site API.
        $baseUrl = config('freegle.sites.user');

        return "{$baseUrl}/api/image/{$attachment->id}?w=120&h=120";
    }

    /**
     * Get URL to view the offer.
     */
    private function getOfferUrl(Message $offer): string
    {
        $baseUrl = config('freegle.sites.user');

        return "{$baseUrl}/message/{$offer->id}";
    }
}

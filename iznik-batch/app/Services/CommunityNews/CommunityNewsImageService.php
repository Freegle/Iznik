<?php

namespace App\Services\CommunityNews;

use App\Models\CommunityNewsItem;
use App\Services\NewsfeedLinkPreviewService;
use App\Services\TusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Images for Community News items, shared by the ChitChat and email channels.
 *
 * We don't ask the research model for images (it can't verify them); instead we
 * take the source page's own og:image via the existing link-preview fetcher
 * (which caches in link_previews), download it, and re-host it through our own
 * image pipeline (TUS upload → delivery cache). That keeps emails free of
 * third-party hotlinks and gives the newsfeed a proper newsfeed_images row.
 *
 * Everything here is best-effort: a missing/slow/broken image never blocks a
 * post or an email, it just means no picture.
 */
class CommunityNewsImageService
{
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private NewsfeedLinkPreviewService $previews,
        private TusService $tus,
    ) {
    }

    /**
     * The og:image of the item's source page, re-hosted via TUS.
     *
     * @return array{externaluid:string, contenttype:string}|null
     */
    public function uploadItemImage(CommunityNewsItem $item): ?array
    {
        $url = trim((string) $item->url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return null;
        }

        try {
            $this->previews->getOrCreate($url);
            $imageUrl = DB::table('link_previews')->where('url', $url)->value('image');
            if (!$imageUrl || !preg_match('#^https?://#i', $imageUrl)) {
                return null;
            }

            $resp = Http::timeout(15)->withHeaders(['User-Agent' => 'Freegle-CommunityNews'])->get($imageUrl);
            if (!$resp->successful()) {
                return null;
            }

            $mime = strtolower(explode(';', $resp->header('Content-Type') ?? '')[0]);
            $data = $resp->body();
            if (!str_starts_with($mime, 'image/') || $data === '' || strlen($data) > self::MAX_BYTES) {
                return null;
            }

            $uploaded = $this->tus->upload($data, $mime);
            if (!$uploaded) {
                return null;
            }

            return [
                'externaluid' => TusService::urlToExternalUid($uploaded),
                'contenttype' => $mime,
            ];
        } catch (\Throwable $e) {
            Log::info('CommunityNews image skipped', ['item' => $item->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Delivery-cache URL for an uploaded image (same scheme as Job's ads).
     */
    public function deliveryUrl(string $externaluid, int $width = 600): ?string
    {
        $p = strrpos($externaluid, 'freegletusd-');
        if ($p === false) {
            return null;
        }

        $fileId = substr($externaluid, $p + strlen('freegletusd-'));
        $source = config('freegle.tus_uploader', 'https://uploads.ilovefreegle.org:8080') . '/' . $fileId;
        $delivery = config('freegle.delivery.base_url');

        return $delivery ? $delivery . '?url=' . urlencode($source) . '&w=' . $width : $source;
    }
}

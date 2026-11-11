<?php

namespace App\Services\AzureArchive;

/**
 * URLs for an image V1 archived (archived = 1), the batch twin of the Go API's
 * misc.ArchivedImageUrls: the full image from freegle.images.archived_domain, and a thumbnail the
 * delivery resizer makes from it. V1's t-prefixed thumbnail blobs are not copied off Azure, so no
 * URL to one may be built (docs/ops/runbooks/azure-archive-migration.md).
 */
final class ArchivedImageUrl
{
    public const THUMB_SIZE = 250;

    public static function path(string $prefix, int $id): string
    {
        return rtrim((string) config('freegle.images.archived_domain'), '/') . "/{$prefix}_{$id}.jpg";
    }

    public static function thumb(string $prefix, int $id): string
    {
        $delivery = rtrim((string) config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org'), '/');

        return $delivery . '?w=' . self::THUMB_SIZE . '&h=' . self::THUMB_SIZE . '&url=' . rawurlencode(self::path($prefix, $id));
    }
}

<?php

namespace App\Mail\Traits;

use App\Models\Message;

/**
 * A square thumbnail of a post's primary photo, cropped by the delivery service, for mail that shows
 * posts as small cards.
 */
trait PostThumbnail
{
    /** Delivery-service cover-crop URL for the post's primary photo, or null when it has none. */
    protected function postThumbnailUrl(Message $message, int $size = 240): ?string
    {
        if (! $message->relationLoaded('attachments') || $message->attachments->isEmpty()) {
            return null;
        }
        $attachment = $message->attachments
            ->filter(fn ($a) => ! empty($a->externaluid) || ! empty($a->externalurl) || (int) ($a->archived ?? 0) === 1)
            ->sortByDesc('primary')
            ->first();
        if (! $attachment) {
            return null;
        }

        $source = ! empty($attachment->externalurl)
            ? $attachment->externalurl
            : rtrim(config('freegle.images.domain', 'https://images.ilovefreegle.org'), '/').'/timg_'.$attachment->id.'.jpg';
        $base = rtrim(config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org'), '/');

        return $base.'/?url='.urlencode($source)."&w=$size&h=$size&fit=cover";
    }
}

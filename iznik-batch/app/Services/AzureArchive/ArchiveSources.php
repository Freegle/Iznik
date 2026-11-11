<?php

namespace App\Services\AzureArchive;

/**
 * The tables V1 archived images from, and the blob name each row's image was written under.
 *
 * The prefixes are V1's (include/message/Attachment.php) and are the ones the Go API and the
 * images.ilovefreegle.org rewrites still build URLs from. The small tables come first so every
 * kind of image is copied, and can be checked, long before the large one finishes.
 *
 * V1 also wrote a thumbnail beside each image (`t` + name). Those are not copied: thumbnails of
 * archived rows are made from the full image by the delivery resizer.
 */
final class ArchiveSources
{
    /** Source table => blob prefix, in the order the copier walks them. */
    public const PREFIXES = [
        'noticeboards_images' => 'bimg',
        'newsfeed_images' => 'fimg',
        'communityevents_images' => 'cimg',
        'groups_images' => 'gimg',
        'newsletters_images' => 'nimg',
        'volunteering_images' => 'oimg',
        'users_stories_images' => 'simg',
        'users_images' => 'uimg',
        'chat_images' => 'mimg',
        'messages_attachments' => 'img',
    ];

    public static function prefix(string $source): string
    {
        if (! isset(self::PREFIXES[$source])) {
            throw new \InvalidArgumentException("Not an archive source: {$source}");
        }

        return self::PREFIXES[$source];
    }

    public static function blobName(string $source, int $id): string
    {
        return self::prefix($source) . '_' . $id . '.jpg';
    }

    /** @return list<string> */
    public static function sources(): array
    {
        return array_keys(self::PREFIXES);
    }
}

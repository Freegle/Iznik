<?php

namespace App\Services\ImageStore;

use League\MimeTypeDetection\FinfoMimeTypeDetector;

/**
 * The Content-Type an object is stored with, which is what the bucket will
 * serve it with. The bytes are believed before the client's declaration:
 * TusService declares every upload as image/webp whatever it really is, and
 * Uppy's declaration is whatever the browser guessed from the filename.
 */
final class ImageContentType
{
    public const DEFAULT = 'application/octet-stream';

    public static function detect(string $head, ?string $declared): string
    {
        if ($head !== '') {
            $sniffed = (new FinfoMimeTypeDetector())->detectMimeTypeFromBuffer($head);

            if (is_string($sniffed) && self::isImageType($sniffed)) {
                return strtolower($sniffed);
            }
        }

        if ($declared !== null && self::isImageType($declared)) {
            return strtolower($declared);
        }

        return self::DEFAULT;
    }

    public static function isImageType(string $type): bool
    {
        return (bool) preg_match('#^image/[a-z0-9.+-]+$#i', $type);
    }
}

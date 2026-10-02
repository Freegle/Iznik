<?php

namespace App\Services\ImageStore;

/**
 * The <id>.info file tusd writes beside every upload.
 *
 * Only three things in it matter here: the id, the declared length (the one
 * fact that says whether an upload is complete - the file on disk is complete
 * when it is exactly this long), and what the client said the bytes were.
 * The Offset field is ignored on purpose: tusd's filestore never updates it on
 * disk, so it is 0 in every production .info.
 */
final class TusInfo
{
    public function __construct(
        public readonly string $id,
        public readonly int $size,
        public readonly bool $sizeIsDeferred,
        public readonly array $metadata,
    ) {}

    public static function fromJson(string $json): ?self
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! array_key_exists('Size', $data) || ! is_int($data['Size'])) {
            return null;
        }

        $id = $data['ID'] ?? '';
        $metadata = $data['MetaData'] ?? [];

        return new self(
            is_string($id) ? $id : '',
            $data['Size'],
            (bool) ($data['SizeIsDeferred'] ?? false),
            is_array($metadata) ? $metadata : [],
        );
    }

    /**
     * The content type the client declared, if it declared an image type.
     * filetype is what tusd itself serves as Content-Type; type is Uppy's
     * older name for the same thing.
     */
    public function declaredType(): ?string
    {
        foreach (['filetype', 'type'] as $key) {
            $value = $this->metadata[$key] ?? null;

            if (is_string($value) && ImageContentType::isImageType($value)) {
                return strtolower($value);
            }
        }

        return null;
    }
}

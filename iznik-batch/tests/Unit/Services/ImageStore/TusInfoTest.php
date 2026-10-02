<?php

namespace Tests\Unit\Services\ImageStore;

use App\Services\ImageStore\ImageContentType;
use App\Services\ImageStore\TusInfo;
use PHPUnit\Framework\TestCase;

/**
 * The .info file tusd writes beside every upload is the only record of how long
 * the upload should be and what the client said it was. The pusher and the
 * migrator both read it, so its parsing is pinned here.
 */
class TusInfoTest extends TestCase
{
    private function realInfo(): string
    {
        // Verbatim shape of a production .info (filestore, tusd v2.4.0).
        return '{"ID":"00000f3dd6e041c729c199a978fd7bfe","Size":33524,"SizeIsDeferred":false,"Offset":0,'
            . '"MetaData":{"filename":"image.webp","filetype":"image/webp","name":"image/jpeg","relativePath":"null","type":"image/webp"},'
            . '"IsPartial":false,"IsFinal":false,"PartialUploads":null,'
            . '"Storage":{"Path":"/images/00000f3dd6e041c729c199a978fd7bfe","Type":"filestore"}}';
    }

    public function test_parses_a_production_info_file(): void
    {
        $info = TusInfo::fromJson($this->realInfo());

        $this->assertNotNull($info);
        $this->assertSame('00000f3dd6e041c729c199a978fd7bfe', $info->id);
        $this->assertSame(33524, $info->size);
        $this->assertFalse($info->sizeIsDeferred);
        $this->assertSame('image/webp', $info->declaredType());
    }

    public function test_offset_in_the_file_is_ignored(): void
    {
        // filestore never updates Offset on disk (it is 0 in every production .info);
        // completion is judged from the file length, never from this field.
        $info = TusInfo::fromJson(str_replace('"Offset":0', '"Offset":33524', $this->realInfo()));

        $this->assertSame(33524, $info->size);
    }

    public function test_invalid_json_is_null(): void
    {
        $this->assertNull(TusInfo::fromJson('not json'));
        $this->assertNull(TusInfo::fromJson(''));
        $this->assertNull(TusInfo::fromJson('{"Size":"abc"}'));
    }

    public function test_deferred_size_is_flagged(): void
    {
        $info = TusInfo::fromJson('{"ID":"abc","Size":0,"SizeIsDeferred":true,"MetaData":{}}');

        $this->assertTrue($info->sizeIsDeferred);
        $this->assertSame(0, $info->size);
    }

    public function test_declared_type_only_trusts_image_types(): void
    {
        $this->assertNull(TusInfo::fromJson('{"ID":"a","Size":1,"MetaData":{"filetype":"application/pdf"}}')->declaredType());
        $this->assertNull(TusInfo::fromJson('{"ID":"a","Size":1,"MetaData":{}}')->declaredType());
        $this->assertSame('image/png', TusInfo::fromJson('{"ID":"a","Size":1,"MetaData":{"type":"image/png"}}')->declaredType());
        // filetype wins over type, matching what tusd itself uses for Content-Type.
        $this->assertSame('image/gif', TusInfo::fromJson('{"ID":"a","Size":1,"MetaData":{"filetype":"image/gif","type":"image/png"}}')->declaredType());
    }

    public function test_content_type_prefers_the_bytes_over_the_declaration(): void
    {
        // TusService declares every upload as image/webp whatever it really is,
        // so the bytes are believed first and the declaration only fills a gap.
        $jpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        // libmagic wants the IHDR chunk after the signature, as every real PNG has.
        $png = "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89";
        $gif = 'GIF89a' . str_repeat("\x00", 16);

        $this->assertSame('image/jpeg', ImageContentType::detect($jpeg, 'image/webp'));
        $this->assertSame('image/png', ImageContentType::detect($png, null));
        $this->assertSame('image/gif', ImageContentType::detect($gif, 'image/jpeg'));
        // Unrecognisable bytes: the declaration, if it is an image type.
        $this->assertSame('image/webp', ImageContentType::detect(str_repeat("\x00", 32), 'image/webp'));
        // Nothing to go on: the safe default.
        $this->assertSame('application/octet-stream', ImageContentType::detect(str_repeat("\x00", 32), null));
        $this->assertSame('application/octet-stream', ImageContentType::detect('', null));
    }
}

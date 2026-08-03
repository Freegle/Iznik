<?php

namespace Tests\Feature\Message;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The backfill fills messages_attachments.contenttype for rows the old image
 * create path stored without one. It must leave alone anything whose type it
 * cannot honestly infer: rows that already have a type, and legacy inline-data
 * rows with no externaluid.
 */
class BackfillAttachmentContentTypeCommandTest extends TestCase
{
    private function attachment(array $attrs = []): int
    {
        return (int) DB::table('messages_attachments')->insertGetId(array_merge([
            'externaluid' => 'freegletusd-'.bin2hex(random_bytes(8)),
            'contenttype' => null,
        ], $attrs));
    }

    private function contentTypeOf(int $id): ?string
    {
        return DB::table('messages_attachments')->where('id', $id)->value('contenttype');
    }

    public function testFillsNullAndEmptyContentTypes(): void
    {
        $nullOne = $this->attachment(['contenttype' => null]);
        $emptyOne = $this->attachment(['contenttype' => '']);

        $this->artisan('messages:backfill-attachment-contenttype')->assertSuccessful();

        $this->assertSame('image/jpeg', $this->contentTypeOf($nullOne));
        $this->assertSame('image/jpeg', $this->contentTypeOf($emptyOne));
    }

    public function testLeavesRowsThatAlreadyHaveATypeAlone(): void
    {
        $existing = $this->attachment(['contenttype' => 'image/png']);

        $this->artisan('messages:backfill-attachment-contenttype')->assertSuccessful();

        $this->assertSame('image/png', $this->contentTypeOf($existing));
    }

    public function testSkipsLegacyRowsWithNoExternalUidByDefault(): void
    {
        // No externaluid: an inline-data row whose real type we cannot infer.
        $legacy = $this->attachment(['externaluid' => null]);

        $this->artisan('messages:backfill-attachment-contenttype')->assertSuccessful();

        $this->assertNull($this->contentTypeOf($legacy));
    }

    public function testIncludeLegacyOptFillsThoseRowsToo(): void
    {
        $legacy = $this->attachment(['externaluid' => null]);

        $this->artisan('messages:backfill-attachment-contenttype --include-legacy')->assertSuccessful();

        $this->assertSame('image/jpeg', $this->contentTypeOf($legacy));
    }

    public function testDryRunWritesNothing(): void
    {
        $id = $this->attachment();

        $this->artisan('messages:backfill-attachment-contenttype --dry-run')->assertSuccessful();

        $this->assertNull($this->contentTypeOf($id));
    }

    public function testLimitCapsTheRowsTouchedAndIsResumable(): void
    {
        $ids = [$this->attachment(), $this->attachment(), $this->attachment()];

        $this->artisan('messages:backfill-attachment-contenttype --limit=1 --chunk=1')->assertSuccessful();

        $filled = collect($ids)->filter(fn ($id) => $this->contentTypeOf($id) === 'image/jpeg')->count();
        $this->assertSame(1, $filled, 'exactly one row should be filled under --limit=1');

        // Running again picks up where it left off rather than redoing the same row.
        $this->artisan('messages:backfill-attachment-contenttype --chunk=1')->assertSuccessful();

        foreach ($ids as $id) {
            $this->assertSame('image/jpeg', $this->contentTypeOf($id));
        }
    }

    public function testHonoursAnExplicitValue(): void
    {
        $id = $this->attachment();

        $this->artisan('messages:backfill-attachment-contenttype --value=image/webp')->assertSuccessful();

        $this->assertSame('image/webp', $this->contentTypeOf($id));
    }

    public function testRejectsAnEmptyValue(): void
    {
        $id = $this->attachment();

        $this->artisan('messages:backfill-attachment-contenttype --value=" "')->assertFailed();

        $this->assertNull($this->contentTypeOf($id));
    }
}

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
            'contenttype' => '',
        ], $attrs));
    }

    private function contentTypeOf(int $id): ?string
    {
        return DB::table('messages_attachments')->where('id', $id)->value('contenttype');
    }

    public function testFillsEmptyContentTypes(): void
    {
        // The schema-parity migration made contenttype VARCHAR(80) NOT NULL, so the
        // bug state the old create path left behind is '' (non-strict MySQL filled
        // the omitted column with the implicit default), never NULL. The command
        // still matches NULL as belt-and-braces for any pre-parity stragglers.
        $emptyOne = $this->attachment(['contenttype' => '']);
        $emptyTwo = $this->attachment(['contenttype' => '']);

        $this->artisan('messages:backfill-attachment-contenttype')->assertSuccessful();

        $this->assertSame('image/jpeg', $this->contentTypeOf($emptyOne));
        $this->assertSame('image/jpeg', $this->contentTypeOf($emptyTwo));
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

        $this->assertSame('', $this->contentTypeOf($legacy), 'legacy row must be left untouched');
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

        $this->assertSame('', $this->contentTypeOf($id));
    }

    public function testLimitCapsTheRowsTouchedAndIsResumable(): void
    {
        $ids = [$this->attachment(), $this->attachment(), $this->attachment()];

        // Assert on the global candidate count, not on which specific row was
        // filled: the command walks by ascending id across the whole table, so
        // under --limit=1 the row it fills may be a baseline row from the test
        // database rather than one of ours.
        $before = $this->candidateCount();
        $this->assertGreaterThanOrEqual(3, $before);

        $this->artisan('messages:backfill-attachment-contenttype --limit=1 --chunk=1')->assertSuccessful();

        $this->assertSame($before - 1, $this->candidateCount(), 'exactly one candidate should be filled under --limit=1');

        // Running again picks up where it left off rather than redoing the same row.
        $this->artisan('messages:backfill-attachment-contenttype --chunk=1')->assertSuccessful();

        $this->assertSame(0, $this->candidateCount());
        foreach ($ids as $id) {
            $this->assertSame('image/jpeg', $this->contentTypeOf($id));
        }
    }

    private function candidateCount(): int
    {
        return DB::table('messages_attachments')
            ->where(function ($w) {
                $w->whereNull('contenttype')->orWhere('contenttype', '');
            })
            ->whereNotNull('externaluid')->where('externaluid', '!=', '')
            ->count();
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

        $this->assertSame('', $this->contentTypeOf($id));
    }
}

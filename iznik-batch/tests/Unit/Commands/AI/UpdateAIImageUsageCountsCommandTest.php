<?php

namespace Tests\Unit\Commands\AI;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateAIImageUsageCountsCommandTest extends TestCase
{
    private array $testImageIds = [];
    private array $testAttachmentIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('ai_images')->where('name', 'LIKE', 'test-usage-%')->delete();
    }

    protected function tearDown(): void
    {
        if ($this->testAttachmentIds) {
            DB::table('messages_attachments')->whereIn('id', $this->testAttachmentIds)->delete();
        }
        DB::table('ai_images')->where('name', 'LIKE', 'test-usage-%')->delete();

        parent::tearDown();
    }

    public function test_updates_usage_counts_in_batches(): void
    {
        // Create test AI images.
        $uid1 = 'test-usage-uid-1-' . uniqid();
        $uid2 = 'test-usage-uid-2-' . uniqid();

        DB::table('ai_images')->insert([
            ['name' => 'test-usage-img1', 'externaluid' => $uid1, 'usage_count' => 0],
            ['name' => 'test-usage-img2', 'externaluid' => $uid2, 'usage_count' => 0],
        ]);

        $img1Id = DB::table('ai_images')->where('name', 'test-usage-img1')->value('id');
        $img2Id = DB::table('ai_images')->where('name', 'test-usage-img2')->value('id');

        // Create attachments referencing img1 (2 uses) and img2 (1 use).
        DB::table('messages_attachments')->insert([
            ['externaluid' => $uid1, 'externalmods' => json_encode(['ai' => true]), 'hash' => 'a'],
            ['externaluid' => $uid1, 'externalmods' => json_encode(['ai' => true]), 'hash' => 'b'],
            ['externaluid' => $uid2, 'externalmods' => json_encode(['ai' => true]), 'hash' => 'c'],
        ]);

        $this->testAttachmentIds = DB::table('messages_attachments')
            ->whereIn('externaluid', [$uid1, $uid2])
            ->pluck('id')
            ->toArray();

        $this->artisan('ai:usage-counts:update')
            ->assertSuccessful();

        $this->assertEquals(2, DB::table('ai_images')->where('id', $img1Id)->value('usage_count'));
        $this->assertEquals(1, DB::table('ai_images')->where('id', $img2Id)->value('usage_count'));
    }

    public function test_skips_images_without_externaluid(): void
    {
        DB::table('ai_images')->insert([
            'name' => 'test-usage-no-uid',
            'externaluid' => null,
            'usage_count' => 99,
        ]);

        $this->artisan('ai:usage-counts:update')
            ->assertSuccessful();

        // Should not have been touched — still 99.
        $this->assertEquals(99, DB::table('ai_images')->where('name', 'test-usage-no-uid')->value('usage_count'));
    }

    public function test_skips_rows_where_count_unchanged(): void
    {
        // Create an image whose usage_count already matches reality.
        $uid = 'test-usage-uid-unchanged-' . uniqid();

        DB::table('ai_images')->insert([
            'name' => 'test-usage-unchanged',
            'externaluid' => $uid,
            'usage_count' => 1,
        ]);

        DB::table('messages_attachments')->insert([
            'externaluid' => $uid,
            'externalmods' => json_encode(['ai' => true]),
            'hash' => 'unchanged',
        ]);

        $this->testAttachmentIds = DB::table('messages_attachments')
            ->where('externaluid', $uid)
            ->pluck('id')
            ->toArray();

        $this->artisan('ai:usage-counts:update')
            ->assertSuccessful();

        // Count should still be 1 (unchanged).
        $this->assertEquals(1, DB::table('ai_images')->where('name', 'test-usage-unchanged')->value('usage_count'));
    }
}

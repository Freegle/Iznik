<?php

namespace Tests\Unit\Commands\AI;

use Illuminate\Support\Facades\Cache;
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

    public function test_the_cursor_is_never_moved_backwards(): void
    {
        // The counts are added to, not set, so a cursor that slipped backwards would make
        // the next pass count the same attachments again and the error would stick until
        // the next full recompute. Two things can push a smaller number at it: the nightly
        // full run finishing after an hourly one has already moved the cursor on, and
        // MAX(id) coming back from a cluster node that is behind.
        $key = 'ai.usage_counts_cursor';
        $ahead = (int) DB::table('messages_attachments')->max('id') + 100000;

        DB::table('config')->upsert(
            [['key' => $key, 'value' => (string) $ahead]],
            ['key'],
            ['value'],
        );

        $this->artisan('ai:usage-counts:update')->assertSuccessful();

        $this->assertEquals(
            $ahead,
            (int) DB::table('config')->where('key', $key)->value('value'),
            'the cursor was moved backwards, so the next run would double-count'
        );

        DB::table('config')->where('key', $key)->delete();
    }

    public function test_a_second_run_exits_while_another_holds_the_lock(): void
    {
        // The hourly and nightly jobs are separate schedule entries, so Laravel's own
        // overlap guard keys them separately and does not hold one off against the other.
        // They take a shared lock instead.
        $lock = Cache::lock('ai:usage-counts:run', 60);
        $this->assertTrue($lock->get(), 'could not take the lock to set the test up');

        try {
            $this->artisan('ai:usage-counts:update')
                ->expectsOutputToContain('Another ai:usage-counts:update run is in progress')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }
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

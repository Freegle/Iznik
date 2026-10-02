<?php

namespace Tests\Unit\Commands;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * chitchat:backfill-leaves - tags existing threads with their road-network
 * region so the road-aware feed narrowing covers pre-tagging posts.
 */
class ChitchatBackfillLeavesTest extends TestCase
{
    private function seedThread(?int $leaf = null): int
    {
        $user = $this->createTestUser();

        return (int) DB::table('newsfeed')->insertGetId([
            'type' => 'Message',
            'userid' => $user->id,
            'message' => 'leaf backfill test',
            'timestamp' => now(),
            'position' => DB::raw("ST_GeomFromText('POINT(-3.1883 55.9533)', 3857)"),
            'leaf' => $leaf,
        ]);
    }

    public function testTagsUntaggedThreads(): void
    {
        $id = $this->seedThread();
        $already = $this->seedThread(42);
        Http::fake(['*/v1/leaf*' => Http::response(['leaves' => [7, 9]])]);

        $this->artisan('chitchat:backfill-leaves', ['--sleep-ms' => 0])->assertExitCode(0);

        $this->assertSame(7, (int) DB::table('newsfeed')->where('id', $id)->value('leaf'));
        $this->assertSame(42, (int) DB::table('newsfeed')->where('id', $already)->value('leaf'), 'tagged rows are skipped');
    }

    public function testEngineUnavailableLeavesRowsUntouched(): void
    {
        $id = $this->seedThread();
        Http::fake(['*/v1/leaf*' => Http::response('not configured', 503)]);

        $this->artisan('chitchat:backfill-leaves', ['--sleep-ms' => 0])->assertExitCode(0);

        $this->assertNull(DB::table('newsfeed')->where('id', $id)->value('leaf'));
    }
}

<?php

namespace Tests\Feature\Message;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * messages:count-views stores the number of View rows per recent post in messages_viewcounts, which
 * the ModTools dashboard's Popular Posts ranks by.
 */
class CountViewsCommandTest extends TestCase
{
    private function addView(int $msgid, int $userid, string $type = 'View'): void
    {
        DB::table('messages_likes')->insert(['msgid' => $msgid, 'userid' => $userid, 'type' => $type]);
    }

    private function stored(int $msgid): ?object
    {
        return DB::table('messages_viewcounts')->where('msgid', $msgid)->first();
    }

    public function test_counts_view_rows_per_post_and_ignores_other_likes(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $popular = $this->createTestMessage($poster, $group, ['arrival' => Carbon::now()->subDays(2)]);
        $quiet = $this->createTestMessage($poster, $group, ['arrival' => Carbon::now()->subDays(3)]);

        $viewers = [$this->createTestUser(), $this->createTestUser(), $this->createTestUser()];
        foreach ($viewers as $v) {
            $this->addView($popular->id, $v->id);
        }
        $this->addView($quiet->id, $viewers[0]->id);
        $this->addView($quiet->id, $viewers[1]->id, 'Love');

        $this->artisan('messages:count-views')->assertExitCode(0);

        $this->assertSame(3, (int) $this->stored($popular->id)->views);
        $this->assertSame(1, (int) $this->stored($quiet->id)->views, 'a Love is not a view');
        $this->assertSame(
            Carbon::parse($popular->fresh()->arrival)->toDateTimeString(),
            Carbon::parse($this->stored($popular->id)->arrival)->toDateTimeString(),
        );
    }

    public function test_a_second_run_updates_the_count_and_the_run_time(): void
    {
        $group = $this->createTestGroup();
        $msg = $this->createTestMessage($this->createTestUser(), $group, ['arrival' => Carbon::now()->subDay()]);
        $this->addView($msg->id, $this->createTestUser()->id);

        $this->artisan('messages:count-views')->assertExitCode(0);
        DB::table('messages_viewcounts')->where('msgid', $msg->id)->update(['counted_at' => '2020-01-01 00:00:00']);

        $this->addView($msg->id, $this->createTestUser()->id);
        $this->artisan('messages:count-views')->assertExitCode(0);

        $row = $this->stored($msg->id);
        $this->assertSame(2, (int) $row->views);
        $this->assertNotSame('2020-01-01 00:00:00', Carbon::parse($row->counted_at)->toDateTimeString());
        $this->assertSame(1, DB::table('messages_viewcounts')->where('msgid', $msg->id)->count());
    }

    public function test_only_the_requested_days_are_recounted(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $recent = $this->createTestMessage($poster, $group, ['arrival' => Carbon::now()->subDays(2)]);
        $old = $this->createTestMessage($poster, $group, ['arrival' => Carbon::now()->subDays(10)]);
        $this->addView($recent->id, $poster->id);
        $this->addView($old->id, $poster->id);

        $this->artisan('messages:count-views --days=5')->assertExitCode(0);

        $this->assertNotNull($this->stored($recent->id));
        $this->assertNull($this->stored($old->id), 'older than --days is left as it was');
    }

    public function test_deleted_and_unviewed_posts_get_no_row(): void
    {
        $group = $this->createTestGroup();
        $poster = $this->createTestUser();
        $deleted = $this->createTestMessage($poster, $group, [
            'arrival' => Carbon::now()->subDay(),
            'deleted' => Carbon::now(),
        ]);
        $unviewed = $this->createTestMessage($poster, $group, ['arrival' => Carbon::now()->subDay()]);
        $this->addView($deleted->id, $poster->id);

        $this->artisan('messages:count-views')->assertExitCode(0);

        $this->assertNull($this->stored($deleted->id));
        $this->assertNull($this->stored($unviewed->id));
    }
}

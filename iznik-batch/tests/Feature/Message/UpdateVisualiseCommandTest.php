<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UpdateVisualiseCommandTest extends TestCase
{
    private function insertLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'TestLoc',
            'type' => 'Postcode',
            'lat'  => $lat,
            'lng'  => $lng,
        ]);
    }

    private function insertAttachment(int $msgid): int
    {
        return DB::table('messages_attachments')->insertGetId([
            'msgid' => $msgid,
        ]);
    }

    private function insertReceivedBy(int $msgid, int $userid): void
    {
        DB::table('messages_by')->insert([
            'msgid'     => $msgid,
            'userid'    => $userid,
            'timestamp' => now(),
        ]);
    }

    public function test_runs_cleanly_with_no_messages(): void
    {
        $this->artisan('messages:update-visualise')
            ->assertExitCode(0);
    }

    public function test_inserts_record_for_nearby_taken_offer(): void
    {
        $group  = $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $locIdA = $this->insertLocation(51.5074, -0.1278);
        $locIdB = $this->insertLocation(51.5200, -0.1000);

        $giver  = $this->createTestUser(['lastlocation' => $locIdA]);
        $taker  = $this->createTestUser(['lastlocation' => $locIdB]);

        $msg  = $this->createTestMessage($giver, $group, ['type' => Message::TYPE_OFFER]);
        $this->insertAttachment($msg->id);
        $this->insertReceivedBy($msg->id, $taker->id);

        $this->artisan('messages:update-visualise')->assertExitCode(0);

        $this->assertEquals(1, DB::table('visualise')->where('msgid', $msg->id)->count());
    }

    public function test_skips_message_already_in_visualise_table(): void
    {
        $group  = $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $locIdA = $this->insertLocation(51.5074, -0.1278);
        $locIdB = $this->insertLocation(51.5200, -0.1000);

        $giver  = $this->createTestUser(['lastlocation' => $locIdA]);
        $taker  = $this->createTestUser(['lastlocation' => $locIdB]);

        $msg  = $this->createTestMessage($giver, $group);
        $attId = $this->insertAttachment($msg->id);
        $this->insertReceivedBy($msg->id, $taker->id);

        // Pre-insert the record.
        DB::table('visualise')->insert([
            'msgid'    => $msg->id,
            'attid'    => $attId,
            'fromuser' => $giver->id,
            'touser'   => $taker->id,
            'fromlat'  => 51.5074,
            'fromlng'  => -0.1278,
            'tolat'    => 51.5200,
            'tolng'    => -0.1000,
            'distance' => 2000,
        ]);

        $this->artisan('messages:update-visualise')
            ->assertExitCode(0);

        $this->assertEquals(1, DB::table('visualise')->where('msgid', $msg->id)->count());
    }

    public function test_skips_users_without_location(): void
    {
        $group = $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $giver = $this->createTestUser(['lastlocation' => null]);
        $taker = $this->createTestUser(['lastlocation' => null]);

        $msg = $this->createTestMessage($giver, $group);
        $this->insertAttachment($msg->id);
        $this->insertReceivedBy($msg->id, $taker->id);

        $this->artisan('messages:update-visualise')
            ->expectsOutputToContain('Inserted 0 visualise record(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('visualise')->count());
    }

    public function test_skips_messages_without_attachments(): void
    {
        $group = $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $locIdA = $this->insertLocation(51.5074, -0.1278);
        $locIdB = $this->insertLocation(51.5200, -0.1000);

        $giver = $this->createTestUser(['lastlocation' => $locIdA]);
        $taker = $this->createTestUser(['lastlocation' => $locIdB]);

        $msg = $this->createTestMessage($giver, $group);
        // No attachment inserted.
        $this->insertReceivedBy($msg->id, $taker->id);

        $this->artisan('messages:update-visualise')
            ->expectsOutputToContain('Inserted 0 visualise record(s)')
            ->assertExitCode(0);
    }

    public function test_dry_run_does_not_write_to_database(): void
    {
        $group  = $this->createTestGroup(['publish' => 1, 'onmap' => 1]);

        $locIdA = $this->insertLocation(51.5074, -0.1278);
        $locIdB = $this->insertLocation(51.5200, -0.1000);

        $giver  = $this->createTestUser(['lastlocation' => $locIdA]);
        $taker  = $this->createTestUser(['lastlocation' => $locIdB]);

        $msg = $this->createTestMessage($giver, $group);
        $this->insertAttachment($msg->id);
        $this->insertReceivedBy($msg->id, $taker->id);

        $this->artisan('messages:update-visualise', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would insert 1 visualise record(s)')
            ->assertExitCode(0);

        $this->assertEquals(0, DB::table('visualise')->count());
    }
}

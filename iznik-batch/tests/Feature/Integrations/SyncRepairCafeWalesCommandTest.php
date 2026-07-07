<?php

namespace Tests\Feature\Integrations;

use App\Services\CommunityEventService;
use App\Services\RepairCafeWalesService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncRepairCafeWalesCommandTest extends TestCase
{
    public function test_runs_cleanly_with_zero_events(): void
    {
        $mock = $this->createMock(RepairCafeWalesService::class);
        $mock->method('sync')->willReturn(['added' => 0, 'updated' => 0, 'deleted' => 0]);
        $this->app->instance(RepairCafeWalesService::class, $mock);

        $this->artisan('integrations:sync-repaircafewales')
            ->expectsOutputToContain('Processed 0 new event(s), 0 updated, 0 deleted.')
            ->assertExitCode(0);
    }

    public function test_dry_run_outputs_dry_run_prefix(): void
    {
        $mock = $this->createMock(RepairCafeWalesService::class);
        $mock->method('sync')->with(true)->willReturn(['added' => 2, 'updated' => 1, 'deleted' => 0]);
        $this->app->instance(RepairCafeWalesService::class, $mock);

        $this->artisan('integrations:sync-repaircafewales', ['--dry-run' => true])
            ->expectsOutputToContain('[DRY RUN] Would process 2 new event(s), 1 updated, 0 deleted.')
            ->assertExitCode(0);
    }

    public function test_creates_event_for_nearby_group(): void
    {
        $freegleGroup = $this->createTestGroup([
            'lat'      => 51.4816,
            'lng'      => -3.1791,
            'publish'  => 1,
            'listable' => 1,
        ]);

        DB::table('locations')->insert([
            'name'  => 'CF10 1AA',
            'canon' => 'cf101aa',
            'type'  => 'Postcode',
            'lat'   => 51.4816,
            'lng'   => -3.1791,
        ]);

        $uid   = 'rcw-test-001@repaircafewales.org';
        $ical  = $this->buildIcal($uid, 'Community Fix-it Session', 'Cardiff Centre, CF10 1AA',
            'https://repaircafewales.org/test',
            now()->addDays(5)->format('Ymd\THis\Z'),
            now()->addDays(5)->addHours(3)->format('Ymd\THis\Z'));

        $service = $this->serviceWithIcal($ical);
        $result  = $service->sync(false);

        $this->assertEquals(1, $result['added']);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', $uid)->count());
        $this->assertEquals(
            1,
            DB::table('communityevents_groups')
                ->join('communityevents', 'communityevents.id', '=', 'communityevents_groups.eventid')
                ->where('communityevents.externalid', $uid)
                ->where('communityevents_groups.groupid', $freegleGroup->id)
                ->count()
        );
    }

    public function test_updates_existing_event(): void
    {
        $this->createTestGroup(['lat' => 51.4816, 'lng' => -3.1791, 'publish' => 1, 'listable' => 1]);

        DB::table('locations')->insert(['name' => 'CF10 1AA', 'canon' => 'cf101aa', 'type' => 'Postcode', 'lat' => 51.4816, 'lng' => -3.1791]);

        $uid = 'rcw-update-001@repaircafewales.org';

        DB::table('communityevents')->insert([
            'externalid'  => $uid,
            'title'       => 'Old Title',
            'location'    => 'Old Location',
            'description' => 'Old desc',
            'pending'     => 0,
        ]);
        $existingId = (int) DB::getPdo()->lastInsertId();
        DB::table('communityevents_dates')->insert([
            'eventid' => $existingId,
            'start'   => now()->addDays(2)->format('Y-m-d H:i:s'),
            'end'     => now()->addDays(2)->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $ical    = $this->buildIcal($uid, 'New Title', 'Cardiff Centre, CF10 1AA', null,
            now()->addDays(5)->format('Ymd\THis\Z'),
            now()->addDays(5)->addHours(3)->format('Ymd\THis\Z'));
        $service = $this->serviceWithIcal($ical);
        $result  = $service->sync(false);

        $this->assertEquals(1, $result['updated']);
        $updated = DB::table('communityevents')->where('externalid', $uid)->first();
        $this->assertEquals('New Title', $updated->title);
        $this->assertEquals(1, $updated->pending);
    }

    public function test_dry_run_does_not_create_event(): void
    {
        $this->createTestGroup(['lat' => 51.4816, 'lng' => -3.1791, 'publish' => 1, 'listable' => 1]);
        DB::table('locations')->insert(['name' => 'CF10 1AA', 'canon' => 'cf101aa', 'type' => 'Postcode', 'lat' => 51.4816, 'lng' => -3.1791]);

        $uid  = 'rcw-dryrun-001@repaircafewales.org';
        $ical = $this->buildIcal($uid, 'Test Event', 'Place, CF10 1AA', null,
            now()->addDays(5)->format('Ymd\THis\Z'),
            now()->addDays(5)->addHours(3)->format('Ymd\THis\Z'));

        $result = $this->serviceWithIcal($ical)->sync(true);

        $this->assertEquals(1, $result['added']);
        $this->assertEquals(0, DB::table('communityevents')->where('externalid', $uid)->count());
    }

    public function test_skips_event_without_postcode(): void
    {
        $this->createTestGroup(['lat' => 51.4816, 'lng' => -3.1791, 'publish' => 1, 'listable' => 1]);

        $uid  = 'rcw-no-postcode@repaircafewales.org';
        $ical = $this->buildIcal($uid, 'No Postcode Event', 'Somewhere without a code', null,
            now()->addDays(5)->format('Ymd\THis\Z'),
            now()->addDays(5)->addHours(3)->format('Ymd\THis\Z'));

        $result = $this->serviceWithIcal($ical)->sync(false);
        $this->assertEquals(0, $result['added']);
    }

    public function test_marks_deleted_when_not_in_feed(): void
    {
        DB::table('communityevents')->insert([
            'externalid'  => 'rcw-old@repaircafewales.org',
            'title'       => 'Old Event',
            'location'    => 'Somewhere',
            'description' => null,
            'pending'     => 0,
            'deleted'     => 0,
        ]);
        $existingId = (int) DB::getPdo()->lastInsertId();
        DB::table('communityevents_dates')->insert([
            'eventid' => $existingId,
            'start'   => now()->addDays(3)->format('Y-m-d H:i:s'),
            'end'     => now()->addDays(3)->addHours(2)->format('Y-m-d H:i:s'),
        ]);

        $emptyIcal = implode("\r\n", ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//T//T//EN', 'END:VCALENDAR', '']);
        $result    = $this->serviceWithIcal($emptyIcal)->sync(false);

        $this->assertEquals(1, $result['deleted']);
        $this->assertEquals(1, DB::table('communityevents')->where('externalid', 'rcw-old@repaircafewales.org')->value('deleted'));
    }

    private function serviceWithIcal(string $icalContent): RepairCafeWalesService
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'rcw_') . '.ics';
        file_put_contents($tmpFile, $icalContent);

        $events = app(CommunityEventService::class);

        return new class($events, $tmpFile) extends RepairCafeWalesService {
            public function __construct(CommunityEventService $events, private string $file)
            {
                parent::__construct($events);
            }

            protected function icalUrl(): string
            {
                return $this->file;
            }
        };
    }

    private function buildIcal(string $uid, string $summary, string $location, ?string $url, string $dtstart, string $dtend): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            "UID:{$uid}",
            "SUMMARY:{$summary}",
            "LOCATION:{$location}",
            "DTSTART:{$dtstart}",
            "DTEND:{$dtend}",
        ];
        if ($url !== null) {
            $lines[] = "URL:{$url}";
        }
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';
        $lines[] = '';
        return implode("\r\n", $lines);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\User;
use App\Services\VisualiseService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('Unit')]
class VisualiseServiceTest extends TestCase
{
    private VisualiseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VisualiseService();
    }

    private function callPrivate(string $method, array $args)
    {
        $m = new \ReflectionMethod(VisualiseService::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->service, $args);
    }

    // ---- distanceMetres -------------------------------------------------

    public static function distanceProvider(): array
    {
        return [
            'identical points' => [51.5, -0.12, 51.5, -0.12, 0, 0],
            'identical at origin' => [0.0, 0.0, 0.0, 0.0, 0, 0],
            // London to Edinburgh is roughly 534km great-circle.
            'london to edinburgh' => [51.5074, -0.1278, 55.9533, -3.1883, 534000, 3000],
            // One degree of latitude is ~111.19km.
            'one degree latitude' => [51.0, 0.0, 52.0, 0.0, 111195, 5],
            // Just over 30km apart (0.3 degrees latitude ~ 33.4km).
            'over 30km apart' => [51.0, 0.0, 51.3, 0.0, 33358, 5],
            // Negative (southern and western) coordinates.
            'southern western hemisphere' => [-33.8688, -70.6693, -34.8688, -70.6693, 111195, 5],
            'crossing the equator' => [-0.5, 10.0, 0.5, 10.0, 111195, 5],
            'crossing the meridian' => [51.5, -0.5, 51.5, 0.5, 69300, 300],
            // Symmetry: swapped arguments give the same answer.
            'symmetric reverse' => [55.9533, -3.1883, 51.5074, -0.1278, 534000, 3000],
        ];
    }

    #[DataProvider('distanceProvider')]
    public function test_distance_metres(float $lat1, float $lng1, float $lat2, float $lng2, int $expected, int $tolerance): void
    {
        $result = $this->callPrivate('distanceMetres', [$lat1, $lng1, $lat2, $lng2]);

        $this->assertIsInt($result);
        $this->assertEqualsWithDelta($expected, $result, $tolerance);
    }

    public function test_distance_metres_is_rounded_to_integer(): void
    {
        // 0.00001 degrees of latitude is ~1.11m: result must be a whole number.
        $result = $this->callPrivate('distanceMetres', [51.0, 0.0, 51.00001, 0.0]);

        $this->assertIsInt($result);
        $this->assertSame(1, $result);
    }

    // ---- allowsProfile --------------------------------------------------

    public static function profileProvider(): array
    {
        return [
            'useprofile true' => [['useprofile' => true], true],
            'useprofile 1' => [['useprofile' => 1], true],
            'useprofile false' => [['useprofile' => false], false],
            'useprofile 0' => [['useprofile' => 0], false],
            'key absent defaults true' => [['other' => 'x'], true],
            'empty array defaults true' => [[], true],
            'null settings defaults true' => [null, true],
        ];
    }

    #[DataProvider('profileProvider')]
    public function test_allows_profile(?array $settings, bool $expected): void
    {
        $user = new User();
        $user->settings = $settings;

        $this->assertSame($expected, $this->callPrivate('allowsProfile', [$user]));
    }

    public function test_allows_profile_when_settings_not_an_array(): void
    {
        // Bypass the array cast to exercise the !is_array branch.
        $user = new class extends User {
            public function getSettingsAttribute($value = null)
            {
                return 'garbage';
            }
        };

        $this->assertTrue($this->callPrivate('allowsProfile', [$user]));
    }

    // ---- scan -----------------------------------------------------------

    private function locatedUser(?float $lat, ?float $lng, array $attrs = []): User
    {
        $locid = DB::table('locations')->insertGetId([
            'name' => 'VIS'.uniqid(),
            'type' => 'Postcode',
            'lat' => $lat,
            'lng' => $lng,
        ]);

        return $this->createTestUser(array_merge(['lastlocation' => $locid], $attrs));
    }

    /** Creates an offer with an attachment and a "taken by" row; returns [msgid, attid]. */
    private function takenOffer(?int $fromUser, ?int $takenBy, string $type = Message::TYPE_OFFER, ?string $when = null): array
    {
        $msgid = DB::table('messages')->insertGetId([
            'type' => $type,
            'fromuser' => $fromUser,
            'subject' => 'OFFER: Visualise test',
            'textbody' => 'x',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
        ]);

        $attid = DB::table('messages_attachments')->insertGetId(['msgid' => $msgid]);

        DB::table('messages_by')->insert([
            'msgid' => $msgid,
            'userid' => $takenBy,
            'timestamp' => $when ?? now()->format('Y-m-d H:i:s'),
            'count' => 1,
        ]);

        return [$msgid, $attid];
    }

    public function test_scan_inserts_normal_case(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.6, -0.12);
        [$msgid, $attid] = $this->takenOffer($from->id, $to->id);

        $count = $this->service->scan('1 hour ago');

        $this->assertSame(1, $count);
        $row = DB::table('visualise')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame($attid, (int) $row->attid);
        $this->assertSame($from->id, (int) $row->fromuser);
        $this->assertSame($to->id, (int) $row->touser);
        $this->assertEqualsWithDelta(51.5, (float) $row->fromlat, 0.0001);
        $this->assertEqualsWithDelta(-0.12, (float) $row->fromlng, 0.0001);
        $this->assertEqualsWithDelta(51.6, (float) $row->tolat, 0.0001);
        $this->assertEqualsWithDelta(11119, (int) $row->distance, 10);
    }

    public function test_scan_is_idempotent_via_insert_or_ignore(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->service->scan('1 hour ago');
        $this->service->scan('1 hour ago');

        $this->assertSame(1, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_no_matching_rows_returns_zero(): void
    {
        // Only a row outside the window exists.
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        $this->takenOffer($from->id, $to->id, Message::TYPE_OFFER, now()->subDays(2)->format('Y-m-d H:i:s'));

        $this->assertSame(0, $this->service->scan('30 minutes ago'));
    }

    public function test_scan_window_includes_older_rows_with_wider_ago(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id, Message::TYPE_OFFER, now()->subHours(5)->format('Y-m-d H:i:s'));

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());

        $this->assertSame(1, $this->service->scan('1 day ago'));
        $this->assertSame(1, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_excludes_non_offers(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id, Message::TYPE_WANTED);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_excludes_null_taker(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        [$msgid] = $this->takenOffer($from->id, null);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_excludes_messages_without_attachment(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$msgid, $attid] = $this->takenOffer($from->id, $to->id);
        DB::table('messages_attachments')->where('id', $attid)->delete();

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_skips_when_taker_user_not_found(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $missing = (int) DB::table('users')->max('id') + 1000000;
        // messages_by.userid has a foreign key; lift it to simulate a taker deleted since.
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            [$msgid] = $this->takenOffer($from->id, $missing);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_skips_when_poster_user_not_found(): void
    {
        $to = $this->locatedUser(51.5, -0.12);
        // messages.fromuser has a foreign key, so a null poster is the "not found" case.
        [$msgid] = $this->takenOffer(null, $to->id);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public static function disallowedProfileProvider(): array
    {
        return [
            'poster disallows' => [true, false],
            'taker disallows' => [false, true],
            'both disallow' => [true, true],
        ];
    }

    #[DataProvider('disallowedProfileProvider')]
    public function test_scan_skips_when_a_profile_is_disallowed(bool $fromOff, bool $toOff): void
    {
        $from = $this->locatedUser(51.5, -0.12, $fromOff ? ['settings' => ['useprofile' => false]] : []);
        $to = $this->locatedUser(51.51, -0.12, $toOff ? ['settings' => ['useprofile' => false]] : []);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_allows_explicit_useprofile_true(): void
    {
        $from = $this->locatedUser(51.5, -0.12, ['settings' => ['useprofile' => true]]);
        $to = $this->locatedUser(51.51, -0.12, ['settings' => ['useprofile' => true]]);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(1, $this->service->scan('1 hour ago'));
        $this->assertSame(1, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public static function missingLocationProvider(): array
    {
        return [
            'from lat zero' => [[0.0, -0.12], [51.51, -0.12]],
            'to lat zero' => [[51.5, -0.12], [0.0, -0.12]],
            'from null' => [[null, null], [51.51, -0.12]],
            'to null' => [[51.5, -0.12], [null, null]],
        ];
    }

    #[DataProvider('missingLocationProvider')]
    public function test_scan_skips_when_a_coordinate_is_falsy(array $fromLoc, array $toLoc): void
    {
        $from = $this->locatedUser($fromLoc[0], $fromLoc[1]);
        $to = $this->locatedUser($toLoc[0], $toLoc[1]);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_skips_when_user_has_no_last_location(): void
    {
        $from = $this->createTestUser();
        $to = $this->locatedUser(51.5, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(0, $this->service->scan('1 hour ago'));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_does_not_treat_zero_longitude_as_missing(): void
    {
        // locations.lat/lng use a decimal:6 cast, which yields the string "0.000000" - truthy in
        // PHP - so only a null coordinate trips the falsy guard. Characterisation of current behaviour.
        $from = $this->locatedUser(51.5, 0.0);
        $to = $this->locatedUser(51.51, 0.0);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(1, $this->service->scan('1 hour ago'));
        $this->assertSame(1, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public static function distanceCutoffProvider(): array
    {
        return [
            'well under 30km' => [51.0, 51.2, 1],
            'just under 30km' => [51.0, 51.2695, 1],
            'just over 30km' => [51.0, 51.2705, 0],
            'far over 30km' => [51.0, 52.0, 0],
        ];
    }

    #[DataProvider('distanceCutoffProvider')]
    public function test_scan_distance_cutoff(float $fromLat, float $toLat, int $expected): void
    {
        $from = $this->locatedUser($fromLat, -0.12);
        $to = $this->locatedUser($toLat, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame($expected, $this->service->scan('1 hour ago'));
        $this->assertSame($expected, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_dry_run_counts_but_inserts_nothing(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$msgid] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(1, $this->service->scan('1 hour ago', true));
        $this->assertSame(0, DB::table('visualise')->where('msgid', $msgid)->count());
    }

    public function test_scan_dry_run_still_applies_distance_filter(): void
    {
        $from = $this->locatedUser(51.0, -0.12);
        $to = $this->locatedUser(52.0, -0.12);
        $this->takenOffer($from->id, $to->id);

        $this->assertSame(0, $this->service->scan('1 hour ago', true));
    }

    public function test_scan_counts_multiple_qualifying_rows(): void
    {
        $from = $this->locatedUser(51.5, -0.12);
        $to = $this->locatedUser(51.51, -0.12);
        [$m1] = $this->takenOffer($from->id, $to->id);
        [$m2] = $this->takenOffer($from->id, $to->id);

        $this->assertSame(2, $this->service->scan('1 hour ago'));
        $this->assertSame(2, DB::table('visualise')->whereIn('msgid', [$m1, $m2])->count());
    }
}

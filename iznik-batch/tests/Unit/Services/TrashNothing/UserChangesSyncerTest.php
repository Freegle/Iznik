<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use App\Models\UserReplyTime;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use App\Services\TrashNothing\Sync\UserChangesSyncer;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Username handling in the user-changes sync. The old username comes from the
 * member's preferred TN address, not from fullname, because fullname is the
 * prettified display name ("Tricia Hayes") and never equals the raw username.
 */
class UserChangesSyncerTest extends TestCase
{
    private const API_BASE = 'https://example.test/fd/api';

    private const TN_DOMAIN = '@user.trashnothing.com';

    /** @var array<int, array> */
    private array $changes = [];

    /** The first page TN fails to serve, or 0 when it serves them all. */
    private int $failFromPage = 0;

    /** @var array<int, array{string, array}> */
    private array $lokiEvents = [];

    /** @var array<string, int> latitude => id of the postcode the nearest-postcode lookup gives for it */
    private array $postcodeIdByLat = [];

    /** @var array<int, string> */
    private array $logged = [];

    private LokiService|\Mockery\MockInterface $loki;

    protected function setUp(): void
    {
        parent::setUp();

        // ONE fake, because fakes merge and the first registered stub wins.
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/postcodes/knn')) {
                foreach ($this->postcodeIdByLat as $lat => $id) {
                    if (abs((float) $request['lat'] - (float) $lat) < 0.0001) {
                        return Http::response(['results' => [['id' => $id, 'distance' => 0]]], 200);
                    }
                }

                return Http::response(['results' => []], 200);
            }

            if ($this->failFromPage > 0 && (int) $request['page'] >= $this->failFromPage) {
                return Http::response('Internal Server Error', 500);
            }

            return Http::response(['changes' => $this->changes], 200);
        });

        $this->loki = Mockery::mock(LokiService::class);
        $this->loki->shouldReceive('logEvent')->andReturnUsing(function ($source, $event, $ctx = []) {
            $this->lokiEvents[] = [$event, $ctx];
        });

        Log::listen(function (MessageLogged $event) {
            $this->logged[] = $event->message;
        });
    }

    private function sync(int $userId, string $username, bool $dryRun = false): void
    {
        $this->syncChanges([[
            'fd_user_id' => $userId,
            'username'   => $username,
            'date'       => '2026-10-01T10:00:00',
        ]], $dryRun);
    }

    /** @param array<int, array> $changes the feed's changes, in the order the feed gives them */
    private function syncChanges(array $changes, bool $dryRun = false): void
    {
        $this->changes = $changes;

        (new UserChangesSyncer($dryRun, false, 'test-key', self::API_BASE, $this->loki, new TrashNothingRateLimiter(0)))
            ->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');
    }

    private function username(string $prefix): string
    {
        return $prefix . random_int(100000, 999999);
    }

    /**
     * @param array<int, array{string, int}> $emails [address, preferred]
     */
    private function tnUser(string $fullname, array $emails): User
    {
        $user = User::create(['fullname' => $fullname, 'added' => now()]);

        foreach ($emails as [$email, $preferred]) {
            UserEmail::create(['userid' => $user->id, 'email' => $email, 'preferred' => $preferred]);
        }

        return $user->fresh();
    }

    /** @return array<string, int> address => preferred */
    private function emailsOf(User $user): array
    {
        $emails = UserEmail::where('userid', $user->id)->pluck('preferred', 'email')->map(fn ($p) => (int) $p)->all();
        ksort($emails);

        return $emails;
    }

    private function renameEvents(): array
    {
        return array_values(array_map(
            fn ($e) => $e[1],
            array_filter($this->lokiEvents, fn ($e) => $e[0] === 'user-email-rename'),
        ));
    }

    public function test_prettified_fullname_with_unchanged_username_is_not_renamed(): void
    {
        $old = $this->username('tricia.hayes');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1]]);

        $this->sync($user->id, $old);

        $this->assertSame(User::tnDisplayName($old), $user->fresh()->fullname);
        $this->assertSame([$alias => 1], $this->emailsOf($user));
        $this->assertSame([], $this->renameEvents());
        $this->assertEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[NAME-CHANGE]')));
    }

    public function test_username_differing_only_in_case_is_not_a_rename(): void
    {
        $old = $this->username('tricia.hayes');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1]]);

        $this->sync($user->id, strtoupper($old));

        $this->assertSame(User::tnDisplayName($old), $user->fresh()->fullname);
        $this->assertSame([$alias => 1], $this->emailsOf($user));
    }

    public function test_rename_moves_each_alias_to_the_new_username_and_keeps_the_preferred_one(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $user = $this->tnUser(User::tnDisplayName($old), [
            ["{$old}-g123" . self::TN_DOMAIN, 1],
            ["{$old}-g456" . self::TN_DOMAIN, 0],
        ]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($new), $user->fullname);
        $this->assertSame(
            ["{$new}-g123" . self::TN_DOMAIN => 1, "{$new}-g456" . self::TN_DOMAIN => 0],
            $this->emailsOf($user),
        );
        $this->assertTrue($user->isTN(), 'a preferred TN address must remain so isTN() holds');

        $events = $this->renameEvents();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing(
            ["{$old}-g123" . self::TN_DOMAIN, "{$old}-g456" . self::TN_DOMAIN],
            array_column($events, 'old_email'),
        );
        foreach ($events as $event) {
            $this->assertSame($user->id, $event['user_id']);
        }
    }

    public function test_rename_includes_a_bare_address(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $bare = User::tnEmailForUsername($old);
        $alias = "{$old}-g9" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$bare, 1], [$alias, 0]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $expected = [User::tnEmailForUsername($new) => 1, "{$new}-g9" . self::TN_DOMAIN => 0];
        ksort($expected);
        $this->assertSame($expected, $this->emailsOf($user));
        $this->assertTrue($user->isTN());
        $this->assertEqualsCanonicalizing([$bare, $alias], array_column($this->renameEvents(), 'old_email'));
    }

    public function test_rename_from_a_bare_address_alone(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $user = $this->tnUser(User::tnDisplayName($old), [[User::tnEmailForUsername($old), 1]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame([User::tnEmailForUsername($new) => 1], $this->emailsOf($user));
        $this->assertTrue($user->isTN());
    }

    public function test_rename_leaves_non_tn_addresses_alone(): void
    {
        $old = $this->username('bibiana');
        $new = $this->username('bibi');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $personal = "{$old}@example.com";
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1], [$personal, 0]]);

        $this->sync($user->id, $new);

        $expected = [
            $personal => 0,
            "{$new}-g123" . self::TN_DOMAIN => 1,
        ];
        ksort($expected);
        $this->assertSame($expected, $this->emailsOf($user));
        $this->assertSame([$alias], array_column($this->renameEvents(), 'old_email'));
    }

    /**
     * "bibiana-gomes" starts with "bibiana-g" but is a different username, so this account holds
     * two, and nothing on it says which of them renamed.
     */
    public function test_a_longer_username_with_the_same_start_is_a_second_username(): void
    {
        $old = $this->username('bibiana');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $longer = "{$old}-gomes-g4840" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1], [$longer, 0]]);
        $before = $this->emailsOf($user);

        $this->sync($user->id, $this->username('bibi'));

        $this->assertSame($before, $this->emailsOf($user->fresh()));
        $this->assertSame([], $this->renameEvents());
    }

    public function test_rename_onto_an_address_held_by_another_user_changes_nothing(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1]]);
        $other = $this->tnUser('Someone Else', [["{$new}-g123" . self::TN_DOMAIN, 1]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($old), $user->fullname);
        $this->assertSame([$alias => 1], $this->emailsOf($user));
        $this->assertSame(["{$new}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($other));
        $this->assertSame([], $this->renameEvents());
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[NAME-CHANGE]') && str_contains($m, 'email-clash')));
    }

    /** Binds a recorder as 'sentry' and returns it. */
    private function recordSentry(): object
    {
        $sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = (string) $message;
            }
        };
        $this->app->instance('sentry', $sentry);

        return $sentry;
    }

    /**
     * TN's change feed repeats a member's username on every change to their account, so a
     * rename that cannot be applied comes round again and again. It is worth one report,
     * not one for every event.
     */
    public function test_a_blocked_rename_is_reported_once_a_day_however_often_the_event_comes_round(): void
    {
        $sentry = $this->recordSentry();
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);
        $this->tnUser('Someone Else', [["{$new}-g123" . self::TN_DOMAIN, 1]]);

        $this->sync($user->id, $new);
        $this->sync($user->id, $new);
        $this->sync($user->id, $new);

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString("user {$user->id}", $sentry->calls[0]);

        // Every event is still logged.
        $this->assertCount(3, array_filter($this->logged, fn ($m) => str_contains($m, '[NAME-CHANGE]') && str_contains($m, 'email-clash')));
    }

    public function test_a_blocked_rename_for_another_member_is_reported_too(): void
    {
        $sentry = $this->recordSentry();
        $new = $this->username('new.name');
        $this->tnUser('Holder', [["{$new}-g123" . self::TN_DOMAIN, 1]]);

        foreach ([1, 2] as $i) {
            $old = $this->username('old.name');
            $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);
            $this->sync($user->id, $new);
        }

        $this->assertCount(2, $sentry->calls, json_encode($sentry->calls));
    }

    /**
     * The username becomes part of an address, and users_emails.email takes whatever it
     * is given. One that does not make a valid address is not written.
     */
    public function test_a_username_that_does_not_make_a_valid_address_changes_nothing(): void
    {
        $sentry = $this->recordSentry();
        $old = $this->username('old.name');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1]]);

        $this->sync($user->id, 'has a space');
        $this->sync($user->id, 'has a space');

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($old), $user->fullname);
        $this->assertSame([$alias => 1], $this->emailsOf($user));
        $this->assertSame([], $this->renameEvents());
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[NAME-CHANGE]') && str_contains($m, 'invalid-address')));
        $this->assertCount(1, $sentry->calls, 'reported once a day, like a clash');
    }

    // -------------------------------------------------------------------------
    // A window read again must not undo a newer change with an older one.
    // -------------------------------------------------------------------------

    /** @return array<string, int> address => users_emails.id */
    private function emailIdsOf(User $user): array
    {
        $ids = UserEmail::where('userid', $user->id)->pluck('id', 'email')->map(fn ($id) => (int) $id)->all();
        ksort($ids);

        return $ids;
    }

    /**
     * Two renames in one window, read again and again (tn:sync holds its stored date while a
     * feed is down). Applied in turn each time, the first would give the member the older
     * username and the second the newer, deleting and creating an address on every pass.
     */
    public function test_two_renames_read_again_leave_the_addresses_as_they_were(): void
    {
        $old = $this->username('old.name');
        $first = $this->username('first.name');
        $second = $this->username('second.name');
        $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);
        $changes = [
            ['fd_user_id' => $user->id, 'username' => $first, 'date' => '2026-10-01T10:00:00'],
            ['fd_user_id' => $user->id, 'username' => $second, 'date' => '2026-10-01T10:05:00'],
        ];

        $this->syncChanges($changes);

        $user = $user->fresh();
        $this->assertSame(["{$second}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($user));
        $idsAfterFirstRead = $this->emailIdsOf($user);
        $this->assertCount(2, $this->renameEvents(), 'each rename is made once');

        $this->syncChanges($changes);
        $this->syncChanges($changes);

        $user = $user->fresh();
        $this->assertSame(["{$second}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($user));
        $this->assertSame($idsAfterFirstRead, $this->emailIdsOf($user), 'no address was deleted and made again');
        $this->assertSame(User::tnDisplayName($second), $user->fullname);
        $this->assertCount(2, $this->renameEvents(), 'reading the window again renames nothing');
    }

    /**
     * Changes are not always seen in the order they were made. The older one, arriving after
     * the newer was applied, is not the member's username.
     */
    public function test_an_older_rename_seen_after_a_newer_one_is_not_applied(): void
    {
        $old = $this->username('old.name');
        $first = $this->username('first.name');
        $second = $this->username('second.name');
        $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);

        $this->syncChanges([
            ['fd_user_id' => $user->id, 'username' => $second, 'date' => '2026-10-01T10:05:00'],
            ['fd_user_id' => $user->id, 'username' => $first, 'date' => '2026-10-01T10:00:00'],
        ]);

        $this->assertSame(["{$second}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($user->fresh()));
        $this->assertCount(1, $this->renameEvents());
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, 'action=superseded field=username')));
    }

    public function test_a_later_rename_than_the_one_applied_is_applied(): void
    {
        $old = $this->username('old.name');
        $first = $this->username('first.name');
        $second = $this->username('second.name');
        $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);

        $this->syncChanges([['fd_user_id' => $user->id, 'username' => $first, 'date' => '2026-10-01T10:00:00']]);
        $this->syncChanges([['fd_user_id' => $user->id, 'username' => $second, 'date' => '2026-10-01T10:05:00']]);

        $this->assertSame(["{$second}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($user->fresh()));
        $this->assertCount(2, $this->renameEvents());
    }

    /** A dry run applies nothing, so it must not make a real run's change look old. */
    public function test_a_dry_run_does_not_supersede_a_real_rename(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $user = $this->tnUser(User::tnDisplayName($old), [["{$old}-g123" . self::TN_DOMAIN, 1]]);

        $this->syncChanges([['fd_user_id' => $user->id, 'username' => $new, 'date' => '2026-10-01T10:05:00']], dryRun: true);
        $this->syncChanges([['fd_user_id' => $user->id, 'username' => $new, 'date' => '2026-10-01T10:00:00']]);

        $this->assertSame(["{$new}-g123" . self::TN_DOMAIN => 1], $this->emailsOf($user->fresh()));
    }

    private function postcode(int $id, string $name, float $lat, float $lng): void
    {
        DB::table('locations')->updateOrInsert(['id' => $id], [
            'name'     => $name,
            'type'     => 'Postcode',
            'lat'      => $lat,
            'lng'      => $lng,
            'geometry' => DB::raw(sprintf("ST_GeomFromText('POINT(%F %F)', %d)", $lng, $lat, (int) config('freegle.srid', 3857))),
        ]);
        $this->postcodeIdByLat[(string) $lat] = $id;
    }

    /** @return string[] the postcodes in the member's PostcodeChange log rows, oldest first */
    private function postcodeChangesOf(User $user): array
    {
        return DB::table('logs')
            ->where('user', $user->id)
            ->where('type', 'User')
            ->where('subtype', 'PostcodeChange')
            ->orderBy('id')
            ->pluck('text')
            ->all();
    }

    /**
     * Two moves in one window, read again and again. Applied in turn each time, the member
     * would be moved to the first postcode and then the second on every pass, with a log row
     * for each. Each move is logged once and the later one stands.
     */
    public function test_two_moves_read_again_are_logged_once_each(): void
    {
        $user = $this->tnUser('Pat', [[$this->username('pat') . self::TN_DOMAIN, 1]]);
        $this->postcode(99000301, 'AB1 1AA', 55.9533, -3.1883);
        $this->postcode(99000302, 'CD2 2BB', 51.5502, 0.1350);
        $changes = [
            ['fd_user_id' => $user->id, 'location' => ['latitude' => 55.9533, 'longitude' => -3.1883], 'date' => '2026-10-01T10:00:00'],
            ['fd_user_id' => $user->id, 'location' => ['latitude' => 51.5502, 'longitude' => 0.1350], 'date' => '2026-10-01T10:05:00'],
        ];

        $this->syncChanges($changes);
        $this->syncChanges($changes);
        $this->syncChanges($changes);

        $this->assertSame(['AB1 1AA', 'CD2 2BB'], $this->postcodeChangesOf($user));
        $this->assertSame(99000302, (int) $user->fresh()->lastlocation);
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, 'action=superseded field=location')));
    }

    public function test_an_older_move_seen_after_a_newer_one_is_not_applied(): void
    {
        $user = $this->tnUser('Pat', [[$this->username('pat') . self::TN_DOMAIN, 1]]);
        $this->postcode(99000301, 'AB1 1AA', 55.9533, -3.1883);
        $this->postcode(99000302, 'CD2 2BB', 51.5502, 0.1350);

        $this->syncChanges([
            ['fd_user_id' => $user->id, 'location' => ['latitude' => 51.5502, 'longitude' => 0.1350], 'date' => '2026-10-01T10:05:00'],
            ['fd_user_id' => $user->id, 'location' => ['latitude' => 55.9533, 'longitude' => -3.1883], 'date' => '2026-10-01T10:00:00'],
        ]);

        $this->assertSame(['CD2 2BB'], $this->postcodeChangesOf($user));
        $this->assertSame(99000302, (int) $user->fresh()->lastlocation);
    }

    public function test_dry_run_rename_writes_nothing(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $preferredAlias = "{$old}-g123" . self::TN_DOMAIN;
        $otherAlias = "{$old}-g456" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$preferredAlias, 1], [$otherAlias, 0]]);

        $this->sync($user->id, $new, dryRun: true);

        $this->assertSame(User::tnDisplayName($old), $user->fresh()->fullname);
        $this->assertSame([$preferredAlias => 1, $otherAlias => 0], $this->emailsOf($user));
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[WRITE] table=users_emails op=insert')));
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[WRITE] table=users_emails op=delete')));
    }

    // -------------------------------------------------------------------------
    // A page TN will not serve stops the run, and the run says so.
    // -------------------------------------------------------------------------

    private function syncer(): UserChangesSyncer
    {
        return new UserChangesSyncer(false, false, 'test-key', self::API_BASE, $this->loki, new TrashNothingRateLimiter(0));
    }

    /**
     * A full first page (the syncer asks for another when it gets 100), whose one real change
     * sets a TN member's reply time, so a test can see the page was applied.
     */
    private function fullPageWithOneReplyTime(User $user): void
    {
        $filler = array_fill(0, 99, ['fd_user_id' => null, 'date' => '2026-10-01T09:30:00']);

        $this->changes = array_merge($filler, [[
            'fd_user_id' => $user->id,
            'reply_time' => 3600,
            'date'       => '2026-10-01T10:00:00',
        ]]);
    }

    public function test_a_first_page_that_cannot_be_fetched_stops_the_run(): void
    {
        $this->failFromPage = 1;
        $this->changes = [['fd_user_id' => $this->createTestUser()->id, 'reply_time' => 60, 'date' => '2026-10-01T10:00:00']];
        $syncer = $this->syncer();

        [$count, $maxDate] = $syncer->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');

        $this->assertTrue($syncer->fetchFailed());
        $this->assertSame(0, $count);
        $this->assertNull($maxDate);
        Http::assertSentCount(1);
    }

    public function test_a_later_page_that_cannot_be_fetched_stops_the_run_after_the_pages_it_got(): void
    {
        $user = $this->tnUser('Pat', [[$this->username('pat') . self::TN_DOMAIN, 1]]);
        $this->fullPageWithOneReplyTime($user);
        $this->failFromPage = 2;
        $syncer = $this->syncer();

        [$count, $maxDate] = $syncer->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');

        $this->assertTrue($syncer->fetchFailed(), 'the caller is told the feed is incomplete');
        $this->assertSame(100, $count, 'page one was applied');
        $this->assertSame('2026-10-01T10:00:00', $maxDate);
        $this->assertSame(3600, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
        Http::assertSentCount(2);
    }

    public function test_a_run_that_reads_every_page_did_not_fail(): void
    {
        $this->failFromPage = 0;
        $this->changes = [['fd_user_id' => null, 'date' => '2026-10-01T10:00:00']];
        $syncer = $this->syncer();

        $syncer->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');

        $this->assertFalse($syncer->fetchFailed());
    }

    public function test_a_failure_does_not_carry_over_to_the_next_run(): void
    {
        $this->changes = [['fd_user_id' => null, 'date' => '2026-10-01T10:00:00']];
        $syncer = $this->syncer();

        $this->failFromPage = 1;
        $syncer->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');
        $this->assertTrue($syncer->fetchFailed());

        $this->failFromPage = 0;
        $syncer->sync('2026-10-01T09:00:00Z', '2026-10-01T11:00:00Z');
        $this->assertFalse($syncer->fetchFailed());
    }

    private function tnUserWithId(?int $tnUserId): User
    {
        $user = $this->tnUser('Test Member', [[$this->username('m') . '-g1' . self::TN_DOMAIN, 1]]);
        if ($tnUserId !== null) {
            $user->tnuserid = $tnUserId;
            $user->save();
        }

        return $user->fresh();
    }

    private function skipEvents(string $reason): array
    {
        return array_values(array_filter(
            $this->lokiEvents,
            fn ($e) => $e[0] === 'user-change-skip' && ($e[1]['reason'] ?? null) === $reason,
        ));
    }

    /**
     * A member the posts API provisioned was never added by TN, so TN does not know our id
     * for them and their changes come with only TN's. They must still reach the account.
     */
    public function test_a_change_without_our_id_reaches_the_member_holding_its_tn_id(): void
    {
        $user = $this->tnUserWithId(99130001);

        $this->syncChanges([[
            'tn_user_id' => 99130001,
            'reply_time' => 4321,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame(4321, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    public function test_a_removal_without_our_id_forgets_the_member_holding_its_tn_id(): void
    {
        $user = $this->tnUserWithId(99130002);

        $this->syncChanges([[
            'tn_user_id'      => 99130002,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Deleted User #' . $user->id, $user->fresh()->fullname);
    }

    /**
     * Two TN members on one Freegle account: TN names the account for both, but a removal or
     * rename by the one whose TN id is not the account's must not touch the other.
     */
    public function test_a_removal_for_another_tn_member_on_the_account_is_skipped(): void
    {
        $user = $this->tnUserWithId(99130003);

        $this->syncChanges([[
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130004,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Test Member', $user->fresh()->fullname, 'the account is not forgotten');
        $this->assertCount(1, $this->skipEvents('tn-user-id-mismatch'));
    }

    public function test_a_rename_for_another_tn_member_on_the_account_is_skipped(): void
    {
        $user = $this->tnUserWithId(99130005);
        $before = $this->emailsOf($user);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130006,
            'username'   => $this->username('other'),
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame($before, $this->emailsOf($user->fresh()), 'the addresses are not renamed');
        $this->assertCount(1, $this->skipEvents('tn-user-id-mismatch'));
    }

    /** TN has just said which of its members the account is, so an account with no TN id gets it. */
    public function test_an_account_without_a_tn_id_is_given_the_changes(): void
    {
        $user = $this->tnUserWithId(null);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130007,
            'reply_time' => 55,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame(99130007, (int) $user->fresh()->tnuserid);
        $this->assertSame(55, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    /**
     * Only TN's id says who someone is, so the account holding it is the member, whatever account
     * TN names. A partner call that moves an address off an account with no id leaves things this
     * way. The account named is left alone and reported: it may be the same member's old account.
     */
    public function test_a_change_whose_tn_id_another_account_holds_reaches_that_account(): void
    {
        $named = $this->tnUserWithId(null);
        $holder = $this->tnUserWithId(99130008);

        $this->syncChanges([[
            'fd_user_id'      => $named->id,
            'tn_user_id'      => 99130008,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Test Member', $named->fresh()->fullname);
        $this->assertSame('Deleted User #' . $holder->id, $holder->fresh()->fullname);
        $this->assertNull($named->fresh()->tnuserid);
        $this->assertCount(1, $this->skipEvents('tn-user-id-held-elsewhere'));
    }

    public function test_a_rename_whose_tn_id_another_account_holds_reaches_that_account(): void
    {
        $named = $this->tnUserWithId(null);
        $holder = $this->tnUserWithId(99130040);
        $namedBefore = $this->emailsOf($named);
        $new = $this->username('renamed');

        $this->syncChanges([[
            'fd_user_id' => $named->id,
            'tn_user_id' => 99130040,
            'username'   => $new,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame([$new], $holder->fresh()->tnUsernames());
        $this->assertSame($namedBefore, $this->emailsOf($named->fresh()));
    }

    /** A partner call took the only address the account had, so it no longer looks like TN's. */
    public function test_a_removal_naming_an_account_that_lost_its_only_address_reaches_the_member(): void
    {
        $sentry = $this->recordSentry();
        $old = User::create(['fullname' => 'Old Account', 'added' => now()]);
        $member = $this->tnUserWithId(99130041);

        $this->syncChanges([[
            'fd_user_id'      => $old->id,
            'tn_user_id'      => 99130041,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Deleted User #' . $member->id, $member->fresh()->fullname);
        $this->assertSame('Old Account', $old->fresh()->fullname);
        $this->assertCount(1, $this->skipEvents('tn-user-id-held-elsewhere'), 'the old account is reported, not dropped in silence');
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
    }

    /** Another member took the bare address this account had. Its TN id still says who it is. */
    public function test_a_removal_reaches_the_account_holding_the_id_when_it_has_no_address_left(): void
    {
        $member = User::create(['fullname' => 'Test Member', 'added' => now(), 'tnuserid' => 99130042]);

        $this->syncChanges([[
            'fd_user_id'      => $member->id,
            'tn_user_id'      => 99130042,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Deleted User #' . $member->id, $member->fresh()->fullname);
    }

    /** Leaving TN says nothing about their Freegle account, so a volunteer's is not forgotten. */
    public function test_a_removal_leaves_a_freegle_account_holding_the_id_alone(): void
    {
        $user = $this->tnUser('Test Member', [[$this->username('vol') . '@example.org', 1]]);
        $user->tnuserid = 99130043;
        $user->save();

        $this->syncChanges([[
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130043,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Test Member', $user->fresh()->fullname);
    }

    /** users.tnuserid is unique, so a deleted account given it would keep the member from any other. */
    public function test_a_deleted_account_is_not_given_a_tn_id(): void
    {
        $user = $this->tnUserWithId(null);
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130044,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertNull($user->fresh()->tnuserid);
    }

    public function test_a_dry_run_does_not_give_an_account_a_tn_id(): void
    {
        $user = $this->tnUserWithId(null);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130009,
            'date'       => '2026-10-01T10:00:00',
        ]], dryRun: true);

        $this->assertNull($user->fresh()->tnuserid);
    }

    /** A merge deletes the account, and TN goes on naming it until it next adds the member. */
    public function test_a_change_naming_an_account_that_has_gone_reaches_the_member_holding_its_tn_id(): void
    {
        $user = $this->tnUserWithId(99130010);
        $gone = (int) DB::table('users')->max('id') + 100000;

        $this->syncChanges([[
            'fd_user_id' => $gone,
            'tn_user_id' => 99130010,
            'reply_time' => 66,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame(66, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    /** tn:split-shared-account does not tell TN, so TN goes on naming the old account. */
    public function test_a_change_naming_the_account_a_member_was_split_from_reaches_them(): void
    {
        $old = $this->tnUserWithId(99130011);
        $split = $this->tnUserWithId(99130012);

        $this->syncChanges([[
            'fd_user_id'      => $old->id,
            'tn_user_id'      => 99130012,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Deleted User #' . $split->id, $split->fresh()->fullname);
        $this->assertSame('Test Member', $old->fresh()->fullname);
        $this->assertCount(0, $this->skipEvents('tn-user-id-mismatch'));
    }

    /**
     * @return array{User, string, string} the account, the username of its preferred address,
     *                                     and the other username
     */
    private function accountWithTwoUsernames(?int $tnUserId): array
    {
        $preferred = $this->username('pref');
        $other = $this->username('other');
        $user = $this->tnUser('Test Member', [
            ["{$preferred}-g1" . self::TN_DOMAIN, 1],
            ["{$other}-g2" . self::TN_DOMAIN, 0],
        ]);

        if ($tnUserId !== null) {
            $user->tnuserid = $tnUserId;
            $user->save();
        }

        return [$user->fresh(), $preferred, $other];
    }

    /**
     * The account's TN id says who one of its members is. Forgetting the account would take the
     * other member with it, and the two usernames may be one member who renamed: a person has
     * to say, and the feed will not bring this change round again.
     */
    public function test_a_removal_on_an_account_with_two_usernames_is_skipped_and_reported(): void
    {
        $sentry = $this->recordSentry();
        [$user] = $this->accountWithTwoUsernames(99130013);
        $before = $this->emailsOf($user);

        $this->syncChanges([[
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130013,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Test Member', $user->fresh()->fullname);
        $this->assertSame($before, $this->emailsOf($user->fresh()));
        $this->assertCount(1, $this->skipEvents('tn-two-usernames'));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString("user {$user->id}", $sentry->calls[0]);
    }

    /** With no TN id on the account, the first removal seen would be taken for its owner's. */
    public function test_a_removal_on_an_account_with_two_usernames_and_no_tn_id_is_skipped(): void
    {
        [$user] = $this->accountWithTwoUsernames(null);

        $this->syncChanges([[
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130014,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame('Test Member', $user->fresh()->fullname);
        $this->assertNull($user->fresh()->tnuserid);
        $this->assertCount(1, $this->skipEvents('tn-two-usernames'));
    }

    /** The id could be either member's, so it is not stamped. The change's other fields still apply. */
    public function test_an_account_with_two_usernames_is_not_given_a_tn_id(): void
    {
        [$user] = $this->accountWithTwoUsernames(null);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130015,
            'reply_time' => 77,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertNull($user->fresh()->tnuserid);
        $this->assertSame(77, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    /**
     * The old username is read from the preferred address, which on this account may be the
     * other member's, so a rename would land on their addresses and leave the renamer's as
     * they were.
     */
    public function test_a_rename_on_an_account_with_two_usernames_changes_nothing_and_is_reported(): void
    {
        $sentry = $this->recordSentry();
        [$user] = $this->accountWithTwoUsernames(99130016);
        $before = $this->emailsOf($user);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130016,
            'username'   => $this->username('new.name'),
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame($before, $this->emailsOf($user->fresh()));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
    }

    /**
     * Every change repeats the member's username. On this account that is not a rename at all
     * when it is the other username, and acting on it would rewrite the preferred address.
     */
    public function test_a_change_repeating_the_other_username_does_not_rewrite_the_preferred_addresses(): void
    {
        [$user, , $other] = $this->accountWithTwoUsernames(99130017);
        $before = $this->emailsOf($user);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130017,
            'username'   => $other,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertSame($before, $this->emailsOf($user->fresh()));
    }

    /** A window is read more than once, so a skipped removal comes round again. */
    public function test_a_skipped_removal_is_reported_once_however_often_it_comes_round(): void
    {
        $sentry = $this->recordSentry();
        $user = $this->tnUserWithId(99130018);
        $change = [
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130019,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ];

        $this->syncChanges([$change]);
        $this->syncChanges([$change]);

        $this->assertSame('Test Member', $user->fresh()->fullname);
        $this->assertCount(2, $this->skipEvents('tn-user-id-mismatch'));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('99130019', $sentry->calls[0]);
    }

    public function test_a_removal_whose_tn_id_another_account_holds_is_reported(): void
    {
        $sentry = $this->recordSentry();
        $named = $this->tnUserWithId(null);
        $this->tnUserWithId(99130020);

        $this->syncChanges([[
            'fd_user_id'      => $named->id,
            'tn_user_id'      => 99130020,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ]]);

        $this->assertCount(1, $this->skipEvents('tn-user-id-held-elsewhere'));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
    }

    /** Only a removal is lost for good by being skipped, so only a removal needs a person. */
    public function test_a_skipped_change_that_is_not_a_removal_is_not_sent_to_sentry(): void
    {
        $sentry = $this->recordSentry();
        $user = $this->tnUserWithId(99130021);

        $this->syncChanges([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130022,
            'reply_time' => 88,
            'date'       => '2026-10-01T10:00:00',
        ]]);

        $this->assertCount(1, $this->skipEvents('tn-user-id-mismatch'));
        $this->assertSame([], $sentry->calls);
    }

    /** A dry run applies nothing, so it must not use up the report the real run will need. */
    public function test_a_dry_run_does_not_report_a_skipped_removal(): void
    {
        $sentry = $this->recordSentry();
        $user = $this->tnUserWithId(99130023);
        $change = [
            'fd_user_id'      => $user->id,
            'tn_user_id'      => 99130024,
            'account_removed' => true,
            'date'            => '2026-10-01T10:00:00',
        ];

        $this->syncChanges([$change], dryRun: true);
        $this->assertSame([], $sentry->calls);

        $this->syncChanges([$change]);
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
    }

    /** A change that fails before its member is known must not stop the ones after it. */
    public function test_a_change_that_fails_while_finding_its_member_does_not_stop_the_sync(): void
    {
        $user = $this->tnUserWithId(99130025);
        $reads = 0;
        User::retrieved(function () use (&$reads) {
            if (++$reads === 1) {
                throw new \RuntimeException('read failed');
            }
        });

        $this->syncChanges([
            ['fd_user_id' => $user->id, 'tn_user_id' => 99130025, 'reply_time' => 11, 'date' => '2026-10-01T10:00:00'],
            ['fd_user_id' => $user->id, 'tn_user_id' => 99130025, 'reply_time' => 22, 'date' => '2026-10-01T10:00:01'],
        ]);

        $this->assertSame(22, (int) UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    /** @return array<string, int> */
    private function backfill(array $changes, bool $dryRun = false): array
    {
        $this->changes = $changes;

        return (new UserChangesSyncer($dryRun, false, 'test-key', self::API_BASE, $this->loki, new TrashNothingRateLimiter(0)))
            ->backfillTnUserIds('2026-01-01T00:00:00Z', '2026-10-01T11:00:00Z');
    }

    /** It reads old changes, so it must apply none of them: a removal here would be months late. */
    public function test_the_backfill_stamps_an_account_and_applies_nothing_else_from_the_change(): void
    {
        $user = $this->tnUserWithId(null);
        $before = $this->emailsOf($user);
        $change = ['fd_user_id' => $user->id, 'tn_user_id' => 99130026, 'date' => '2026-02-01T10:00:00'];

        $counts = $this->backfill([
            $change + ['username' => $user->tnUsernames()[0]],
            $change + ['account_removed' => true, 'username' => $this->username('renamed'), 'reply_time' => 99],
        ]);

        $this->assertSame(1, $counts['stamped']);
        $this->assertSame(99130026, (int) $user->fresh()->tnuserid);
        $this->assertSame('Test Member', $user->fresh()->fullname, 'the removal is not applied');
        $this->assertSame($before, $this->emailsOf($user->fresh()), 'the rename is not applied');
        $this->assertNull(UserReplyTime::where('userid', $user->id)->value('replytime'));
    }

    public function test_the_backfill_counts_an_account_once_however_many_changes_name_it(): void
    {
        $user = $this->tnUserWithId(null);
        $change = ['fd_user_id' => $user->id, 'tn_user_id' => 99130027, 'date' => '2026-02-01T10:00:00'];

        $counts = $this->backfill([$change, $change], dryRun: true);

        $this->assertSame(1, $counts['stamped']);
    }

    public function test_the_backfill_leaves_an_account_with_two_usernames_alone(): void
    {
        [$user] = $this->accountWithTwoUsernames(null);

        $counts = $this->backfill([['fd_user_id' => $user->id, 'tn_user_id' => 99130028, 'date' => '2026-02-01T10:00:00']]);

        $this->assertSame(1, $counts['two-usernames']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    /** TN named the account, but with no TN address on it nothing shows TN added this one. */
    public function test_the_backfill_leaves_an_account_with_no_tn_address_alone(): void
    {
        $user = $this->tnUser('Test Member', [[$this->username('plain') . '@example.com', 1]]);

        $counts = $this->backfill([['fd_user_id' => $user->id, 'tn_user_id' => 99130029, 'date' => '2026-02-01T10:00:00']]);

        $this->assertSame(1, $counts['no-tn-address']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    public function test_the_backfill_leaves_an_id_another_account_holds(): void
    {
        $named = $this->tnUserWithId(null);
        $this->tnUserWithId(99130030);

        $counts = $this->backfill([['fd_user_id' => $named->id, 'tn_user_id' => 99130030, 'date' => '2026-02-01T10:00:00']]);

        $this->assertSame(1, $counts['held-elsewhere']);
        $this->assertNull($named->fresh()->tnuserid);
    }

    public function test_the_backfill_leaves_an_account_that_already_holds_an_id(): void
    {
        $same = $this->tnUserWithId(99130031);
        $other = $this->tnUserWithId(99130032);

        $counts = $this->backfill([
            ['fd_user_id' => $same->id, 'tn_user_id' => 99130031, 'date' => '2026-02-01T10:00:00'],
            ['fd_user_id' => $other->id, 'tn_user_id' => 99130033, 'date' => '2026-02-01T10:00:00'],
        ]);

        $this->assertSame(2, $counts['already-has-an-id']);
        $this->assertSame(99130032, (int) $other->fresh()->tnuserid);
    }

    /** Both ids are needed: one alone does not link anything to anything. */
    public function test_the_backfill_ignores_a_change_with_only_one_id(): void
    {
        $user = $this->tnUserWithId(null);

        $counts = $this->backfill([
            ['tn_user_id' => 99130034, 'date' => '2026-02-01T10:00:00'],
            ['fd_user_id' => $user->id, 'date' => '2026-02-01T10:00:00'],
        ]);

        $this->assertSame(0, $counts['stamped']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    public function test_a_dry_run_backfill_counts_what_it_would_stamp_and_writes_nothing(): void
    {
        $user = $this->tnUserWithId(null);

        $counts = $this->backfill([['fd_user_id' => $user->id, 'tn_user_id' => 99130035, 'date' => '2026-02-01T10:00:00']], dryRun: true);

        $this->assertSame(1, $counts['stamped']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    public function test_the_backfill_stops_at_a_page_it_cannot_read(): void
    {
        $this->failFromPage = 1;
        $syncer = new UserChangesSyncer(false, false, 'test-key', self::API_BASE, $this->loki, new TrashNothingRateLimiter(0));

        $syncer->backfillTnUserIds('2026-01-01T00:00:00Z', '2026-10-01T11:00:00Z');

        $this->assertTrue($syncer->fetchFailed());
    }

    /** A page it never read could name the account again with another id, so it stamps nothing. */
    public function test_the_backfill_writes_nothing_when_a_later_page_cannot_be_read(): void
    {
        $user = $this->tnUserWithId(null);
        $this->failFromPage = 2;
        $this->changes = array_fill(0, 100, ['fd_user_id' => $user->id, 'tn_user_id' => 99130045, 'date' => '2026-02-01T10:00:00']);
        $syncer = new UserChangesSyncer(false, false, 'test-key', self::API_BASE, $this->loki, new TrashNothingRateLimiter(0));

        $counts = $syncer->backfillTnUserIds('2026-01-01T00:00:00Z', '2026-10-01T11:00:00Z');

        $this->assertTrue($syncer->fetchFailed());
        $this->assertSame(0, $counts['stamped']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    /** Two members named on one account is the clearest sign in the feed that it is shared. */
    public function test_the_backfill_leaves_an_account_two_tn_ids_name(): void
    {
        $user = $this->tnUserWithId(null);

        $counts = $this->backfill([
            ['fd_user_id' => $user->id, 'tn_user_id' => 99130046, 'date' => '2026-02-01T10:00:00'],
            ['fd_user_id' => $user->id, 'tn_user_id' => 99130047, 'date' => '2026-02-02T10:00:00'],
        ]);

        $this->assertSame(1, $counts['two-tn-ids']);
        $this->assertSame(0, $counts['stamped']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    /** Stamping either would be a guess, and the dry run would count both as stamped. */
    public function test_the_backfill_leaves_a_tn_id_named_on_two_accounts(): void
    {
        $first = $this->tnUserWithId(null);
        $second = $this->tnUserWithId(null);

        $counts = $this->backfill([
            ['fd_user_id' => $first->id, 'tn_user_id' => 99130048, 'date' => '2026-02-01T10:00:00'],
            ['fd_user_id' => $second->id, 'tn_user_id' => 99130048, 'date' => '2026-02-02T10:00:00'],
        ]);

        $this->assertSame(2, $counts['two-accounts']);
        $this->assertNull($first->fresh()->tnuserid);
        $this->assertNull($second->fresh()->tnuserid);
    }

    /** A merge removes the account, and TN goes on naming it in old changes. */
    public function test_the_backfill_ignores_an_account_that_has_gone_when_counting_accounts(): void
    {
        $user = $this->tnUserWithId(null);
        $gone = (int) DB::table('users')->max('id') + 100000;

        $counts = $this->backfill([
            ['fd_user_id' => $gone, 'tn_user_id' => 99130049, 'date' => '2026-02-01T10:00:00'],
            ['fd_user_id' => $user->id, 'tn_user_id' => 99130049, 'date' => '2026-02-02T10:00:00'],
        ]);

        $this->assertSame(1, $counts['stamped']);
        $this->assertSame(99130049, (int) $user->fresh()->tnuserid);
    }

    /** A username may only refuse something, and this is a refusal. */
    public function test_the_backfill_leaves_an_account_whose_username_the_feed_never_gave(): void
    {
        $user = $this->tnUserWithId(null);

        $counts = $this->backfill([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130050,
            'username'   => $this->username('someoneelse'),
            'date'       => '2026-02-01T10:00:00',
        ]]);

        $this->assertSame(1, $counts['username-differs']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    public function test_the_backfill_matches_a_username_whatever_its_case(): void
    {
        $user = $this->tnUserWithId(null);

        $counts = $this->backfill([[
            'fd_user_id' => $user->id,
            'tn_user_id' => 99130051,
            'username'   => strtoupper($user->tnUsernames()[0]),
            'date'       => '2026-02-01T10:00:00',
        ]]);

        $this->assertSame(1, $counts['stamped']);
    }

    /** users.tnuserid is unique, so this would hold the id away from the member until forgotten. */
    public function test_the_backfill_leaves_a_deleted_account(): void
    {
        $user = $this->tnUserWithId(null);
        DB::table('users')->where('id', $user->id)->update(['deleted' => now()]);

        $counts = $this->backfill([['fd_user_id' => $user->id, 'tn_user_id' => 99130052, 'date' => '2026-02-01T10:00:00']]);

        $this->assertSame(1, $counts['deleted']);
        $this->assertNull($user->fresh()->tnuserid);
    }

    /** A TN id makes an account a TN account, and a TN account cannot be a moderator. */
    public function test_the_backfill_leaves_a_freegle_account_with_one_tn_alias(): void
    {
        $user = $this->tnUser('Volunteer', [
            [$this->username('vol') . '@example.org', 1],
            [$this->username('tn') . '-g1' . self::TN_DOMAIN, 0],
        ]);

        $counts = $this->backfill([['fd_user_id' => $user->id, 'tn_user_id' => 99130053, 'date' => '2026-02-01T10:00:00']]);

        $this->assertSame(1, $counts['not-a-tn-account']);
        $this->assertNull($user->fresh()->tnuserid);
    }
}

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

    public function test_rename_leaves_non_tn_and_other_usernames_addresses_alone(): void
    {
        // "bibiana-gomes" starts with "bibiana-g" but is a different username.
        $old = $this->username('bibiana');
        $new = $this->username('bibi');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $longer = "{$old}-gomes-g4840" . self::TN_DOMAIN;
        $personal = "{$old}@example.com";
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1], [$longer, 0], [$personal, 0]]);

        $this->sync($user->id, $new);

        $expected = [
            $longer => 0,
            $personal => 0,
            "{$new}-g123" . self::TN_DOMAIN => 1,
        ];
        ksort($expected);
        $this->assertSame($expected, $this->emailsOf($user));
        $this->assertSame([$alias], array_column($this->renameEvents(), 'old_email'));
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
}

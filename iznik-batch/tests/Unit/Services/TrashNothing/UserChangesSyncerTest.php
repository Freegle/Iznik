<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use App\Services\LokiService;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use App\Services\TrashNothing\Sync\UserChangesSyncer;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * Username handling in the user-changes sync. The old username comes from the
 * member's TN address, not from fullname, because fullname is the prettified
 * display name ("Tricia Hayes") and never equals the raw username.
 */
class UserChangesSyncerTest extends TestCase
{
    private const API_BASE = 'https://example.test/fd/api';

    private const TN_DOMAIN = '@user.trashnothing.com';

    /** @var array<int, array> */
    private array $changes = [];

    /** @var array<int, array{string, array}> */
    private array $lokiEvents = [];

    /** @var array<int, string> */
    private array $logged = [];

    private LokiService|\Mockery\MockInterface $loki;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn () => Http::response(['changes' => $this->changes], 200));

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
        $this->changes = [[
            'fd_user_id' => $userId,
            'username'   => $username,
            'date'       => '2026-10-01T10:00:00',
        ]];

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
        return UserEmail::where('userid', $user->id)->pluck('preferred', 'email')->map(fn ($p) => (int) $p)->all();
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

    public function test_rename_collapses_suffixed_aliases_to_one_preferred_bare_address(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $preferredAlias = "{$old}-g123" . self::TN_DOMAIN;
        $otherAlias = "{$old}-g456" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$preferredAlias, 1], [$otherAlias, 0]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($new), $user->fullname);
        $this->assertSame([User::tnEmailForUsername($new) => 1], $this->emailsOf($user));
        $this->assertTrue($user->isTN(), 'the bare address must stay preferred so isTN() holds');

        $events = $this->renameEvents();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing([$preferredAlias, $otherAlias], array_column($events, 'old_email'));
        foreach ($events as $event) {
            $this->assertSame($user->id, $event['user_id']);
            $this->assertSame(User::tnEmailForUsername($new), $event['new_email']);
        }
    }

    public function test_rename_from_a_bare_address(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $bare = User::tnEmailForUsername($old);
        $user = $this->tnUser(User::tnDisplayName($old), [[$bare, 1]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($new), $user->fullname);
        $this->assertSame([User::tnEmailForUsername($new) => 1], $this->emailsOf($user));
        $this->assertTrue($user->isTN());
        $this->assertSame([$bare], array_column($this->renameEvents(), 'old_email'));
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
            $longer                        => 0,
            $personal                      => 0,
            User::tnEmailForUsername($new) => 1,
        ];
        $actual = $this->emailsOf($user);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
        $this->assertSame([$alias], array_column($this->renameEvents(), 'old_email'));
    }

    public function test_rename_onto_an_address_held_by_another_user_leaves_addresses_untouched(): void
    {
        $old = $this->username('old.name');
        $new = $this->username('new.name');
        $alias = "{$old}-g123" . self::TN_DOMAIN;
        $user = $this->tnUser(User::tnDisplayName($old), [[$alias, 1]]);
        $other = $this->tnUser('Someone Else', [[User::tnEmailForUsername($new), 1]]);

        $this->sync($user->id, $new);

        $user = $user->fresh();
        $this->assertSame(User::tnDisplayName($new), $user->fullname);
        $this->assertSame([$alias => 1], $this->emailsOf($user));
        $this->assertSame([User::tnEmailForUsername($new) => 1], $this->emailsOf($other));
        $this->assertSame([], $this->renameEvents());
        $this->assertNotEmpty(array_filter($this->logged, fn ($m) => str_contains($m, '[NAME-CHANGE]') && str_contains($m, 'email-clash')));
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
}

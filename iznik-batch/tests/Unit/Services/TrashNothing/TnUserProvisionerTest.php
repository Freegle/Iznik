<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\User;
use App\Models\UserEmail;
use App\Services\LokiService;
use App\Services\TrashNothing\Ingestion\TnUserProvisioner;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use OpenAPI\Client\Configuration;
use Tests\TestCase;

class TnUserProvisionerTest extends TestCase
{
    private const API_KEY = 'secret-public-key-1234';

    /**
     * TN's answer per user id: [status, body] or a callable run in place of a
     * response. ONE Http::fake reads this, because fakes merge and the first
     * registered stub wins (see .claude/rules/laravel-batch-traps.md).
     *
     * @var array<int, array{int, mixed}|callable>
     */
    private array $tnUsers = [];

    /** @var array<int, MessageLogged> */
    private array $logged = [];

    private LokiService $loki;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim(Configuration::getDefaultConfiguration()->getHost(), '/');

        $this->loki = Mockery::mock(LokiService::class)->shouldIgnoreMissing();

        Http::fake(function (Request $request) {
            if (!preg_match('#^' . preg_quote($this->baseUrl, '#') . '/users/(\d+)\?#', $request->url(), $m)) {
                return Http::response('not found', 404);
            }

            $answer = $this->tnUsers[(int) $m[1]] ?? [404, 'User does not exist.'];
            if (is_callable($answer)) {
                return $answer($request);
            }

            [$status, $body] = $answer;

            return Http::response(
                is_array($body) ? json_encode($body) : $body,
                $status,
                ['Content-Type' => is_array($body) ? 'application/json' : 'text/html'],
            );
        });

        Log::listen(function (MessageLogged $event) {
            $this->logged[] = $event;
        });
    }

    private function provisioner(bool $dryRun = false, bool $localTesting = false): TnUserProvisioner
    {
        return new TnUserProvisioner(
            dryRun: $dryRun,
            localTesting: $localTesting,
            publicApiKey: self::API_KEY,
            loki: $this->loki,
            rateLimiter: new TrashNothingRateLimiter(0),
        );
    }

    private function tnId(): int
    {
        return random_int(900_000_000, 999_999_999);
    }

    private function uniqueUsername(string $prefix): string
    {
        return $prefix . random_int(100000, 999999);
    }

    private function tnUser(string $username, array $overrides = []): array
    {
        return array_merge([
            'user_id'       => '1',
            'username'      => $username,
            'country'       => 'GB',
            'profile_image' => null,
            'member_since'  => '2020-01-01T00:00:00Z',
            'firstname'     => null,
            'lastname'      => null,
            'reply_time'    => null,
            'about_me'      => null,
        ], $overrides);
    }

    private function lookupsFor(int $tnId): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), "/users/{$tnId}?"))->count();
    }

    public function test_existing_tnuserid_makes_no_http_call(): void
    {
        $tnId = $this->tnId();
        $user = $this->createTestUser(['tnuserid' => $tnId]);

        $result = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertSame($user->id, $result->id);
        Http::assertNothingSent();
    }

    public function test_creates_user_from_full_response(): void
    {
        $tnId     = $this->tnId();
        $username = 'tricia.hayes';
        $this->tnUsers[$tnId] = [200, $this->tnUser($username, ['firstname' => 'Tricia', 'lastname' => 'Hayes'])];

        $this->loki->shouldReceive('logEvent')->once()->with('tn-sync', 'user-create-from-tn', Mockery::on(
            fn ($ctx) => $ctx['tn_user_id'] === $tnId && is_int($ctx['user_id']) && $ctx['linked_existing'] === false
        ));

        $provisioner = $this->provisioner();
        $user        = $provisioner->resolveOrCreate($tnId);

        $this->assertNotNull($user);
        $this->assertNull($provisioner->lastFailureReason());
        $user = $user->fresh();
        $this->assertSame('Tricia Hayes', $user->fullname);
        $this->assertSame('Tricia', $user->firstname);
        $this->assertSame('Hayes', $user->lastname);
        $this->assertSame($tnId, (int) $user->tnuserid);
        $this->assertSame('tricia.hayes@user.trashnothing.com', $user->email_preferred);
        $this->assertTrue($user->isTN());

        Http::assertSent(fn (Request $r) => $r->url() === $this->baseUrl . "/users/{$tnId}?api_key=" . self::API_KEY);
    }

    public function test_null_first_and_last_names_are_left_unset(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [200, $this->tnUser($this->uniqueUsername('nonames'))];

        $user = $this->provisioner()->resolveOrCreate($tnId)->fresh();

        $this->assertNull($user->firstname);
        $this->assertNull($user->lastname);
    }

    /**
     * Every user-level field the email subscribe path and Go's CreatePartnerUser
     * write, and none of their membership side effects.
     */
    public function test_field_parity_with_existing_creation_paths(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('parity_check.');
        $email    = "{$username}@user.trashnothing.com";
        $this->tnUsers[$tnId] = [200, $this->tnUser($username, [
            'firstname'  => 'Pat',
            'lastname'   => 'Rity',
            'about_me'   => 'I like giving things away.',
            'reply_time' => 0,
        ])];

        $user = $this->provisioner()->resolveOrCreate($tnId);
        $row  = DB::table('users')->where('id', $user->id)->first();

        $this->assertSame(User::tnDisplayName($username), $row->fullname);
        $this->assertSame('Pat', $row->firstname);
        $this->assertSame('Rity', $row->lastname);
        $this->assertSame('User', $row->systemrole);
        $this->assertNotNull($row->added);
        $this->assertNotNull($row->lastaccess);
        $this->assertSame($tnId, (int) $row->tnuserid);
        $this->assertNull($row->lastlocation);
        $this->assertNull($row->settings);
        $this->assertNull($row->source);
        $this->assertNull($row->deleted);

        $emails = DB::table('users_emails')->where('userid', $user->id)->get();
        $this->assertCount(1, $emails);
        $this->assertSame($email, $emails[0]->email);
        $this->assertSame(1, (int) $emails[0]->preferred);
        $this->assertNotNull($emails[0]->added);
        $this->assertSame(User::canonMail($email), $emails[0]->canon);
        $this->assertSame(strrev(User::canonMail($email)), $emails[0]->backwards);

        $this->assertSame(
            'I like giving things away.',
            DB::table('users_aboutme')->where('userid', $user->id)->value('text'),
        );
        $replyTime = DB::table('users_replytime')->where('userid', $user->id)->first();
        $this->assertNotNull($replyTime, 'a reply time of 0 is legitimate and is stored');
        $this->assertSame(0, (int) $replyTime->replytime);

        $this->assertSame(0, DB::table('memberships')->where('userid', $user->id)->count());
        $this->assertSame(0, DB::table('memberships_history')->where('userid', $user->id)->count());
        $this->assertSame(0, DB::table('logs')->where('user', $user->id)->where('type', 'Group')->count());
    }

    public function test_links_existing_alias_account_instead_of_duplicating(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('linkme');
        $alias    = "{$username}-g123@user.trashnothing.com";
        $existing = $this->createTestUser(['email_preferred' => $alias, 'fullname' => 'Link Me']);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $this->loki->shouldReceive('logEvent')->once()->with('tn-sync', 'user-create-from-tn', Mockery::on(
            fn ($ctx) => $ctx['user_id'] === $existing->id && $ctx['linked_existing'] === true
        ));

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame($tnId, (int) $existing->fresh()->tnuserid);
        $this->assertSame(1, User::where('tnuserid', $tnId)->count());
        $this->assertSame('Link Me', $existing->fresh()->fullname, 'an existing name is not overwritten');

        $emails = UserEmail::where('userid', $existing->id)->pluck('preferred', 'email');
        $this->assertSame(1, (int) $emails[$alias], 'the current preferred address stays preferred');
        $this->assertSame(0, (int) $emails["{$username}@user.trashnothing.com"]);
    }

    public function test_links_existing_bare_address_account(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('bare');
        $existing = $this->createTestUser(['email_preferred' => "{$username}@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame($tnId, (int) $existing->fresh()->tnuserid);
        $this->assertSame(1, UserEmail::where('userid', $existing->id)->count());
    }

    /**
     * canonMail strips everything after the last hyphen of a TN local part, so
     * "maryX-jane@" and someone else's "maryX-g12@" share a canon. They are
     * different people.
     */
    public function test_canon_match_with_a_different_username_is_not_linked(): void
    {
        $tnId  = $this->tnId();
        $mary  = $this->uniqueUsername('mary');
        $other = $this->createTestUser(['email_preferred' => "{$mary}-g12@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser("{$mary}-jane")];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($other->id, $user->id);
        $this->assertNull($other->fresh()->tnuserid);
        $this->assertSame("{$mary}-jane@user.trashnothing.com", $user->fresh()->email_preferred);
    }

    public function test_deleted_account_is_not_linked(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('gone');
        $deleted  = $this->createTestUser(['email_preferred' => "{$username}-g9@user.trashnothing.com", 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($deleted->id, $user->id);
        $this->assertNull($deleted->fresh()->tnuserid);
    }

    public function test_username_clash_with_a_different_tnuserid_returns_null(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('clash');
        $holder   = $this->createTestUser([
            'email_preferred' => "{$username}-g5@user.trashnothing.com",
            'tnuserid'        => $tnId + 1,
        ]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_USERNAME_CLASH, $provisioner->lastFailureReason());
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
        $this->assertSame($tnId + 1, (int) $holder->fresh()->tnuserid);
    }

    public function test_404_is_not_found(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [404, "User {$tnId} does not exist."];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
    }

    public function test_null_username_is_not_found(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [200, $this->tnUser('', ['username' => null])];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
    }

    /**
     * Nothing is remembered, so a member who had no username when TN was
     * first asked is created once they have one.
     */
    public function test_a_miss_is_asked_again(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('later');
        $this->tnUsers[$tnId] = [200, $this->tnUser('', ['username' => null])];

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));

        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];
        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotNull($user);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertSame(2, $this->lookupsFor($tnId));
    }

    public function test_username_that_makes_an_invalid_address_is_a_miss(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [200, $this->tnUser('has space')];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
    }

    public function test_server_error_is_a_lookup_failure(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
    }

    public function test_rate_limit_and_challenge_responses_are_failures(): void
    {
        $limited    = $this->tnId();
        $challenged = $this->tnId();
        $this->tnUsers[$limited]    = fn () => Http::response('Too Many Requests', 429, ['Retry-After' => '30']);
        $this->tnUsers[$challenged] = fn () => Http::response('<html>challenge</html>', 200, ['cf-mitigated' => 'challenge']);

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($limited));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        $this->assertNull($provisioner->resolveOrCreate($challenged));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('dry.run');
        $email    = "{$username}@user.trashnothing.com";
        $this->tnUsers[$tnId] = [200, $this->tnUser($username, ['about_me' => 'Hi', 'reply_time' => 60])];

        $provisioner = $this->provisioner(dryRun: true);
        $user        = $provisioner->resolveOrCreate($tnId);

        $this->assertNotNull($user);
        $this->assertFalse($user->exists);
        $this->assertSame(User::tnDisplayName($username), $user->fullname);
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
        $this->assertSame(0, UserEmail::where('email', $email)->count());
        $this->assertTrue(
            collect($this->logged)->contains(fn ($e) => str_contains($e->message, 'TN-SYNC-TRACE [WRITE] table=users op=insert')),
        );
    }

    public function test_dry_run_link_writes_nothing(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('drylink');
        $existing = $this->createTestUser(['email_preferred' => "{$username}-g7@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner(dryRun: true)->resolveOrCreate($tnId);

        $this->assertSame($existing->id, $user->id);
        $this->assertNull($existing->fresh()->tnuserid);
        $this->assertSame(1, UserEmail::where('userid', $existing->id)->count());
    }

    /**
     * tn:sync and the coverage backfill can provision the same poster at once.
     * The other process wins the tnuserid unique index while this one is still
     * asking TN; this one must hand back the winner, not fail or make a twin.
     */
    public function test_duplicate_key_race_returns_existing_user(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('race');
        $winner   = null;

        $this->tnUsers[$tnId] = function () use (&$winner, $tnId, $username) {
            $winner = User::create(['fullname' => 'Winner', 'added' => now(), 'tnuserid' => $tnId]);

            return Http::response(json_encode($this->tnUser($username)), 200, ['Content-Type' => 'application/json']);
        };

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotNull($winner);
        $this->assertSame($winner->id, $user->id);
        $this->assertSame(1, User::where('tnuserid', $tnId)->count());
        $this->assertSame(0, UserEmail::where('email', "{$username}@user.trashnothing.com")->count());
    }

    public function test_api_key_does_not_appear_in_logs(): void
    {
        $echoed = $this->tnId();
        $timeout = $this->tnId();
        $this->tnUsers[$echoed] = fn (Request $r) => Http::response('Bad request for ' . $r->url(), 500);
        $this->tnUsers[$timeout] = fn (Request $r) => throw new ConnectionException('cURL error 28: timed out for ' . $r->url());

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($echoed));
        $this->assertNull($provisioner->resolveOrCreate($timeout));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());

        $this->assertNotEmpty($this->logged);
        foreach ($this->logged as $event) {
            $this->assertStringNotContainsString(self::API_KEY, $event->message . json_encode($event->context));
        }
        $this->assertTrue(
            collect($this->logged)->contains(fn ($e) => str_contains(json_encode($e->context), 'api_key=<redacted>')),
        );
    }

    public function test_local_testing_reads_fixture(): void
    {
        $user = $this->provisioner(localTesting: true)->resolveOrCreate(99010901);

        $this->assertSame('Fixture Poster', $user->fresh()->fullname);
        $this->assertSame('fixture.poster@user.trashnothing.com', $user->fresh()->email_preferred);
        Http::assertNothingSent();
    }

    public function test_local_testing_missing_fixture_is_a_miss(): void
    {
        $provisioner = $this->provisioner(localTesting: true);

        $this->assertNull($provisioner->resolveOrCreate($this->tnId()));
        $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
        Http::assertNothingSent();
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;
use OpenAPI\Client\Configuration;
use Tests\Support\LagsTheReadNode;
use Tests\TestCase;

class TnUserProvisionerTest extends TestCase
{
    use LagsTheReadNode;

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

    /** @var array<int, array> Guzzle options of each request TN was sent, in order. */
    private array $requestOptions = [];

    private LokiService $loki;

    private string $baseUrl;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseUrl = rtrim(Configuration::getDefaultConfiguration()->getHost(), '/');

        $this->loki = Mockery::mock(LokiService::class)->shouldIgnoreMissing();

        Http::fake(function (Request $request, array $options) {
            $this->requestOptions[] = $options;

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
            fn ($ctx) => $ctx['tn_user_id'] === $tnId && is_int($ctx['user_id'])
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

        // The key is only accepted as a query parameter: a header gives 401.
        Http::assertSent(fn (Request $r) => $r->url() === $this->baseUrl . "/users/{$tnId}?api_key=" . self::API_KEY
            && !$r->hasHeader('Authorization'));
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

    /** Only a TN id says who someone is, so an account holding this username proves nothing. */
    public function test_an_account_on_an_alias_of_the_username_is_not_linked(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('nolink');
        $alias    = "{$username}-g123@user.trashnothing.com";
        $existing = $this->createTestUser(['email_preferred' => $alias, 'fullname' => 'Not Linked']);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($existing->id, $user->id);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertNull($existing->fresh()->tnuserid);
        $this->assertSame("{$username}@user.trashnothing.com", $user->fresh()->email_preferred);
        $this->assertSame(1, UserEmail::where('email', $alias)->where('userid', $existing->id)->count(), 'its alias stays');
    }

    /**
     * TN has said this id has this username, so the bare address is this member's. It moves here
     * whoever holds it, and the account it left keeps a preferred address if it has another.
     */
    public function test_the_bare_address_moves_to_the_member_from_an_account_with_no_tn_id(): void
    {
        $sentry   = $this->recordSentry();
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('bare');
        $bare     = "{$username}@user.trashnothing.com";
        $existing = $this->createTestUser(['email_preferred' => $bare]);
        $spare    = $this->uniqueUsername('spare') . '-g9@user.trashnothing.com';
        UserEmail::create(['userid' => $existing->id, 'email' => $spare, 'preferred' => 0]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($existing->id, $user->id);
        $this->assertNull($existing->fresh()->tnuserid);
        $this->assertSame($user->id, (int) UserEmail::where('email', $bare)->value('userid'));
        $this->assertSame($bare, $user->fresh()->email_preferred);
        $this->assertSame($spare, $existing->fresh()->email_preferred, 'the account it left keeps a preferred address');

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString("user {$existing->id}", $sentry->calls[0][0]);
        $this->assertStringNotContainsString($username, $sentry->calls[0][0], 'a report names ids, never an address');
    }

    public function test_the_bare_address_moves_from_an_account_holding_another_tn_id(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('taken');
        $bare     = "{$username}@user.trashnothing.com";
        $holder   = $this->createTestUser(['email_preferred' => $bare, 'tnuserid' => $tnId + 1]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertSame($tnId + 1, (int) $holder->fresh()->tnuserid);
        $this->assertSame($user->id, (int) UserEmail::where('email', $bare)->value('userid'));
    }

    /**
     * "maryX-jane@" and someone else's "maryX-g12@" are different people. canonMail
     * used to strip everything after the last hyphen, which gave them one canon;
     * it now strips only a -g<digits> suffix, so they no longer collide at all.
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

    /**
     * Rows written before canonMail stopped stripping after the last hyphen keep
     * the old canon until users:backfill-email-canon rewrites them. Someone else's
     * "maryX-jane-x@" then still holds canon "maryX-jane@usertrashnothingcom", the
     * same as this member's bare address. The username check must refuse it.
     */
    public function test_stale_canon_row_for_a_different_username_is_not_linked(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('mary') . '-jane';
        $other    = $this->createTestUser();
        DB::table('users_emails')->insert([
            'userid'    => $other->id,
            'email'     => "{$username}-x@user.trashnothing.com",
            'canon'     => "{$username}@usertrashnothingcom",
            'backwards' => strrev("{$username}@usertrashnothingcom"),
            'preferred' => 0,
            'added'     => now(),
        ]);
        $this->assertSame(
            User::canonMail("{$username}@user.trashnothing.com"),
            "{$username}@usertrashnothingcom",
            'the stale row must collide on canon, or this test proves nothing'
        );
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($other->id, $user->id);
        $this->assertNull($other->fresh()->tnuserid);
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

    /**
     * A deleted account proves no more than a live one, and only keeps an address until it is
     * forgotten, so the address moves here as it would from a live account.
     */
    public function test_the_bare_address_moves_from_a_deleted_account(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('goneb');
        $bare     = "{$username}@user.trashnothing.com";
        $deleted  = $this->createTestUser(['email_preferred' => $bare, 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();
        $user        = $provisioner->resolveOrCreate($tnId);

        $this->assertNull($provisioner->lastFailureReason());
        $this->assertNotSame($deleted->id, $user->id);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertSame($user->id, (int) UserEmail::where('email', $bare)->value('userid'));
        $this->assertNull($deleted->fresh()->tnuserid);
    }

    /** A live account on an alias is not the member either, whoever holds the bare address. */
    public function test_a_live_alias_account_is_not_linked_when_a_deleted_one_holds_the_bare_address(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('split');
        $this->createTestUser(['email_preferred' => "{$username}@user.trashnothing.com", 'deleted' => now()]);
        $live     = $this->createTestUser(['email_preferred' => "{$username}-g5@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotSame($live->id, $user->id);
        $this->assertNull($live->fresh()->tnuserid);
    }

    /**
     * Two members can share a username: TN does not promise one is unique. An alias of it on an
     * account holding another TN id is that member's, and must not stop this one's post.
     */
    public function test_the_same_username_on_an_account_with_another_tn_id_is_another_member(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('shared');
        $alias    = "{$username}-g5@user.trashnothing.com";
        $holder   = $this->createTestUser(['email_preferred' => $alias, 'tnuserid' => $tnId + 1]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();
        $user        = $provisioner->resolveOrCreate($tnId);

        $this->assertNull($provisioner->lastFailureReason());
        $this->assertNotSame($holder->id, $user->id);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertSame($tnId + 1, (int) $holder->fresh()->tnuserid);
        $this->assertSame($holder->id, (int) UserEmail::where('email', $alias)->value('userid'), 'its alias stays');
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
     * TN's 404 is an answer, and the same answer comes back for every post the poster makes in
     * every sync window that overlaps, so the first one is remembered and the rest are not
     * asked. Each run is its own provisioner, so only the cache carries it.
     */
    public function test_a_not_found_poster_is_remembered_and_not_asked_again(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [404, "User {$tnId} does not exist."];

        foreach (range(1, 3) as $run) {
            $provisioner = $this->provisioner();

            $this->assertNull($provisioner->resolveOrCreate($tnId, "gone-post-{$run}"));
            $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason(), "run {$run}");
        }

        $this->assertSame(1, $this->lookupsFor($tnId));
    }

    public function test_a_poster_with_no_username_is_remembered_too(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [200, $this->tnUser('', ['username' => null])];

        foreach (range(1, 3) as $run) {
            $provisioner = $this->provisioner();

            $this->assertNull($provisioner->resolveOrCreate($tnId));
            $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason(), "run {$run}");
        }

        $this->assertSame(1, $this->lookupsFor($tnId));
    }

    /** A refusal is TN's answer about the poster too, and keeps its own reason when remembered. */
    public function test_a_refused_poster_is_remembered_with_its_reason(): void
    {
        $this->recordSentry();
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [410, 'Gone'];

        foreach (range(1, 3) as $run) {
            $provisioner = $this->provisioner();

            $this->assertNull($provisioner->resolveOrCreate($tnId));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_REFUSED, $provisioner->lastFailureReason(), "run {$run}");
        }

        $this->assertSame(1, $this->lookupsFor($tnId));
    }

    public function test_each_poster_is_remembered_on_their_own(): void
    {
        $first  = $this->tnId();
        $second = $this->tnId();
        $this->tnUsers[$first]  = [404, 'No.'];
        $this->tnUsers[$second] = [404, 'No.'];

        $this->provisioner()->resolveOrCreate($first);
        $this->provisioner()->resolveOrCreate($first);
        $this->provisioner()->resolveOrCreate($second);

        $this->assertSame(1, $this->lookupsFor($first));
        $this->assertSame(1, $this->lookupsFor($second), 'the other poster was not covered by the first answer');
    }

    /**
     * An hour, not for ever and not a day: an answer can be wrong (a bad base URL, a 404 from
     * an edge, a member too new to be on TN's replica), and every post by the poster is skipped
     * until it expires. A member who had no username when TN was first asked is created once
     * they have one.
     */
    public function test_a_remembered_miss_is_asked_again_after_an_hour(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('later');
        $this->tnUsers[$tnId] = [200, $this->tnUser('', ['username' => null])];

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));

        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];
        $this->travel(59)->minutes();
        $this->assertNull($this->provisioner()->resolveOrCreate($tnId), 'still inside the hour');
        $this->assertSame(1, $this->lookupsFor($tnId));

        $this->travel(2)->minutes();
        $user = $this->provisioner()->resolveOrCreate($tnId);
        $this->travelBack();

        $this->assertNotNull($user);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
        $this->assertSame(2, $this->lookupsFor($tnId));
    }

    /**
     * A wrong 404 (TN's replica not yet holding a brand-new user) is put right by the next
     * hour's lookup, and the poster's posts are ingested from then on.
     */
    public function test_a_wrong_not_found_answer_stops_hiding_the_poster_after_an_hour(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('newcomer');
        $this->tnUsers[$tnId] = [404, 'Not replicated yet.'];

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId, 'post-1'));

        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];
        $this->assertNull($this->provisioner()->resolveOrCreate($tnId, 'post-2'), 'remembered inside the hour');

        $this->travel(61)->minutes();
        $user = $this->provisioner()->resolveOrCreate($tnId, 'post-3');
        $this->travelBack();

        $this->assertNotNull($user, 'the poster is provisioned once the answer has expired');
    }

    /** The remembered answer is only a stand-in for asking TN: an account that holds the id wins. */
    public function test_a_remembered_miss_does_not_hide_an_account_that_has_since_been_linked(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [404, 'No.'];
        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));

        $linked = $this->createTestUser(['tnuserid' => $tnId]);

        $this->assertSame($linked->id, $this->provisioner()->resolveOrCreate($tnId)->id);
        $this->assertSame(1, $this->lookupsFor($tnId));
    }

    /** Only an answer is remembered. Not getting one says nothing about the poster. */
    public function test_a_failure_to_ask_is_not_remembered(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('back');
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());

        $this->tnUsers[$tnId] = fn () => throw new ConnectionException('cURL error 28: timed out');
        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));

        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];
        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotNull($user, 'asked again each time, and answered the third');
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
    }

    /** A dry run must leave nothing behind for the next real run. */
    public function test_a_dry_run_remembers_nothing(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [404, 'No.'];

        $this->assertNull($this->provisioner(dryRun: true)->resolveOrCreate($tnId));
        $this->assertNull($this->provisioner(dryRun: true)->resolveOrCreate($tnId));
        $this->assertSame(2, $this->lookupsFor($tnId), 'a dry run asks every time');

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));
        $this->assertSame(3, $this->lookupsFor($tnId), 'and the real run was not answered by it');
    }

    public function test_a_deleted_account_holding_the_tnuserid_gets_no_post(): void
    {
        $tnId    = $this->tnId();
        $deleted = $this->createTestUser(['tnuserid' => $tnId, 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($this->uniqueUsername('left'))];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_ACCOUNT_DELETED, $provisioner->lastFailureReason());
        $this->assertSame('tn-account-deleted', TnUserProvisioner::REASON_ACCOUNT_DELETED);
        Http::assertNothingSent();
        $this->assertSame([$deleted->id], User::where('tnuserid', $tnId)->pluck('id')->all(), 'the id stays where it is');
    }

    /**
     * users.tnuserid is unique, so a live account found by address cannot be stamped with an id a
     * deleted account holds. That used to fail on the index and be counted as a failed lookup.
     */
    public function test_a_live_account_on_the_address_is_not_linked_while_a_deleted_account_holds_the_tnuserid(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('twin');
        $live     = $this->createTestUser(['email_preferred' => "{$username}-g7@user.trashnothing.com"]);
        $this->createTestUser(['tnuserid' => $tnId, 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId, 'twin-post'));
        $this->assertSame(TnUserProvisioner::REASON_ACCOUNT_DELETED, $provisioner->lastFailureReason());
        $this->assertNull($live->fresh()->tnuserid);
    }

    /** What forgetting the account does (User::forget() clears tnuserid): the poster is new again. */
    public function test_once_the_deleted_account_lets_go_of_the_tnuserid_the_poster_gets_a_live_account(): void
    {
        $tnId     = $this->tnId();
        $deleted  = $this->createTestUser(['tnuserid' => $tnId, 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($this->uniqueUsername('back'))];
        $this->assertNull($this->provisioner()->resolveOrCreate($tnId));

        DB::table('users')->where('id', $deleted->id)->update(['tnuserid' => null]);
        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertNotNull($user);
        $this->assertNotSame($deleted->id, $user->id);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
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

    public function test_the_lookup_gives_up_quickly(): void
    {
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [404, 'User does not exist.'];

        $this->provisioner()->resolveOrCreate($tnId);

        $this->assertCount(1, $this->requestOptions);
        $this->assertLessThanOrEqual(10, $this->requestOptions[0]['timeout']);
        $this->assertLessThanOrEqual(5, $this->requestOptions[0]['connect_timeout']);
    }

    /**
     * A 404 and a user with no username are TN's answer, and nothing a retry would change.
     * Anything that stopped TN answering is a failure the next run must ask about again.
     * The post sync decides whether to stop from this reason, so the two must not blur.
     */
    public function test_an_answer_is_definite_and_a_failure_to_get_one_is_not(): void
    {
        $notFound   = $this->tnId();
        $noUsername = $this->tnId();
        $serverDown = $this->tnId();
        $timedOut   = $this->tnId();
        $this->tnUsers[$notFound]   = [404, 'User does not exist.'];
        $this->tnUsers[$noUsername] = [200, $this->tnUser('', ['username' => null])];
        $this->tnUsers[$serverDown] = [503, 'Service Unavailable'];
        $this->tnUsers[$timedOut]   = fn () => throw new ConnectionException('cURL error 28: timed out');

        $reasons = [];
        foreach ([$notFound, $noUsername, $serverDown, $timedOut] as $id) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($id));
            $reasons[] = $provisioner->lastFailureReason();
        }

        $this->assertSame([
            TnUserProvisioner::REASON_NOT_FOUND,
            TnUserProvisioner::REASON_NOT_FOUND,
            TnUserProvisioner::REASON_LOOKUP_FAILED,
            TnUserProvisioner::REASON_LOOKUP_FAILED,
        ], $reasons);
    }

    /** Binds a recorder as 'sentry' and returns it. */
    private function recordSentry(): object
    {
        $sentry = new class
        {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = [(string) $message, (string) $severity];
            }
        };
        $this->app->instance('sentry', $sentry);

        return $sentry;
    }

    public function test_a_rejected_key_is_reported_to_sentry_at_most_once_an_hour(): void
    {
        $sentry = $this->recordSentry();
        $first  = $this->tnId();
        $second = $this->tnId();
        $this->tnUsers[$first]  = [401, 'Unauthorized'];
        $this->tnUsers[$second] = [403, 'Forbidden'];

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($first));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_KEY_REJECTED, $provisioner->lastFailureReason());
        $this->assertNull($provisioner->resolveOrCreate($second));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_KEY_REJECTED, $provisioner->lastFailureReason());

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('rejected', $sentry->calls[0][0]);
        $this->assertStringNotContainsString(self::API_KEY, $sentry->calls[0][0]);
        $this->assertSame((string) \Sentry\Severity::error(), $sentry->calls[0][1]);

        // tn:sync runs every minute and each run is a new instance, so the next ten runs
        // must not report again.
        for ($run = 1; $run <= 10; $run++) {
            $this->travel(1)->minutes();
            $this->assertNull($this->provisioner()->resolveOrCreate($first));
        }
        $this->assertCount(1, $sentry->calls, 'a run a minute is not a report a minute');

        $this->travel(61)->minutes();
        $this->assertNull($this->provisioner()->resolveOrCreate($first));
        $this->assertCount(2, $sentry->calls, 'still rejected an hour later, so it is reported again');
        $this->travelBack();
    }

    public function test_a_cloudflare_block_is_not_a_rejected_key(): void
    {
        $sentry  = $this->recordSentry();
        $blocked = $this->tnId();
        $this->tnUsers[$blocked] = fn () => Http::response('<html>blocked</html>', 403, ['cf-mitigated' => 'challenge']);

        $this->assertNull($this->provisioner()->resolveOrCreate($blocked));

        $this->assertSame([], $sentry->calls);
    }

    public static function permanentClientErrors(): array
    {
        return [
            'bad request'              => [400],
            'gone'                     => [410],
            'unprocessable'            => [422],
            'unavailable for legal reasons' => [451],
        ];
    }

    /**
     * TN answered, and the answer is about this poster, so a retry would get it again. It is a
     * definite skip like a 404, with its own reason so it can be told apart, not a failure
     * that holds up the window.
     */
    #[DataProvider('permanentClientErrors')]
    public function test_a_client_error_about_the_poster_is_a_definite_skip(int $status): void
    {
        $this->recordSentry();
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [$status, 'No.'];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId, 'refused-post-' . $status));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_REFUSED, $provisioner->lastFailureReason());
        $this->assertSame('tn-user-lookup-refused', TnUserProvisioner::REASON_LOOKUP_REFUSED);
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());

        // Logged...
        $logged = array_filter($this->logged, fn (MessageLogged $e) => $e->message === 'TN user provisioning: lookup refused');
        $this->assertCount(1, $logged);
        $this->assertSame($status, array_values($logged)[0]->context['status']);

        // ...and counted as nothing: a refusal is not a failure to ask.
        $this->assertSame(1, $this->lookupsFor($tnId));
        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES; $minute++) {
            $again = $this->provisioner();
            $again->resolveOrCreate($tnId, 'refused-post-' . $status);
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_REFUSED, $again->lastFailureReason());
            $this->travel(1)->minutes();
        }
    }

    public function test_a_refused_status_is_reported_to_sentry_once_a_day(): void
    {
        $sentry = $this->recordSentry();
        $gone1  = $this->tnId();
        $gone2  = $this->tnId();
        $bad    = $this->tnId();
        $this->tnUsers[$gone1] = [410, 'Gone'];
        $this->tnUsers[$gone2] = [410, 'Gone'];
        $this->tnUsers[$bad]   = [400, 'Bad Request'];

        $this->provisioner()->resolveOrCreate($gone1);
        $this->provisioner()->resolveOrCreate($gone2);
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('410', $sentry->calls[0][0]);
        $this->assertStringNotContainsString(self::API_KEY, $sentry->calls[0][0]);

        $this->provisioner()->resolveOrCreate($bad);
        $this->assertCount(2, $sentry->calls, 'a different status is its own report');
        $this->assertStringContainsString('400', $sentry->calls[1][0]);

        $this->travel(23)->hours();
        $this->provisioner()->resolveOrCreate($gone1);
        $this->assertCount(2, $sentry->calls, 'still the same day');

        $this->travel(2)->hours();
        $this->provisioner()->resolveOrCreate($gone1);
        $this->assertCount(3, $sentry->calls, 'a day later it is reported again');
        $this->travelBack();
    }

    public static function transientStatuses(): array
    {
        return [
            'request timeout' => [408],
            'rate limited'    => [429],
            'server error'    => [500],
            'bad gateway'     => [502],
            'unavailable'     => [503],
            'gateway timeout' => [504],
        ];
    }

    /**
     * Only a failure to ask is retried. A rejected key is not one: see
     * test_a_rejected_key_is_not_counted_against_the_post.
     */
    #[DataProvider('transientStatuses')]
    public function test_a_failure_to_ask_is_retried(int $status): void
    {
        $this->recordSentry();
        $tnId = $this->tnId();
        $this->tnUsers[$tnId] = [$status, 'Try later'];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId, 'transient-post-' . $status));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
    }

    public static function rejectedKeyStatuses(): array
    {
        return [
            'unauthorised' => [401],
            'forbidden'    => [403],
        ];
    }

    /**
     * A rejected key fails every lookup until someone fixes it, so it says nothing about the
     * post. It is its own reason, it is never counted towards giving the post up, and the
     * only report is the hourly one about the key.
     */
    #[DataProvider('rejectedKeyStatuses')]
    public function test_a_rejected_key_is_not_counted_against_the_post(int $status): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [$status, 'Unauthorized'];

        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES + 1; $minute++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'keyed-post-' . $status));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_KEY_REJECTED, $provisioner->lastFailureReason(), "minute {$minute}");
            $this->travel(1)->minutes();
        }
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('rejected', $sentry->calls[0][0]);

        // Once the key works, a failure is the post's first.
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];
        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId, 'keyed-post-' . $status));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        $this->assertCount(1, $sentry->calls, 'nothing about the post');
    }

    public function test_a_failing_lookup_is_reported_once_its_post_has_waited_long_enough(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        // One failed lookup of the post in each run, a minute apart: a run stops at its first failure.
        for ($minute = 0; $minute < TnUserProvisioner::LOOKUP_WARN_AFTER_MINUTES; $minute++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'wedged-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
            $this->travel(1)->minutes();
        }
        $this->assertSame([], $sentry->calls, 'a few minutes is a blip');

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId, 'wedged-post'));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('wedged-post', $sentry->calls[0][0]);
        $this->assertStringContainsString((string) $tnId, $sentry->calls[0][0]);
        $this->assertStringNotContainsString(self::API_KEY, $sentry->calls[0][0]);

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId, 'wedged-post'));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        $this->assertCount(1, $sentry->calls, 'one report once it has waited, not one for every run after it');
    }

    /**
     * The limits are minutes, not runs: tn:sync runs every few seconds, and a poster that fails
     * on many runs close together has not been waited on for long.
     */
    public function test_many_failures_close_together_are_a_short_wait(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];

        // 40 runs ten seconds apart: more failures than there are minutes before giving up.
        for ($run = 1; $run <= 40; $run++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'patient-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason(), "run {$run}");
            $this->travel(10)->seconds();
        }

        $this->assertCount(1, $sentry->calls, 'warned once it had waited five minutes, and not given up');
        $this->assertStringContainsString(TnUserProvisioner::LOOKUP_WARN_AFTER_MINUTES . ' minutes', $sentry->calls[0][0]);
    }

    public function test_a_post_whose_lookups_keep_failing_is_given_up_and_reported_once(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];

        for ($minute = 0; $minute < TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES; $minute++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'doomed-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason(), "minute {$minute}");
            $this->travel(1)->minutes();
        }
        $reportsBeforeTheLimit = count($sentry->calls);
        $this->assertSame(1, $reportsBeforeTheLimit, 'the warning, and nothing else');

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId, 'doomed-post'));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_GAVE_UP, $provisioner->lastFailureReason());
        $this->assertSame('tn-user-lookup-gave-up', TnUserProvisioner::REASON_LOOKUP_GAVE_UP);
        $this->assertCount(2, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('doomed-post', $sentry->calls[1][0]);
        $this->assertStringContainsString('skipped', $sentry->calls[1][0]);

        $again = $this->provisioner();
        $this->assertNull($again->resolveOrCreate($tnId, 'doomed-post'));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_GAVE_UP, $again->lastFailureReason());
        $this->assertCount(2, $sentry->calls, 'told once');
    }

    /**
     * Giving up is about not holding the window, not about never asking again: a post read
     * again once TN answers is ingested like any other.
     */
    public function test_a_post_that_was_given_up_is_resolved_when_tn_answers_later(): void
    {
        $this->recordSentry();
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('recovered');
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];

        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES; $minute++) {
            $this->provisioner()->resolveOrCreate($tnId, 'recovering-post');
            $this->travel(1)->minutes();
        }
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_GAVE_UP, $this->lastReasonFor($tnId, 'recovering-post'));

        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];
        $user = $this->provisioner()->resolveOrCreate($tnId, 'recovering-post');

        $this->assertNotNull($user);
        $this->assertSame($tnId, (int) $user->fresh()->tnuserid);
    }

    private function lastReasonFor(int $tnId, string $postId): ?string
    {
        $provisioner = $this->provisioner();
        $provisioner->resolveOrCreate($tnId, $postId);

        return $provisioner->lastFailureReason();
    }

    public function test_each_post_is_timed_from_its_own_first_failure(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        // The same poster behind two posts, the second first failing four minutes after the first.
        $this->provisioner()->resolveOrCreate($tnId, 'separate-post-1');
        $this->travel(TnUserProvisioner::LOOKUP_WARN_AFTER_MINUTES - 1)->minutes();
        $this->provisioner()->resolveOrCreate($tnId, 'separate-post-2');
        $this->travel(TnUserProvisioner::LOOKUP_WARN_AFTER_MINUTES - 1)->minutes();

        $this->provisioner()->resolveOrCreate($tnId, 'separate-post-2');
        $this->assertSame([], $sentry->calls, 'the second post has waited four minutes');

        $this->provisioner()->resolveOrCreate($tnId, 'separate-post-1');
        $this->assertCount(1, $sentry->calls, 'the first has waited eight');
        $this->assertStringContainsString('separate-post-1', $sentry->calls[0][0]);
    }

    public function test_a_failure_with_no_post_is_not_counted(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES + 1; $minute++) {
            $provisioner = $this->provisioner();
            $provisioner->resolveOrCreate($tnId);
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
            $this->travel(1)->minutes();
        }

        $this->assertSame([], $sentry->calls);
    }

    public function test_a_dry_run_does_not_count_a_failure(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES + 1; $minute++) {
            $provisioner = $this->provisioner(dryRun: true);
            $provisioner->resolveOrCreate($tnId, 'dry-run-post');
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
            $this->travel(1)->minutes();
        }
        $this->assertSame([], $sentry->calls, 'a dry run changes nothing the next real run reads');

        $real = $this->provisioner();
        $real->resolveOrCreate($tnId, 'dry-run-post');
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $real->lastFailureReason());
    }

    /**
     * An answer is not a failure: a poster TN answers for is never counted, whatever it
     * answers, so a run of 404s ahead of a failing poster cannot use up its budget.
     */
    public function test_an_answered_lookup_is_not_counted(): void
    {
        $sentry = $this->recordSentry();
        $gone   = $this->tnId();
        $this->tnUsers[$gone] = [404, 'User does not exist.'];

        for ($minute = 0; $minute <= TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES + 1; $minute++) {
            $provisioner = $this->provisioner();
            $provisioner->resolveOrCreate($gone, 'answered-post');
            $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
            $this->travel(1)->minutes();
        }

        $this->assertSame([], $sentry->calls);
    }

    /**
     * Another process wins the tnuserid index while this one is writing, but its row cannot be
     * found: no user was provisioned and nothing was answered, so it is a failure to resolve
     * like any other and goes through the same clock.
     */
    public function test_a_lost_race_with_no_winner_is_counted_like_any_failed_lookup(): void
    {
        $sentry   = $this->recordSentry();
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('nowinner');
        $email    = "{$username}@user.trashnothing.com";
        $other    = $this->createTestUser();
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        // The address is taken by someone else between the check for it and the write.
        UserEmail::creating(function (UserEmail $row) use ($email, $other) {
            if ($row->email === $email && !UserEmail::where('email', $email)->exists()) {
                DB::table('users_emails')->insert([
                    'userid'    => $other->id,
                    'email'     => $email,
                    'preferred' => 0,
                    'added'     => now(),
                    'canon'     => User::canonMail($email),
                    'backwards' => strrev(User::canonMail($email)),
                ]);
            }
        });

        for ($minute = 0; $minute < TnUserProvisioner::LOOKUP_GIVE_UP_AFTER_MINUTES; $minute++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'raced-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason(), "minute {$minute}");
            // The conflicting row was the other user's all along; put the test back as it was.
            DB::table('users_emails')->where('email', $email)->delete();
            $this->travel(1)->minutes();
        }
        $this->assertCount(1, $sentry->calls, 'waited long enough to be warned of');

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId, 'raced-post'));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_GAVE_UP, $provisioner->lastFailureReason());
        $this->assertCount(2, $sentry->calls);
        DB::table('users_emails')->where('email', $email)->delete();
    }

    /**
     * A poster created a moment ago is on the primary and possibly not yet on the replica.
     * Reading the replica would ask TN again, try to create them, hit the unique tnuserid
     * index and fail the post.
     */
    public function test_a_poster_created_a_moment_ago_is_found_when_the_replica_is_behind(): void
    {
        $tnId = $this->tnId();

        $this->withLaggingReadNode(['users'], function (string $lag) use ($tnId) {
            $id = DB::connection($lag)->table('users')->insertGetId(['fullname' => 'Just Created', 'added' => now(), 'tnuserid' => $tnId]);
            DB::setDefaultConnection($lag);

            try {
                $this->assertNull(User::where('tnuserid', $tnId)->first(), 'the read node has not got the row yet');

                $user = $this->provisioner()->resolveOrCreate($tnId);
            } finally {
                DB::setDefaultConnection('mysql');
                DB::connection($lag)->table('users')->where('id', $id)->delete();
            }

            $this->assertNotNull($user);
            $this->assertSame($id, $user->id);
        });

        Http::assertNothingSent();
    }

    public function test_a_lost_race_takes_the_winner_from_the_primary_when_the_replica_is_behind(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('lagrace');
        $winnerId = null;

        $this->withLaggingReadNode(['users', 'users_emails'], function (string $lag) use ($tnId, $username, &$winnerId) {
            $this->tnUsers[$tnId] = function () use (&$winnerId, $lag, $tnId, $username) {
                $winnerId = DB::connection($lag)->table('users')->insertGetId(['fullname' => 'Winner', 'added' => now(), 'tnuserid' => $tnId]);

                return Http::response(json_encode($this->tnUser($username)), 200, ['Content-Type' => 'application/json']);
            };
            DB::setDefaultConnection($lag);

            try {
                $provisioner = $this->provisioner();
                $user = $provisioner->resolveOrCreate($tnId);
            } finally {
                DB::setDefaultConnection('mysql');
                if ($winnerId !== null) {
                    DB::connection($lag)->table('users')->where('id', $winnerId)->delete();
                }
            }

            $this->assertNotNull($winnerId);
            $this->assertNotNull($user, 'the winner is on the primary');
            $this->assertSame($winnerId, $user->id);
            $this->assertNull($provisioner->lastFailureReason());
        });
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

    public function test_dry_run_moving_the_bare_address_writes_nothing(): void
    {
        $sentry   = $this->recordSentry();
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('drymove');
        $bare     = "{$username}@user.trashnothing.com";
        $existing = $this->createTestUser(['email_preferred' => $bare]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $this->provisioner(dryRun: true)->resolveOrCreate($tnId);

        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
        $this->assertSame($existing->id, (int) UserEmail::where('email', $bare)->value('userid'));
        $this->assertSame([], $sentry->calls);
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

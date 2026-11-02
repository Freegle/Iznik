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
        // Attaching the address it already holds must not demote it: addEmail's
        // primary: 0 would otherwise un-prefer it, leaving no preferred address,
        // so isTN() turns false and the user-changes sync skips the member.
        $this->assertSame(1, (int) UserEmail::where('userid', $existing->id)->value('preferred'));
        $this->assertTrue($existing->fresh()->isTN());
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
     * users_emails.email is unique, so a deleted account still holding the bare
     * address cannot be given to a new user, and its address is not ours to take
     * back. That is its own answer, not a failed lookup: TN was asked and replied.
     */
    public function test_deleted_account_holding_the_bare_address_has_its_own_reason(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('goneb');
        $deleted  = $this->createTestUser(['email_preferred' => "{$username}@user.trashnothing.com", 'deleted' => now()]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();

        $this->assertNull($provisioner->resolveOrCreate($tnId));
        $this->assertSame(TnUserProvisioner::REASON_ADDRESS_HELD_BY_DELETED_USER, $provisioner->lastFailureReason());
        $this->assertSame(0, User::where('tnuserid', $tnId)->count());
        $this->assertNull($deleted->fresh()->tnuserid);
        $this->assertSame(1, UserEmail::where('email', "{$username}@user.trashnothing.com")->count());
    }

    /**
     * A live account on an alias is still the member, even when a deleted account holds
     * the bare address: the tnuserid is stamped, and the address that cannot be
     * attached is left off rather than failing the whole link on the unique index.
     */
    public function test_links_the_live_alias_account_when_a_deleted_one_holds_the_bare_address(): void
    {
        $tnId     = $this->tnId();
        $username = $this->uniqueUsername('split');
        $deleted  = $this->createTestUser(['email_preferred' => "{$username}@user.trashnothing.com", 'deleted' => now()]);
        $live     = $this->createTestUser(['email_preferred' => "{$username}-g5@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser($username)];

        $provisioner = $this->provisioner();
        $user        = $provisioner->resolveOrCreate($tnId);

        $this->assertNull($provisioner->lastFailureReason());
        $this->assertSame($live->id, $user->id);
        $this->assertSame($tnId, (int) $live->fresh()->tnuserid);
        $this->assertNull($deleted->fresh()->tnuserid);
        $this->assertSame(1, UserEmail::where('userid', $live->id)->count(), 'the held bare address is not attached');
        $this->assertSame(1, UserEmail::where('userid', $deleted->id)->count());
    }

    /**
     * TN usernames are mixed case, addresses are matched without regard to case,
     * and "same member" compares lowercased usernames.
     */
    public function test_a_mixed_case_username_links_the_account_on_its_lowercase_alias(): void
    {
        $tnId     = $this->tnId();
        $lower    = $this->uniqueUsername('mixedcase');
        $existing = $this->createTestUser(['email_preferred' => "{$lower}-g5@user.trashnothing.com"]);
        $this->tnUsers[$tnId] = [200, $this->tnUser(ucfirst($lower))];

        $user = $this->provisioner()->resolveOrCreate($tnId);

        $this->assertSame($existing->id, $user->id);
        $this->assertSame($tnId, (int) $existing->fresh()->tnuserid);
        $this->assertSame(1, UserEmail::where('userid', $existing->id)->whereRaw('preferred = 1')->count());
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
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        $this->assertNull($provisioner->resolveOrCreate($second));

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
        for ($run = 0; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $again = $this->provisioner();
            $again->resolveOrCreate($tnId, 'refused-post-' . $status);
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_REFUSED, $again->lastFailureReason());
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
            'rejected key'    => [401],
            'forbidden'       => [403],
        ];
    }

    /**
     * Only a failure to ask is retried. The key being rejected is included: it says nothing
     * about the poster, and it is reported separately.
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

    public function test_a_failing_lookup_is_reported_when_its_post_reaches_the_warning_count(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        // One failed lookup of the post in each run: a run stops at its first failure.
        for ($run = 1; $run < TnUserProvisioner::LOOKUP_WARN_AT; $run++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'wedged-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        }
        $this->assertSame([], $sentry->calls, 'a few failures are a blip');

        $this->assertNull($this->provisioner()->resolveOrCreate($tnId, 'wedged-post'));
        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString('wedged-post', $sentry->calls[0][0]);
        $this->assertStringContainsString((string) $tnId, $sentry->calls[0][0]);
        $this->assertStringNotContainsString(self::API_KEY, $sentry->calls[0][0]);

        $provisioner = $this->provisioner();
        $this->assertNull($provisioner->resolveOrCreate($tnId, 'wedged-post'));
        $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        $this->assertCount(1, $sentry->calls, 'one report at the warning count, not one for every run after it');
    }

    public function test_a_post_whose_lookups_keep_failing_is_given_up_and_reported_once(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];

        for ($run = 1; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'doomed-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason(), "run {$run}");
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

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $this->provisioner()->resolveOrCreate($tnId, 'recovering-post');
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

    public function test_failures_are_counted_for_each_post_separately(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        // The same poster behind many posts: each post has its own budget.
        for ($post = 1; $post <= 3; $post++) {
            for ($run = 1; $run < TnUserProvisioner::LOOKUP_WARN_AT; $run++) {
                $this->provisioner()->resolveOrCreate($tnId, "separate-post-{$post}");
            }
        }

        $this->assertSame([], $sentry->calls);
    }

    public function test_a_failure_with_no_post_is_not_counted(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_GIVE_UP_AT + 2; $run++) {
            $provisioner = $this->provisioner();
            $provisioner->resolveOrCreate($tnId);
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
        }

        $this->assertSame([], $sentry->calls);
    }

    public function test_a_dry_run_does_not_count_a_failure(): void
    {
        $sentry = $this->recordSentry();
        $tnId   = $this->tnId();
        $this->tnUsers[$tnId] = [500, 'Internal Server Error'];

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_GIVE_UP_AT + 2; $run++) {
            $provisioner = $this->provisioner(dryRun: true);
            $provisioner->resolveOrCreate($tnId, 'dry-run-post');
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason());
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

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_GIVE_UP_AT + 2; $run++) {
            $provisioner = $this->provisioner();
            $provisioner->resolveOrCreate($gone, 'answered-post');
            $this->assertSame(TnUserProvisioner::REASON_NOT_FOUND, $provisioner->lastFailureReason());
        }

        $this->assertSame([], $sentry->calls);
    }

    /**
     * Another process wins the tnuserid index while this one is writing, but its row cannot be
     * found: no user was provisioned and nothing was answered, so it is a failure to resolve
     * like any other and goes through the same count.
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

        for ($run = 1; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $provisioner = $this->provisioner();
            $this->assertNull($provisioner->resolveOrCreate($tnId, 'raced-post'));
            $this->assertSame(TnUserProvisioner::REASON_LOOKUP_FAILED, $provisioner->lastFailureReason(), "run {$run}");
            // The conflicting row was the other user's all along; put the test back as it was.
            DB::table('users_emails')->where('email', $email)->delete();
        }
        $this->assertCount(1, $sentry->calls, 'reached the warning count');

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

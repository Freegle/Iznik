<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Group;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageGroup;
use App\Models\User;
use App\Services\ContentCheckService;
use App\Services\ItemService;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Ingestion\DeliberateSkips;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use App\Services\TrashNothing\Ingestion\TnUserProvisioner;
use App\Services\TrashNothing\Sync\TrashNothingRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use OpenAPI\Client\Configuration;
use Tests\Support\SeedsSpatialIndex;
use Tests\TestCase;

/**
 * Tests for GroupPostIngestionService.
 *
 * Verifies that the TN API ingestion path correctly mirrors the email path's
 * routing decisions and writes. All tests use fixture arrays (the same format
 * as tests/fixtures/tn_sync/posts_page_1.json) so no live TN API is needed.
 */
class GroupPostIngestionServiceTest extends TestCase
{
    use SeedsSpatialIndex;

    private LokiService $loki;
    private ItemService $itemService;

    /**
     * TN's GET /users/{id} answer per TN user id, as [status, body]. Anything
     * not listed is TN's 404. ONE Http::fake reads this, because fakes merge
     * and the first registered stub wins (see .claude/rules/laravel-batch-traps.md).
     *
     * @var array<int, array{int, mixed}>
     */
    private array $tnUsers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loki = app(LokiService::class);
        $this->itemService = app(ItemService::class);

        $baseUrl = rtrim(Configuration::getDefaultConfiguration()->getHost(), '/');
        Http::fake(function (Request $request) use ($baseUrl) {
            // Only TN is faked. Anything else (the spatial index the location tests
            // seed through SeedsSpatialIndex) returns null, which lets it through to
            // the real service; answering it 404 broke that seeding.
            if (!str_starts_with($request->url(), $baseUrl)) {
                return null;
            }
            if (!preg_match('#^' . preg_quote($baseUrl, '#') . '/users/(\d+)\?#', $request->url(), $m)) {
                return Http::response('not found', 404);
            }

            [$status, $body] = $this->tnUsers[(int) $m[1]] ?? [404, 'User does not exist.'];

            return Http::response(
                is_array($body) ? json_encode($body) : $body,
                $status,
                ['Content-Type' => is_array($body) ? 'application/json' : 'text/html'],
            );
        });
    }

    /**
     * Make TN answer GET /users/{id} for a poster Freegle has never met, with a
     * username unique to this test so parallel runs cannot share an address.
     *
     * @return array{int, string} [tnUserId, username]
     */
    private function tnKnowsPoster(): array
    {
        $tnUserId = random_int(900000000, 999999999);
        $username = 'tricia.hayes' . uniqid();
        $this->tnUsers[$tnUserId] = [200, [
            'user_id'       => (string) $tnUserId,
            'username'      => $username,
            'country'       => 'GB',
            'profile_image' => null,
            'member_since'  => '2024-03-01T09:00:00Z',
            'firstname'     => 'Tricia',
            'lastname'      => 'Hayes',
            'reply_time'    => null,
            'feedback'      => null,
            'about_me'      => null,
        ]];

        return [$tnUserId, $username];
    }

    /**
     * A Freegle user TN knows: the post payload carries TN's own user id, and the
     * service resolves it through users.tnuserid.
     */
    private function createTnUser(array $attributes = []): User
    {
        return $this->createTestUser(array_merge(['tnuserid' => random_int(900000000, 999999999)], $attributes));
    }

    private function makeService(bool $dryRun = true, bool $localTesting = false): GroupPostIngestionService
    {
        return new GroupPostIngestionService(
            dryRun: $dryRun,
            loki: $this->loki,
            itemService: $this->itemService,
            userProvisioner: new TnUserProvisioner(
                dryRun: $dryRun,
                localTesting: false,
                publicApiKey: 'test-public-key',
                loki: $this->loki,
                rateLimiter: new TrashNothingRateLimiter(0),
            ),
            localTesting: $localTesting,
        );
    }

    private function createTestLocation(): int
    {
        return (int) DB::table('locations')->insertGetId([
            'name' => 'TestLocation_' . uniqid(),
            'type' => 'Postcode',
            'lat'  => 55.9533,
            'lng'  => -3.1883,
        ]);
    }

    private function makePost(array $overrides = []): array
    {
        return array_merge([
            'post_id'   => 'tn-unit-test-' . uniqid(),
            // Source post: TN sets group_id only on the per-group COPIES it makes
            // when a post is sent to a group, and those are discarded as
            // crossposts (see ingest()). Tests that want a copy set it.
            'group_id'  => null,
            'user_id'   => null,
            'title'     => 'Old wooden bookshelf',
            'content'   => 'Good condition, free to collect.',
            'date'      => '2026-07-07T12:00:00Z',
            'type'      => 'offer',
            'outcome'   => null,
            'latitude'  => null,
            'longitude' => null,
            'photos'    => [],
        ], $overrides);
    }

    // -------------------------------------------------------------------------
    // Skip cases
    // -------------------------------------------------------------------------

    public function test_skips_when_user_id_is_null(): void
    {
        $group = $this->createTestGroup();
        $post  = $this->makePost(['user_id' => null]);

        $result = $this->makeService()->ingest($post, $group);

        $this->assertSame('skipped', $result);
    }

    public function test_skips_a_post_from_a_tn_user_neither_freegle_nor_tn_knows(): void
    {
        // TN's user_id is TN's own id. With no users.tnuserid match and a 404 from
        // TN's users endpoint there is no name and no address for the poster, so the
        // post is skipped in live mode too, and nothing is invented: no user, no
        // address, no membership, no message.
        $group   = $this->createTestGroup();
        $tnId    = 999999999;
        $postId  = 'tn-unknown-' . uniqid();
        $before  = DB::table('users')->count();
        $post    = $this->makePost(['post_id' => $postId, 'user_id' => $tnId]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('skipped', $result);
        $this->assertSame($before, DB::table('users')->count(), 'No stub user may be created');
        $this->assertFalse(DB::table('users')->where('id', $tnId)->exists(), 'The TN id must never become a Freegle id');
        $this->assertFalse(DB::table('users_emails')->where('email', "tn{$tnId}@user.trashnothing.com")->exists());
        $this->assertSame(0, Message::where('tnpostid', $postId)->count());
    }

    public function test_creates_a_tn_poster_freegle_has_never_met_and_holds_the_post_as_unmapped(): void
    {
        // A deliberate divergence from email-path case 2, which drops this post:
        // TN gives the poster's username, so the user is created and the post
        // ingested. The new user has no lastlocation, so it waits for a moderator.
        [$tnId, $username] = $this->tnKnowsPoster();
        $group  = $this->createTestGroup();
        $postId = 'tn-new-poster-' . uniqid();

        $pendingTrace = [];
        Log::listen(function ($message) use (&$pendingTrace) {
            if (str_contains((string) $message->message, 'table=messages_groups op=update')) {
                $pendingTrace[] = (string) $message->message;
            }
        });

        $service = $this->makeService(dryRun: false);
        $result  = $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group);

        $this->assertSame('pending', $result);
        $user = User::where('tnuserid', $tnId)->first();
        $this->assertNotNull($user, 'The poster is created with their TN id');
        $this->assertSame(User::tnDisplayName($username), $user->fullname, 'Prettified as Go CreatePartnerUser does');
        $this->assertNull($user->lastlocation);
        $this->assertTrue(
            DB::table('users_emails')->where('userid', $user->id)->where('email', "{$username}@user.trashnothing.com")->exists(),
        );

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertSame($user->id, (int) $message->fromuser);
        $this->assertSame(
            MessageGroup::COLLECTION_PENDING,
            MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->value('collection'),
        );
        $this->assertCount(1, $pendingTrace);
        $this->assertStringContainsString('reason=unmapped user', $pendingTrace[0]);
        // A pending reason is kept out of the context, as for any Pending post.
        $this->assertArrayNotHasKey('routing_reason', $service->getLastRoutingContext());
    }

    public function test_the_provisioner_gives_a_new_poster_no_membership_and_ingestion_gives_the_one_it_needs(): void
    {
        // The provisioner writes user data only. The one membership the poster ends up
        // with is the post's own group, from recordMembership().
        [$tnId] = $this->tnKnowsPoster();
        $group = $this->createTestGroup();

        $this->makeService(dryRun: false)->ingest($this->makePost(['user_id' => $tnId]), $group);

        $user = User::where('tnuserid', $tnId)->first();
        $this->assertNotNull($user);
        $this->assertSame(
            [$group->id],
            DB::table('memberships')->where('userid', $user->id)->pluck('groupid')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_dry_run_traces_a_new_tn_poster_without_writing_them(): void
    {
        [$tnId, $username] = $this->tnKnowsPoster();
        $group = $this->createTestGroup();

        $writes = [];
        Log::listen(function ($message) use (&$writes) {
            if (str_contains((string) $message->message, 'TN-SYNC-TRACE [WRITE]')) {
                $writes[] = (string) $message->message;
            }
        });

        $result = $this->makeService(dryRun: true)->ingest($this->makePost(['user_id' => $tnId]), $group);

        $this->assertSame('pending', $result);
        $this->assertFalse(User::where('tnuserid', $tnId)->exists(), 'Dry run creates no user');
        $this->assertFalse(DB::table('users_emails')->where('email', "{$username}@user.trashnothing.com")->exists());
        $this->assertNotEmpty(
            array_filter($writes, fn ($line) => str_contains($line, 'table=users op=insert')),
            'The would-be user insert is traced',
        );
    }

    public function test_skips_with_a_lookup_failed_reason_when_tn_cannot_be_asked(): void
    {
        $tnId = random_int(900000000, 999999999);
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];
        $group  = $this->createTestGroup();
        $postId = 'tn-lookup-failed-' . uniqid();
        $before = DB::table('users')->count();

        $service = $this->makeService(dryRun: false);

        $this->assertSame('skipped', $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group));
        $this->assertSame(RoutingResult::DROPPED, GroupPostIngestionService::outcomeFor('skipped'));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_TN_USER_LOOKUP_FAILED],
            $service->getLastRoutingContext(),
        );
        $this->assertSame('tn-user-lookup-failed', GroupPostIngestionService::REASON_TN_USER_LOOKUP_FAILED);
        $this->assertSame($before, DB::table('users')->count());
        $this->assertSame(0, Message::where('tnpostid', $postId)->count());
    }

    public function test_skips_with_a_refused_reason_when_tn_refuses_the_lookup(): void
    {
        $tnId = random_int(900000000, 999999999);
        $this->tnUsers[$tnId] = [410, 'Gone'];
        $group  = $this->createTestGroup();
        $postId = 'tn-lookup-refused-' . uniqid();

        $service = $this->makeService(dryRun: false);

        $this->assertSame('skipped', $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED],
            $service->getLastRoutingContext(),
        );
        $this->assertSame('tn-user-lookup-refused', GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED);
        $this->assertSame(0, Message::where('tnpostid', $postId)->count());
    }

    public function test_skips_with_a_gave_up_reason_once_the_lookups_for_the_post_have_failed_too_often(): void
    {
        $tnId = random_int(900000000, 999999999);
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];
        $group  = $this->createTestGroup();
        $postId = 'tn-lookup-gave-up-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $tnId]);

        // Each run is its own service and provisioner; the count lives in the cache.
        for ($run = 1; $run < TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $service = $this->makeService(dryRun: false);
            $service->ingest($post, $group);
            $this->assertSame(
                GroupPostIngestionService::REASON_TN_USER_LOOKUP_FAILED,
                $service->getLastRoutingContext()['routing_reason'],
                "run {$run}",
            );
        }

        $service = $this->makeService(dryRun: false);
        $this->assertSame('skipped', $service->ingest($post, $group));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_TN_USER_LOOKUP_GAVE_UP],
            $service->getLastRoutingContext(),
        );
        $this->assertSame('tn-user-lookup-gave-up', GroupPostIngestionService::REASON_TN_USER_LOOKUP_GAVE_UP);
        $this->assertSame(0, Message::where('tnpostid', $postId)->count());
    }

    public function test_a_username_held_by_another_tn_user_does_not_stop_the_post(): void
    {
        // TN says usernames are not guaranteed unique, and only a TN id says who someone
        // is, so an account holding the username has no bearing on this member's post.
        [$tnId, $username] = $this->tnKnowsPoster();
        $other  = $this->createTnUser(['email_preferred' => "{$username}@user.trashnothing.com"]);
        $group  = $this->createTestGroup();
        $postId = 'tn-shared-name-' . uniqid();

        $service = $this->makeService(dryRun: false);

        $this->assertNotSame('skipped', $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group));

        $poster = User::where('tnuserid', $tnId)->first();
        $this->assertNotNull($poster);
        $this->assertNotSame($other->id, $poster->id);
        $this->assertNotSame($tnId, (int) $other->fresh()->tnuserid, 'The other member keeps their own TN id');
        $this->assertSame($poster->id, (int) Message::where('tnpostid', $postId)->value('fromuser'));
    }

    public function test_a_deleted_account_holding_the_address_does_not_stop_the_post(): void
    {
        [$tnId, $username] = $this->tnKnowsPoster();
        $this->createTestUser(['email_preferred' => "{$username}@user.trashnothing.com", 'deleted' => now()]);
        $group  = $this->createTestGroup();
        $postId = 'tn-deleted-holder-' . uniqid();

        $service = $this->makeService(dryRun: false);

        $this->assertNotSame('skipped', $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group));

        $poster = User::where('tnuserid', $tnId)->first();
        $this->assertNotNull($poster);
        $this->assertSame($poster->id, (int) Message::where('tnpostid', $postId)->value('fromuser'));
    }

    public function test_skips_with_its_own_reason_when_a_deleted_account_holds_the_tnuserid(): void
    {
        $tnId    = random_int(900000000, 999999999);
        $deleted = $this->createTestUser(['tnuserid' => $tnId, 'deleted' => now()]);
        $group   = $this->createTestGroup();
        $postId  = 'tn-deleted-tnuserid-' . uniqid();

        $service = $this->makeService(dryRun: false);

        $this->assertSame('skipped', $service->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED],
            $service->getLastRoutingContext(),
        );
        $this->assertSame('tn-account-deleted', GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED);
        $this->assertSame(0, Message::where('tnpostid', $postId)->count());
        $this->assertSame(0, Message::where('fromuser', $deleted->id)->count(), 'nothing lands on the deleted account');
    }

    public function test_resolves_the_poster_by_tn_user_id_and_never_by_freegle_id(): void
    {
        // The collision seen on 2026-09-25: a Freegle account whose id happens to equal
        // the TN user's id must not be given the post. The user with that tnuserid must.
        $tnId    = 987654321;
        DB::table('users')->insert([
            'id'         => $tnId,
            'fullname'   => 'Decoy who merely holds the number',
            'systemrole' => 'User',
            'added'      => now(),
        ]);
        $this->assertTrue(DB::table('users')->where('id', $tnId)->exists(), 'Pre-condition: a Freegle account holds the TN id');
        $poster  = $this->createTestUser(['tnuserid' => $tnId]);
        $group   = $this->createTestGroup();
        $postId  = 'tn-collision-' . uniqid();
        $post    = $this->makePost(['post_id' => $postId, 'user_id' => $tnId]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertNotSame('skipped', $result);
        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertSame($poster->id, (int) $message->fromuser, 'Attributed to the tnuserid match');
        $this->assertNotSame($tnId, (int) $message->fromuser, 'Never to the account that merely holds the same number');
    }

    public function test_preserves_the_tn_post_date_from_an_iso_string(): void
    {
        // Array-shaped posts (fixtures, --local-testing, anything rebuilt from
        // JSON) carry the date as an ISO-8601 string rather than a DateTime.
        // It must still land in messages.date, not be replaced by now().
        $group  = $this->createTestGroup();
        $user   = $this->createTnUser();
        $postId = 'tn-date-string-' . uniqid();
        $post   = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->tnuserid,
            'date'    => '2026-07-07T12:00:00Z',
        ]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Message row must be created');
        $this->assertSame('2026-07-07 12:00:00', (string) $message->date);
    }

    public function test_preserves_the_tn_post_date_from_a_datetime(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTnUser();
        $postId = 'tn-date-object-' . uniqid();
        $post   = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->tnuserid,
            'date'    => new \DateTime('2026-07-07T12:00:00Z'),
        ]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Message row must be created');
        $this->assertSame('2026-07-07 12:00:00', (string) $message->date);
    }

    public function test_falls_back_to_now_when_the_post_date_is_unusable(): void
    {
        $group  = $this->createTestGroup();
        $user   = $this->createTnUser();
        $postId = 'tn-date-bad-' . uniqid();
        $post   = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->tnuserid,
            'date'    => 'not a date',
        ]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'An unparseable date must not lose the post');
        $this->assertEqualsWithDelta(now()->timestamp, strtotime((string) $message->date), 60);
    }

    public function test_second_ingest_of_the_same_post_is_a_duplicate(): void
    {
        // If the same post arrives again (overlap window), the second call must detect
        // the duplicate rather than write a second message.
        $user   = $this->createTnUser();
        $group  = $this->createTestGroup();
        $postId = 'tn-idem-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $svc    = $this->makeService(dryRun: false);

        $first  = $svc->ingest($post, $group);
        $second = $svc->ingest($post, $group);

        $this->assertNotSame('skipped', $first);
        $this->assertSame('duplicate', $second);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
    }

    /**
     * A post on the line between two groups can resolve to either on different reads
     * of the same window. It is still one post, so the second read must not place it
     * on the neighbour as well.
     */
    public function test_a_post_read_again_on_a_neighbouring_group_is_a_duplicate(): void
    {
        $user   = $this->createTnUser();
        $first  = $this->createTestGroup();
        $second = $this->createTestGroup();
        $postId = 'tn-boundary-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $svc    = $this->makeService(dryRun: false);

        $this->assertNotSame('skipped', $svc->ingest($post, $first));
        $this->assertSame('duplicate', $svc->ingest($post, $second));

        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
        $this->assertSame(0, DB::table('messages_groups')->where('groupid', $second->id)
            ->whereIn('msgid', Message::where('tnpostid', $postId)->pluck('id'))->count());
    }

    public function test_creates_post_for_non_member_of_resolved_group(): void
    {
        // The group here is resolved from the post's own coordinates
        // (Location::groupsNear), not from membership, so the poster is
        // frequently not a member of the resolved group — that must still
        // succeed, using the same 'DEFAULT' posting status a brand-new
        // member would get, rather than being skipped. Like any unmoderated
        // poster it lands Pending for messages:contentcheck to promote;
        // ContentCheckService::isUserModerated() applies the same DEFAULT
        // fallback for a TN post whose poster has no membership, so nothing
        // strands it there.
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        // Deliberately no membership created.

        $postId = 'tn-non-member-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Expected a messages row even though the poster is not a group member');

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg, 'Expected a messages_groups row for the non-member post');
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
    }

    public function test_returns_duplicate_when_post_already_ingested(): void
    {
        $user  = $this->createTnUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        // Pre-create a message row that looks like a previously-ingested post.
        $postId  = 'tn-dup-' . uniqid();
        $message = Message::create([
            'type'      => Message::TYPE_OFFER,
            'fromuser'  => $user->id,
            'subject'   => 'OFFER: Already ingested',
            'textbody'  => 'body',
            'source'    => 'TN-API',
            'tnpostid'  => $postId,
            'date'      => now(),
        ]);
        MessageGroup::create([
            'msgid'      => $message->id,
            'groupid'    => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival'    => now(),
        ]);

        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $result = $this->makeService()->ingest($post, $group);

        $this->assertSame('duplicate', $result);
    }

    // -------------------------------------------------------------------------
    // Dry-run: no DB writes, correct log output
    // -------------------------------------------------------------------------

    public function test_dry_run_returns_pending_for_unmapped_user(): void
    {
        $user  = $this->createTnUser(); // lastlocation defaults to null
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post   = $this->makePost(['user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: true)->ingest($post, $group);

        $this->assertSame('pending', $result);
    }

    public function test_dry_run_emits_write_trace_log_and_makes_no_db_writes(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $logLines = [];
        Log::listen(function ($message) use (&$logLines) {
            if (str_contains((string) $message->message, 'TN-SYNC-TRACE [WRITE]')) {
                $logLines[] = (string) $message->message;
            }
        });

        $post   = $this->makePost(['user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: true)->ingest($post, $group);

        // In dry-run the routing result is still computed correctly.
        $this->assertSame('pending', $result);

        // At least one WRITE trace line must have been emitted.
        $this->assertNotEmpty($logLines, 'Expected TN-SYNC-TRACE [WRITE] log lines in dry-run');

        // No messages row should have been created in the DB.
        $msgCount = DB::table('messages')->where('fromuser', $user->id)->count();
        $this->assertSame(0, $msgCount, 'Dry-run must not write to messages table');
    }

    // -------------------------------------------------------------------------
    // Live writes (dryRun = false)
    // -------------------------------------------------------------------------

    public function test_live_creates_message_and_messages_groups_rows(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        // An unmoderated poster starts Pending for the content check — see
        // test_unmoderated_poster_starts_pending_for_the_content_check().
        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message, 'Expected a messages row with tnpostid=' . $postId);
        $this->assertSame($user->id, $message->fromuser);
        $this->assertSame(Message::SOURCE_EMAIL, $message->source);
        $this->assertSame(Message::TYPE_OFFER, $message->type);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg, 'Expected a messages_groups row');
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
        $this->assertTrue($mg->mod_messaging_allowed, 'mod_messaging_allowed should default to true when not passed');
    }

    /**
     * The photos come with the post as attachments, so TN's link to them is not kept in
     * the text, as on the email path.
     */
    public function test_the_tn_link_to_the_photos_is_taken_out_of_the_text(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-pics-' . uniqid();
        $post   = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->tnuserid,
            'content' => "Sofas in great condition.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/2vwPHsx",
        ]);
        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertSame('Sofas in great condition.', $message->textbody);
    }

    /**
     * TN is the master for a TN member's location and tn:sync keeps lastlocation in
     * step with it. The post's coordinates place the post, not the member.
     */
    public function test_post_coordinates_do_not_move_a_member_who_has_a_location(): void
    {
        // Open sea, so no real postcode in the spatial index out-competes the sentinel.
        $postPcId = 99000301;
        $this->seedSpatialPoint('postcodes', $postPcId, 56.710, 3.110);
        try {
            DB::table('locations')->insert([
                'id' => $postPcId, 'name' => 'ZZ9 9ZY', 'type' => 'Postcode', 'lat' => 56.710, 'lng' => 3.110,
            ]);
            $homeId = $this->createTestLocation();
            $user  = $this->createTnUser(['lastlocation' => $homeId]);
            $group = $this->createTestGroup();
            $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

            $postId = 'tn-home-' . uniqid();
            $post   = $this->makePost([
                'post_id' => $postId, 'user_id' => $user->tnuserid, 'latitude' => 56.710, 'longitude' => 3.110,
            ]);
            $this->makeService(dryRun: false)->ingest($post, $group);

            $message = Message::where('tnpostid', $postId)->first();
            $this->assertNotNull($message);
            $this->assertEquals($postPcId, $message->locationid);
            $this->assertEquals($homeId, DB::table('users')->where('id', $user->id)->value('lastlocation'));
        } finally {
            $this->removeSpatial('postcodes', [$postPcId]);
        }
    }

    public function test_live_persists_mod_messaging_disallowed_when_specified(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-nomodmsg-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group, modMessagingAllowed: false);

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg);
        $this->assertFalse($mg->mod_messaging_allowed);
    }

    /**
     * The email path stopped approving unmoderated posters on arrival - they start
     * Pending so messages:contentcheck can gate them - and this path must do the
     * same. It previously mapped DEFAULT/UNMODERATED straight to approved, which
     * let a TN post that a content rule would block go live unchecked.
     *
     * Pending here must NOT notify mods or index the post: both belong to the
     * content-check job, so a clean post creates no mod work when it is promoted a
     * minute later, and a flagged one is never visible in the meantime.
     */
    public function test_unmoderated_poster_starts_pending_for_the_content_check(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'UNMODERATED']);

        $notified = false;
        Log::listen(function ($message) use (&$notified) {
            if (str_contains((string) $message->message, 'notified group mods')) {
                $notified = true;
            }
        });

        $postId = 'tn-awaiting-check-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $this->assertSame('pending', $this->makeService(dryRun: false)->ingest($post, $group));

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
        $this->assertNull($mg->approvedat, 'A post awaiting the content check has not been approved');
        // Left for the content check to do on promotion.
        $this->assertNull($mg->contentcheck_checked_at, 'Ingestion must not stamp the content check itself');
        $this->assertFalse($notified, 'Mods must not be notified for a post that is only awaiting the content check');
        $this->assertSame(
            0,
            DB::table('messages_spatial')->where('msgid', $message->id)->count(),
            'A post awaiting the content check must not be in the spatial index',
        );
    }

    /**
     * A member whose posting status is blank is moderated, as on the email path, the web
     * and in the content check: the post waits for a moderator, who is told now, rather
     * than being treated as an unmoderated poster waiting for the content check.
     */
    public function test_a_member_with_a_blank_posting_status_is_moderated(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => null]);

        $notified = false;
        Log::listen(function ($message) use (&$notified) {
            if (str_contains((string) $message->message, 'notified group mods')) {
                $notified = true;
            }
        });

        $postId = 'tn-blank-status-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $this->assertSame('pending', $this->makeService(dryRun: false)->ingest($post, $group));

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
        $this->assertTrue($notified, 'Mods are told about a post from a moderated member when it arrives');
    }

    public function test_live_creates_pending_message_when_group_is_moderated(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['settings' => json_encode(['moderated' => true])]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-live-mod-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);

        $mg = MessageGroup::where('msgid', $message->id)->where('groupid', $group->id)->first();
        $this->assertNotNull($mg);
        $this->assertSame(MessageGroup::COLLECTION_PENDING, $mg->collection);
    }

    public function test_live_is_idempotent_on_second_ingest(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-idem-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $svc    = $this->makeService(dryRun: false);

        $first  = $svc->ingest($post, $group);
        $second = $svc->ingest($post, $group);

        $this->assertSame('pending', $first);
        $this->assertSame('duplicate', $second);

        $count = Message::where('tnpostid', $postId)->count();
        $this->assertSame(1, $count, 'Second ingest must not create a second messages row');
    }

    public function test_overlap_window_does_not_duplicate_posts(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-overlap-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $svc    = $this->makeService(dryRun: false);

        // Simulates the first sync window: post is created.
        $first = $svc->ingest($post, $group);
        $this->assertSame('pending', $first);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());

        // Simulates the overlap window re-fetching the same post.
        // postAlreadyExists() must detect the duplicate and return early without
        // inserting a second messages row or messages_groups row.
        $second = $svc->ingest($post, $group);
        $this->assertSame('duplicate', $second);
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
        $this->assertSame(
            1,
            MessageGroup::whereIn('msgid', Message::where('tnpostid', $postId)->pluck('id'))->count(),
            'messages_groups must not acquire a second row for the same post',
        );
    }

    public function test_live_synthesizes_rfc822_blob_in_messages_message(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-rfc-' . uniqid();
        $post   = $this->makePost([
            'post_id'  => $postId,
            'user_id'  => $user->tnuserid,
            'title'    => 'Bicycle',
            'content'  => 'Blue bike, collect from porch.',
        ]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertNotEmpty($message->message, 'messages.message (RFC822 blob) must not be empty');
        $this->assertStringContainsString('X-Trashnothing-Post-Id: ' . $postId, $message->message);
        $this->assertStringContainsString('OFFER: Bicycle', $message->message);
    }

    /**
     * The 'TN-' prefix on messages.sourceheader is load-bearing: the monthly
     * LoveJunk invoice splits revenue on `sourceheader LIKE 'TN-%'`
     * (LoveJunkInvoiceService), LoveJunkService attributes the post's source by it,
     * and ProcessBackgroundTasksCommand uses it to skip creating freebie alerts for
     * TN posts (TN syndicates those itself). A post ingested by the API must
     * therefore be recognisable as TN, exactly as the email-path one is.
     */
    public function test_live_stamps_a_tn_sourceheader(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-src-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);

        $this->makeService(dryRun: false)->ingest($post, $group);

        $message = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($message);
        $this->assertSame(GroupPostIngestionService::SOURCEHEADER, $message->sourceheader);
        $this->assertStringStartsWith('TN-', (string) $message->sourceheader);
        $this->assertStringContainsString('X-Trash-Nothing-Source: API', $message->message);
    }

    // -------------------------------------------------------------------------
    // Worry words. Mirrors the email-path tests in IncomingMailServiceTest
    // (test_contains_worry_words_*): both paths must read concern_keywords, so
    // that identical content is routed identically however it arrives.
    //
    // Every unmoderated poster's post is now Pending on arrival (the content check
    // promotes the clean ones), so 'pending' alone no longer says whether the worry
    // words bit. The reason on the collection=Pending trace line does: 'worry words'
    // when held, 'posting-status' when the post is merely awaiting the content check.
    // -------------------------------------------------------------------------

    /**
     * Ingest live and return the reason from the collection=Pending trace line,
     * or null if the post was not routed to Pending at all.
     */
    private function ingestAndCapturePendingReason(array $post, $group): ?string
    {
        $reasons = [];
        Log::listen(function ($message) use (&$reasons) {
            $text = (string) $message->message;
            if (str_contains($text, 'set=collection=Pending reason=')) {
                $reasons[] = substr($text, strpos($text, 'reason=') + strlen('reason='));
            }
        });

        $this->makeService(dryRun: false)->ingest($post, $group);

        return $reasons[0] ?? null;
    }

    public function test_worry_words_hold_post_for_concern_keyword(): void
    {
        // Sanity check bounding the whitelist tests below: a genuine
        // (non-whitelisted) concern keyword must still hold the post.
        DB::table('concern_keywords')->insert([
            'keyword'  => 'cash',
            'category' => 'review',
            'action'   => 'flag',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-' . uniqid(),
            'user_id' => $user->tnuserid,
            'title'   => 'Sofa, cash on collection',
            'content' => 'Collection only, please bring a van.',
        ]);

        $this->assertSame('worry words', $this->ingestAndCapturePendingReason($post, $group));
    }

    public function test_worry_words_ignore_stale_legacy_worrywords_table(): void
    {
        // Regression guard (Discourse #9944/7): subjectContainsWorryWords() used to
        // read the legacy 'worrywords' table, a one-time migration snapshot that is
        // never written to again (see MigrateConcernKeywordsCommand). A row inserted
        // only there must NOT be able to hold a post - if it does, this method is
        // checking the wrong table again.
        DB::table('worrywords')->insert([
            'keyword' => 'puppy',
            'type'    => 'Review',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-legacy-' . uniqid(),
            'user_id' => $user->tnuserid,
            'title'   => 'Free puppy',
            'content' => 'Adorable puppy needs a good home.',
        ]);

        // Pending only because it is awaiting the content check, NOT because the
        // legacy row held it.
        $this->assertSame('posting-status', $this->ingestAndCapturePendingReason($post, $group));
    }

    public function test_worry_words_respect_whitelisted_phrase_despite_contained_keyword(): void
    {
        // Discourse #9944/7: 'Cashes Green' was whitelisted via the concern_keywords
        // 'allowed' category (the current admin UI), but the TN API path kept holding
        // such posts because it read the legacy 'worrywords' table, which never
        // received the whitelist row - so identical content was held here while being
        // let through by email.
        //
        // The single-word check is EXACT match only (levenshtein < 1), so this uses a
        // keyword that is a whole word inside the whitelisted phrase ('green' inside
        // 'Cashes Green'). The legacy table is seeded with the same keyword (but NOT
        // the whitelist row, which only ever existed in concern_keywords) so the test
        // genuinely fails against the pre-fix code rather than passing because the
        // legacy table had nothing to match on.
        DB::table('worrywords')->insert([
            'keyword' => 'green',
            'type'    => 'Review',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'  => 'green',
            'category' => 'review',
            'action'   => 'flag',
        ]);
        DB::table('concern_keywords')->insert([
            'keyword'  => 'Cashes Green',
            'category' => 'allowed',
            'action'   => 'flag',
        ]);

        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $post = $this->makePost([
            'post_id' => 'tn-worry-allowed-' . uniqid(),
            'user_id' => $user->tnuserid,
            'title'   => 'Sofa near Cashes Green (Stroud)',
            'content' => 'Collection only, please bring a van.',
        ]);

        // Whitelisted phrase must suppress the contained 'green' match, leaving the
        // post Pending only for the content check rather than held for worry words.
        $this->assertSame('posting-status', $this->ingestAndCapturePendingReason($post, $group));
    }

    // -------------------------------------------------------------------------
    // Reposts vs crossposts. TN keeps a SOURCE post (no group_id) plus one COPY
    // per group it was sent to (group_id set), so each group's mods can moderate
    // their own copy. Copies are crossposts and are discarded — Freegle does its
    // own cross-posting via rippling. A repost is a new source post, so it has no
    // group_id and is kept as its own message, matching the email path.
    // -------------------------------------------------------------------------

    public function test_repost_creates_a_second_message_rather_than_bumping_the_original(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $original = $this->createTestMessage($user, $group, [
            'subject' => 'OFFER: Old wooden bookshelf',
            'lat'     => 55.9533,
            'lng'     => -3.1883,
            'arrival' => now()->subMinutes(10),
        ]);
        $originalArrival = MessageGroup::where('msgid', $original->id)->where('groupid', $group->id)->first()->arrival;

        // Same poster, same item, same spot, new TN post_id and no group_id —
        // a repost (a new source post), which is kept, matching the email path.
        $postId = 'tn-repost-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'title'     => 'Old wooden bookshelf',
            'latitude'  => 55.9534,
            'longitude' => -3.1882,
            'date'      => now()->addMinute()->toIso8601String(),
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);

        $reposted = Message::where('tnpostid', $postId)->first();
        $this->assertNotNull($reposted, 'A repost must create its own new message');
        $this->assertNotSame($original->id, $reposted->id, 'The repost must be a separate row, not the original re-pointed');

        // The original is left entirely alone — no bump, no Repost log.
        $mg = MessageGroup::where('msgid', $original->id)->where('groupid', $group->id)->first();
        $this->assertSame(0, $mg->autoreposts);
        $this->assertEquals($originalArrival, $mg->arrival);
        $this->assertSame(0, DB::table('logs')->where('msgid', $original->id)->where('subtype', 'Repost')->count());
        $this->assertSame(0, DB::table('messages_postings')->where('msgid', $original->id)->where('repost', 1)->count());
    }

    public function test_post_with_a_tn_group_id_is_discarded_as_a_crosspost(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-crosspost-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => '8444', // TN's per-group copy — a crosspost.
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('crosspost', $result);
        $this->assertNull(Message::where('tnpostid', $postId)->first(), 'A per-group copy must not create a message');
    }

    public function test_source_post_without_a_group_id_is_ingested(): void
    {
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-source-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => null,
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $result = $this->makeService(dryRun: false)->ingest($post, $group);

        $this->assertSame('pending', $result);
        $this->assertNotNull(Message::where('tnpostid', $postId)->first());
    }

    public function test_empty_string_group_id_counts_as_a_source_post(): void
    {
        // TN has been seen returning '' rather than null for "no group"; that is
        // still a source post, not a copy, and must not be silently discarded.
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-source-empty-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'group_id'  => '',
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $this->assertSame('pending', $this->makeService(dryRun: false)->ingest($post, $group));
        $this->assertNotNull(Message::where('tnpostid', $postId)->first());
    }

    public function test_a_deleted_message_still_counts_as_a_duplicate(): void
    {
        // Deleting is a decision — a moderator, the member, or a user purge.
        // A later run whose window still covers the post (every overlap does)
        // must not undo it, so the idempotency guard deliberately does NOT
        // filter on messages.deleted the way the email path's
        // findLiveTnMessage() does. See existingMessage().
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-deleted-dup-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $service = $this->makeService(dryRun: false);
        $this->assertSame('pending', $service->ingest($post, $group));

        $message = Message::where('tnpostid', $postId)->firstOrFail();
        $message->deleted = now();
        $message->save();

        $this->assertSame('duplicate', $service->ingest($post, $group));
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
    }

    public function test_a_deleted_message_whose_tnpostid_was_cleared_does_not_block_a_later_ingest(): void
    {
        // The two paths that soft-delete a TN message as a DUPLICATE rather than
        // as a decision — the email path's lost-create-race branch and
        // TnMergeCrosspostsCommand — null tnpostid in the same update. That is
        // what keeps them out of the guard, so the post can still be ingested.
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-merged-away-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $service = $this->makeService(dryRun: false);
        $this->assertSame('pending', $service->ingest($post, $group));

        // Both callers clear messageid alongside tnpostid, which is what lets a
        // fresh copy be created without colliding on the messageid unique index.
        $message = Message::where('tnpostid', $postId)->firstOrFail();
        DB::table('messages')->where('id', $message->id)->update([
            'deleted'   => now(),
            'tnpostid'  => null,
            'messageid' => null,
        ]);

        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
    }

    public function test_the_same_tn_post_id_is_still_skipped_as_a_duplicate_on_the_same_group(): void
    {
        // The one de-duplication that remains: (tnpostid, groupid) idempotency,
        // so an overlapping sync window doesn't ingest the same TN post twice.
        $locationId = $this->createTestLocation();
        $user  = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT']);

        $postId = 'tn-same-postid-' . uniqid();
        $post   = $this->makePost([
            'post_id'   => $postId,
            'user_id'   => $user->tnuserid,
            'title'     => 'Old wooden bookshelf',
            'latitude'  => 55.9533,
            'longitude' => -3.1883,
        ]);

        $service = $this->makeService(dryRun: false);
        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame('duplicate', $service->ingest($post, $group));
        $this->assertSame(1, Message::where('tnpostid', $postId)->count());
    }

    // -------------------------------------------------------------------------
    // Routing context — the input to the caller's single Loki entry.
    //
    // This service does not log to Loki itself, exactly as IncomingMailService
    // doesn't: it accumulates context that PostSyncer turns into one entry. So
    // these tests assert getLastRoutingContext(), the same seam the email path
    // exposes. End-to-end payload/label shape is covered by TnApiLokiParityTest.
    // See plans/tn-api-post-ingestion.md section I.
    // -------------------------------------------------------------------------

    public function test_ingested_post_sets_full_routing_context(): void
    {
        $locationId = $this->createTestLocation();
        $user = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $postId = 'tn-ctx-ingested-'.uniqid();
        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::PENDING, GroupPostIngestionService::outcomeFor('pending'));
        // The 'approved' mapping is still exercised: the write branch is retained for
        // any future caller, mirroring the same retained branch on the email path.
        $this->assertSame(RoutingResult::APPROVED, GroupPostIngestionService::outcomeFor('approved'));

        $context = $service->getLastRoutingContext();
        $this->assertSame($group->id, $context['group_id']);
        $this->assertSame($group->nameshort, $context['group_name']);
        $this->assertSame($user->id, $context['user_id']);
        $this->assertArrayNotHasKey('routing_reason', $context);
        // The join key back to messages.tnpostid for the email-side comparison.
        $this->assertSame(Message::where('tnpostid', $postId)->first()->id, $context['message_id']);
    }

    public function test_pending_context_omits_the_pending_reason(): void
    {
        // The email path computes the same reason and keeps it out of its Loki
        // context (case 9), so including it here would read as a difference
        // between the paths where none exists.
        $user = $this->createTnUser(['lastlocation' => null]);  // unmapped -> pending
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $post = $this->makePost(['post_id' => 'tn-ctx-pending-'.uniqid(), 'user_id' => $user->tnuserid]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::PENDING, GroupPostIngestionService::outcomeFor('pending'));
        $this->assertArrayNotHasKey('routing_reason', $service->getLastRoutingContext());
    }

    public function test_duplicate_sets_a_duplicate_reason_and_nothing_else(): void
    {
        $user = $this->createTnUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $postId = 'tn-ctx-dup-'.uniqid();
        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Already ingested',
            'textbody' => 'body',
            'source' => 'TN-API',
            'tnpostid' => $postId,
            'date' => now(),
        ]);
        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $service = $this->makeService();

        $this->assertSame('duplicate', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::DROPPED, GroupPostIngestionService::outcomeFor('duplicate'));
        // dropped() REPLACES the context, so an early drop carries the reason
        // and nothing else — exactly as the email path's early drops do.
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_DUPLICATE],
            $service->getLastRoutingContext(),
        );
    }

    public function test_unknown_user_sets_the_email_paths_own_reason_wording(): void
    {
        // Mirrors email-path case 2, including its omission of group context.
        $group = $this->createTestGroup();
        $post = $this->makePost(['post_id' => 'tn-ctx-unknown-'.uniqid(), 'user_id' => 999999999]);
        $service = $this->makeService(dryRun: true);

        $this->assertSame('skipped', $service->ingest($post, $group));
        $this->assertSame(
            ['routing_reason' => 'Post from unknown user'],
            $service->getLastRoutingContext(),
        );
    }

    public function test_prohibited_keeps_group_context_but_sets_no_reason(): void
    {
        // Mirrors email-path case 6, which returns DROPPED without going through
        // dropped() and so carries group/user context and no routing_reason.
        $locationId = $this->createTestLocation();
        $user = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'PROHIBITED']);

        $post = $this->makePost(['post_id' => 'tn-ctx-prohibited-'.uniqid(), 'user_id' => $user->tnuserid]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('dropped', $service->ingest($post, $group));

        $context = $service->getLastRoutingContext();
        $this->assertArrayNotHasKey('routing_reason', $context);
        $this->assertSame($group->id, $context['group_id']);
        $this->assertSame($user->id, $context['user_id']);
        $this->assertArrayNotHasKey('message_id', $context);
    }

    public function test_crosspost_sets_a_crosspost_reason_explaining_the_divergence(): void
    {
        // A TN per-group COPY is discarded (Freegle ripples instead), whereas
        // the email path ingests one message per group copy. That volume
        // difference is intended, so it must be explained in the stream rather
        // than looking like posts going missing.
        $user = $this->createTnUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $post = $this->makePost([
            'post_id' => 'tn-ctx-crosspost-'.uniqid(),
            'user_id' => $user->tnuserid,
            'group_id' => '8444',  // TN's per-group copy.
        ]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('crosspost', $service->ingest($post, $group));
        $this->assertSame(RoutingResult::DROPPED, GroupPostIngestionService::outcomeFor('crosspost'));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_CROSSPOST],
            $service->getLastRoutingContext(),
        );
    }

    public function test_context_is_reset_between_posts(): void
    {
        // Mirrors IncomingMailService::route() clearing the context at entry:
        // without it, a drop would inherit the previous post's group/user.
        $locationId = $this->createTestLocation();
        $user = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);
        $service = $this->makeService(dryRun: false);

        $service->ingest($this->makePost(['post_id' => 'tn-ctx-first-'.uniqid(), 'user_id' => $user->tnuserid]), $group);
        $this->assertArrayHasKey('message_id', $service->getLastRoutingContext());

        // A crosspost drops before any group/user context is set.
        $service->ingest($this->makePost([
            'post_id' => 'tn-ctx-second-'.uniqid(),
            'user_id' => $user->tnuserid,
            'group_id' => '8444',
        ]), $group);

        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_CROSSPOST],
            $service->getLastRoutingContext(),
            'Context from the previous post must not leak into this one',
        );
    }

    public function test_repost_routes_like_any_other_source_post(): void
    {
        // Reposts are no longer de-duplicated (they match the email path: a
        // repost is a new source post and creates its own message), so there is
        // no special repost outcome — it routes, and is logged, exactly like a
        // first-time post. This is what closed the old "what subtype should
        // `reposted` use?" question.
        $locationId = $this->createTestLocation();
        $user = $this->createTnUser(['lastlocation' => $locationId]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $original = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Electric sander',
            'textbody' => 'body',
            'source' => 'TN-API',
            'tnpostid' => 'tn-ctx-orig-'.uniqid(),
            'date' => now()->subDays(2),
            'lat' => 55.9533,
            'lng' => -3.1883,
        ]);
        MessageGroup::create([
            'msgid' => $original->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now()->subDays(2),
        ]);

        $postId = 'tn-ctx-repost-'.uniqid();
        $post = $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->tnuserid,
            'title' => 'Electric sander',
            'type' => 'offer',
            'latitude' => 55.9533,
            'longitude' => -3.1883,
            'date' => now()->toIso8601String(),
        ]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('pending', $service->ingest($post, $group));

        $context = $service->getLastRoutingContext();
        $this->assertArrayNotHasKey('routing_reason', $context);
        // Its own new message, not the original.
        $this->assertSame(Message::where('tnpostid', $postId)->first()->id, $context['message_id']);
        $this->assertNotSame($original->id, $context['message_id']);
    }

    // -------------------------------------------------------------------------
    // Photo selection
    // -------------------------------------------------------------------------

    /**
     * @return string|null the URL bestPhotoUrl() would download for $photo
     */
    private function bestPhotoUrl(mixed $photo): ?string
    {
        $method = new \ReflectionMethod(GroupPostIngestionService::class, 'bestPhotoUrl');

        return $method->invoke($this->makeService(), $photo);
    }

    public function test_best_photo_url_takes_the_largest_image_not_the_smallest(): void
    {
        // TN documents photos[].images as ordered SMALLEST to largest (see
        // PublicApi/docs/Model/Photo.md), so images[0] is a thumbnail. Taking
        // it ingested a 220x294 image live where the email path, which scrapes
        // the post body, got the 1200x900 original for the same post.
        $photo = [
            'url' => 'https://trashnothing.com/img/large.jpg',
            'images' => [
                ['url' => 'https://trashnothing.com/img/photo.220x294.jpg', 'width' => 220, 'height' => 294],
                ['url' => 'https://trashnothing.com/img/photo.600x450.jpg', 'width' => 600, 'height' => 450],
                ['url' => 'https://trashnothing.com/img/photo.1200x900.jpg', 'width' => 1200, 'height' => 900],
            ],
        ];

        $this->assertSame('https://trashnothing.com/img/photo.1200x900.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_falls_back_to_the_url_field_when_there_are_no_images(): void
    {
        $photo = ['url' => 'https://trashnothing.com/img/large.jpg', 'images' => []];

        $this->assertSame('https://trashnothing.com/img/large.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_falls_back_when_the_largest_image_carries_no_url(): void
    {
        // Every field on TN's Photo/PhotoImagesInner models is optional, so a
        // malformed last entry must not lose the photo entirely.
        $photo = [
            'url' => 'https://trashnothing.com/img/large.jpg',
            'images' => [
                ['url' => 'https://trashnothing.com/img/photo.220x294.jpg', 'width' => 220],
                ['width' => 1200],
            ],
        ];

        $this->assertSame('https://trashnothing.com/img/large.jpg', $this->bestPhotoUrl($photo));
    }

    public function test_best_photo_url_returns_null_when_the_photo_has_nothing_usable(): void
    {
        $this->assertNull($this->bestPhotoUrl(['images' => []]));
    }
    /**
     * The array form above only ever appears in fixtures; a live run gets the generated
     * OpenAPI Photo object, and the same smallest-to-largest rule has to hold for it.
     */
    public function test_best_photo_url_reads_an_openapi_photo_object_the_same_way(): void
    {
        $image = fn (?string $url) => new class($url)
        {
            public function __construct(private ?string $url) {}

            public function getUrl(): ?string
            {
                return $this->url;
            }
        };

        $photo = fn (array $images) => new class($images)
        {
            public function __construct(private array $images) {}

            public function getImages(): array
            {
                return $this->images;
            }

            public function getUrl(): ?string
            {
                return 'https://trashnothing.com/img/object-fallback.jpg';
            }
        };

        $this->assertSame(
            'https://trashnothing.com/img/object-1200x900.jpg',
            $this->bestPhotoUrl($photo([
                $image('https://trashnothing.com/img/object-220x294.jpg'),
                $image('https://trashnothing.com/img/object-1200x900.jpg'),
            ]))
        );

        // Every field on TN's models is optional, so a largest entry with no url of its own
        // must fall back rather than lose the photo.
        $this->assertSame(
            'https://trashnothing.com/img/object-fallback.jpg',
            $this->bestPhotoUrl($photo([$image(null)]))
        );

        $this->assertSame(
            'https://trashnothing.com/img/object-fallback.jpg',
            $this->bestPhotoUrl($photo([]))
        );
    }

    /**
     * The hash exists to deduplicate images, so it has to produce something for every byte
     * string it is handed. Returning null on data GD cannot decode would quietly switch dedup
     * off for exactly the files most likely to be repeat uploads of one photo.
     */
    public function test_image_hash_falls_back_to_a_digest_for_undecodable_data(): void
    {
        $method = new \ReflectionMethod(GroupPostIngestionService::class, 'computeImageHash');
        $method->setAccessible(true);
        $hash = fn (string $data) => $method->invoke($this->makeService(), $data);

        $notAnImage = $hash('this is not an image');

        $this->assertNotNull($notAnImage);
        $this->assertSame(16, strlen($notAnImage));

        // Deterministic, and different data hashes differently - without both, dedup either
        // never matches or matches everything.
        $this->assertSame($notAnImage, $hash('this is not an image'));
        $this->assertNotSame($notAnImage, $hash('different bytes entirely'));

        // A real image goes down the perceptual-hash path instead, and must still give 16 hex
        // characters so the two kinds of hash are interchangeable in the same column.
        $png = imagecreatetruecolor(8, 8);
        ob_start();
        imagepng($png);
        $pngData = (string) ob_get_clean();
        imagedestroy($png);

        $this->assertSame(16, strlen($hash($pngData)));
    }

    // -------------------------------------------------------------------------
    // The poster's membership of the group. Every TN post leaves its poster a
    // member. A group the poster did not choose (not in freegle_group_ids, so
    // modMessagingAllowed = false) gives them an implicit membership; one they
    // did choose gives them an ordinary one, or clears an implicit one.
    // -------------------------------------------------------------------------

    private function membershipOf(User $user, Group $group): ?object
    {
        return DB::table('memberships')->where('userid', $user->id)->where('groupid', $group->id)->first();
    }

    private function historyOf(User $user, Group $group): \Illuminate\Support\Collection
    {
        return DB::table('memberships_history')->where('userid', $user->id)->where('groupid', $group->id)->get();
    }

    private function joinLogsOf(User $user, Group $group): \Illuminate\Support\Collection
    {
        return DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
            ->where('type', 'Group')->where('subtype', 'Joined')->get();
    }

    private function ingestFor(User $user, Group $group, bool $modMessagingAllowed, bool $dryRun = false): string
    {
        $post = $this->makePost(['post_id' => 'tn-implicit-' . uniqid(), 'user_id' => $user->tnuserid]);

        return $this->makeService(dryRun: $dryRun)->ingest($post, $group, modMessagingAllowed: $modMessagingAllowed);
    }

    public function test_a_post_on_a_group_the_poster_did_not_choose_gives_them_an_implicit_membership(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        // Their own membership elsewhere, with mail on, which the implicit one does not copy.
        $this->createMembership($user, $this->createTestGroup(), [
            'emailfrequency' => -1, 'eventsallowed' => 1, 'volunteeringallowed' => 1,
        ]);

        $this->assertSame('pending', $this->ingestFor($user, $group, false));

        $m = $this->membershipOf($user, $group);
        $this->assertNotNull($m, 'the poster is now a member of the group the post was placed on');
        $this->assertSame(1, (int) $m->implicit);
        $this->assertSame(0, (int) $m->rippled, 'it is not a ripple membership');
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $m->added_origin);
        $this->assertSame(Membership::ROLE_MEMBER, $m->role);
        $this->assertSame(Membership::COLLECTION_APPROVED, $m->collection);
        $this->assertSame('DEFAULT', $m->ourPostingStatus);
        // Nothing tells a TN poster they are on the group, so it never starts mailing them.
        $this->assertSame(0, (int) $m->emailfrequency, 'mail is off, not copied as for a ripple join');
        $this->assertSame(0, (int) $m->eventsallowed);
        $this->assertSame(0, (int) $m->volunteeringallowed);

        // A history row, so memberships:process runs its abuse checks (and, by its origin,
        // sends no welcome), and a join log ModTools shows, made by nobody.
        $history = $this->historyOf($user, $group);
        $this->assertCount(1, $history);
        $this->assertSame(Membership::ADDED_ORIGIN_TN_UNADDRESSED, $history[0]->added_origin);
        $this->assertSame(1, (int) $history[0]->processingrequired);
        $this->assertSame(0, (int) $history[0]->rippled);

        $logs = $this->joinLogsOf($user, $group);
        $this->assertCount(1, $logs);
        $this->assertSame('TrashNothing unaddressed', $logs[0]->text);
        $this->assertNull($logs[0]->byuser, 'the system placed them, as for a ripple join');

        $this->assertSame(
            'joined',
            DB::table('rippling_reach_member_pending')->where('userid', $user->id)->value('reason')
        );
    }

    public function test_a_post_on_a_group_the_poster_chose_gives_them_an_ordinary_membership(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();

        $this->ingestFor($user, $group, true);

        $m = $this->membershipOf($user, $group);
        $this->assertNotNull($m, 'every TN post has a membership behind it');
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(Membership::ADDED_ORIGIN_PARTNER, $m->added_origin, 'consent through TN, as TN\'s own partner add');
        $this->assertSame(Membership::COLLECTION_APPROVED, $m->collection);
        $this->assertSame('DEFAULT', $m->ourPostingStatus, 'a blank status would hold their posts');

        $history = $this->historyOf($user, $group);
        $this->assertCount(1, $history);
        $this->assertSame(Membership::ADDED_ORIGIN_PARTNER, $history[0]->added_origin);

        $logs = $this->joinLogsOf($user, $group);
        $this->assertCount(1, $logs);
        $this->assertSame(GroupPostIngestionService::JOIN_LOG_CHOSEN, $logs[0]->text);
        $this->assertSame($user->id, (int) $logs[0]->byuser, 'their own join, as the partner add logs it');
    }

    public function test_a_second_post_on_the_same_group_adds_no_second_history_row_or_log(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();

        $this->ingestFor($user, $group, false);
        $this->ingestFor($user, $group, false);

        $this->assertCount(1, $this->historyOf($user, $group));
        $this->assertCount(1, $this->joinLogsOf($user, $group));
    }

    public function test_the_implicit_membership_does_not_hold_the_post_for_moderators(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        $postId = 'tn-implicit-check-' . uniqid();
        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);

        $this->makeService(dryRun: false)->ingest($post, $group, modMessagingAllowed: false);

        $msgid = (int) Message::where('tnpostid', $postId)->value('id');
        $this->assertNotNull($this->membershipOf($user, $group));
        $this->assertFalse(
            app(ContentCheckService::class)->isUserModerated($msgid, $group->id),
            'the content check treats the poster as it did with no membership at all'
        );
    }

    public function test_an_ordinary_membership_is_never_made_implicit(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT', 'emailfrequency' => 4, 'added_origin' => Membership::ADDED_ORIGIN_MANUAL,
        ]);

        $this->ingestFor($user, $group, false);

        $m = $this->membershipOf($user, $group);
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(4, (int) $m->emailfrequency, 'the existing row is left alone');
        $this->assertSame(Membership::ADDED_ORIGIN_MANUAL, $m->added_origin, 'it keeps the origin of how it was made');
        $this->assertCount(0, $this->historyOf($user, $group), 'nobody joined, so no history row');
        $this->assertCount(0, $this->joinLogsOf($user, $group));
    }

    public function test_a_post_with_consent_makes_an_implicit_membership_ordinary(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, [
            'ourPostingStatus' => 'DEFAULT', 'implicit' => 1, 'added_origin' => 'tn_unaddressed',
        ]);

        $this->ingestFor($user, $group, true);

        $this->assertSame(0, (int) $this->membershipOf($user, $group)->implicit);
        $this->assertSame(
            'TrashNothing-placed membership became ordinary: consented through TrashNothing',
            DB::table('logs')->where('user', $user->id)->where('groupid', $group->id)
                ->where('type', 'Group')->where('subtype', 'Joined')->value('text')
        );
    }

    public function test_a_membership_failure_is_reported_to_sentry_and_the_post_is_still_ingested(): void
    {
        $sentry = new class {
            public array $calls = [];

            public function captureException(\Throwable $e): void
            {
                $this->calls[] = $e->getMessage();
            }
        };
        $this->app->instance('sentry', $sentry);
        $engagement = $this->createMock(\App\Services\Ripple\RippleEngagementService::class);
        $engagement->method('clear')->willThrowException(new \RuntimeException('lock wait timeout'));
        $this->app->instance(\App\Services\Ripple\RippleEngagementService::class, $engagement);

        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['ourPostingStatus' => 'DEFAULT', 'implicit' => 1]);

        $postId = 'tn-implicit-fail-' . uniqid();
        $post = $this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]);
        $this->makeService(dryRun: false)->ingest($post, $group, modMessagingAllowed: true);

        $this->assertNotNull(Message::where('tnpostid', $postId)->first(), 'the post is kept');
        $this->assertSame(['lock wait timeout'], $sentry->calls);
    }

    public function test_the_join_log_carries_its_reason_in_the_same_transaction_as_the_membership(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();

        // The log insert fails: nothing of the join may be left behind.
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `logs`')
                && in_array(GroupPostIngestionService::JOIN_LOG_UNADDRESSED, $query->bindings, true)) {
                throw new \RuntimeException('log insert failed');
            }
        });
        try {
            $this->ingestFor($user, $group, false);
        } finally {
            DB::getEventDispatcher()->forget(\Illuminate\Database\Events\QueryExecuted::class);
        }

        $this->assertNull($this->membershipOf($user, $group));
        $this->assertCount(0, $this->historyOf($user, $group));
    }

    public function test_a_post_with_consent_makes_a_ripple_membership_ordinary_too(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        $this->createMembership($user, $group, ['rippled' => 1, 'implicit' => 1]);

        $this->ingestFor($user, $group, true);

        $m = $this->membershipOf($user, $group);
        $this->assertSame(0, (int) $m->implicit);
        $this->assertSame(0, (int) $m->rippled);
    }

    public function test_a_later_post_without_consent_leaves_a_cleared_membership_ordinary(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();

        $this->ingestFor($user, $group, false);
        $this->ingestFor($user, $group, true);
        $this->ingestFor($user, $group, false);

        $this->assertSame(0, (int) $this->membershipOf($user, $group)->implicit);
    }

    public function test_a_banned_poster_is_not_given_a_membership(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);

        $this->ingestFor($user, $group, false);

        $this->assertNull($this->membershipOf($user, $group));
    }

    public function test_a_banned_poster_gets_no_post_on_that_group(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);
        $post    = $this->makePost(['user_id' => $user->tnuserid]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('skipped', $service->ingest($post, $group, modMessagingAllowed: true));
        $this->assertSame(
            ['routing_reason' => GroupPostIngestionService::REASON_BANNED],
            $service->getLastRoutingContext(),
        );
        $this->assertFalse(
            DB::table('messages')->where('tnpostid', $post['post_id'])->exists(),
            'a post from a banned poster is not placed on the group, so its moderators never own it',
        );
        $this->assertNull($this->membershipOf($user, $group));
    }

    public function test_a_poster_banned_from_one_of_two_groups_gets_the_post_on_the_other_only(): void
    {
        $user    = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $banned  = $this->createTestGroup();
        $allowed = $this->createTestGroup();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $banned->id]);
        $post    = $this->makePost(['user_id' => $user->tnuserid]);
        $service = $this->makeService(dryRun: false);

        $this->assertSame('skipped', $service->ingest($post, $banned, modMessagingAllowed: true));
        $this->assertSame('pending', $service->ingest($post, $allowed, modMessagingAllowed: true));

        $groups = DB::table('messages_groups')
            ->join('messages', 'messages.id', '=', 'messages_groups.msgid')
            ->where('messages.tnpostid', $post['post_id'])
            ->pluck('messages_groups.groupid')
            ->all();
        $this->assertSame([$allowed->id], array_map('intval', $groups));
        $this->assertNull($this->membershipOf($user, $banned));
        $this->assertNotNull($this->membershipOf($user, $allowed));
    }

    public function test_a_dry_run_writes_no_membership_and_clears_nothing(): void
    {
        $user    = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $newTo   = $this->createTestGroup();
        $implicitOn = $this->createTestGroup();
        $this->createMembership($user, $implicitOn, ['implicit' => 1]);

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            if (str_contains((string) $message->message, 'TN-SYNC-TRACE [MEMBERSHIP]')) {
                $lines[] = (string) $message->message;
            }
        });

        $chosen = $this->createTestGroup();

        $this->ingestFor($user, $newTo, false, dryRun: true);
        $this->ingestFor($user, $implicitOn, true, dryRun: true);
        $this->ingestFor($user, $chosen, true, dryRun: true);

        $this->assertNull($this->membershipOf($user, $newTo));
        $this->assertNull($this->membershipOf($user, $chosen));
        $this->assertSame(1, (int) $this->membershipOf($user, $implicitOn)->implicit);
        foreach ([$newTo, $chosen] as $group) {
            $this->assertCount(0, $this->historyOf($user, $group));
            $this->assertCount(0, $this->joinLogsOf($user, $group));
        }
        $this->assertSame([
            'TN-SYNC-TRACE [MEMBERSHIP] op=insert-implicit user_id=' . $user->id . ' group_id=' . $newTo->id . ' added_origin=tn_unaddressed',
            'TN-SYNC-TRACE [MEMBERSHIP] op=clear-implicit user_id=' . $user->id . ' group_id=' . $implicitOn->id,
            'TN-SYNC-TRACE [MEMBERSHIP] op=insert-ordinary user_id=' . $user->id . ' group_id=' . $chosen->id . ' added_origin=partner',
        ], $lines, 'a dry run still says what it would have done');
    }

    // -------------------------------------------------------------------------
    // Posts skipped for want of a user are noted for tn:verify-email-coverage.
    // -------------------------------------------------------------------------

    public function test_a_post_skipped_for_a_poster_tn_does_not_know_is_noted(): void
    {
        $tnId   = random_int(900000000, 999999999);
        $group  = $this->createTestGroup();
        $postId = 'tn-deliberate-unknown-' . uniqid();

        $this->makeService(dryRun: false)->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group);

        $this->assertSame(GroupPostIngestionService::REASON_UNKNOWN_USER, DeliberateSkips::reasonFor($postId));
    }

    public function test_a_post_with_no_tn_user_id_is_noted(): void
    {
        $group  = $this->createTestGroup();
        $postId = 'tn-deliberate-no-user-' . uniqid();

        $this->makeService(dryRun: false)->ingest($this->makePost(['post_id' => $postId, 'user_id' => null]), $group);

        $this->assertSame(GroupPostIngestionService::REASON_NO_USER_ID, DeliberateSkips::reasonFor($postId));
    }

    public function test_every_final_reason_for_an_unresolved_poster_is_noted(): void
    {
        $group = $this->createTestGroup();

        // [reason, how the poster comes to be unresolvable]
        $refused = random_int(900000000, 999999999);
        $this->tnUsers[$refused] = [410, 'Gone'];

        $deletedId = random_int(900000000, 999999999);
        $this->createTestUser(['tnuserid' => $deletedId, 'deleted' => now()]);

        $cases = [
            GroupPostIngestionService::REASON_TN_USER_LOOKUP_REFUSED => $refused,
            GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED     => $deletedId,
        ];

        foreach ($cases as $reason => $tnId) {
            $postId = 'tn-deliberate-' . uniqid();
            $this->makeService(dryRun: false)->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group);

            $this->assertSame($reason, DeliberateSkips::reasonFor($postId), $reason);
        }
    }

    public function test_a_banned_posters_post_is_noted(): void
    {
        $user  = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        $group = $this->createTestGroup();
        DB::table('users_banned')->insert(['userid' => $user->id, 'groupid' => $group->id]);
        $postId = 'tn-deliberate-banned-' . uniqid();

        $this->makeService(dryRun: false)->ingest($this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]), $group);

        $this->assertSame(GroupPostIngestionService::REASON_BANNED, DeliberateSkips::reasonFor($postId));
    }

    /**
     * Nothing was decided when TN could not be asked, and a given-up post is still wanted: neither
     * leaves a note, and both stay misses for the verifier, which is its second chance at them.
     */
    public function test_a_failed_or_given_up_lookup_is_not_noted(): void
    {
        $tnId = random_int(900000000, 999999999);
        $this->tnUsers[$tnId] = [503, 'Service Unavailable'];
        $group  = $this->createTestGroup();
        $postId = 'tn-not-deliberate-' . uniqid();
        $post   = $this->makePost(['post_id' => $postId, 'user_id' => $tnId]);

        for ($run = 1; $run <= TnUserProvisioner::LOOKUP_GIVE_UP_AT; $run++) {
            $service = $this->makeService(dryRun: false);
            $service->ingest($post, $group);

            $this->assertNull(DeliberateSkips::reasonFor($postId), "run {$run}");
        }

        $this->assertSame(
            GroupPostIngestionService::REASON_TN_USER_LOOKUP_GAVE_UP,
            $service->getLastRoutingContext()['routing_reason'],
        );
    }

    public function test_a_dry_run_notes_nothing(): void
    {
        $tnId   = random_int(900000000, 999999999);
        $group  = $this->createTestGroup();
        $postId = 'tn-deliberate-dry-' . uniqid();

        $this->makeService(dryRun: true)->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group);

        $this->assertNull(DeliberateSkips::reasonFor($postId));
    }

    /** Fixtures stand in for TN, so what a --local-testing run decides is not a fact about a real post. */
    public function test_a_local_testing_run_notes_nothing(): void
    {
        $tnId   = random_int(900000000, 999999999);
        $group  = $this->createTestGroup();
        $postId = 'tn-deliberate-local-' . uniqid();

        $this->makeService(dryRun: false, localTesting: true)
            ->ingest($this->makePost(['post_id' => $postId, 'user_id' => $tnId]), $group);

        $this->assertNull(DeliberateSkips::reasonFor($postId));
    }

    /** Binds a recorder as 'sentry' and returns it. */
    private function recordSentry(): object
    {
        $sentry = new class {
            public array $calls = [];

            public function captureMessage($message, $severity = null): void
            {
                $this->calls[] = (string) $message;
            }
        };
        $this->app->instance('sentry', $sentry);

        return $sentry;
    }

    /** @return int a TN user id held by a deleted account, the one skip only a person can put right */
    private function posterIdThatNeedsAPerson(): int
    {
        $deletedId = random_int(900000000, 999999999);
        $this->createTestUser(['tnuserid' => $deletedId, 'deleted' => now()]);

        return $deletedId;
    }

    /**
     * A skip only a person can put right is sent to Sentry, once a day for each reason: the
     * sync meets the same poster's posts every minute.
     */
    public function test_a_skip_that_needs_a_person_is_reported_once_a_day_for_each_reason(): void
    {
        $sentry = $this->recordSentry();
        $group  = $this->createTestGroup();
        $tnId   = $this->posterIdThatNeedsAPerson();

        foreach (['first', 'second'] as $which) {
            $this->makeService(dryRun: false)
                ->ingest($this->makePost(['post_id' => "tn-human-{$tnId}-{$which}", 'user_id' => $tnId]), $group);
        }

        $this->assertCount(1, $sentry->calls, json_encode($sentry->calls));
        $this->assertStringContainsString(GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED, $sentry->calls[0]);

        $this->travel(25)->hours();
        $this->makeService(dryRun: false)
            ->ingest($this->makePost(['post_id' => 'tn-human-next-day', 'user_id' => $tnId]), $group);
        $this->travelBack();

        $this->assertCount(2, $sentry->calls, 'reported again the next day');
    }

    public function test_a_skip_nobody_needs_to_look_at_is_not_reported(): void
    {
        $sentry = $this->recordSentry();
        $group  = $this->createTestGroup();
        $banned = $this->createTnUser(['lastlocation' => $this->createTestLocation()]);
        DB::table('users_banned')->insert(['userid' => $banned->id, 'groupid' => $group->id]);

        $service = $this->makeService(dryRun: false);
        $service->ingest($this->makePost(['post_id' => 'tn-quiet-banned', 'user_id' => $banned->tnuserid]), $group);
        $service->ingest($this->makePost(['post_id' => 'tn-quiet-no-id', 'user_id' => null]), $group);
        $service->ingest($this->makePost(['post_id' => 'tn-quiet-unknown', 'user_id' => random_int(900000000, 999999999)]), $group);

        $this->assertSame([], $sentry->calls);
    }

    public function test_a_dry_run_or_a_local_testing_run_reports_nothing_to_sentry(): void
    {
        $sentry = $this->recordSentry();
        $group  = $this->createTestGroup();
        $tnId   = $this->posterIdThatNeedsAPerson();

        $this->makeService(dryRun: true)->ingest($this->makePost(['post_id' => 'tn-human-dry', 'user_id' => $tnId]), $group);
        $this->makeService(dryRun: false, localTesting: true)->ingest($this->makePost(['post_id' => 'tn-human-local', 'user_id' => $tnId]), $group);

        $this->assertSame([], $sentry->calls);
    }

    public function test_the_reason_that_needs_a_person_is_the_skip_no_retry_can_change(): void
    {
        $this->assertSame([
            GroupPostIngestionService::REASON_TN_ACCOUNT_DELETED,
        ], DeliberateSkips::NEEDS_A_HUMAN_REASONS);
        $this->assertFalse(DeliberateSkips::needsAHuman(GroupPostIngestionService::REASON_UNKNOWN_USER));
        $this->assertFalse(DeliberateSkips::needsAHuman(GroupPostIngestionService::REASON_BANNED));
    }

    public function test_a_post_that_is_ingested_is_not_noted(): void
    {
        $user   = $this->createTnUser();
        $group  = $this->createTestGroup();
        $postId = 'tn-deliberate-ingested-' . uniqid();

        $this->makeService(dryRun: false)->ingest($this->makePost(['post_id' => $postId, 'user_id' => $user->tnuserid]), $group);

        $this->assertNull(DeliberateSkips::reasonFor($postId));
    }
}

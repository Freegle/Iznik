<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Models\Message;
use App\Services\LokiService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\TrashNothing\Sync\PostSyncer;
use Tests\TestCase;

/**
 * End-to-end shape of the TN API path's Loki output, asserted against the email
 * path's real output rather than a hardcoded copy of it.
 *
 * The API path is structured to mirror the email path exactly:
 *
 *   email:  IncomingMailService::route()      -> getLastRoutingContext()
 *             -> IncomingMailController emits LokiService::logIncomingEmail()
 *
 *   api:    GroupPostIngestionService::ingest() -> getLastRoutingContext()
 *             -> PostSyncer emits LokiService::logIngestedPost()
 *
 * In both, the router never touches Loki and the caller emits exactly one
 * entry. These tests cover the emission end of that; the context end is covered
 * by GroupPostIngestionServiceTest.
 *
 * See plans/tn-api-post-ingestion.md section I.
 */
class TnApiLokiParityTest extends TestCase
{
    use CapturesRoutedLokiEntries;

    private LokiService $loki;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loki = $this->enableLokiCapture();
    }

    protected function tearDown(): void
    {
        $this->tearDownLokiCapture();
        parent::tearDown();
    }

    private function makeSyncer(bool $dryRun = false): PostSyncer
    {
        return new PostSyncer(
            dryRun: $dryRun,
            localTesting: false,
            apiKey: 'test-key',
            apiBaseUrl: 'https://example.invalid',
            loki: $this->loki,
        );
    }

    private function processPost(PostSyncer $syncer, array $post): void
    {
        $method = new \ReflectionMethod(PostSyncer::class, 'processPost');
        $method->invoke($syncer, $post, null);
    }

    /**
     * A mapped user routes Approved rather than Pending (an unmapped user is
     * forced to Pending), which the shape test needs so both sides compare the
     * same outcome.
     */
    private function createMappedUser(): \App\Models\User
    {
        $locationId = (int) \Illuminate\Support\Facades\DB::table('locations')->insertGetId([
            'name' => 'TestLocation_'.uniqid(),
            'type' => 'Postcode',
            'lat' => 55.9533,
            'lng' => -3.1883,
        ]);

        return $this->createTestUser(['lastlocation' => $locationId]);
    }

    private function makePost(array $overrides = []): array
    {
        return array_merge([
            'post_id' => 'tn-loki-'.uniqid('', true),
            'group_id' => null,
            'user_id' => null,
            'title' => 'Old wooden bookshelf',
            'content' => 'Good condition, free to collect.',
            'date' => '2026-07-07T12:00:00Z',
            'type' => 'offer',
            'outcome' => null,
            'latitude' => null,
            'longitude' => null,
            'photos' => [],
        ], $overrides);
    }

    public function test_api_entry_has_the_same_shape_as_an_email_path_entry(): void
    {
        // An email-path entry for an approved group post, exactly as
        // IncomingMailController::receive() emits it.
        $this->loki->logIncomingEmail(
            'poster@example.com',
            'testgroup@groups.ilovefreegle.org',
            'poster@example.com',
            'OFFER: Bookshelf (Edinburgh)',
            '<abc123@mail.example.com>',
            RoutingResult::APPROVED->value,
            ['group_id' => 42, 'group_name' => 'testgroup', 'user_id' => 7, 'message_id' => 999],
        );

        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createMappedUser();
        $this->createMembership($user, $group);

        $this->processPost($this->makeSyncer(), $this->makePost([
            'user_id' => $user->id,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        [$email, $api] = $this->routedEntries();

        // Both sides routed Approved, so the subtype must match too — this is a
        // like-for-like comparison, not just a structural one.
        $this->assertSame(RoutingResult::APPROVED->value, $api['labels']['subtype']);

        // Same label keys, and identical values on every label but source.
        $this->assertSame(array_keys($email['labels']), array_keys($api['labels']));
        $this->assertSame('incoming_mail', $email['labels']['source']);
        $this->assertSame('tn_api', $api['labels']['source']);
        unset($email['labels']['source'], $api['labels']['source']);
        $this->assertSame($email['labels'], $api['labels']);

        // The API entry carries every field the email entry does. It also adds
        // tn_post_id, which the email path structurally cannot supply — see
        // section I.5a — so this is a subset check in that direction only.
        $this->assertSame(
            [],
            array_diff(array_keys($email['message']), array_keys($api['message'])),
            'API routing entry is missing fields the email path emits'
        );
        $this->assertSame(['tn_post_id'], array_values(array_diff(
            array_keys($api['message']), array_keys($email['message'])
        )));
    }

    public function test_emits_exactly_one_entry_per_ingested_post(): void
    {
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createTestUser(['lastlocation' => null]);
        $this->createMembership($user, $group);

        $postId = 'tn-loki-one-'.uniqid();
        $this->processPost($this->makeSyncer(), $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->id,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        // onlyRoutedEntry() is the assertion: exactly one, never zero or two.
        $entry = $this->onlyRoutedEntry();

        $this->assertSame('routed', $entry['labels']['type']);
        $this->assertSame($postId, $entry['message']['tn_post_id']);
        $this->assertSame($group->id, $entry['message']['group_id']);
        $this->assertSame($user->id, $entry['message']['user_id']);
        $this->assertSame('OFFER: Old wooden bookshelf', $entry['message']['subject']);
        $this->assertSame($group->nameshort.'@groups.ilovefreegle.org', $entry['message']['envelope_to']);
    }

    public function test_created_message_id_overwrites_the_synthesized_one(): void
    {
        // The FD message id is the join key back to messages.tnpostid, which is
        // how the email side is correlated (section I.5a). It must win over the
        // synthesized RFC822 id, exactly as the email path's context does.
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createTestUser(['lastlocation' => null]);
        $this->createMembership($user, $group);

        $postId = 'tn-loki-msgid-'.uniqid();
        $this->processPost($this->makeSyncer(), $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->id,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        $this->assertSame(
            Message::where('tnpostid', $postId)->first()->id,
            $this->onlyRoutedEntry()['message']['message_id'],
        );
    }

    public function test_falls_back_to_the_synthesized_message_id_when_no_message_was_created(): void
    {
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createTestUser();
        $this->createMembership($user, $group);

        $postId = 'tn-loki-nomsg-'.uniqid();
        $this->processPost($this->makeSyncer(), $this->makePost([
            'post_id' => $postId,
            'user_id' => $user->id,
            'group_id' => '8444',  // Crosspost: discarded, no message created.
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        $message = $this->onlyRoutedEntry()['message'];

        // Matches messages.messageid as GroupPostIngestionService would write it.
        $this->assertSame($postId.'@tn.trashnothing.com-'.$group->id, $message['message_id']);
        $this->assertSame('crosspost', $message['routing_reason']);
        $this->assertSame(RoutingResult::DROPPED->value, $message['routing_outcome']);
    }

    public function test_dry_run_entries_are_emitted_and_tagged(): void
    {
        // Loki entries are NOT suppressed in dry-run — comparing the two paths
        // during a parallel run is the whole point — but they must be
        // distinguishable from real ingestion.
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createTestUser(['lastlocation' => null]);
        $this->createMembership($user, $group);

        $this->processPost($this->makeSyncer(dryRun: true), $this->makePost([
            'user_id' => $user->id,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        $this->assertTrue($this->onlyRoutedEntry()['message']['dry_run']);
    }

    public function test_emits_nothing_when_loki_is_disabled(): void
    {
        config(['freegle.loki.enabled' => false]);
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createTestUser(['lastlocation' => null]);
        $this->createMembership($user, $group);

        $syncer = new PostSyncer(
            dryRun: false,
            localTesting: false,
            apiKey: 'test-key',
            apiBaseUrl: 'https://example.invalid',
            loki: new LokiService,
        );

        $this->processPost($syncer, $this->makePost([
            'user_id' => $user->id,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        $this->assertSame([], $this->routedEntries());
    }
}

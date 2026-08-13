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

    /**
     * Runs ONE TN post through BOTH real ingestion paths and diffs the two Loki
     * entries they produce.
     *
     * This is the test the whole section exists for. The other tests here check
     * the API entry against a hand-built email entry, which only proves the two
     * SCHEMAS agree; this one proves the two PATHS agree — same post in, same
     * routing outcome and same context out.
     *
     * The email side runs IncomingMailService::route() for real and then emits
     * the Loki entry exactly as IncomingMailController::receive() does. The test
     * plays the controller's role rather than modifying it: the email path is
     * frozen, and EmailReplaySyncer (the parity tool's email side) deliberately
     * does not emit these entries — see plans/tn-api-post-ingestion.md I.6.
     *
     * Both sides are pointed at the SAME user and group, so a difference in the
     * compared fields is a real divergence rather than test-fixture noise.
     */
    public function test_both_paths_produce_the_same_loki_entry_for_the_same_post_content(): void
    {
        $group = $this->createTestGroup(['lat' => 55.9533, 'lng' => -3.1883]);
        $user = $this->createMappedUser();
        $userEmail = $this->createTestUserEmail($user, ['preferred' => 1]);
        // MODERATED, not DEFAULT, deliberately: a DEFAULT poster walks into an
        // already-documented divergence — IncomingMailService::handleGroupPost()
        // no longer auto-approves DEFAULT posters on arrival (they wait for the
        // content-check job) while GroupPostIngestionService::ingest() still
        // does, so identical content would route Pending vs Approved for reasons
        // that have nothing to do with Loki. MODERATED pends on both paths,
        // isolating what this test is actually for. Same reasoning as
        // EmailApiParityTest::seedParityUser().
        $this->createMembership($user, $group, ['ourPostingStatus' => 'MODERATED']);

        // The two paths deliberately synthesize the SAME messages.messageid for a
        // given TN post_id (see EmailReplaySyncer::parseCsvRow), and the API
        // path's idempotency check keys on tnpostid — so running both against
        // one post_id in a single database makes the second path see the first
        // path's row and skip as a duplicate. Each side therefore gets its own
        // post_id for the SAME post content. Nothing being compared below
        // depends on the id: the routing decision and its context come from the
        // content, group and user, which are identical for both.
        $emailPostId = 'tn-bothpaths-email-'.uniqid();
        $apiPostId = 'tn-bothpaths-api-'.uniqid();
        $title = 'Old wooden bookshelf';
        $subject = 'OFFER: '.$title;

        // --- email path: parse a TN post email, route it, emit as the controller does
        $envelopeTo = $group->nameshort.'@'.config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
        $raw = $this->buildTnPostEmail($userEmail->email, $envelopeTo, $subject, $emailPostId);

        $parser = app(\App\Services\Mail\Incoming\MailParserService::class);
        $mailService = app(\App\Services\Mail\Incoming\IncomingMailService::class);

        $parsed = $parser->parse($raw, $userEmail->email, $envelopeTo);
        $emailResult = $mailService->route($parsed);

        // Verbatim from IncomingMailController::receive().
        $this->loki->logIncomingEmail(
            $userEmail->email,
            $envelopeTo,
            $parsed->fromAddress,
            $parsed->subject ?? '',
            $parsed->messageId ?? '',
            $emailResult->value,
            $mailService->getLastRoutingContext(),
        );

        // --- API path: the same post, through the real syncer
        $this->processPost($this->makeSyncer(), $this->makePost([
            'post_id' => $apiPostId,
            'user_id' => $user->id,
            'title' => $title,
            'latitude' => 55.9533,
            'longitude' => -3.1883,
        ]));

        $entries = $this->routedEntries();
        $this->assertCount(2, $entries, 'Expected one entry from each path');
        [$email, $api] = $entries;

        // Guard: if the email path dropped the post before reaching the group
        // post handler, the comparison below would be vacuous.
        $this->assertSame(
            RoutingResult::PENDING->value,
            $email['message']['routing_outcome'],
            'Email path did not ingest the post, so there is nothing meaningful to compare'
        );

        // Labels: identical but for source.
        $this->assertSame('incoming_mail', $email['labels']['source']);
        $this->assertSame('tn_api', $api['labels']['source']);
        unset($email['labels']['source'], $api['labels']['source']);
        $this->assertSame($email['labels'], $api['labels'], 'Label sets diverged between the paths');

        // The API entry carries every field the email entry does, and exactly
        // one extra (tn_post_id).
        $this->assertSame(
            [],
            array_diff(array_keys($email['message']), array_keys($api['message'])),
            'API entry is missing fields the email path emits'
        );
        $this->assertSame(['tn_post_id'], array_values(array_diff(
            array_keys($api['message']), array_keys($email['message'])
        )));

        // The fields that must agree: same post, same group, same user, so the
        // same routing decision and the same describing context.
        foreach (['routing_outcome', 'subject', 'group_id', 'group_name', 'user_id'] as $field) {
            $this->assertSame(
                $email['message'][$field] ?? null,
                $api['message'][$field] ?? null,
                "Field '{$field}' diverged between the email and API paths"
            );
        }

        // Neither path sets a routing_reason on a clean approval.
        $this->assertArrayNotHasKey('routing_reason', $email['message']);
        $this->assertArrayNotHasKey('routing_reason', $api['message']);

        // Fields that differ BY DESIGN, asserted explicitly so the divergence
        // stays deliberate rather than drifting unnoticed:
        //  - envelope_from/from_address: the API path has no SMTP envelope.
        //  - message_id: each path created its own messages row.
        //  - tn_post_id: API-only, see I.5a.
        $this->assertSame($userEmail->email, $email['message']['envelope_from']);
        $this->assertSame('', $api['message']['envelope_from']);
        $this->assertSame($userEmail->email, $email['message']['from_address']);
        $this->assertSame('', $api['message']['from_address']);
        $this->assertIsInt($email['message']['message_id']);
        $this->assertIsInt($api['message']['message_id']);
        $this->assertSame($apiPostId, $api['message']['tn_post_id']);
        $this->assertArrayNotHasKey('tn_post_id', $email['message']);
    }

    /**
     * A TN group-post email, headers matching EmailReplaySyncer::buildRawEmail().
     *
     * X-Trash-Nothing-Secret must be PRESENT (even empty) or
     * IncomingMailService::shouldSkipSpamCheck()'s unconfigured-secret fallback
     * never fires and the post routes as spam instead.
     */
    private function buildTnPostEmail(string $from, string $to, string $subject, string $postId): string
    {
        $headers = [
            'From' => $from,
            'To' => $to,
            'Subject' => $subject,
            'Date' => now()->format('D, d M Y H:i:s O'),
            'Message-ID' => '<'.$postId.'@tn.trashnothing.com>',
            'X-Trash-Nothing-Secret' => (string) config('freegle.mail.trashnothing_secret', ''),
            'X-Trash-Nothing-Post-Id' => $postId,
            'X-Trash-Nothing-Post-Coordinates' => '55.9533,-3.1883',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=utf-8',
        ];

        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = "{$name}: {$value}";
        }

        return implode("\r\n", $lines)."\r\n\r\nGood condition, free to collect.";
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

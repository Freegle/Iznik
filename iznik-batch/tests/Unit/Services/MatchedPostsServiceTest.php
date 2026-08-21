<?php

namespace Tests\Unit\Services;

use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\FreegleApiClient;
use App\Services\MatchedPostsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The vector matching itself lives in apiv2 and is stubbed here via
 * FreegleApiClient::fake(); these tests cover the service's own job — fanning a
 * fresh post's matches out to both owners and applying every dedup/eligibility
 * guard.
 */
class MatchedPostsServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        FreegleApiClient::clearFake();
        parent::tearDown();
    }

    /**
     * Base fixture: an eligible recipient with a fresh WANTED, and an offerer with
     * a matching (not-fresh) OFFER that apiv2 returns as the match. Returns
     * [recipient, wanted, offerer, offer].
     */
    private function seedMatch(array $recipientAttrs = []): array
    {
        $group = $this->createTestGroup();

        $recipient = $this->createTestUser(array_merge([
            'lastaccess' => now(),
            'relevantallowed' => 1,
            'lastrelevantcheck' => null,
        ], $recipientAttrs));

        $offerer = $this->createTestUser([
            'lastaccess' => now(),
            'relevantallowed' => 1,
            'lastrelevantcheck' => null,
        ]);

        // The recipient's own fresh WANTED — the driver (needs spatial + embedding).
        $wanted = $this->makeFreshDriver($recipient, $group, 'Wanted', 'WANTED: Bicycle (London)');

        // The matching OFFER — a live post apiv2 returns; not itself a driver.
        $offer = $this->createTestMessage($offerer, $group, [
            'type' => 'Offer',
            'subject' => 'OFFER: Bicycle (London)',
            'arrival' => now()->subDay(),
        ]);

        // Sequential fakes, in call order:
        //  1. matchesForPost(wanted) → [offer]   (drives both directions)
        //  2. matchesForPost(offer)  → [wanted]  (verifyReach: offer owner can reach the wanted)
        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.82, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1]]],
            ['body' => [['id' => $wanted->id, 'score' => 0.82, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        return [$recipient, $wanted, $offerer, $offer];
    }

    private function makeFreshDriver(User $user, Group $group, string $type, string $subject): Message
    {
        $message = $this->createTestMessage($user, $group, [
            'type' => $type,
            'subject' => $subject,
            'arrival' => now(),
        ]);

        DB::statement(
            'INSERT INTO messages_spatial (msgid, groupid, msgtype, successful, promised, arrival, point)
             VALUES (?, ?, ?, 0, 0, ?, ST_GeomFromText(?, 3857))',
            [$message->id, $group->id, $type, now(), sprintf('POINT(%F %F)', $group->lng, $group->lat)]
        );
        DB::statement(
            'INSERT INTO messages_embeddings (msgid, subject_embedding, model_version) VALUES (?, ?, ?)',
            [$message->id, str_repeat("\0", 1024), 'test']
        );

        return $message;
    }

    private function notificationFor(array $notifications, int $userId): ?array
    {
        foreach ($notifications as $n) {
            if ((int) $n['user']->id === $userId) {
                return $n;
            }
        }

        return null;
    }

    public function test_fans_a_fresh_match_out_to_both_owners(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedMatch();

        $notifications = app(MatchedPostsService::class)->buildNotifications();

        // Direction (i): the wanted's owner is shown the matching offer.
        $toRecipient = $this->notificationFor($notifications, $recipient->id);
        $this->assertNotNull($toRecipient, 'wanted owner should be notified');
        $this->assertCount(1, $toRecipient['items']);
        $this->assertEquals($offer->id, $toRecipient['items'][0]['message']->id);
        $this->assertEquals('Wanted', $toRecipient['items'][0]['reason']->type, 'reason is their own wanted');

        // Direction (ii): the offer's owner is shown the matching wanted.
        $toOfferer = $this->notificationFor($notifications, $offerer->id);
        $this->assertNotNull($toOfferer, 'offer owner should be notified of the new wanted');
        $this->assertEquals($wanted->id, $toOfferer['items'][0]['message']->id);
    }

    public function test_drops_a_direction_ii_match_outside_the_recipients_reach(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedMatch();

        // matchesForPost(wanted) → [offer] (direction i fine); matchesForPost(offer)
        // → [] means the fresh wanted has NOT rippled out to the offer owner, so the
        // direction-(ii) notification must be dropped by the reach check.
        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.82, 'groupid' => 0, 'lat' => 51.5, 'lng' => -0.1]]],
            ['body' => []],
        ]);

        $notifications = app(MatchedPostsService::class)->buildNotifications();

        $this->assertNotNull($this->notificationFor($notifications, $recipient->id), 'direction (i) still delivered');
        $this->assertNull($this->notificationFor($notifications, $offerer->id), 'direction (ii) dropped — out of reach');
    }

    public function test_does_not_match_a_withdrawn_post(): void
    {
        // Withdrawn belongs with Taken and Received: the poster has taken the item off
        // Freegle, so offering it as a match sends someone after something that is gone.
        [$recipient, , $offerer, $offer] = $this->seedMatch();

        DB::table('messages_outcomes')->insert([
            'msgid' => $offer->id,
            'outcome' => 'Withdrawn',
            'timestamp' => now(),
        ]);

        $notifications = app(MatchedPostsService::class)->buildNotifications();

        $toRecipient = $this->notificationFor($notifications, $recipient->id);
        $this->assertNull($toRecipient, 'a withdrawn offer must not be matched to a wanted');
    }

    public function test_never_re_mails_a_post_already_in_the_ledger(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedMatch();

        DB::table('messages_matched_notified')->insert([
            'msgid' => $offer->id,
            'userid' => $recipient->id,
            'mailed_at' => now(),
        ]);

        $notifications = app(MatchedPostsService::class)->buildNotifications();

        $this->assertNull($this->notificationFor($notifications, $recipient->id),
            'already-mailed offer must not be re-sent to the recipient');
    }

    public function test_excludes_a_post_the_recipient_clicked_but_not_a_scroll_impression(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedMatch();

        // A genuine open (pageview=1) suppresses the match.
        DB::table('messages_likes')->insert([
            'msgid' => $offer->id, 'userid' => $recipient->id, 'type' => 'View', 'pageview' => 1,
        ]);

        $this->assertNull(
            $this->notificationFor(app(MatchedPostsService::class)->buildNotifications(), $recipient->id),
            'a clicked (pageview=1) post must be excluded'
        );

        // A mere feed impression (pageview=0) must NOT suppress it.
        DB::table('messages_likes')->where('msgid', $offer->id)->where('userid', $recipient->id)->update(['pageview' => 0]);
        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.82, 'groupid' => 0, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        $this->assertNotNull(
            $this->notificationFor(app(MatchedPostsService::class)->buildNotifications(), $recipient->id),
            'a scroll-past impression (pageview=0) must not suppress the match'
        );
    }

    public function test_skips_recipients_who_opted_out(): void
    {
        [$recipient] = $this->seedMatch(['relevantallowed' => 0]);

        $this->assertNull(
            $this->notificationFor(app(MatchedPostsService::class)->buildNotifications(), $recipient->id),
            'relevantallowed=0 must be skipped'
        );
    }

    public function test_respects_the_per_user_cooldown(): void
    {
        [$recipient] = $this->seedMatch(['lastrelevantcheck' => now()->subHour()]); // < 4h ago

        $this->assertNull(
            $this->notificationFor(app(MatchedPostsService::class)->buildNotifications(), $recipient->id),
            'a recipient mailed within the cooldown must be skipped'
        );
    }

    public function test_drops_matches_below_the_relevance_floor(): void
    {
        [$recipient, $wanted, $offerer, $offer] = $this->seedMatch();

        // The only match scores 0.60 — below the 0.66 floor (config default), so it
        // is dropped before any fan-out: neither owner is notified. Because it never
        // enters the link set there is no direction-(ii) reach call, so one fake.
        FreegleApiClient::fake([
            ['body' => [['id' => $offer->id, 'score' => 0.60, 'groupid' => 0, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        $notifications = app(MatchedPostsService::class)->buildNotifications();

        $this->assertNull($this->notificationFor($notifications, $recipient->id),
            'a below-floor match must not be shown to the wanted owner');
        $this->assertNull($this->notificationFor($notifications, $offerer->id),
            'and must not fan out to the offer owner');
    }

    public function test_collapses_crossposts_to_a_single_card(): void
    {
        $group = $this->createTestGroup();
        $recipient = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);
        $offerer = $this->createTestUser(['lastaccess' => now(), 'relevantallowed' => 1, 'lastrelevantcheck' => null]);

        $wanted = $this->makeFreshDriver($recipient, $group, 'Wanted', 'WANTED: Lamp (London)');

        // The same item crossposted to two groups: two messages, one owner, one
        // subject, distinct ids — exactly how a crosspost is stored.
        $offerA = $this->createTestMessage($offerer, $group, ['type' => 'Offer', 'subject' => 'OFFER: Lamp (London)', 'arrival' => now()->subDay()]);
        $offerB = $this->createTestMessage($offerer, $group, ['type' => 'Offer', 'subject' => 'OFFER: Lamp (London)', 'arrival' => now()->subDay()]);

        FreegleApiClient::fake([
            ['body' => [
                ['id' => $offerA->id, 'score' => 0.80, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1],
                ['id' => $offerB->id, 'score' => 0.78, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1],
            ]],
            ['body' => [['id' => $wanted->id, 'score' => 0.80, 'groupid' => $group->id, 'lat' => 51.5, 'lng' => -0.1]]],
        ]);

        $toRecipient = $this->notificationFor(app(MatchedPostsService::class)->buildNotifications(), $recipient->id);

        $this->assertNotNull($toRecipient, 'wanted owner is notified');
        $this->assertCount(1, $toRecipient['items'], 'crossposts of one item collapse to a single card');
        $this->assertEquals($offerA->id, $toRecipient['items'][0]['message']->id, 'keeps the higher-scoring copy');
    }
}

<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\TrashNothing\Ingestion\ChatMessageIngestionService;
use App\Services\TrashNothing\Ingestion\GroupPostIngestionService;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the hand-maintained mirror between the two TN post-ingestion paths.
 *
 * GroupPostIngestionService is a deliberate line-by-line reimplementation of
 * IncomingMailService::handleGroupPost() + ::createGroupPostMessage() — see
 * that class's docblock. Nothing in the language or the type system ties the
 * two together, so a fix applied to the email path and not mirrored is
 * invisible: it compiles, the suite is green, and TN posts quietly diverge
 * from every other post on the site.
 *
 * tn:parity-check is the thorough answer, but it is an on-demand command that
 * needs live TN data and a disposable database — nobody runs it on a routine
 * PR, and once the cutover flag is on it is tn:verify-email-coverage (coverage
 * only, not behaviour) that runs on a schedule. This test is the cheap
 * always-on half: it does not check that the mirror is CORRECT, only that
 * nobody changed one side without looking at the other.
 *
 * WHEN THIS TEST FAILS you have edited the email path. Do this:
 *   1. Decide whether the change also applies to the API path. Most do.
 *   2. If so, make the matching change in GroupPostIngestionService.
 *   3. Re-pin the digest below to the value the failure message prints.
 *   4. If the change was comment-only or cosmetic, just re-pin — the
 *      normalisation below already drops whole-line // comments and blank
 *      lines, so a digest change means real code moved.
 *
 * Re-pinning without step 1 defeats the whole point. It takes ten seconds to
 * check; a silently unmirrored fix costs a lot more than that to find later.
 */
class EmailPathMirrorDriftTest extends TestCase
{
    /**
     * Digest of each mirrored email-path method, normalised as below.
     *
     * Regenerate with the value printed by this test's failure message.
     */
    private const PINNED_DIGESTS = [
        // Re-pinned for the merge of master, after doing step 1 above rather than
        // skipping it. What master changed in the email path since this branch left it:
        //
        //  - 30fb3a827, a second delivery of the same TN email for a group is a no-op
        //    instead of an exception logged as an error. The API path does not need it:
        //    it already skips a post it has ingested for that group (the idempotency
        //    check in GroupPostIngestionService::ingest, "reason=duplicate").
        //  - 1fe4f0630, routing a cross-post email scopes its updates to that group's
        //    own row (Discourse 10142). The API path does not need it either: it skips
        //    TN's per-group copies outright ("reason=crosspost") and ingests only the
        //    source post, so there is never a second arrival to race with.
        //  - The rest are subscribe-mail and canon/backwards fixes, which are in the
        //    address-matching path, not in post ingestion.
        //
        // This branch's own edit to the method is TN-SYNC-TRACE logging, which the API
        // path already emits.
        //
        // Re-pinned on 25 September: the approval update now skips a copy marked
        // messages_groups.needs_moderator, which only a moderator's Approve clears.
        // The API mirror's approval update carries the same guard.
        'handleGroupPost'         => 'ed0b06f9e8de0f7c0ab41532b2b521bbed8e2bb63bebcc24b966df9674db9e31',
        // Re-pinned on 21 September: createGroupPostMessage now checks that the
        // location id the spatial index hands back still exists in `locations`
        // before writing it to users.lastlocation, and ingests without a location
        // when it does not. That is the guard the API mirror already had
        // (GroupPostIngestionService, "LOCATION-STALE"); without it a stale id
        // failed the foreign key and lost the whole post.
        //
        // Re-pinned on 1 October: a TN member's lastlocation is only filled in by a
        // post when it is empty, because TN is the master for it. The API mirror makes
        // the same check.
        'createGroupPostMessage'  => '2e541f310c4733ee124793d71b07d99cebc20be4adfde5dcf04fb6da3d6073bf',

        // TN chat messages: mirrored in ChatMessageIngestionService (and the gate's predicate), per
        // plans/2026-11-12-tn-api-chat-ingestion.md 4.3. Pinned when the mirror was written.
        'handleChatNotificationReply' => '5ee34591ca68e1c9e4198be6e95f7a1fd925db4193a2ee975f61e9dd496381df',
        'handleDirectMail'            => '9706b32ca1635c4c6f541431a5f2aa0794ce7520dcc32fbbb048ca3b2581f508',
        'handleVolunteersMessage'     => 'b0f2d2fa61b4c49040aa4f2d415d1955bca66edc200119859d9faee7e7e819ed',
        'createChatMessageFromEmail'  => '9766a4bdfa569235a3e74593c4cd0346fd3952cf90738bc194bbb95d4c29bf5f',
        'findRefMessage'              => 'e68355ad9a5e3313b6959bc01328f2c7bbe87fb3d414a81149dc021fafdc324e',
        'chatIsClosedToModerator'     => '8e633855b20b4c66171948de6398a2375bdb0a2dbd0f21d93f01be3fc1a105ab',
        'isUserInChat'                => 'a8cbc4861a373eee9a65bac460f9da100901337ca43356e539d0491980d3ea31',
        'trackEmailReply'             => 'c24176b0a0690f4d89fb706e7aa59f1caeb62d74a6597e6ed1d8d6024a67c687',
        'getOrCreateUserChat'         => '7170eaec0a5f8bb8c83e420103cf4b0126e45c88bdabdb5c1cac36a411b08aec',
        'holdReplyIfOutsideReach'     => 'f1f3f0f0d4321d018a982f0946faf6a7c602ee4dd6bc0b0e0c795c5591443807',
        'resolveReplierLatLng'        => '8b1792afdd9d9039e361a739878b57df5006f190f31c321e991976c54e1b9f43',
        'mapReportReason'             => '795492c5a676e27ee88127b1011d293dd3db48fc547315b692e49008045b6f1b',
        'isKnownSpammer'              => '03748f5a653ed3e37a1d0f2a66fc09babf4f7513a9e3d1b354a548c6f124d313',
        'isReadReceipt'               => 'ae56f409fa091f9959af06f8052106e8a93a10d833277ee100d3e32ef2868587',
    ];

    public static function mirroredMethods(): array
    {
        return array_combine(array_keys(self::PINNED_DIGESTS), array_map(fn ($m) => [$m], array_keys(self::PINNED_DIGESTS)));
    }

    /** Where each pinned email-path method is mirrored. */
    private static function mirrorOf(string $method): string
    {
        return in_array($method, ['handleGroupPost', 'createGroupPostMessage'], true)
            ? GroupPostIngestionService::class
            : ChatMessageIngestionService::class;
    }

    #[DataProvider('mirroredMethods')]
    public function test_the_email_path_has_not_changed_without_the_api_mirror_being_reviewed(string $method): void
    {
        $actual = self::digestOf(IncomingMailService::class, $method);

        $this->assertSame(
            self::PINNED_DIGESTS[$method],
            $actual,
            sprintf(
                "IncomingMailService::%s() has changed.\n\n"
                . "It is mirrored by hand in %s. Check whether this change also belongs there, "
                . "make it if so, then re-pin PINNED_DIGESTS['%s'] in %s to:\n\n    %s\n",
                $method,
                self::mirrorOf($method),
                $method,
                basename(__FILE__),
                $actual,
            ),
        );
    }

    public function test_the_api_path_mirror_still_exists(): void
    {
        // A rename or removal of either side must fail loudly rather than
        // leave the digests above pinned to something nothing mirrors.
        $this->assertTrue(
            (new ReflectionClass(GroupPostIngestionService::class))->hasMethod('ingest'),
            'GroupPostIngestionService::ingest() is the API-path mirror entry point.',
        );
        $this->assertTrue(
            (new ReflectionClass(ChatMessageIngestionService::class))->hasMethod('plan'),
            'ChatMessageIngestionService::plan() is the chat mirror entry point.',
        );

        foreach (array_keys(self::PINNED_DIGESTS) as $method) {
            $this->assertTrue(
                (new ReflectionClass(IncomingMailService::class))->hasMethod($method),
                "IncomingMailService::{$method}() is pinned here but no longer exists — "
                . 'if it was renamed, update this test; if it was deleted, the API path is '
                . 'now the only implementation and this guard can go.',
            );
        }
    }

    /**
     * SHA-256 of a method's source with indentation, blank lines and whole-line
     * // comments removed, so reformatting and documentation churn do not fire
     * the guard but any change to the code itself does.
     */
    private static function digestOf(string $class, string $method): string
    {
        $reflection = new ReflectionMethod($class, $method);
        $source     = file($reflection->getFileName());
        $body       = array_slice(
            $source,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1,
        );

        $significant = [];
        foreach ($body as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            $significant[] = $line;
        }

        return hash('sha256', implode("\n", $significant));
    }
}

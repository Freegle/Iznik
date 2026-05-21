<?php

namespace Tests\Feature\Message;

use App\Models\MessageGroup;
use App\Services\Mail\Incoming\IncomingMailService;
use App\Services\Mail\Incoming\MailParserService;
use App\Services\Mail\Incoming\RoutingResult;
use App\Services\PushNotificationService;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmailFixtures;
use Tests\TestCase;

/**
 * AssertFlip ordering test for the push-before-ContentCheck bug (issue #9688).
 *
 * BUG: Moderators hear the new-pending-post beep up to ~30 s before the post
 * appears in ModTools Pending, because IncomingMailService dispatches
 * notifyGroupMods() immediately when the message enters Pending — before
 * ContentCheckService has run and set contentcheck_checked_at.
 *
 * ModTools only shows messages where contentcheck_checked_at IS NOT NULL, so
 * the push notification arrives while the message is still invisible to mods.
 *
 * FIX (not done here): remove notifyGroupMods() from IncomingMailService; let
 * ContentCheckService::processUnprocessed() own the push after it writes
 * contentcheck_checked_at.
 */
class PushBeforeContentCheckTest extends TestCase
{
    use EmailFixtures;

    /**
     * AssertFlip STEP 2 (final test, currently FAILING).
     *
     * Correct ordering: the push notification must NOT fire while
     * contentcheck_checked_at is still NULL (i.e. before ContentCheck has run).
     *
     * Today this FAILS because IncomingMailService calls notifyGroupMods()
     * synchronously when the message enters Pending — at which point
     * contentcheck_checked_at is always NULL.
     */
    public function test_push_fires_only_after_contentcheck_marks_message_visible(): void
    {
        $group = $this->createTestGroup();
        $user  = $this->createTestUser(['email_preferred' => $this->uniqueEmail('moderated')]);
        $this->createMembership($user, $group, ['ourPostingStatus' => 'MODERATED']);
        DB::table('users')->where('id', $user->id)->update([
            'lastlocation' => $this->createLocation(51.5, -0.1),
        ]);

        // Spy: did notifyGroupMods fire while contentcheck_checked_at was still NULL?
        $pushFiredBeforeContentCheck = false;

        $this->mock(PushNotificationService::class, function ($mock) use (
            &$pushFiredBeforeContentCheck,
            $group
        ) {
            $mock->shouldReceive('notifyGroupMods')
                ->withAnyArgs()
                ->andReturnUsing(function (int $groupId) use (
                    &$pushFiredBeforeContentCheck,
                    $group
                ) {
                    if ($groupId === $group->id) {
                        // At the moment the push fires, is there a pending message
                        // that ContentCheck has NOT yet processed?
                        $unchecked = DB::table('messages_groups')
                            ->where('groupid', $groupId)
                            ->where('collection', MessageGroup::COLLECTION_PENDING)
                            ->whereNull('contentcheck_checked_at')
                            ->exists();

                        if ($unchecked) {
                            $pushFiredBeforeContentCheck = true;
                        }
                    }

                    return 0;
                });
        });

        $parser    = app(MailParserService::class);
        $service   = app(IncomingMailService::class);
        $userEmail = $user->emails->first()->email;

        $email = $this->createMinimalEmail([
            'From'    => $userEmail,
            'To'      => $group->nameshort . '@groups.ilovefreegle.org',
            'Subject' => 'OFFER: Test Chair (London)',
        ], 'Nice chair, collection only.');

        $parsed = $parser->parse(
            $email,
            $userEmail,
            $group->nameshort . '@groups.ilovefreegle.org'
        );

        $result = $service->route($parsed);

        $this->assertEquals(RoutingResult::PENDING, $result, 'Message should route to Pending');

        // Correct ordering: the push must NOT fire before ContentCheck has set
        // contentcheck_checked_at (the ModTools visibility gate).
        //
        // This assertion FAILS today: IncomingMailService dispatches notifyGroupMods()
        // immediately on Pending entry, long before ContentCheckService runs.
        $this->assertFalse(
            $pushFiredBeforeContentCheck,
            'Push notification fired before ContentCheck set contentcheck_checked_at — ' .
            'moderators hear the beep before the post appears in ModTools Pending queue'
        );
    }

    private function createLocation(float $lat, float $lng): int
    {
        return DB::table('locations')->insertGetId([
            'name' => 'Test Location ' . uniqid(),
            'type' => 'Polygon',
            'lat'  => $lat,
            'lng'  => $lng,
        ]);
    }
}

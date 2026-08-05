<?php

namespace Tests\Unit\Mail;

use App\Mail\Session\UnsubscribedNotice;
use App\Services\UnsubscribeService;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The acknowledgement someone gets after unsubscribing by mail.
 *
 * The content is the point of it: what we turned off, what may still arrive, and a way to
 * stop the rest without going hunting. "I unsubscribed and you're still emailing me" is one
 * of the two complaints Support sees about this, and a member who has to find Settings and
 * log in to finish the job is how that happens.
 */
class UnsubscribedNoticeTest extends TestCase
{
    /**
     * @param  string[]  $turnedOff
     * @param  string[]  $stillOn
     * @return array<string,mixed>
     */
    private function viewData(string $type, array $turnedOff, array $stillOn): array
    {
        $user = $this->createTestUser();

        $mailable = new UnsubscribedNotice(
            $user->id,
            $this->uniqueEmail('notice'),
            'Jacky',
            $type,
            $turnedOff,
            $stillOn
        );
        $mailable->build();

        $property = new ReflectionProperty($mailable, 'mjmlData');
        $property->setAccessible(true);

        return $property->getValue($mailable);
    }

    public function test_offers_one_tap_to_stop_everything(): void
    {
        $data = $this->viewData(
            UnsubscribeService::TYPE_DIGEST,
            [UnsubscribeService::TYPE_DIGEST],
            [UnsubscribeService::TYPE_CHAT]
        );

        $this->assertArrayHasKey('stopAllUrl', $data);
        $this->assertStringContainsString('/user/unsubscribe?', $data['stopAllUrl']);
        $this->assertStringContainsString('t=all', $data['stopAllUrl']);
    }

    public function test_stop_everything_link_is_keyed_so_it_needs_no_login(): void
    {
        // The whole point of one tap is that it works from the email. A link the member has
        // to log in to follow is the Settings page again.
        $data = $this->viewData(
            UnsubscribeService::TYPE_DIGEST,
            [UnsubscribeService::TYPE_DIGEST],
            [UnsubscribeService::TYPE_CHAT]
        );

        $this->assertMatchesRegularExpression('/[?&]k=[a-f0-9]{32}\b/', $data['stopAllUrl']);
        $this->assertMatchesRegularExpression('/[?&]u=\d+/', $data['stopAllUrl']);
    }

    public function test_says_what_was_turned_off_and_what_remains(): void
    {
        $data = $this->viewData(
            UnsubscribeService::TYPE_DIGEST,
            [UnsubscribeService::TYPE_DIGEST],
            [UnsubscribeService::TYPE_CHAT, UnsubscribeService::TYPE_NEWSLETTER]
        );

        $this->assertSame([UnsubscribeService::describe(UnsubscribeService::TYPE_DIGEST)], $data['turnedOff']);
        $this->assertSame([
            UnsubscribeService::describe(UnsubscribeService::TYPE_CHAT),
            UnsubscribeService::describe(UnsubscribeService::TYPE_NEWSLETTER),
        ], $data['stillOn']);
        $this->assertFalse($data['alreadyOff']);
        $this->assertFalse($data['everythingAlreadyOff']);
    }

    public function test_hides_the_stop_everything_offer_when_nothing_is_left(): void
    {
        // Offering to stop everything to someone who just stopped everything reads as a
        // system that did not notice what they did.
        $data = $this->viewData(
            UnsubscribeService::TYPE_ALL,
            [UnsubscribeService::TYPE_DIGEST],
            []
        );

        $this->assertTrue($data['everythingAlreadyOff']);
    }

    public function test_is_honest_when_it_changed_nothing(): void
    {
        $data = $this->viewData(UnsubscribeService::TYPE_RELEVANT, [], [UnsubscribeService::TYPE_CHAT]);

        $this->assertTrue($data['alreadyOff']);
        $this->assertSame(
            UnsubscribeService::describe(UnsubscribeService::TYPE_RELEVANT),
            $data['whatTheyAskedFor']
        );
    }

    public function test_carries_no_unsubscribe_header_of_its_own(): void
    {
        // It is a direct answer to something the member just asked for, and it has to reach
        // someone who has turned everything off.
        $user = $this->createTestUser();
        $mailable = new UnsubscribedNotice($user->id, $this->uniqueEmail('notice'), 'Jacky', UnsubscribeService::TYPE_ALL, [], []);

        $method = new \ReflectionMethod($mailable, 'unsubscribeType');
        $method->setAccessible(true);

        $this->assertNull($method->invoke($mailable));
    }
}

<?php

namespace Tests\Unit\Mail;

use App\Mail\Session\UnsubscribedNotice;
use App\Services\UnsubscribeService;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The acknowledgement someone gets after unsubscribing by mail.
 *
 * The content is the point of it, and Support sees this go wrong in two opposite ways
 * (Discourse #6484): people still getting email they thought they had stopped, and people
 * deleting accounts they meant to keep. So it has to say what was turned off, what may
 * still arrive, offer a way to stop the rest without hunting through Settings, and keep
 * "stop emailing me" clearly separate from "leave Freegle".
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

    /**
     * @return array<string,mixed>
     */
    private function digestCase(): array
    {
        return $this->viewData(
            UnsubscribeService::TYPE_DIGEST,
            [UnsubscribeService::TYPE_DIGEST],
            [UnsubscribeService::TYPE_CHAT, UnsubscribeService::TYPE_NEWSLETTER]
        );
    }

    public function test_offers_one_tap_to_stop_the_rest(): void
    {
        $data = $this->digestCase();

        $this->assertArrayHasKey('stopMostUrl', $data);
        $this->assertStringContainsString('/user/unsubscribe?', $data['stopMostUrl']);
        $this->assertStringContainsString('t='.UnsubscribeService::TYPE_ALL_EXCEPT_REPLIES, $data['stopMostUrl']);
        $this->assertTrue($data['canStopMore']);
    }

    public function test_the_one_tap_option_keeps_replies_to_your_posts(): void
    {
        // Taking chat with it would mean someone offers a sofa, a neighbour replies, and
        // they never find out.
        $data = $this->digestCase();

        $this->assertStringEndsWith(
            't='.UnsubscribeService::TYPE_ALL_EXCEPT_REPLIES,
            $data['stopMostUrl'],
            'Must not be the plain "all" category, which stops chat too'
        );
    }

    public function test_stop_the_rest_link_is_keyed_so_it_needs_no_login(): void
    {
        // The whole point of one tap is that it works from the email. A link the member has
        // to log in to follow is the Settings page again.
        $data = $this->digestCase();

        $this->assertMatchesRegularExpression('/[?&]k=[a-f0-9]{32}\b/', $data['stopMostUrl']);
        $this->assertMatchesRegularExpression('/[?&]u=\d+/', $data['stopMostUrl']);
    }

    public function test_leaving_freegle_is_a_separate_button_to_the_unsubscribe_page(): void
    {
        // Conflating "stop emailing me" with "delete my account" is how people lose accounts
        // they meant to keep, so the two are different buttons with different words.
        $data = $this->digestCase();

        $this->assertArrayHasKey('leaveUrl', $data);
        $this->assertStringContainsString('/unsubscribe?', $data['leaveUrl']);
        $this->assertStringNotContainsString('/user/unsubscribe', $data['leaveUrl']);
        $this->assertMatchesRegularExpression('/[?&]k=[a-f0-9]{32}\b/', $data['leaveUrl']);
    }

    public function test_does_not_offer_to_stop_the_rest_when_only_replies_remain(): void
    {
        // Nothing left to stop but chat, which this option deliberately keeps - offering it
        // would do nothing.
        $data = $this->viewData(
            UnsubscribeService::TYPE_DIGEST,
            [UnsubscribeService::TYPE_DIGEST],
            [UnsubscribeService::TYPE_CHAT]
        );

        $this->assertFalse($data['canStopMore']);
    }

    public function test_says_what_was_turned_off_and_what_remains(): void
    {
        $data = $this->digestCase();

        $this->assertSame([UnsubscribeService::describe(UnsubscribeService::TYPE_DIGEST)], $data['turnedOff']);
        $this->assertSame([
            UnsubscribeService::describe(UnsubscribeService::TYPE_CHAT),
            UnsubscribeService::describe(UnsubscribeService::TYPE_NEWSLETTER),
        ], $data['stillOn']);
        $this->assertFalse($data['alreadyOff']);
        $this->assertFalse($data['everythingAlreadyOff']);
    }

    public function test_hides_the_offer_when_nothing_is_left(): void
    {
        // Offering to stop more to someone who just stopped everything reads as a system
        // that did not notice what they did.
        $data = $this->viewData(UnsubscribeService::TYPE_ALL, [UnsubscribeService::TYPE_DIGEST], []);

        $this->assertTrue($data['everythingAlreadyOff']);
        $this->assertFalse($data['canStopMore']);
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

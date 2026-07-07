<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\StripQuotedService;
use Tests\TestCase;

class StripQuotedServiceTest extends TestCase
{
    protected StripQuotedService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new StripQuotedService;
    }

    public function test_strips_basic_quote_markers(): void
    {
        $input = "Hello\n> quoted line\n> another quote\nWorld";
        $result = $this->service->strip($input);
        $this->assertEquals('Hello', trim(explode("\n", $result)[0]));
        $this->assertStringNotContainsString('> quoted line', $result);
    }

    public function test_strips_pipe_quote_markers(): void
    {
        $input = "Reply text\n| quoted via pipe\n| more pipe quote";
        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('| quoted', $result);
    }

    public function test_strips_em_client_original_message(): void
    {
        $input = "Ok, here's a reply.\r\n\r\n------ Original Message ------\r\nFrom: \"Edward Hibbert\" <notify-5147-16226909@users.ilovefreegle.org>\r\nTo: log@ehibbert.org.uk\r\nSent: 14/05/2016 14:19:19\r\nSubject: Re: [FreeglePlayground] Offer: chair (Hesketh Lane PR3)\r\n\r\n>Edward Hibbert wrote: Test";

        $result = $this->service->strip($input);
        $this->assertEquals("Ok, here's a reply.", $result);
    }

    public function test_strips_em_client_with_trailing_underscore_dash(): void
    {
        // notif_reply_text18 pattern: reply followed by "---_" then Original Message
        $input = "Ok, here's a reply.---_\r\n\r\n------ Original Message ------\r\nFrom: \"Edward Hibbert\" <notify-5147-16226909@users.ilovefreegle.org>\r\nTo: log@ehibbert.org.uk\r\nSent: 14/05/2016 14:19:19\r\nSubject: Re: [FreeglePlayground] Offer: chair\r\n\r\n>Edward Hibbert wrote: Test";

        $result = $this->service->strip($input);
        $this->assertEquals("Ok, here's a reply.", $result);
    }

    public function test_strips_gmail_on_wrote_pattern(): void
    {
        $input = "Ok, here's a reply.\r\n\r\nOn Sat, May 14, 2016 at 2:19 PM, Edward Hibbert <\r\nnotify-5147-16226909@users.ilovefreegle.org> wrote:\r\n\r\n> quoted text here";

        $result = $this->service->strip($input);
        $this->assertEquals("Ok, here's a reply.", $result);
    }

    public function test_strips_on_wrote_with_freegle_address(): void
    {
        // notif_reply_text13 pattern: reply on same line as "On ... wrote:"
        $input = "Yes no problem, roughly how big is it. Will depends if it car or van.\r\nOn 20 Jul 2018 10:39 am, gothe <notify-4703531-875040@users.ilovefreegle.org> wrote:\r\n\r\n> Hi - could you collect sometime?";

        $result = $this->service->strip($input);
        // The "On ... wrote:" line stays because it's on the same line as content
        $this->assertStringContainsString('Yes no problem', $result);
    }

    public function test_strips_original_message_separator(): void
    {
        $input = "Replying.\r\n\r\n----Original message----\r\nFrom: someone@ilovefreegle.org\r\nOld message text";

        $result = $this->service->strip($input);
        $this->assertEquals('Replying.', $result);
    }

    public function test_strips_dashes_separator(): void
    {
        $input = "My reply\r\n--------------------------------------------\r\nOriginal content";

        $result = $this->service->strip($input);
        $this->assertEquals('My reply', $result);
    }

    public function test_strips_tn_underscores(): void
    {
        $input = "Reply here\r\n_________________________________________________________________\r\nOriginal TN message";

        $result = $this->service->strip($input);
        $this->assertEquals('Reply here', $result);
    }

    public function test_strips_windows_phone_underscores(): void
    {
        $input = "Reply text\r\n________________________________\r\nFrom: someone";

        $result = $this->service->strip($input);
        $this->assertEquals('Reply text', $result);
    }

    public function test_strips_original_message_variations(): void
    {
        $variations = [
            '-------- Original message --------',
            '----- Original Message -----',
            '-----Original Message-----',
            '-------- Mensagem original --------',
        ];

        foreach ($variations as $sep) {
            $input = "Please may I be considered\r\n\r\n{$sep}\r\nOld stuff";
            $result = $this->service->strip($input);
            $this->assertEquals('Please may I be considered', $result, "Failed for separator: {$sep}");
        }
    }

    public function test_strips_yahoo_groups_separator(): void
    {
        $input = "Reply text\r\n\r\n__,_._,___\r\nYahoo group footer";

        $result = $this->service->strip($input);
        $this->assertEquals('Reply text', $result);
    }

    public function test_strips_freegle_from_header(): void
    {
        $input = "Some reply\r\nFrom: user@ilovefreegle.org\r\nMore text";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('ilovefreegle.org', $result);
    }

    public function test_strips_trashnothing_from_header(): void
    {
        $input = "Some reply\r\nFrom: user@trashnothing.com\r\nMore text";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('trashnothing.com', $result);
    }

    public function test_strips_reply_prompt(): void
    {
        $input = "My reply\r\nYou can respond by just replying to this email - but it works better if you use the button.\r\nFooter";

        $result = $this->service->strip($input);
        $this->assertEquals('My reply', $result);
    }

    public function test_strips_our_footer(): void
    {
        $input = "Reply text\r\nThis message was from user #123, and this mail was sent to test@example.com.";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('This message was from user', $result);
    }

    public function test_strips_charity_footer(): void
    {
        $input = "Reply text\r\nFreegle is registered as a charity with HMRC (ref. XT32865) and is run by volunteers. Which is nice.";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('charity', $result);
    }

    public function test_strips_inline_image_references(): void
    {
        $input = "Ok, here's a reply.\r\n\r\n[cid:image001.png@01D6A726.60C509D0]";

        $result = $this->service->strip($input);
        $this->assertEquals("Ok, here's a reply.", $result);
    }

    public function test_strips_tn_autowording(): void
    {
        $input = "Reply text\r\n[Note: This is an automated response from trashnothing.com on behalf of the post author]";

        $result = $this->service->strip($input);
        $this->assertEquals('Reply text', $result);
    }

    public function test_strips_login_keys_from_urls(): void
    {
        config(['freegle.user_site' => 'www.ilovefreegle.org']);
        $service = new StripQuotedService;

        $input = "Check https://www.ilovefreegle.org?u=1234&k=secret5678 this link";

        $result = $service->strip($input);
        $this->assertStringNotContainsString('k=secret5678', $result);
        $this->assertStringContainsString('https://www.ilovefreegle.org', $result);
    }

    public function test_strips_sent_from_iphone(): void
    {
        $input = "My reply\r\n\r\nSent from my iPhone";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('iPhone', $result);
        $this->assertStringContainsString('My reply', $result);
    }

    public function test_strips_sent_from_samsung(): void
    {
        $input = "My reply\r\n\r\nSent from my Samsung Galaxy smartphone";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('Samsung', $result);
    }

    public function test_strips_get_outlook(): void
    {
        $input = "My reply\r\nGet Outlook for Android";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('Outlook', $result);
    }

    public function test_strips_sent_from_yahoo_mail(): void
    {
        $input = "My reply\r\nSent from Yahoo Mail on Android";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('Yahoo Mail', $result);
    }

    public function test_strips_yahoo_mail_css(): void
    {
        $input = "Reply\r\nblockquote, div.yahoo_quoted { margin-left: 0 !important; border-left:1px #715FFA solid !important; padding-left:1ex !important; background-color:white !important; }";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('yahoo_quoted', $result);
    }

    public function test_converts_unicode_spaces(): void
    {
        $input = "Reply\xc2\xa0text";

        $result = $this->service->strip($input);
        $this->assertStringContainsString('Reply text', $result);
    }

    public function test_collapses_redundant_linebreaks(): void
    {
        $input = "Line one\r\n\r\n\r\n\r\nLine two";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString("\r\n\r\n\r\n", $result);
    }

    public function test_preserves_reply_to_colon_in_body(): void
    {
        // "On ... wrote:" regex should not eat content like "reply to:\nSomewhere"
        $input = "Ok, here's a reply to:\r\n\r\nSomewhere.";

        $result = $this->service->strip($input);
        $this->assertStringContainsString("Ok, here's a reply to:", $result);
        $this->assertStringContainsString('Somewhere.', $result);
    }

    public function test_strips_group_sigs(): void
    {
        $input = "Reply text\r\n~*~*~*~*~*~*\r\nGroup signature stuff";

        $result = $this->service->strip($input);
        $this->assertEquals('Reply text', $result);
    }

    public function test_strips_penguin_power_sig(): void
    {
        $input = "Hello\r\n\r\nI would like these\r\n\r\nSent using penguin power from my iPhone";

        $result = $this->service->strip($input);
        $this->assertStringNotContainsString('penguin power', $result);
        $this->assertStringContainsString('Hello', $result);
    }

    public function test_empty_string_returns_empty(): void
    {
        $result = $this->service->strip('');
        $this->assertEquals('', $result);
    }

    public function test_plain_text_unchanged(): void
    {
        $input = 'Just a simple reply with no quoting at all.';
        $result = $this->service->strip($input);
        $this->assertEquals($input, $result);
    }

    /**
     * Integration-style test matching legacy notif_reply_text17 fixture.
     */
    public function test_complex_reply_with_sig_and_on_wrote(): void
    {
        $input = "Hello\r\n\r\nI would be interested in these as have a big slug problem and also the lawn feed and could collect today ?\r\n\r\nMany thanks\r\n\r\nAnn\r\n\r\n\r\nSent using penguin power from my iPhone\r\n\r\nOn 10 Jul 2019, at 10:36, bXXX XXXXXan wise <replyto-58907365-4150515@users.ilovefreegle.org> wrote:\r\n\r\nSuitable for helping to get rid of both slugs and snails";

        $result = $this->service->strip($input);
        $this->assertStringContainsString('Hello', $result);
        $this->assertStringContainsString('big slug problem', $result);
        $this->assertStringContainsString('Ann', $result);
        $this->assertStringNotContainsString('penguin power', $result);
        $this->assertStringNotContainsString('Suitable for helping', $result);
    }
}

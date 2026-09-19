<?php

namespace Tests\Unit\Support;

use App\Support\ChatWarnNotHold;
use Tests\TestCase;

class ChatWarnNotHoldTest extends TestCase
{
    public function test_off_by_default(): void
    {
        config(['freegle.moderation.chat_warn_not_hold' => false]);
        $this->assertFalse(ChatWarnNotHold::enabled());

        config(['freegle.moderation.chat_warn_not_hold' => true]);
        $this->assertTrue(ChatWarnNotHold::enabled());
    }

    public function test_reason_keys_match_the_go_api(): void
    {
        $cases = [
            'Money' => 'money',
            'Link' => 'link',
            'URL on DBL' => 'link',
            'Email' => 'contact',
            'Language' => 'language',
            'WorryWord' => 'concern',
            'Referenced known spammer' => 'scam',
            'Greetings spam' => 'scam',
            'Known spam keyword' => 'scam',
            'Spam' => 'checked',
            'Last' => 'checked',
            'Fully' => 'checked',
            'Something new' => 'checked',
        ];
        foreach ($cases as $stored => $key) {
            $this->assertSame($key, ChatWarnNotHold::reason($stored), $stored);
        }
        $this->assertSame('checked', ChatWarnNotHold::reason(null));
    }

    public function test_warning_text_never_contains_the_message(): void
    {
        foreach (['money', 'link', 'contact', 'language', 'concern', 'scam', 'checked', 'unknown'] as $key) {
            $text = ChatWarnNotHold::warningText($key);
            $this->assertNotSame('', $text, $key);
            $this->assertStringContainsString('Open Freegle', $text, $key);
        }
    }
}

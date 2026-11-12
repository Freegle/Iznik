<?php

namespace Tests\Unit\Mail;

use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Tests\TestCase;

/**
 * Member text in the AMP part must never stop an email being sent.
 */
class AmpEmailMemberTextTest extends TestCase
{
    private function createAmpEmailInstance(): object
    {
        return new class {
            use \App\Mail\Traits\AmpEmail;

            public function attach(string $ampHtml, Email $message): void
            {
                $this->setAmpContent($ampHtml);
                $this->applyAmpToMessage($message);
            }
        };
    }

    public static function memberTextProvider(): array
    {
        return [
            'mask' => ['Face mask: free to a good home'],
            'behavior' => ['Dog behavior: calm'],
            'before' => ['Sale:before 5pm'],
            'after' => ['Sale:after 5pm'],
            'expression' => ['Great expression (used once)'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('memberTextProvider')]
    public function test_member_text_that_looks_like_css_does_not_stop_the_amp_part_being_attached(string $text): void
    {
        config(['freegle.amp.secret' => 'test-secret', 'freegle.amp.enabled' => true]);

        $ampHtml = '<!doctype html><html amp4email><head><style amp4email-boilerplate>body{visibility:hidden}</style>' .
            '<style amp-custom>.card { color: #338808; }</style></head>' .
            '<body><div class="card">' . e($text) . '</div></body></html>';

        $message = (new Email())
            ->from('noreply@example.invalid')
            ->to('member@example.invalid')
            ->html('<p>' . e($text) . '</p>')
            ->text($text);

        $this->createAmpEmailInstance()->attach($ampHtml, $message);

        $this->assertInstanceOf(AlternativePart::class, $message->getBody());
        $this->assertStringContainsString('text/x-amp-html', $message->getBody()->toString());
    }
}

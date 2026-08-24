<?php

namespace Tests\Unit\Mail;

use App\Mail\Matched\MatchedPosts;
use App\Models\Message;
use App\Models\User;
use Tests\TestCase;

class MatchedPostsSubjectTest extends TestCase
{
    private function item(string $type, string $subject): array
    {
        $m = new Message();
        $m->type = $type;
        $m->subject = $subject;
        $reason = new Message();
        $reason->type = $type === 'Offer' ? 'Wanted' : 'Offer';
        $reason->subject = 'WANTED: something';

        return ['message' => $m, 'reason' => $reason, 'score' => 0.8];
    }

    private function mail(array $items): MatchedPosts
    {
        $u = new User();
        $u->id = 1;

        return new MatchedPosts($u, 'test@example.com', $items);
    }

    public function test_multiple_matches_use_the_generic_subject(): void
    {
        $mail = $this->mail([
            $this->item('Offer', 'OFFER: Bike (Leeds)'),
            $this->item('Wanted', 'WANTED: Sofa (York)'),
        ]);

        $this->assertEquals('Freegle matches for you', $mail->envelope()->subject);
    }

    public function test_single_match_names_the_item(): void
    {
        $mail = $this->mail([$this->item('Offer', 'OFFER: Bike (Leeds)')]);

        $this->assertEquals('Someone is offering: Bike', $mail->envelope()->subject);
    }
}

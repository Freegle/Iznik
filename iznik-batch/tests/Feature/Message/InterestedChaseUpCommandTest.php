<?php

namespace Tests\Feature\Message;

use App\Mail\Message\InterestedChaseUp;
use App\Models\Group;
use App\Models\Message;
use App\Models\MessageInterested;
use App\Models\User;
use App\Services\EmailSpoolerService;
use Illuminate\Support\Facades\DB;
use ReflectionProperty;
use Tests\TestCase;

/**
 * messages:interested-chaseup: which marks are picked, one email per member, chasedat set on exactly the marks
 * named, and nothing the second time. The spooler is replaced so no mail leaves; the mailables it is given are kept.
 */
class InterestedChaseUpCommandTest extends TestCase
{
    private ?Group $group = null;

    /** @var array<int, InterestedChaseUp> */
    private array $spooled = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->spooled = [];
        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->andReturnUsing(function ($mail) {
                $this->spooled[] = $mail;

                return 'spool-id';
            });
        });
    }

    private function member(array $attributes = []): User
    {
        return $this->createTestUser($attributes + ['lastaccess' => now()]);
    }

    private function openPost(array $attributes = []): Message
    {
        $this->group ??= $this->createTestGroup();

        return $this->createTestMessage($this->createTestUser(), $this->group, $attributes);
    }

    private function mark(User $member, Message $post, string $ago = '30 hours'): MessageInterested
    {
        return MessageInterested::create(['msgid' => $post->id, 'userid' => $member->id, 'added' => now()->sub(\DateInterval::createFromDateString($ago))]);
    }

    private function run_(array $options = []): void
    {
        $this->artisan('messages:interested-chaseup', $options)->assertExitCode(0);
    }

    // ---------------------------------------------------------------- selection

    public function test_a_mark_a_day_old_on_an_open_post_is_chased_and_marked(): void
    {
        $member = $this->member();
        $post = $this->openPost();
        $mark = $this->mark($member, $post);

        $this->run_();

        $this->assertCount(1, $this->spooled);
        $this->assertNotNull($mark->fresh()->chasedat);
    }

    public function test_the_age_window_is_respected(): void
    {
        $member = $this->member();
        $young = $this->mark($member, $this->openPost(), '23 hours');
        $old = $this->mark($member, $this->openPost(), '8 days');
        $edge = $this->mark($member, $this->openPost(), '6 days');

        $this->run_();

        $this->assertNull($young->fresh()->chasedat);
        $this->assertNull($old->fresh()->chasedat);
        $this->assertNotNull($edge->fresh()->chasedat);
        $this->assertCount(1, $this->spooled);
    }

    public function test_each_exclusion_leaves_the_mark_alone(): void
    {
        $member = $this->member();
        $cases = [];

        $cases['replied'] = $this->openPost();
        $room = $this->createTestChatRoom($member, User::findOrFail($cases['replied']->fromuser));
        $this->createTestChatMessage($room, $member, ['type' => 'Interested', 'refmsgid' => $cases['replied']->id, 'date' => now()->subHours(2)]);

        $cases['deleted'] = $this->openPost(['deleted' => now()]);

        $cases['outcome'] = $this->openPost();
        DB::table('messages_outcomes')->insert(['msgid' => $cases['outcome']->id, 'outcome' => 'Taken', 'timestamp' => now()]);

        $cases['promised'] = $this->openPost();
        DB::table('messages_promises')->insert(['msgid' => $cases['promised']->id, 'userid' => $this->member()->id, 'promisedat' => now()]);

        $cases['not_approved'] = $this->openPost();
        DB::table('messages_groups')->where('msgid', $cases['not_approved']->id)->update(['collection' => 'Pending']);

        $cases['retracted'] = $this->openPost();
        DB::table('messages_groups')->where('msgid', $cases['retracted']->id)->update(['deleted' => 1]);

        $marks = [];
        foreach ($cases as $name => $post) {
            $marks[$name] = $this->mark($member, $post);
        }
        $marks['already_chased'] = $this->mark($member, $this->openPost());
        $marks['already_chased']->update(['chasedat' => now()->subHour()]);

        $this->run_();

        $this->assertSame([], $this->spooled);
        foreach ($marks as $name => $mark) {
            if ($name === 'already_chased') {
                continue;
            }
            $this->assertNull($mark->fresh()->chasedat, "$name must be left alone");
        }
    }

    public function test_a_reply_to_one_post_does_not_stop_the_chase_for_another(): void
    {
        $member = $this->member();
        $replied = $this->openPost();
        $open = $this->openPost();
        $this->mark($member, $replied);
        $openMark = $this->mark($member, $open);
        $room = $this->createTestChatRoom($member, User::findOrFail($replied->fromuser));
        $this->createTestChatMessage($room, $member, ['type' => 'Interested', 'refmsgid' => $replied->id, 'date' => now()->subHours(2)]);

        $this->run_();

        $this->assertCount(1, $this->spooled);
        $this->assertSame([$open->id], $this->spooled[0]->about()['messages']);
        $this->assertNotNull($openMark->fresh()->chasedat);
    }

    // ---------------------------------------------------------------- members

    public function test_a_member_who_wants_no_mail_is_not_mailed_but_is_not_picked_again(): void
    {
        $skipped = [
            'deleted' => $this->member(['deleted' => now()]),
            'bouncing' => $this->member(['bouncing' => 1]),
            'mail_off' => $this->member(['settings' => ['simplemail' => 'None']]),
            'dormant' => $this->member(['lastaccess' => now()->subYears(2)]),
        ];
        $marks = [];
        foreach ($skipped as $name => $member) {
            $marks[$name] = $this->mark($member, $this->openPost());
        }

        $this->run_();

        $this->assertSame([], $this->spooled);
        foreach ($marks as $name => $mark) {
            $this->assertNotNull($mark->fresh()->chasedat, "$name is marked so it is not picked every hour");
        }
    }

    public function test_a_member_with_no_usable_address_is_not_mailed(): void
    {
        $member = $this->member();
        DB::table('users_emails')->where('userid', $member->id)->update(['email' => 'u'.$member->id.'@users.ilovefreegle.org']);
        $mark = $this->mark($member, $this->openPost());

        $this->run_();

        $this->assertSame([], $this->spooled);
        $this->assertNotNull($mark->fresh()->chasedat);
    }

    public function test_one_email_per_member_names_all_their_posts(): void
    {
        $a = $this->member();
        $b = $this->member();
        $posts = [$this->openPost(), $this->openPost(), $this->openPost()];
        $marks = [$this->mark($a, $posts[0]), $this->mark($a, $posts[1]), $this->mark($b, $posts[2])];

        $this->run_();

        $this->assertCount(2, $this->spooled);
        $byUser = [];
        foreach ($this->spooled as $mail) {
            $byUser[$mail->user->id] = $mail->about()['messages'];
        }
        $this->assertEqualsCanonicalizing([$posts[0]->id, $posts[1]->id], $byUser[$a->id]);
        $this->assertSame([$posts[2]->id], $byUser[$b->id]);
        foreach ($marks as $mark) {
            $this->assertNotNull($mark->fresh()->chasedat);
        }
    }

    public function test_a_second_run_sends_nothing(): void
    {
        $this->mark($this->member(), $this->openPost());

        $this->run_();
        $this->run_();

        $this->assertCount(1, $this->spooled);
    }

    public function test_a_mark_added_after_the_first_run_is_chased_on_its_own_later(): void
    {
        $member = $this->member();
        $first = $this->mark($member, $this->openPost());
        $this->run_();
        $second = $this->mark($member, $this->openPost());

        $this->run_();

        $this->assertCount(2, $this->spooled);
        $this->assertSame([$second->msgid], $this->spooled[1]->about()['messages']);
        $this->assertNotNull($first->fresh()->chasedat);
    }

    public function test_members_are_handled_in_chunks(): void
    {
        config(['freegle.interested.chunk' => 2]);
        for ($i = 0; $i < 5; $i++) {
            $this->mark($this->member(), $this->openPost());
        }

        $this->run_();

        $this->assertCount(5, $this->spooled);
    }

    public function test_the_age_comes_from_config(): void
    {
        config(['freegle.interested.chaseup_after_hours' => 2]);
        $mark = $this->mark($this->member(), $this->openPost(), '3 hours');

        $this->run_();

        $this->assertNotNull($mark->fresh()->chasedat);
    }

    public function test_a_failed_send_gives_the_marks_back(): void
    {
        $this->mock(EmailSpoolerService::class, function ($m) {
            $m->shouldReceive('spool')->andThrow(new \RuntimeException('spool full'));
        });
        $mark = $this->mark($this->member(), $this->openPost());

        try {
            $this->artisan('messages:interested-chaseup')->run();
        } catch (\Throwable $e) {
            // The failure is reported by the batch logging wrapper or rethrown; either way the mark must be free.
        }

        $this->assertNull($mark->fresh()->chasedat);
    }

    // ---------------------------------------------------------------- dry run

    public function test_a_dry_run_lists_without_sending_or_writing(): void
    {
        $mailed = $this->mark($this->member(), $this->openPost());
        $skipped = $this->mark($this->member(['bouncing' => 1]), $this->openPost());

        $this->artisan('messages:interested-chaseup', ['--dry-run' => true])
            ->expectsOutputToContain('Would email user')
            ->assertExitCode(0);

        $this->assertSame([], $this->spooled);
        $this->assertNull($mailed->fresh()->chasedat);
        $this->assertNull($skipped->fresh()->chasedat);
    }

    // ---------------------------------------------------------------- the mail

    public function test_the_subject_names_a_single_post(): void
    {
        $member = $this->member();
        $one = $this->openPost(['subject' => 'OFFER: Sofa (Leeds)']);
        $this->mark($member, $one);

        $this->run_();

        $this->assertSame('Still interested? Sofa is still available', $this->subjectOf($this->spooled[0]));
    }

    public function test_the_subject_and_view_for_several_posts(): void
    {
        $member = $this->member();
        $one = $this->openPost(['subject' => 'OFFER: Sofa (Leeds)']);
        $two = $this->openPost(['subject' => 'WANTED: Lamp (Leeds)']);
        $mail = new InterestedChaseUp($member, $member->email_preferred, [$one, $two]);

        $this->assertSame('Still interested? 2 posts are still available', $this->subjectOf($mail));

        $mail->build();
        $html = $this->renderView($mail);

        $this->assertStringContainsString('2 posts you were interested in are still available', $html);
        $this->assertStringContainsString('Sofa', $html);
        $this->assertStringContainsString('Lamp', $html);
        $this->assertSame(2, substr_count($html, '>Reply</a>'));
        $this->assertSame($member->id, $this->recipientOf($mail));
        $this->assertSame([], $mail->about()['users']);
    }

    public function test_the_reply_link_opens_the_reply_pane_and_is_tracked(): void
    {
        $member = $this->member();
        $post = $this->openPost(['subject' => 'OFFER: Sofa (Leeds)']);
        $mail = new InterestedChaseUp($member, $member->email_preferred, [$post]);
        $mail->build();
        $html = $this->renderView($mail);

        $this->assertStringContainsString('A post you were interested in is still available', $html);
        $this->assertNotNull(DB::table('email_tracking')->where('email_type', 'InterestedChaseUp')->where('recipient_email', $member->email_preferred)->first());
        // Tracked links point at the tracker, not straight at the post.
        preg_match_all('#<a href="([^"]+)"[^>]*>Reply</a>#', $html, $m);
        $this->assertCount(1, $m[1]);
        $this->assertStringNotContainsString('/message/'.$post->id.'?reply=1', $m[1][0]);
    }

    public function test_the_reply_link_goes_straight_to_the_post_when_tracking_is_off(): void
    {
        config(['freegle.mail.tracking_enabled' => false]);
        $member = $this->member();
        $post = $this->openPost();
        $mail = new InterestedChaseUp($member, $member->email_preferred, [$post]);
        $mail->build();

        $this->assertStringContainsString('/message/'.$post->id.'?reply=1', $this->renderView($mail));
    }

    public function test_a_photo_is_shown_when_the_post_has_one(): void
    {
        $member = $this->member();
        $post = $this->openPost();
        DB::table('messages_attachments')->insert(['msgid' => $post->id, 'externaluid' => 'freegletusd-abc', 'primary' => 1]);
        $mail = new InterestedChaseUp($member, $member->email_preferred, [$post->fresh(['attachments', 'groups'])]);
        $mail->build();

        $this->assertStringContainsString('timg_', urldecode($this->renderView($mail)));
    }

    // ---------------------------------------------------------------- helpers for the mail

    private function subjectOf(InterestedChaseUp $mail): string
    {
        return $mail->envelope()->subject;
    }

    private function recipientOf(InterestedChaseUp $mail): ?int
    {
        $method = new \ReflectionMethod($mail, 'getRecipientUserId');

        return $method->invoke($mail);
    }

    /** The MJML the mail would send, rendered from the data it built (the MJML server is not needed). */
    private function renderView(InterestedChaseUp $mail): string
    {
        $template = new ReflectionProperty(\App\Mail\MjmlMailable::class, 'mjmlTemplate');
        $data = new ReflectionProperty(\App\Mail\MjmlMailable::class, 'mjmlData');

        return view($template->getValue($mail), $data->getValue($mail))->render();
    }
}

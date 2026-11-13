<?php

namespace Tests\Feature\Support;

use App\Mail\Chat\ReferToSupportMail;
use App\Models\BackgroundTask;
use App\Models\User;
use App\Services\Support\BriefFormatter;
use App\Services\Support\FirstPassService;
use App\Services\Support\MailboxHistory;
use App\Services\Support\UnconfiguredMailboxHistory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A chat referred to Support: support:first-pass takes the queued refer_to_support task, runs the AI
 * first pass and sends Support one mail from the community's volunteers address. Nothing else is
 * stored. One Http::fake closure per test reads $this->helper, so a test changes what the helper
 * answers by changing that array and never registers a second fake.
 */
class ReferToSupportTest extends TestCase
{
    /** @var array{status: int, body: mixed, throw: ?string} */
    private array $helper;

    /** @var Request[] */
    private array $helperRequests = [];

    /** @var Request[] */
    private array $imageRequests = [];

    /** [body, content type] every image fetch answers with, when set. */
    private ?array $imageOverride = null;

    /** A real 1x1 JPEG; images are typed from their bytes, not from what the server says. */
    private const JPEG_B64 = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    /** A real 1x1 PNG. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** Runs once, on the first helper call, when set. */
    private ?\Closure $onHelperCall = null;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('freegle.support.first_pass', [
            'enabled' => true, 'timeout' => 30, 'per_referrer_per_day' => 10,
            'max_age_minutes' => 60, 'run_minutes' => 10, 'uploads_url' => 'https://uploads.test/',
        ]);
        config()->set('freegle.ux.prompts', ['helper_url' => 'http://ai-support-helper:3000', 'internal_secret' => 'test-secret']);
        config()->set('freegle.mail.support_addr', 'support-'.uniqid().'@test.invalid');
        config()->set('freegle.delivery.base_url', 'https://delivery.test');

        $this->helperRequests = [];
        $this->imageRequests = [];
        $this->imageOverride = null;
        $this->onHelperCall = null;
        $this->helper = ['status' => 200, 'throw' => null, 'body' => $this->okBody()];

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), '/internal/support-first-pass')) {
                $this->helperRequests[] = $request;
                if ($this->onHelperCall) {
                    $hook = $this->onHelperCall;
                    $this->onHelperCall = null;
                    $hook();
                }
                if ($this->helper['throw']) {
                    throw new ConnectionException($this->helper['throw']);
                }

                return Http::response($this->helper['body'], $this->helper['status']);
            }
            $this->imageRequests[] = $request;
            if ($this->imageOverride) {
                return Http::response($this->imageOverride[0], 200, ['Content-Type' => $this->imageOverride[1]]);
            }
            if (str_contains($request->url(), 'bad')) {
                return Http::response('no', 404);
            }

            return Http::response(base64_decode(self::JPEG_B64), 200, ['Content-Type' => 'image/jpeg']);
        });
    }

    private function okBody(string $answer = 'The member could not find the item.'): array
    {
        return [
            'brief' => [
                'version' => 2, 'question' => 'Why can the member not collect the item?', 'answer' => $answer,
                'classification' => 'Usage', 'confidence' => ['level' => 'High', 'reason' => 'Clear chat'],
                'checks' => ['Read the chat & the post', 'Looked at the app logs'],
                'open_questions' => ['Which phone is it?'],
                'draft' => ['to' => 'referrer', 'text' => 'Please tell them to tap Mark as taken.', 'link_warnings' => ['https://example.invalid/x is not a Freegle page']],
                'images' => ['sent' => 0, 'skipped' => 0],
            ],
            'usage' => ['inputTokens' => 1200, 'outputTokens' => 300, 'cacheCreation' => 10, 'cacheRead' => 20, 'durationMs' => 4500],
            'costUsd' => 0.0421, 'model' => 'test-model', 'driver' => 'sdk',
            'quota' => ['fiveHour' => 41.5, 'sevenDay' => 12], 'quotaBefore' => ['fiveHour' => 40, 'sevenDay' => 11.5],
            'transcript' => '[{"role":"assistant","text":"done"}]',
        ];
    }

    /**
     * Queue a referral the way the Go API does. 'data' overrides what is in the task, 'task' the row.
     *
     * @return array{task: int, referrer: User, member: User, chat: int, group: object}
     */
    private function refer(array $over = []): array
    {
        $referrer = $over['referrer'] ?? $this->createTestUser(['fullname' => 'Referring Mod']);
        $member = $over['member'] ?? $this->createTestUser(['fullname' => 'Some Member']);
        $group = $over['group'] ?? $this->createTestGroup();
        $chat = $over['chat'] ?? DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2Mod', 'user1' => $member->id, 'groupid' => $group->id, 'created' => now(),
        ]);
        $data = array_merge([
            'chatid' => $chat, 'userid' => $referrer->id, 'memberid' => $member->id, 'groupid' => $group->id, 'reason' => '',
        ], $over['data'] ?? []);
        $id = DB::table('background_tasks')->insertGetId(array_merge([
            'task_type' => BackgroundTask::TASK_REFER_TO_SUPPORT, 'data' => json_encode($data),
        ], $over['task'] ?? []));

        return ['task' => $id, 'referrer' => $referrer, 'member' => $member, 'chat' => (int) $chat, 'group' => $group];
    }

    private function runPass(): void
    {
        $this->artisan('support:first-pass')->assertSuccessful();
        $this->artisan('mail:spool:process')->assertSuccessful();
    }

    private function task(int $id): object
    {
        return DB::table('background_tasks')->find($id);
    }

    private function mailsFrom(User $referrer)
    {
        return Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->referrerId === $referrer->id);
    }

    private function textOf(ReferToSupportMail $mail): string
    {
        $copy = clone $mail;
        $copy->build();

        return (string) $copy->viewData['text'];
    }

    private function chatMessage(int $chat, ?int $uid, string $text, array $o = []): int
    {
        return DB::table('chat_messages')->insertGetId(array_merge([
            'chatid' => $chat, 'userid' => $uid, 'message' => $text, 'type' => 'Default', 'date' => now(),
            'reviewrequired' => 0, 'reviewrejected' => 0, 'processingrequired' => 0, 'processingsuccessful' => 1,
            'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
        ], $o));
    }

    public function test_a_referral_is_one_mail_from_the_volunteers_address_with_the_first_pass_and_no_chat_content(): void
    {
        Mail::fake();
        $c = $this->refer(['data' => ['reason' => 'Member says they cannot collect']]);
        $this->chatMessage($c['chat'], $c['member']->id, 'SECRET CHAT WORDS');

        $this->runPass();

        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $mail = $this->mailsFrom($c['referrer'])->first();
        $volunteers = $c['group']->nameshort.'-volunteers@'.config('freegle.mail.group_domain');
        $envelope = $mail->envelope();
        $this->assertSame($volunteers, $envelope->from->address, 'it comes from the community, not from no-reply');
        $this->assertSame(($c['group']->namefull ?: $c['group']->nameshort).' Volunteers', $envelope->from->name);
        $this->assertSame($volunteers, $envelope->replyTo[0]->address);
        $this->assertSame("{$c['referrer']->fullname} (#{$c['referrer']->id}) asked for help with chat #{$c['chat']} with {$c['member']->fullname} (#{$c['member']->id})", $envelope->subject);

        $site = rtrim(config('freegle.sites.mod'), '/');
        $text = $this->textOf($mail);
        $this->assertStringContainsString('The moderator asked: Member says they cannot collect', $text);
        $this->assertStringContainsString('Community: '.($c['group']->namefull ?: $c['group']->nameshort), $text);
        $this->assertStringContainsString("Member: {$c['member']->fullname} (#{$c['member']->id})", $text);
        $this->assertStringContainsString("Chat: #{$c['chat']} (A member writing to the volunteers)", $text);
        $this->assertStringContainsString('The question: Why can the member not collect the item?', $text);
        $this->assertStringContainsString('The member could not find the item.', $text);
        $this->assertStringContainsString('Classification: Usage. Confidence: High (Clear chat)', $text);
        $this->assertStringContainsString('Which phone is it?', $text);
        $this->assertStringContainsString('Draft to the referring volunteer, not sent', $text);
        $this->assertStringContainsString('Check before sending', $text);
        $this->assertStringContainsString('Read the chat & the post', $text, 'plain text is not HTML escaped');
        $this->assertStringContainsString('5-hour 42% used', $text);
        $this->assertStringContainsString("{$site}/support/refer/{$c['chat']}", $text);
        $this->assertStringContainsString("{$site}/support/{$c['member']->id}", $text);
        $this->assertStringNotContainsString('/support/cases/', $text, 'there are no cases');
        $this->assertStringNotContainsString('SECRET CHAT WORDS', $text);

        $html = (clone $mail)->render();
        $this->assertStringContainsString('A volunteer has asked Support for help', $html);
        $this->assertStringContainsString('Member says they cannot collect', $html);
        $this->assertStringContainsString('Why can the member not collect the item?', $html);
        $this->assertStringContainsString('Please tell them to tap Mark as taken.', $html);
        $this->assertStringContainsString('Read the chat &amp; the post', $html, 'the HTML part is escaped');
        $this->assertStringContainsString("{$site}/support/refer/{$c['chat']}", $html);
        $this->assertStringNotContainsString('SECRET CHAT WORDS', $html);

        // The task is done, and the moderator's free text has left it.
        $task = $this->task($c['task']);
        $this->assertNotNull($task->processed_at);
        $this->assertArrayNotHasKey('reason', json_decode($task->data, true));
        $this->assertArrayNotHasKey('brief', json_decode($task->data, true));
        $this->assertSame($c['chat'], json_decode($task->data, true)['chatid']);

        $run = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $c['referrer']->id)->first();
        $this->assertSame($c['member']->id, (int) $run->userid);
        $this->assertSame("First pass for referral {$c['task']} of chat {$c['chat']}", $run->query);
        $this->assertSame('Success', $run->status);
        $this->assertSame(1200, (int) $run->input_tokens);
        $this->assertSame('test-model', $run->model);
        $this->assertEquals(41.5, (float) $run->quota_5h_after);
        $this->assertStringContainsString('**The question:** Why can the member not collect the item?', $run->analysis);
    }

    public function test_the_mail_goes_once_however_often_the_command_runs(): void
    {
        Mail::fake();
        $c = $this->refer();

        $this->runPass();
        $this->runPass();
        $this->runPass();

        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $this->assertCount(1, $this->helperRequests);
    }

    public function test_a_member_to_member_chat_uses_the_community_the_api_chose(): void
    {
        Mail::fake();
        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $chat = DB::table('chat_rooms')->insertGetId(['chattype' => 'User2User', 'user1' => $member->id, 'user2' => $other->id, 'created' => now()]);
        $c = $this->refer(['chat' => $chat, 'data' => ['memberid' => 0]]);

        $this->runPass();

        $mail = $this->mailsFrom($c['referrer'])->first();
        $this->assertSame($c['group']->nameshort.'-volunteers@'.config('freegle.mail.group_domain'), $mail->envelope()->from->address);
        $this->assertSame("{$c['referrer']->fullname} (#{$c['referrer']->id}) asked for help with chat #{$chat}", $mail->envelope()->subject);
        $this->assertStringContainsString("Chat: #{$chat} (Two members)", $this->textOf($mail));
        $this->assertStringNotContainsString('Member:', $this->textOf($mail));
        $this->assertNull($this->helperRequests[0]->data()['memberid'], 'with no single member the pass is chat-only');
        $this->assertSame($c['group']->id, $this->helperRequests[0]->data()['chat']['group']['id']);
    }

    public function test_with_no_community_it_comes_from_no_reply_and_replies_go_to_the_referrer(): void
    {
        Mail::fake();
        $member = $this->createTestUser();
        $other = $this->createTestUser();
        $chat = DB::table('chat_rooms')->insertGetId(['chattype' => 'User2User', 'user1' => $member->id, 'user2' => $other->id, 'created' => now()]);
        $c = $this->refer(['chat' => $chat, 'data' => ['groupid' => 0]]);

        $this->runPass();

        $envelope = $this->mailsFrom($c['referrer'])->first()->envelope();
        $this->assertSame(config('freegle.mail.noreply_addr'), $envelope->from->address);
        $this->assertSame($c['referrer']->email_preferred, $envelope->replyTo[0]->address);
        $this->assertNotEmpty($envelope->replyTo[0]->address);
        $this->assertStringNotContainsString('Community:', $this->textOf($this->mailsFrom($c['referrer'])->first()));
    }

    public function test_the_helper_request_has_the_chat_roles_images_and_unavailable_mail_history(): void
    {
        Mail::fake();
        $referrer = $this->createTestUser();
        $member = $this->createTestUser();
        $group = $this->createTestGroup();
        $chat = DB::table('chat_rooms')->insertGetId(['chattype' => 'User2User', 'user1' => $member->id, 'user2' => $referrer->id, 'groupid' => $group->id, 'created' => now()]);
        $c = $this->refer(['referrer' => $referrer, 'member' => $member, 'group' => $group, 'chat' => $chat, 'data' => ['reason' => 'Help'], 'task' => ['created_at' => DB::raw('NOW() - INTERVAL 5 MINUTE')]]);
        $other = $this->createTestUser();
        $this->chatMessage($chat, $member->id, 'member says hello');
        $this->chatMessage($chat, $referrer->id, 'mod replies');
        $this->chatMessage($chat, $other->id, 'someone else');
        $this->chatMessage($chat, $other->id, 'system note', ['type' => 'System']);
        $this->chatMessage($chat, $member->id, 'REVIEW HOLD', ['reviewrequired' => 1]);
        $this->chatMessage($chat, $member->id, 'REJECTED', ['reviewrejected' => 1]);
        $this->chatMessage($chat, $member->id, 'DELETED', ['deleted' => 1]);
        $held = $this->chatMessage($chat, $member->id, 'MOD HELD');
        DB::table('chat_messages_held')->insert(['msgid' => $held, 'userid' => $referrer->id, 'timestamp' => now()]);

        // Images: two good (one with a rotate), one that cannot be fetched, one held and so left out.
        $img = function (string $uid, ?string $mods = null, array $o = []) use ($chat, $member) {
            $m = $this->chatMessage($chat, $member->id, 'photo', $o);
            DB::table('chat_images')->insert(['chatmsgid' => $m, 'externaluid' => $uid, 'externalmods' => $mods, 'archived' => 0]);
        };
        $img('freegletusd-good1', '{"rotate":90}');
        $img('freegletusd-good2');
        $img('freegletusd-bad3');
        $img('freegletusd-held4', null, ['reviewrequired' => 1]);

        $this->runPass();

        $this->assertCount(1, $this->helperRequests);
        $req = $this->helperRequests[0];
        $this->assertSame('1', $req->header('X-Freegle-Internal')[0]);
        $this->assertSame('test-secret', $req->header('X-Freegle-Internal-Secret')[0]);
        $this->assertSame('http://ai-support-helper:3000/internal/support-first-pass', $req->url());

        $body = $req->data();
        $this->assertSame($c['task'], $body['referralid']);
        $this->assertArrayNotHasKey('caseid', $body);
        $this->assertArrayNotHasKey('recentCases', $body);
        $this->assertSame($member->id, $body['memberid']);
        $referredAt = \Carbon\Carbon::parse($this->task($c['task'])->created_at, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
        $this->assertSame(['kind' => 'referral', 'chatid' => $chat, 'referrerid' => $referrer->id, 'reason' => 'Help', 'referredAt' => $referredAt], $body['source']);
        $this->assertSame('User2User', $body['chat']['type']);
        $this->assertSame($group->id, $body['chat']['group']['id']);

        $texts = array_column($body['chat']['messages'], 'text');
        $this->assertContains('member says hello', $texts);
        foreach (['REVIEW HOLD', 'REJECTED', 'DELETED', 'MOD HELD'] as $hidden) {
            $this->assertNotContains($hidden, $texts);
        }
        $from = array_column($body['chat']['messages'], 'from', 'text');
        $this->assertSame('member', $from['member says hello']);
        $this->assertSame('moderator', $from['mod replies']);
        $this->assertSame('other', $from['someone else']);
        $this->assertSame('system', $from['system note']);

        $this->assertCount(2, $body['images'], 'the unfetchable image is skipped and the held one is never asked for');
        $this->assertSame(1, $body['imagesSkipped']);
        $this->assertSame('image/jpeg', $body['images'][0]['mime']);
        $this->assertSame(self::JPEG_B64, $body['images'][0]['data']);
        $urls = array_map(fn ($r) => $r->url(), $this->imageRequests);
        $this->assertContains('https://delivery.test?url=https://uploads.test/good1&ro=90', $urls);
        $this->assertContains('https://delivery.test?url=https://uploads.test/good2', $urls);
        $this->assertNotContains('https://delivery.test?url=https://uploads.test/held4', $urls);

        $this->assertFalse($body['mailHistory']['available']);
        $this->assertSame(UnconfiguredMailboxHistory::REASON, $body['mailHistory']['reason']);
        $this->assertSame([], $body['mailHistory']['entries']);
    }

    /** The referral object the command builds from a task, for calling the service directly. */
    private function referralOf(array $c): object
    {
        return (object) [
            'id' => $c['task'], 'chatid' => $c['chat'], 'userid' => $c['member']->id, 'referrerid' => $c['referrer']->id,
            'groupid' => $c['group']->id, 'reason' => null, 'created_at' => $this->task($c['task'])->created_at,
        ];
    }

    public function test_images_that_are_too_big_or_not_images_are_skipped_and_at_most_five_are_fetched(): void
    {
        Mail::fake();
        $c = $this->refer();
        for ($i = 1; $i <= 7; $i++) {
            $m = $this->chatMessage($c['chat'], $c['member']->id, 'p');
            // Archived and plain legacy rows use the archive and image domains the way the Go API does.
            DB::table('chat_images')->insert(['chatmsgid' => $m, 'externaluid' => null, 'archived' => $i % 2]);
        }
        $svc = app(FirstPassService::class);

        $this->assertSame(config('freegle.images.archived_domain').'/mimg_9.jpg', $svc->chatImageUrl((object) ['id' => 9, 'archived' => 1, 'externaluid' => '']));
        $this->assertSame(config('freegle.images.domain').'/mimg_9.jpg', $svc->chatImageUrl((object) ['id' => 9, 'archived' => 0, 'externaluid' => '']));

        $referral = $this->referralOf($c);
        $this->runPass();

        $this->assertCount(5, $this->imageRequests);
        $this->assertCount(5, $this->helperRequests[0]->data()['images']);

        // An answer that is not an image is dropped.
        $this->imageOverride = ['<html>', 'text/html'];
        $req = $svc->buildRequest($referral);
        $this->assertSame([], $req['images']);
        $this->assertSame(5, $req['imagesSkipped']);
    }

    public function test_an_image_is_typed_from_its_bytes_and_one_that_is_not_a_known_image_is_skipped_and_counted(): void
    {
        $c = $this->refer();
        $m = $this->chatMessage($c['chat'], $c['member']->id, 'p');
        DB::table('chat_images')->insert(['chatmsgid' => $m, 'externaluid' => null, 'archived' => 0]);
        $svc = app(FirstPassService::class);
        $referral = $this->referralOf($c);

        // A PNG that the server calls a JPEG goes as a PNG.
        $this->imageOverride = [base64_decode(self::PNG_B64), 'image/jpeg'];
        $req = $svc->buildRequest($referral);
        $this->assertSame('image/png', $req['images'][0]['mime']);
        $this->assertSame(0, $req['imagesSkipped']);

        // Something that is not an image but is called one is dropped, as is an image type the helper refuses.
        foreach (['<html>not an image</html>', 'BM'.str_repeat("\0", 60)] as $bytes) {
            $this->imageOverride = [$bytes, 'image/jpeg'];
            $req = $svc->buildRequest($referral);
            $this->assertSame([], $req['images']);
            $this->assertSame(1, $req['imagesSkipped']);
        }
    }

    /** @return array<string, array{int, mixed, string}> */
    public static function failures(): array
    {
        return [
            'deadline' => [502, ['error' => 'x', 'code' => 'DEADLINE'], FirstPassService::REASON_TOO_LONG],
            'credit' => [502, ['error' => 'x', 'code' => 'CREDIT'], FirstPassService::REASON_CREDIT],
            'too many steps' => [502, ['error' => 'x', 'code' => 'TOO_MANY_STEPS'], FirstPassService::REASON_STEPS],
            'lockdown' => [502, ['error' => 'x', 'code' => 'DUMP_LOCKDOWN'], FirstPassService::REASON_LOCKDOWN],
            'dump key refused' => [502, ['error' => 'x', 'code' => 'DUMP_AUTH'], FirstPassService::REASON_NOT_SET_UP],
            'model' => [502, ['error' => 'x', 'code' => 'MODEL'], FirstPassService::REASON_FAILED],
            'bad output code' => [502, ['error' => 'x', 'code' => 'BAD_OUTPUT'], FirstPassService::REASON_FAILED],
            'ok but no brief' => [200, ['brief' => 'text'], FirstPassService::REASON_FAILED],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failure_where_the_helper_answered_is_mailed_at_once_with_its_reason_and_never_retried(int $status, mixed $body, string $reason): void
    {
        Mail::fake();
        $c = $this->refer();
        $this->helper = ['status' => $status, 'body' => $body, 'throw' => null];

        $this->runPass();
        $this->runPass();

        $this->assertCount(1, $this->helperRequests, 'the model and the dump are not spent again');
        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $text = $this->textOf($this->mailsFrom($c['referrer'])->first());
        $this->assertStringContainsString("No AI first pass: {$reason}", $text);
        $this->assertStringContainsString('/support/refer/'.$c['chat'], $text);
        $this->assertStringContainsString("There is no first pass for this one: {$reason}.", (clone $this->mailsFrom($c['referrer'])->first())->render());
        $this->assertNotNull($this->task($c['task'])->processed_at);

        // The helper answered, so the attempt left an Error run with the plain reason and nothing else.
        $runs = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $c['referrer']->id)->get();
        $this->assertCount(1, $runs);
        $this->assertSame('Error', $runs[0]->status);
        $this->assertSame($reason, $runs[0]->error);
    }

    public function test_a_server_error_that_is_not_a_helper_answer_is_retried_three_times_and_each_attempt_is_recorded(): void
    {
        Mail::fake();
        $c = $this->refer();
        $this->helper = ['status' => 500, 'body' => ['error' => 'x'], 'throw' => null];

        $this->runPass();
        $this->assertNull($this->task($c['task'])->processed_at, 'retried later, not at once');
        $this->assertSame(1, (int) $this->task($c['task'])->attempts);
        $this->assertCount(0, $this->mailsFrom($c['referrer']));

        $this->runPass();
        $this->runPass();
        $this->runPass();

        $this->assertCount(3, $this->helperRequests);
        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $this->assertStringContainsString('No AI first pass: '.FirstPassService::REASON_FAILED, $this->textOf($this->mailsFrom($c['referrer'])->first()));
        $this->assertSame(3, DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $c['referrer']->id)->where('status', 'Error')->count());
    }

    public function test_a_timed_out_helper_leaves_an_error_run_with_a_plain_reason(): void
    {
        Mail::fake();
        $c = $this->refer();
        $this->helper['throw'] = 'cURL error 28: Operation timed out after 330001 milliseconds secret-detail';

        $this->runPass();

        // The request may have spent tokens, so the attempt is recorded with no tokens and counts against the cap.
        $runs = DB::table('support_ai_runs')->where('modid', $c['referrer']->id)->get();
        $this->assertCount(1, $runs);
        $this->assertSame('Error', $runs[0]->status);
        $this->assertSame(0, (int) $runs[0]->input_tokens + (int) $runs[0]->output_tokens);
        $this->assertSame(FirstPassService::REASON_TOO_LONG, $runs[0]->error);
        $this->assertSame(FirstPassService::REASON_TOO_LONG, $this->task($c['task'])->error_message);
    }

    public function test_a_pass_that_was_killed_three_times_is_mailed_without_asking_again(): void
    {
        Mail::fake();
        // Each start is counted before the helper is asked, so a run that dies mid-pass still counts.
        $c = $this->refer(['task' => ['attempts' => 3]]);

        $this->runPass();

        $this->assertCount(0, $this->helperRequests);
        $this->assertStringContainsString('No AI first pass: '.FirstPassService::REASON_INTERRUPTED, $this->textOf($this->mailsFrom($c['referrer'])->first()));
    }

    /** @return array<string, array{string}> */
    public static function unreachable(): array
    {
        return [
            'refused' => ['cURL error 7: Failed to connect to ai-support-helper secret-detail'],
            'dns' => ['cURL error 6: Could not resolve host: ai-support-helper secret-detail'],
            'connect timeout' => ['cURL error 28: Connection timed out after 10001 milliseconds secret-detail'],
        ];
    }

    #[DataProvider('unreachable')]
    public function test_an_unreachable_helper_leaves_the_referral_waiting_with_no_attempt_run_row_or_cap_use(string $message): void
    {
        Mail::fake();
        $c = $this->refer();
        $this->helper['throw'] = $message;

        $this->runPass();
        $this->runPass();

        $task = $this->task($c['task']);
        $this->assertNull($task->processed_at);
        $this->assertSame(0, (int) $task->attempts);
        $this->assertSame(FirstPassService::REASON_UNREACHABLE, $task->error_message);
        $this->assertSame(0, DB::table('support_ai_runs')->where('modid', $c['referrer']->id)->count());
        $this->assertCount(0, $this->mailsFrom($c['referrer']));
        $this->assertCount(2, $this->helperRequests, 'asked again on each run');

        // After an hour Support is told anyway, with the plain reason rather than "busy".
        DB::table('background_tasks')->where('id', $c['task'])->update(['created_at' => DB::raw('NOW() - INTERVAL 90 MINUTE')]);
        $this->runPass();
        $this->assertCount(2, $this->helperRequests);
        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $this->assertStringContainsString('No AI first pass: The AI helper could not be reached', $this->textOf($this->mailsFrom($c['referrer'])->first()));
    }

    public function test_a_busy_helper_leaves_referrals_waiting_without_counting_an_attempt_and_stops_the_run(): void
    {
        Mail::fake();
        $a = $this->refer();
        $b = $this->refer();
        $this->helper = ['status' => 429, 'body' => ['error' => 'BUSY'], 'throw' => null];

        $this->runPass();

        $this->assertCount(1, $this->helperRequests, 'the second referral is not tried against a busy helper');
        foreach ([$a, $b] as $c) {
            $this->assertNull($this->task($c['task'])->processed_at);
            $this->assertSame(0, (int) $this->task($c['task'])->attempts);
            $this->assertCount(0, $this->mailsFrom($c['referrer']));
        }
        $this->assertSame(0, DB::table('support_ai_runs')->where('modid', $a['referrer']->id)->count());

        // The helper frees up: both are done on the next run, one after the other, and the first is
        // mailed before the second is asked.
        $this->helper = ['status' => 200, 'body' => $this->okBody(), 'throw' => null];
        $seen = [];
        $this->onHelperCall = function () use (&$seen, $a) {
            $this->onHelperCall = function () use (&$seen, $a) {
                $seen[] = $this->task($a['task'])->processed_at;
            };
        };
        $this->runPass();
        $this->assertNotNull($seen[0] ?? null, 'the first referral was already mailed when the second was asked');
        $this->assertCount(1, $this->mailsFrom($a['referrer']));
        $this->assertCount(1, $this->mailsFrom($b['referrer']));
    }

    public function test_a_referral_older_than_the_limit_is_mailed_without_a_pass_and_keeps_an_earlier_reason(): void
    {
        Mail::fake();
        $plain = $this->refer(['task' => ['created_at' => DB::raw('NOW() - INTERVAL 2 HOUR')]]);
        $failed = $this->refer(['task' => ['created_at' => DB::raw('NOW() - INTERVAL 2 HOUR'), 'attempts' => 1, 'error_message' => FirstPassService::REASON_CREDIT]]);

        $this->runPass();

        $this->assertCount(0, $this->helperRequests);
        $this->assertStringContainsString('No AI first pass: '.FirstPassService::REASON_BUSY, $this->textOf($this->mailsFrom($plain['referrer'])->first()));
        $this->assertStringContainsString('No AI first pass: '.FirstPassService::REASON_CREDIT, $this->textOf($this->mailsFrom($failed['referrer'])->first()));
    }

    public function test_with_the_first_pass_off_a_referral_is_mailed_at_once_without_one(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.enabled', false);
        $c = $this->refer(['data' => ['reason' => 'Please look']]);

        $this->runPass();

        $this->assertCount(0, $this->helperRequests);
        $this->assertCount(1, $this->mailsFrom($c['referrer']));
        $text = $this->textOf($this->mailsFrom($c['referrer'])->first());
        $this->assertStringContainsString('The moderator asked: Please look', $text);
        $this->assertStringContainsString('No AI first pass: The first pass is turned off', $text);
        $this->assertSame(0, DB::table('support_ai_runs')->where('modid', $c['referrer']->id)->count());
    }

    public function test_the_off_switch_reads_a_string_flag_the_way_the_environment_gives_it(): void
    {
        Mail::fake();
        foreach (['', '0', 'false', 'off'] as $off) {
            config()->set('freegle.support.first_pass.enabled', $off);
            $this->refer();
            $this->runPass();
        }
        $this->assertCount(0, $this->helperRequests);

        config()->set('freegle.support.first_pass.enabled', '1');
        $this->refer();
        $this->runPass();
        $this->assertCount(1, $this->helperRequests);
    }

    public function test_a_referrer_over_the_daily_cap_is_mailed_without_a_pass_and_timeouts_count(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.per_referrer_per_day', 2);
        $referrer = $this->createTestUser();
        $this->refer(['referrer' => $referrer]);
        $this->refer(['referrer' => $referrer]);
        $third = $this->refer(['referrer' => $referrer]);
        $elsewhere = $this->refer();

        $this->runPass();

        $this->assertCount(3, $this->helperRequests, 'two for the capped referrer and one for the other');
        $this->assertCount(3, $this->mailsFrom($referrer));
        $last = Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->chatId === $third['chat'])->first();
        $this->assertStringContainsString('No AI first pass: The first pass limit for today was reached', $this->textOf($last));
        $this->assertNotNull($this->mailsFrom($elsewhere['referrer'])->first()->brief, 'one referrer cannot stop another');

        // A read timeout may have spent the model, so it counts too.
        $slow = $this->createTestUser();
        $this->helper['throw'] = 'cURL error 28: Operation timed out after 330001 milliseconds';
        $this->refer(['referrer' => $slow]);
        $this->runPass();
        $this->runPass();
        $this->assertSame(2, DB::table('support_ai_runs')->where('modid', $slow->id)->count());
        $this->helper['throw'] = null;
        $this->runPass();
        $this->assertStringContainsString('No AI first pass: '.FirstPassService::REASON_LIMIT, $this->textOf($this->mailsFrom($slow)->first()));
    }

    public function test_a_referral_whose_chat_is_gone_is_still_mailed_without_a_chat_link(): void
    {
        Mail::fake();
        $c = $this->refer(['data' => ['reason' => 'Please look at this']]);
        Schema::disableForeignKeyConstraints();
        DB::table('chat_rooms')->where('id', $c['chat'])->delete();
        Schema::enableForeignKeyConstraints();

        $this->runPass();

        $this->assertCount(0, $this->helperRequests, 'there is nothing to ask the helper about');
        $mail = $this->mailsFrom($c['referrer'])->first();
        $this->assertSame("{$c['referrer']->fullname} (#{$c['referrer']->id}) asked for help with a chat that is no longer there", $mail->envelope()->subject);
        $text = $this->textOf($mail);
        $this->assertStringContainsString('The moderator asked: Please look at this', $text);
        $this->assertStringContainsString('No AI first pass: The chat is no longer there', $text);
        $this->assertStringNotContainsString('/support/refer/', $text);
        $this->assertStringContainsString('No longer there', (clone $mail)->render());
    }

    public function test_a_task_that_names_no_chat_or_referrer_fails_and_mails_nothing(): void
    {
        Mail::fake();
        $id = DB::table('background_tasks')->insertGetId(['task_type' => BackgroundTask::TASK_REFER_TO_SUPPORT, 'data' => json_encode(['chatid' => 0])]);

        $this->runPass();
        $this->runPass();

        $this->assertNotNull($this->task($id)->failed_at);
        $this->assertNull($this->task($id)->processed_at);
        Mail::assertNothingSent();
    }

    public function test_a_failure_building_or_spooling_the_mail_leaves_the_referral_for_the_next_run(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.enabled', false);
        $c = $this->refer();
        $broken = new class(app(MailboxHistory::class)) extends FirstPassService {
            public function referralMail(object $referral, ?array $brief, ?string $skipReason): ReferToSupportMail
            {
                throw new \RuntimeException('cannot build');
            }
        };
        $this->app->instance(FirstPassService::class, $broken);
        $this->artisan('support:first-pass')->assertSuccessful();
        $this->assertNull($this->task($c['task'])->processed_at, 'nothing was claimed');
        $this->app->forgetInstance(FirstPassService::class);

        $real = app(\App\Services\EmailSpoolerService::class);
        $spooler = \Mockery::mock(\App\Services\EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andThrow(new \RuntimeException('spool down'));
        $this->app->instance(\App\Services\EmailSpoolerService::class, $spooler);
        $this->artisan('support:first-pass')->assertSuccessful();
        $this->assertNull($this->task($c['task'])->processed_at, 'the claim was put back');
        $this->assertArrayHasKey('reason', json_decode($this->task($c['task'])->data, true), 'the reason stays until the mail has gone');

        $this->app->instance(\App\Services\EmailSpoolerService::class, $real);
        $this->runPass();
        $this->assertCount(1, $this->mailsFrom($c['referrer']));
    }

    public function test_a_pass_whose_mail_failed_is_mailed_on_the_next_run_without_asking_the_helper_again(): void
    {
        Mail::fake();
        $c = $this->refer();
        $real = app(\App\Services\EmailSpoolerService::class);
        $spooler = \Mockery::mock(\App\Services\EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andThrow(new \RuntimeException('spool down'));
        $this->app->instance(\App\Services\EmailSpoolerService::class, $spooler);

        $this->artisan('support:first-pass')->assertSuccessful();

        $this->assertCount(1, $this->helperRequests);
        $this->assertNull($this->task($c['task'])->processed_at);
        $this->assertSame('The member could not find the item.', json_decode($this->task($c['task'])->data, true)['brief']['answer']);

        $this->app->instance(\App\Services\EmailSpoolerService::class, $real);
        $this->runPass();

        $this->assertCount(1, $this->helperRequests, 'the model is not spent a second time');
        $mail = $this->mailsFrom($c['referrer'])->first();
        $this->assertStringContainsString('The member could not find the item.', $this->textOf($mail));
        $this->assertStringContainsString('5-hour 42% used', $this->textOf($mail));
        $this->assertArrayNotHasKey('brief', json_decode($this->task($c['task'])->data, true));
        $this->assertSame(1, DB::table('support_ai_runs')->where('modid', $c['referrer']->id)->count());
    }

    public function test_the_general_task_queue_leaves_a_referral_alone(): void
    {
        Mail::fake();
        $c = $this->refer();

        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])->assertSuccessful();

        $task = $this->task($c['task']);
        $this->assertNull($task->processed_at);
        $this->assertNull($task->failed_at);
        $this->assertSame(0, (int) $task->attempts);
        $this->assertCount(0, $this->helperRequests);
    }

    public function test_a_malformed_brief_is_formatted_without_throwing(): void
    {
        $odd = ['question' => [1], 'answer' => 'Short answer', 'checks' => 'not a list', 'open_questions' => ['', [1]], 'related_cases' => [7], 'draft' => 'y', 'pool' => 'z'];

        $text = BriefFormatter::text($odd);
        $this->assertStringContainsString('Short answer', $text);
        $this->assertStringNotContainsString('What was checked', $text);
        $this->assertStringNotContainsString('Related', $text, 'there are no cases to relate to');
        $this->assertStringContainsString('AI credit pool: not known', $text);
        $this->assertStringStartsWith('Short answer', BriefFormatter::markdown($odd));
        $this->assertSame('AI credit pool at run time: 5-hour not known, 7-day 3% used', BriefFormatter::poolLine(['sevenDay' => 3.2]));

        $parts = BriefFormatter::parts($odd);
        $this->assertSame(['', 'Short answer', [], [], ''], [$parts['question'], $parts['answer'], $parts['open'], $parts['checks'], $parts['draft']]);

        // And the mail renders with it.
        $mail = new ReferToSupportMail('Mod', 1, 2, 'User2Mod', 'Member', 3, 'Group', 'g-volunteers@test.invalid', 'Group Volunteers', 'g-volunteers@test.invalid', 'Group Volunteers', null, $odd);
        $this->assertStringContainsString('Short answer', $mail->render());
    }

    public function test_a_confidence_reason_ending_in_a_full_stop_renders_once(): void
    {
        $brief = $this->okBody()['brief'];
        $brief['confidence'] = ['level' => 'High', 'reason' => 'Clear chat.'];

        $text = BriefFormatter::text($brief);
        $this->assertStringContainsString('Confidence: High (Clear chat)', $text);
        $this->assertStringNotContainsString('chat.)', $text);
        $this->assertStringContainsString('**Confidence:** High (Clear chat).', BriefFormatter::markdown($brief));
        $this->assertSame('Clear chat', BriefFormatter::parts($brief)['confidenceReason']);
    }

    public function test_the_mailbox_history_binding_is_the_unconfigured_one(): void
    {
        $history = app(MailboxHistory::class);

        $this->assertInstanceOf(UnconfiguredMailboxHistory::class, $history);
        $this->assertSame(['available' => false, 'reason' => 'mailbox access is not set up yet', 'entries' => []], $history->forMember(5));
    }

    public function test_the_purge_clears_what_old_first_pass_runs_found_and_nothing_else(): void
    {
        $mod = $this->createTestUser();
        $run = fn (string $kind, string $age) => DB::table('support_ai_runs')->insertGetId([
            'modid' => $mod->id, 'kind' => $kind, 'query' => 'q', 'analysis' => 'found', 'transcript' => 't', 'status' => 'Success',
            'created_at' => DB::raw("NOW() - INTERVAL {$age}"),
        ]);
        $old = $run('FirstPass', '13 MONTH');
        $recent = $run('FirstPass', '11 MONTH');
        $interactive = $run('Interactive', '13 MONTH');

        $this->artisan('support:purge-runs')->assertSuccessful();

        $this->assertNull(DB::table('support_ai_runs')->find($old)->analysis);
        $this->assertNull(DB::table('support_ai_runs')->find($old)->transcript);
        $this->assertSame('found', DB::table('support_ai_runs')->find($recent)->analysis);
        $this->assertSame('found', DB::table('support_ai_runs')->find($interactive)->analysis);
    }

    public function test_the_config_block_reads_through_envsetting_with_blank_as_the_default(): void
    {
        $read = function (array $env) {
            $saved = [];
            foreach ($env as $name => $value) {
                $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
                putenv("{$name}={$value}");
                $_ENV[$name] = $_SERVER[$name] = $value;
            }
            try {
                return (require base_path('config/freegle.php'))['support']['first_pass'];
            } finally {
                foreach ($saved as $name => [$e, $s, $g]) {
                    $g === false ? putenv($name) : putenv("{$name}={$g}");
                    if ($e === null) {
                        unset($_ENV[$name]);
                    } else {
                        $_ENV[$name] = $e;
                    }
                    if ($s === null) {
                        unset($_SERVER[$name]);
                    } else {
                        $_SERVER[$name] = $s;
                    }
                }
            }
        };
        $names = ['SUPPORT_FIRST_PASS_ENABLED', 'SUPPORT_FIRST_PASS_TIMEOUT', 'SUPPORT_FIRST_PASS_PER_REFERRER_PER_DAY', 'SUPPORT_FIRST_PASS_MAX_AGE_MINUTES', 'SUPPORT_FIRST_PASS_RUN_MINUTES'];

        $blank = $read(array_fill_keys($names, ''));
        $this->assertFalse($blank['enabled']);
        $this->assertSame([510, 10, 60, 10], [$blank['timeout'], $blank['per_referrer_per_day'], $blank['max_age_minutes'], $blank['run_minutes']]);

        $set = $read(['SUPPORT_FIRST_PASS_ENABLED' => '1', 'SUPPORT_FIRST_PASS_TIMEOUT' => '400', 'SUPPORT_FIRST_PASS_PER_REFERRER_PER_DAY' => '3', 'SUPPORT_FIRST_PASS_MAX_AGE_MINUTES' => '30', 'SUPPORT_FIRST_PASS_RUN_MINUTES' => '5']);
        $this->assertTrue($set['enabled']);
        $this->assertSame([400, 3, 30, 5], [$set['timeout'], $set['per_referrer_per_day'], $set['max_age_minutes'], $set['run_minutes']]);
    }
}

<?php

namespace Tests\Feature\SupportCases;

use App\Mail\Chat\ReferToSupportMail;
use App\Mail\Chat\SupportCaseMessageMail;
use App\Models\User;
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
 * The support case first pass: support:first-pass, the case thread notifications, retention and
 * the replay command. One Http::fake closure per test reads $this->helper, so a test changes what
 * the helper answers by changing that array and never registers a second fake.
 */
class SupportFirstPassTest extends TestCase
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

        $this->helperRequests = [];
        $this->imageRequests = [];
        $this->imageOverride = null;
        config()->set('freegle.delivery.base_url', 'https://delivery.test');
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
                'open_questions' => ['Which phone is it?'], 'related_cases' => [4242],
                'draft' => ['to' => 'referrer', 'text' => 'Please tell them to tap Mark as taken.', 'link_warnings' => ['https://example.invalid/x is not a Freegle page']],
                'images' => ['sent' => 0, 'skipped' => 0],
            ],
            'usage' => ['inputTokens' => 1200, 'outputTokens' => 300, 'cacheCreation' => 10, 'cacheRead' => 20, 'durationMs' => 4500],
            'costUsd' => 0.0421, 'model' => 'test-model', 'driver' => 'sdk',
            'quota' => ['fiveHour' => 41.5, 'sevenDay' => 12], 'quotaBefore' => ['fiveHour' => 40, 'sevenDay' => 11.5],
            'transcript' => '[{"role":"assistant","text":"done"}]',
        ];
    }

    /** @return array{case: int, referrer: User, member: User, chat: int, group: object} */
    private function makeCase(array $over = []): array
    {
        $referrer = $over['referrer'] ?? $this->createTestUser(['fullname' => 'Referring Mod']);
        $member = $over['member'] ?? $this->createTestUser(['fullname' => 'Some Member']);
        $group = $over['group'] ?? $this->createTestGroup();
        $chat = $over['chat'] ?? DB::table('chat_rooms')->insertGetId([
            'chattype' => 'User2User', 'user1' => $member->id, 'user2' => $referrer->id, 'groupid' => $group->id, 'created' => now(),
        ]);
        $row = array_merge([
            'chatid' => $chat, 'userid' => $member->id, 'referrerid' => $referrer->id, 'source' => 'Referral',
            'reason' => null, 'status' => 'Open', 'firstpass_status' => 'Pending',
            'open_key' => $chat.'-'.$referrer->id,
        ], array_diff_key($over, array_flip(['referrer', 'member', 'group', 'chat'])));
        $id = DB::table('support_cases')->insertGetId($row);

        return ['case' => $id, 'referrer' => $referrer, 'member' => $member, 'chat' => (int) $chat, 'group' => $group];
    }

    private function runPass(): void
    {
        $this->artisan('support:first-pass')->assertSuccessful();
        $this->artisan('mail:spool:process')->assertSuccessful();
    }

    private function case(int $id): ?object
    {
        return DB::table('support_cases')->find($id);
    }

    private function mailsFor(int $caseId): int
    {
        return Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->caseId === $caseId)->count();
    }

    private function bodyOf(ReferToSupportMail|SupportCaseMessageMail $mail): string
    {
        $copy = clone $mail;
        $copy->build();

        return (string) $copy->viewData['body'];
    }

    private function sentMail(int $caseId): ReferToSupportMail
    {
        return Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->caseId === $caseId)->first();
    }

    public function test_a_helper_success_stores_the_run_and_brief_and_mails_once_with_the_brief(): void
    {
        Mail::fake();
        $c = $this->makeCase(['reason' => 'Member says they cannot collect']);

        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Done', $case->firstpass_status);
        $this->assertNull($case->firstpass_error);
        $brief = json_decode($case->brief, true);
        $this->assertSame('The member could not find the item.', $brief['answer']);
        $this->assertEquals(['fiveHour' => 41.5, 'sevenDay' => 12.0], $brief['pool']);

        $run = DB::table('support_ai_runs')->find($case->firstpass_runid);
        $this->assertSame('FirstPass', $run->kind);
        $this->assertSame($c['referrer']->id, (int) $run->modid);
        $this->assertSame($c['member']->id, (int) $run->userid);
        $this->assertSame("First pass for support case {$c['case']}", $run->query);
        $this->assertSame('Success', $run->status);
        $this->assertSame(1200, (int) $run->input_tokens);
        $this->assertSame(300, (int) $run->output_tokens);
        $this->assertSame(4500, (int) $run->duration_ms);
        $this->assertSame('test-model', $run->model);
        $this->assertEquals(41.5, (float) $run->quota_5h_after);
        $this->assertEquals(40, (float) $run->quota_5h_before);
        $this->assertStringContainsString('The member could not find the item.', $run->analysis);
        $this->assertStringContainsString('**The question:** Why can the member not collect the item?', $run->analysis);
        $this->assertStringContainsString('## What was checked', $run->analysis);
        $this->assertStringNotContainsString('## Member', $run->analysis);

        $this->assertSame(1, $this->mailsFor($c['case']));
        $mail = $this->sentMail($c['case']);
        $this->assertNotNull($this->case($c['case'])->mailed_at);
        $this->assertSame("{$c['referrer']->fullname} (#{$c['referrer']->id}) asked for help with chat #{$c['chat']} with {$c['member']->fullname} (#{$c['member']->id})", $mail->envelope()->subject);
        $this->assertSame($c['group']->nameshort.'-volunteers@groups.ilovefreegle.org', $mail->envelope()->replyTo[0]->address);

        $text = $this->bodyOf($mail);
        $site = config('freegle.sites.mod');
        $this->assertStringContainsString("Support case #{$c['case']}", $text);
        $this->assertStringContainsString('The moderator asked: Member says they cannot collect', $text);
        $this->assertStringContainsString('The question: Why can the member not collect the item?', $text);
        $this->assertStringContainsString('The member could not find the item.', $text);
        $this->assertStringContainsString('Classification: Usage. Confidence: High (Clear chat)', $text);
        $this->assertStringContainsString('Which phone is it?', $text);
        $this->assertStringContainsString("{$site}/support/cases/4242", $text);
        $this->assertStringContainsString('Draft to the referring volunteer, not sent', $text);
        $this->assertStringContainsString('Check before sending', $text);
        $this->assertStringContainsString('What was checked', $text);
        $this->assertStringContainsString('Read the chat & the post', $text, 'plain text is not HTML escaped');
        foreach (['Summary:', 'Anything unusual', 'Could not find', 'Recent posts and chats', 'Bans and merges'] as $gone) {
            $this->assertStringNotContainsString($gone, $text);
        }
        $this->assertLessThan(strpos($text, 'Draft to the referring'), strpos($text, 'The question:'));
        $this->assertLessThan(strpos($text, 'What was checked'), strpos($text, 'Draft to the referring'));
        $this->assertStringContainsString('5-hour 42% used', $text);
        $this->assertStringContainsString("{$site}/support/cases/{$c['case']}", $text);
        $this->assertStringContainsString("{$site}/support/refer/{$c['chat']}", $text);
    }

    public function test_the_helper_request_has_the_chat_roles_images_and_unavailable_mail_history(): void
    {
        Mail::fake();
        $c = $this->makeCase(['reason' => 'Help']);
        $other = $this->createTestUser();
        $mkMsg = function (?int $uid, string $text, array $o = []) use ($c) {
            return DB::table('chat_messages')->insertGetId(array_merge([
                'chatid' => $c['chat'], 'userid' => $uid, 'message' => $text, 'type' => 'Default', 'date' => now(),
                'reviewrequired' => 0, 'reviewrejected' => 0, 'processingrequired' => 0, 'processingsuccessful' => 1,
                'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
            ], $o));
        };
        $mkMsg($c['member']->id, 'member says hello');
        $mkMsg($c['referrer']->id, 'mod replies');
        $mkMsg($other->id, 'someone else');
        $mkMsg($other->id, 'system note', ['type' => 'System']);
        $mkMsg($c['member']->id, 'REVIEW HOLD', ['reviewrequired' => 1]);
        $mkMsg($c['member']->id, 'REJECTED', ['reviewrejected' => 1]);
        $mkMsg($c['member']->id, 'DELETED', ['deleted' => 1]);
        $held = $mkMsg($c['member']->id, 'MOD HELD');
        DB::table('chat_messages_held')->insert(['msgid' => $held, 'userid' => $c['referrer']->id, 'timestamp' => now()]);

        // Images: two good (one with a rotate), one that cannot be fetched, one held and so left out.
        $img = function (string $uid, ?string $mods = null, array $o = []) use ($mkMsg, $c) {
            $m = $mkMsg($c['member']->id, 'photo', $o);
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
        $this->assertSame($c['case'], $body['caseid']);
        $this->assertSame($c['member']->id, $body['memberid']);
        $this->assertSame(['kind' => 'referral', 'chatid' => $c['chat'], 'referrerid' => $c['referrer']->id, 'reason' => 'Help', 'referredAt' => \Carbon\Carbon::parse(DB::table('support_cases')->where('id', $c['case'])->value('created_at'))->utc()->format('Y-m-d H:i:s')], $body['source']);
        $this->assertSame('User2User', $body['chat']['type']);
        $this->assertSame($c['group']->id, $body['chat']['group']['id']);

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

    public function test_the_helper_request_carries_the_referral_time_and_only_other_recent_cases(): void
    {
        Mail::fake();
        $other = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'question' => 'Why did uploads fail?', 'classification' => 'Incident', 'answer' => 'x']), 'created_at' => now()->subDays(2)]);
        $replied = $this->makeCase(['created_at' => now()->subDays(1)]);
        DB::table('support_case_messages')->insert(['caseid' => $replied['case'], 'userid' => $replied['referrer']->id, 'role' => 'Referrer', 'body' => 'Referrer chat']);
        DB::table('support_case_messages')->insert(['caseid' => $replied['case'], 'userid' => $replied['referrer']->id, 'role' => 'Support', 'body' => 'First reply']);
        DB::table('support_case_messages')->insert(['caseid' => $replied['case'], 'userid' => $replied['referrer']->id, 'role' => 'Support', 'body' => str_repeat('L', 900)]);
        $old = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'answer' => 'old']), 'created_at' => now()->subDays(15)]);
        $bare = $this->makeCase(['created_at' => now()->subDays(1)]);
        $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'answer' => 'this']), 'mailed_at' => now(), 'created_at' => now()->subHour()]);
        $c = $this->makeCase(['created_at' => '2026-11-07 09:30:15']);
        // Only this case is pending to the helper; the others are already done or left pending, so run the service directly.
        $case = DB::table('support_cases')->where('id', $c['case'])->first();
        $req = app(\App\Services\Support\FirstPassService::class)->buildRequest($case);

        $this->assertSame('2026-11-07 09:30:15', $req['source']['referredAt']);
        $ids = array_column($req['recentCases'], 'id');
        $this->assertNotContains($c['case'], $ids);
        $this->assertNotContains($old['case'], $ids, 'older than 14 days');
        $this->assertNotContains($bare['case'], $ids, 'no brief and no Support reply');
        $this->assertContains($other['case'], $ids);
        $this->assertContains($replied['case'], $ids);
        $this->assertSame($ids, array_values(array_reverse(collect($ids)->sort()->values()->all())), 'newest first');
        $byId = collect($req['recentCases'])->keyBy('id');
        $this->assertSame('Why did uploads fail?', $byId[$other['case']]['question']);
        $this->assertSame('Incident', $byId[$other['case']]['classification']);
        $this->assertSame('', $byId[$other['case']]['supportReply']);
        $this->assertSame(str_repeat('L', 600), $byId[$replied['case']]['supportReply'], 'the latest Support reply, cut to 600');
        $this->assertSame('', $byId[$replied['case']]['question']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/', $byId[$other['case']]['created']);
    }

    public function test_images_that_are_too_big_or_not_images_are_skipped_and_at_most_five_are_fetched(): void
    {
        Mail::fake();
        $c = $this->makeCase();
        for ($i = 1; $i <= 7; $i++) {
            $m = DB::table('chat_messages')->insertGetId([
                'chatid' => $c['chat'], 'userid' => $c['member']->id, 'message' => 'p', 'type' => 'Default', 'date' => now(),
                'reviewrequired' => 0, 'reviewrejected' => 0, 'processingrequired' => 0, 'processingsuccessful' => 1,
                'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
            ]);
            // Archived and plain legacy rows use the archive and image domains the way the Go API does.
            DB::table('chat_images')->insert(['chatmsgid' => $m, 'externaluid' => null, 'archived' => $i % 2]);
        }
        $svc = app(FirstPassService::class);

        $this->assertSame(config('freegle.images.archived_domain').'/mimg_9.jpg', $svc->chatImageUrl((object) ['id' => 9, 'archived' => 1, 'externaluid' => '']));
        $this->assertSame(config('freegle.images.domain').'/mimg_9.jpg', $svc->chatImageUrl((object) ['id' => 9, 'archived' => 0, 'externaluid' => '']));

        $this->runPass();

        $this->assertCount(5, $this->imageRequests);
        $this->assertCount(5, $this->helperRequests[0]->data()['images']);

        // An answer that is not an image is dropped.
        $this->imageOverride = ['<html>', 'text/html'];
        $req = $svc->buildRequest($this->case($c['case']));
        $this->assertSame([], $req['images']);
        $this->assertSame(5, $req['imagesSkipped']);
    }

    public function test_an_image_is_typed_from_its_bytes_and_one_that_is_not_a_known_image_is_skipped_and_counted(): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $m = DB::table('chat_messages')->insertGetId([
            'chatid' => $c['chat'], 'userid' => $c['member']->id, 'message' => 'p', 'type' => 'Default', 'date' => now(),
            'reviewrequired' => 0, 'reviewrejected' => 0, 'processingrequired' => 0, 'processingsuccessful' => 1,
            'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
        ]);
        DB::table('chat_images')->insert(['chatmsgid' => $m, 'externaluid' => null, 'archived' => 0]);
        $svc = app(FirstPassService::class);

        // A PNG that the server calls a JPEG goes as a PNG.
        $this->imageOverride = [base64_decode(self::PNG_B64), 'image/jpeg'];
        $req = $svc->buildRequest($this->case($c['case']));
        $this->assertSame('image/png', $req['images'][0]['mime']);
        $this->assertSame(0, $req['imagesSkipped']);

        // Something that is not an image but is called one is dropped, as is an image type the helper refuses.
        foreach (["<html>not an image</html>", "BM".str_repeat("\0", 60)] as $bytes) {
            $this->imageOverride = [$bytes, 'image/jpeg'];
            $req = $svc->buildRequest($this->case($c['case']));
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
            'dump' => [502, ['error' => 'x', 'code' => 'DUMP'], FirstPassService::REASON_FAILED],
            'model' => [502, ['error' => 'x', 'code' => 'MODEL'], FirstPassService::REASON_FAILED],
            'bad output code' => [502, ['error' => 'x', 'code' => 'BAD_OUTPUT'], FirstPassService::REASON_FAILED],
            'ok but no brief' => [200, ['brief' => 'text'], FirstPassService::REASON_FAILED],
        ];
    }

    #[DataProvider('failures')]
    public function test_a_failure_where_the_helper_answered_fails_at_once_and_is_never_retried(int $status, mixed $body, string $reason): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $this->helper = ['status' => $status, 'body' => $body, 'throw' => null];

        $this->runPass();
        $this->runPass();
        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Failed', $case->firstpass_status);
        $this->assertSame(1, (int) $case->firstpass_attempts);
        $this->assertSame($reason, $case->firstpass_error);
        $this->assertCount(1, $this->helperRequests, 'the model and the dump are not spent again');

        $this->assertSame(1, $this->mailsFor($c['case']));
        $text = $this->bodyOf($this->sentMail($c['case']));
        $this->assertStringContainsString("First pass skipped: {$reason}", $text);
        $this->assertStringContainsString('/support/cases/'.$c['case'], $text);
        $this->assertStringContainsString('/support/refer/'.$c['chat'], $text);

        // The helper answered, so the attempt left an Error run with the plain reason and nothing else.
        $runs = DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $c['referrer']->id)->get();
        $this->assertCount(1, $runs);
        $this->assertSame('Error', $runs[0]->status);
        $this->assertSame($reason, $runs[0]->error);
        $this->assertNull($case->brief);
    }

    public function test_a_confidence_reason_ending_in_a_full_stop_renders_once_in_the_text_and_the_markdown(): void
    {
        $brief = $this->okBody()['brief'];
        $brief['confidence'] = ['level' => 'High', 'reason' => 'Clear chat.'];

        $text = \App\Services\Support\BriefFormatter::text($brief);
        $this->assertStringContainsString('Confidence: High (Clear chat)', $text);
        $this->assertStringNotContainsString('chat.)', $text);
        $this->assertStringNotContainsString('chat..', $text);

        $md = \App\Services\Support\BriefFormatter::markdown($brief);
        $this->assertStringContainsString('**Confidence:** High (Clear chat).', $md);
        $this->assertStringNotContainsString('chat.)', $md);
        $this->assertStringNotContainsString('chat..', $md);
    }

    public function test_a_server_error_that_is_not_a_helper_answer_is_retried_three_times_and_each_attempt_is_recorded(): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $this->helper = ['status' => 500, 'body' => ['error' => 'x'], 'throw' => null];

        $this->runPass();
        $case = $this->case($c['case']);
        $this->assertSame('Pending', $case->firstpass_status, 'retried later, not at once');
        $this->assertSame(1, (int) $case->firstpass_attempts);
        $this->assertSame(0, $this->mailsFor($c['case']));

        $this->runPass();
        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Failed', $case->firstpass_status);
        $this->assertSame(3, (int) $case->firstpass_attempts);
        $this->assertSame(FirstPassService::REASON_FAILED, $case->firstpass_error);
        $this->assertCount(3, $this->helperRequests);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertSame(3, DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('modid', $c['referrer']->id)->where('status', 'Error')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function terminalFailures(): array
    {
        return [
            'lockdown' => ['DUMP_LOCKDOWN', FirstPassService::REASON_LOCKDOWN],
            'dump key refused' => ['DUMP_AUTH', FirstPassService::REASON_NOT_SET_UP],
        ];
    }

    #[DataProvider('terminalFailures')]
    public function test_a_lockdown_or_refused_dump_key_fails_at_once_without_retrying(string $code, string $reason): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $this->helper = ['status' => 502, 'body' => ['error' => 'x', 'code' => $code], 'throw' => null];

        $this->runPass();
        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Failed', $case->firstpass_status);
        $this->assertSame(1, (int) $case->firstpass_attempts);
        $this->assertSame($reason, $case->firstpass_error);
        $this->assertCount(1, $this->helperRequests);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertStringContainsString("First pass skipped: {$reason}", $this->bodyOf($this->sentMail($c['case'])));
    }

    public function test_an_unreachable_or_timed_out_helper_fails_with_a_plain_reason_and_an_error_run_row(): void
    {
        Mail::fake();
        $a = $this->makeCase();
        $this->helper['throw'] = 'cURL error 7: Failed to connect to ai-support-helper secret-detail';
        $this->runPass();
        $this->assertSame(FirstPassService::REASON_FAILED, $this->case($a['case'])->firstpass_error);
        $this->assertStringNotContainsString('secret-detail', (string) $this->case($a['case'])->firstpass_error);

        $b = $this->makeCase();
        $this->helper['throw'] = 'cURL error 28: Operation timed out after 330001 milliseconds';
        $this->runPass();
        $this->assertSame(FirstPassService::REASON_TOO_LONG, $this->case($b['case'])->firstpass_error);

        // The request may have spent tokens, so each attempt is recorded with no tokens and counts against the cap.
        $runs = DB::table('support_ai_runs')->whereIn('modid', [$a['referrer']->id, $b['referrer']->id])->get();
        $this->assertCount(3, $runs, 'a is tried in both runs and b in the second');
        foreach ($runs as $run) {
            $this->assertSame('FirstPass', $run->kind);
            $this->assertSame('Error', $run->status);
            $this->assertSame(0, (int) $run->input_tokens + (int) $run->output_tokens);
            $this->assertStringNotContainsString('secret-detail', (string) $run->error);
        }
        $this->assertSame(FirstPassService::REASON_TOO_LONG, DB::table('support_ai_runs')->where('modid', $b['referrer']->id)->value('error'));
    }

    public function test_a_server_with_no_helper_secret_fails_at_once_with_the_not_set_up_reason(): void
    {
        Mail::fake();
        config()->set('freegle.ux.prompts', ['helper_url' => 'http://ai-support-helper:3000', 'internal_secret' => '']);
        $c = $this->makeCase();

        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Failed', $case->firstpass_status);
        $this->assertSame(FirstPassService::REASON_NOT_SET_UP, $case->firstpass_error);
        $this->assertCount(0, $this->helperRequests);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertSame(0, DB::table('support_ai_runs')->where('modid', $c['referrer']->id)->count(), 'nothing was asked, so nothing is counted');
    }

    public function test_a_guard_refusal_is_the_not_set_up_reason_and_is_not_retried(): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $this->helper = ['status' => 403, 'body' => ['error' => 'no'], 'throw' => null];

        $this->runPass();

        $this->assertSame('Failed', $this->case($c['case'])->firstpass_status);
        $this->assertSame(FirstPassService::REASON_NOT_SET_UP, $this->case($c['case'])->firstpass_error);
    }

    public function test_a_busy_helper_leaves_the_case_pending_without_counting_an_attempt_and_stops_the_run(): void
    {
        Mail::fake();
        $a = $this->makeCase();
        $b = $this->makeCase();
        $this->helper = ['status' => 429, 'body' => ['error' => 'BUSY'], 'throw' => null];

        $this->runPass();

        $this->assertCount(1, $this->helperRequests, 'the second case is not tried against a busy helper');
        foreach ([$a, $b] as $c) {
            $case = $this->case($c['case']);
            $this->assertSame('Pending', $case->firstpass_status);
            $this->assertSame(0, (int) $case->firstpass_attempts);
            $this->assertNull($case->firstpass_error);
            $this->assertSame(0, $this->mailsFor($c['case']));
        }
        $this->assertSame(0, DB::table('support_ai_runs')->where('modid', $a['referrer']->id)->count());

        // The helper frees up: both are done on the next run, one after the other.
        $this->helper = ['status' => 200, 'body' => $this->okBody(), 'throw' => null];
        $this->runPass();
        $this->assertSame('Done', $this->case($a['case'])->firstpass_status);
        $this->assertSame('Done', $this->case($b['case'])->firstpass_status);
    }

    public function test_a_pending_case_older_than_the_limit_gets_the_plain_mail_and_is_never_run(): void
    {
        Mail::fake();
        $c = $this->makeCase(['created_at' => DB::raw('NOW() - INTERVAL 2 HOUR')]);

        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Failed', $case->firstpass_status);
        $this->assertSame(FirstPassService::REASON_BUSY, $case->firstpass_error);
        $this->assertCount(0, $this->helperRequests);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertStringContainsString('First pass skipped: '.FirstPassService::REASON_BUSY, $this->bodyOf($this->sentMail($c['case'])));
    }

    public function test_an_aged_case_that_already_failed_an_attempt_keeps_that_reason(): void
    {
        Mail::fake();
        $c = $this->makeCase(['created_at' => DB::raw('NOW() - INTERVAL 2 HOUR'), 'firstpass_error' => FirstPassService::REASON_CREDIT, 'firstpass_attempts' => 1]);

        $this->runPass();

        $this->assertSame(FirstPassService::REASON_CREDIT, $this->case($c['case'])->firstpass_error);
        $this->assertSame('Failed', $this->case($c['case'])->firstpass_status);
    }

    public function test_with_the_first_pass_off_a_pending_case_is_mailed_plain_at_once(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.enabled', false);
        $c = $this->makeCase();

        $this->runPass();

        $case = $this->case($c['case']);
        $this->assertSame('Skipped', $case->firstpass_status);
        $this->assertSame(FirstPassService::REASON_OFF, $case->firstpass_error);
        $this->assertCount(0, $this->helperRequests);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertStringContainsString('First pass skipped: The first pass is turned off', $this->bodyOf($this->sentMail($c['case'])));
    }

    public function test_the_off_switch_reads_a_string_flag_the_way_the_environment_gives_it(): void
    {
        Mail::fake();
        foreach (['', '0', 'false', 'off'] as $off) {
            config()->set('freegle.support.first_pass.enabled', $off);
            $c = $this->makeCase();
            $this->runPass();
            $this->assertSame('Skipped', $this->case($c['case'])->firstpass_status, "'{$off}' is off");
        }
        config()->set('freegle.support.first_pass.enabled', '1');
        $c = $this->makeCase();
        $this->runPass();
        $this->assertSame('Done', $this->case($c['case'])->firstpass_status);
    }

    public function test_a_running_case_nobody_is_working_on_is_reaped_and_mailed_plain(): void
    {
        Mail::fake();
        $stale = $this->makeCase(['firstpass_status' => 'Running', 'updated_at' => DB::raw('NOW() - INTERVAL 2 HOUR')]);
        $fresh = $this->makeCase(['firstpass_status' => 'Running']);

        $this->runPass();

        $this->assertSame('Failed', $this->case($stale['case'])->firstpass_status);
        $this->assertSame(FirstPassService::REASON_INTERRUPTED, $this->case($stale['case'])->firstpass_error);
        $this->assertSame(1, $this->mailsFor($stale['case']));
        $this->assertSame('Running', $this->case($fresh['case'])->firstpass_status, 'a pass still within its wait is left alone');
        $this->assertSame(0, $this->mailsFor($fresh['case']));
    }

    public function test_the_mail_goes_once_however_often_the_command_runs(): void
    {
        Mail::fake();
        $c = $this->makeCase();

        $this->runPass();
        $this->runPass();
        $this->runPass();

        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertCount(1, $this->helperRequests);
        $this->assertNotNull($this->case($c['case'])->mailed_at);
    }

    public function test_a_case_already_marked_mailed_is_not_mailed_again(): void
    {
        Mail::fake();
        $c = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['answer' => 's']), 'mailed_at' => now()]);

        $this->runPass();

        $this->assertSame(0, $this->mailsFor($c['case']));
    }

    public function test_a_finished_case_that_missed_its_mail_is_mailed_by_the_next_run(): void
    {
        Mail::fake();
        $c = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'answer' => 'Left over answer'])]);

        $this->runPass();

        $this->assertSame(1, $this->mailsFor($c['case']));
        $this->assertStringContainsString('Left over answer', $this->bodyOf($this->sentMail($c['case'])));
        $this->assertCount(0, $this->helperRequests);
    }

    public function test_a_referrer_over_the_daily_cap_is_skipped_with_the_plain_mail(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.per_referrer_per_day', 2);
        $referrer = $this->createTestUser();
        $first = $this->makeCase(['referrer' => $referrer]);
        $second = $this->makeCase(['referrer' => $referrer]);
        $third = $this->makeCase(['referrer' => $referrer]);
        $elsewhere = $this->makeCase();

        $this->runPass();

        $this->assertSame('Done', $this->case($first['case'])->firstpass_status);
        $this->assertSame('Done', $this->case($second['case'])->firstpass_status);
        $this->assertSame('Skipped', $this->case($third['case'])->firstpass_status);
        $this->assertSame(FirstPassService::REASON_LIMIT, $this->case($third['case'])->firstpass_error);
        $this->assertStringContainsString('First pass skipped: The first pass limit for today was reached', $this->bodyOf($this->sentMail($third['case'])));
        $this->assertSame('Done', $this->case($elsewhere['case'])->firstpass_status, 'one referrer cannot stop another');
        $this->assertCount(3, $this->helperRequests);
    }

    public function test_connection_failures_count_against_the_daily_cap(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.per_referrer_per_day', 2);
        $referrer = $this->createTestUser();
        $this->helper['throw'] = 'cURL error 7: Failed to connect';
        $a = $this->makeCase(['referrer' => $referrer]);
        $this->runPass();
        $this->runPass();
        $this->assertSame(2, DB::table('support_ai_runs')->where('modid', $referrer->id)->count());

        $this->helper['throw'] = null;
        $this->runPass();
        $this->assertSame('Skipped', $this->case($a['case'])->firstpass_status);
        $this->assertSame(FirstPassService::REASON_LIMIT, $this->case($a['case'])->firstpass_error);
    }

    public function test_a_reused_brief_spends_nothing_so_it_is_given_even_to_a_referrer_at_the_cap(): void
    {
        Mail::fake();
        config()->set('freegle.support.first_pass.per_referrer_per_day', 1);
        $first = $this->makeCase();
        $this->runPass();

        $mod2 = $this->createTestUser();
        DB::table('support_ai_runs')->insert(['modid' => $mod2->id, 'kind' => 'FirstPass', 'query' => 'x', 'status' => 'Success']);
        $second = $this->makeCase(['referrer' => $mod2, 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();

        $this->assertSame('Done', $this->case($second['case'])->firstpass_status);
        $this->assertCount(1, $this->helperRequests);
    }

    public function test_a_brief_is_not_reused_for_a_different_reason_but_is_for_the_same_or_none(): void
    {
        Mail::fake();
        $first = $this->makeCase(['reason' => 'Help with the item']);
        $this->runPass();
        $this->assertCount(1, $this->helperRequests);

        $other = $this->makeCase(['referrer' => $this->createTestUser(), 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group'], 'reason' => 'Something else entirely']);
        $this->runPass();
        $this->assertCount(2, $this->helperRequests, 'a different reason runs the model');
        $this->assertSame('Done', $this->case($other['case'])->firstpass_status);

        $same = $this->makeCase(['referrer' => $this->createTestUser(), 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group'], 'reason' => 'Something else entirely']);
        $none = $this->makeCase(['referrer' => $this->createTestUser(), 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();
        $this->assertCount(2, $this->helperRequests, 'the same reason, or none, reuses');
        $this->assertSame('Done', $this->case($same['case'])->firstpass_status);
        $this->assertSame('Done', $this->case($none['case'])->firstpass_status);
    }

    public function test_a_brief_is_not_reused_when_a_visible_message_is_newer_than_it_but_a_hidden_one_does_not_stop_reuse(): void
    {
        Mail::fake();
        $first = $this->makeCase();
        $this->runPass();
        $this->assertCount(1, $this->helperRequests);

        $msg = fn (array $o) => DB::table('chat_messages')->insertGetId(array_merge([
            'chatid' => $first['chat'], 'userid' => $first['member']->id, 'message' => 'later', 'type' => 'Default',
            'date' => DB::raw('NOW() + INTERVAL 1 HOUR'), 'reviewrequired' => 0, 'reviewrejected' => 0, 'processingrequired' => 0,
            'processingsuccessful' => 1, 'mailedtoall' => 0, 'seenbyall' => 0, 'platform' => 1,
        ], $o));
        $msg(['reviewrequired' => 1]);
        $msg(['deleted' => 1]);
        $reused = $this->makeCase(['referrer' => $this->createTestUser(), 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();
        $this->assertCount(1, $this->helperRequests, 'only hidden messages are newer, so reuse');

        $msg([]);
        $fresh = $this->makeCase(['referrer' => $this->createTestUser(), 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();
        $this->assertCount(2, $this->helperRequests, 'a visible newer message runs the model');
        $this->assertSame('Done', $this->case($fresh['case'])->firstpass_status);
    }

    public function test_a_second_referrer_for_the_same_chat_reuses_a_recent_brief_without_a_model_call(): void
    {
        Mail::fake();
        $first = $this->makeCase();
        $this->runPass();
        $this->assertCount(1, $this->helperRequests);

        $mod2 = $this->createTestUser(['fullname' => 'Second Mod']);
        $second = $this->makeCase(['referrer' => $mod2, 'member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();

        $this->assertCount(1, $this->helperRequests, 'no second model call');
        $a = $this->case($first['case']);
        $b = $this->case($second['case']);
        $this->assertSame('Done', $b->firstpass_status);
        $this->assertSame($a->brief, $b->brief);
        $this->assertSame($a->firstpass_runid, $b->firstpass_runid);
        $this->assertSame(1, $this->mailsFor($second['case']));
        $this->assertStringContainsString('The question: Why can the member not collect the item?', $this->bodyOf($this->sentMail($second['case'])));
        $this->assertSame(1, DB::table('support_ai_runs')->where('kind', 'FirstPass')->where('userid', $first['member']->id)->count());
    }

    public function test_a_brief_is_not_reused_for_a_different_member_or_after_a_day(): void
    {
        Mail::fake();
        $first = $this->makeCase();
        $this->runPass();

        // Same chat, but a different member chosen for this referrer.
        $otherMember = $this->createTestUser();
        $differentMember = $this->makeCase(['member' => $otherMember, 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();
        $this->assertCount(2, $this->helperRequests);
        $this->assertSame('Done', $this->case($differentMember['case'])->firstpass_status);

        // The first brief is two days old.
        DB::table('support_cases')->whereIn('id', [$first['case'], $differentMember['case']])->update(['created_at' => DB::raw('NOW() - INTERVAL 2 DAY')]);
        $third = $this->makeCase(['member' => $first['member'], 'chat' => $first['chat'], 'group' => $first['group']]);
        $this->runPass();
        $this->assertCount(3, $this->helperRequests);
        $this->assertSame('Done', $this->case($third['case'])->firstpass_status);
    }

    public function test_a_case_with_no_member_runs_a_chat_only_pass(): void
    {
        Mail::fake();
        $c = $this->makeCase(['userid' => null]);

        $this->runPass();

        $this->assertNull($this->helperRequests[0]->data()['memberid']);
        $this->assertSame('Done', $this->case($c['case'])->firstpass_status);
        $this->assertSame(1, $this->mailsFor($c['case']));
    }

    public function test_a_case_whose_chat_is_gone_is_skipped_with_a_reason_and_still_mailed_without_a_chat_link(): void
    {
        Mail::fake();
        $c = $this->makeCase(['chatid' => null, 'open_key' => null, 'reason' => 'Please look at this']);

        $this->runPass();

        $this->assertCount(0, $this->helperRequests, 'there is nothing to ask the helper about');
        $case = $this->case($c['case']);
        $this->assertSame('Skipped', $case->firstpass_status);
        $this->assertSame('The chat is no longer there', $case->firstpass_error);
        $this->assertSame(0, (int) $case->firstpass_attempts);
        $this->assertSame(1, $this->mailsFor($c['case']));
        $text = $this->bodyOf($this->sentMail($c['case']));
        $this->assertStringContainsString("Support case #{$c['case']}", $text);
        $this->assertStringContainsString('The moderator asked: Please look at this', $text);
        $this->assertStringContainsString('First pass skipped: The chat is no longer there', $text);
        $this->assertStringContainsString('/support/cases/'.$c['case'], $text);
        $this->assertStringNotContainsString('/support/refer/', $text);

        // A chat row that has been deleted behaves the same.
        $d = $this->makeCase();
        Schema::disableForeignKeyConstraints();
        DB::table('chat_rooms')->where('id', $d['chat'])->delete();
        Schema::enableForeignKeyConstraints();
        $this->runPass();
        $this->assertSame('Skipped', $this->case($d['case'])->firstpass_status);
        $this->assertSame(1, $this->mailsFor($d['case']));
        $this->assertCount(0, $this->helperRequests);
    }

    public function test_a_group_chat_goes_to_the_helper_as_type_group(): void
    {
        Mail::fake();
        $member = $this->createTestUser();
        $referrer = $this->createTestUser();
        $chat = DB::table('chat_rooms')->insertGetId(['chattype' => 'Group', 'user1' => $member->id, 'user2' => $referrer->id, 'created' => now()]);
        $this->makeCase(['member' => $member, 'referrer' => $referrer, 'chat' => $chat]);

        $this->runPass();

        $this->assertSame('Group', $this->helperRequests[0]->data()['chat']['type']);
    }

    public function test_the_mail_is_built_before_it_is_claimed_so_a_failure_building_it_leaves_it_to_the_next_run(): void
    {
        Mail::fake();
        $c = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'answer' => 'S'])]);
        $broken = new class(app(MailboxHistory::class)) extends FirstPassService {
            public function referralMail(object $case, ?array $brief, ?string $skipReason): ReferToSupportMail
            {
                throw new \RuntimeException('cannot build');
            }
        };
        $this->app->instance(FirstPassService::class, $broken);

        $this->artisan('support:first-pass')->assertSuccessful();
        $this->assertNull($this->case($c['case'])->mailed_at, 'nothing was claimed, so the next run sends it');

        $this->app->forgetInstance(FirstPassService::class);
        $this->runPass();
        $this->assertSame(1, $this->mailsFor($c['case']));
    }

    public function test_a_failure_spooling_puts_the_claim_back(): void
    {
        Mail::fake();
        $c = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['version' => 2, 'answer' => 'S'])]);
        $spooler = \Mockery::mock(\App\Services\EmailSpoolerService::class);
        $spooler->shouldReceive('spool')->andThrow(new \RuntimeException('spool down'));
        $this->app->instance(\App\Services\EmailSpoolerService::class, $spooler);

        $this->artisan('support:first-pass')->assertSuccessful();

        $this->assertNull($this->case($c['case'])->mailed_at);
    }

    public function test_a_finished_case_is_mailed_before_the_next_one_is_run(): void
    {
        Mail::fake();
        $a = $this->makeCase();
        $b = $this->makeCase();
        $seen = [];
        $this->onHelperCall = function () use (&$seen, $a) {
            $this->onHelperCall = function () use (&$seen, $a) {
                $seen[] = $this->case($a['case'])->mailed_at;
            };
        };

        $this->runPass();

        $this->assertCount(2, $this->helperRequests);
        $this->assertNotNull($seen[0] ?? null, 'the first case was already mailed when the second was asked');
        $this->assertSame(1, $this->mailsFor($b['case']));
    }

    public function test_a_malformed_brief_is_formatted_without_throwing(): void
    {
        $odd = ['question' => [1], 'answer' => 'Short answer', 'checks' => 'not a list', 'open_questions' => ['', [1]], 'related_cases' => ['x', -3, 7, 7], 'draft' => 'y', 'pool' => 'z'];

        $text = \App\Services\Support\BriefFormatter::text($odd);

        $this->assertStringContainsString('Short answer', $text);
        $this->assertStringContainsString('/support/cases/7', $text);
        $this->assertSame(1, substr_count($text, '/support/cases/'));
        $this->assertStringNotContainsString('What was checked', $text);
        $this->assertStringContainsString('AI credit pool: not known', $text);
        $this->assertStringStartsWith('Short answer', \App\Services\Support\BriefFormatter::markdown($odd));
        $this->assertSame('AI credit pool at run time: 5-hour not known, 7-day 3% used', \App\Services\Support\BriefFormatter::poolLine(['sevenDay' => 3.2]));
    }

    public function test_the_mailbox_history_binding_is_the_unconfigured_one(): void
    {
        $history = app(MailboxHistory::class);

        $this->assertInstanceOf(UnconfiguredMailboxHistory::class, $history);
        $this->assertSame(['available' => false, 'reason' => 'mailbox access is not set up yet', 'entries' => []], $history->forMember(5));
        $this->assertFalse($history->forMember(null)['available']);
    }

    public function test_the_legacy_refer_to_support_task_still_mails_and_a_case_task_does_not(): void
    {
        Mail::fake();
        $c = $this->makeCase();
        $legacyMod = $this->createTestUser(['fullname' => 'Legacy Mod']);
        $chat = DB::table('chat_rooms')->insertGetId(['chattype' => 'User2User', 'user1' => $c['member']->id, 'user2' => $legacyMod->id, 'groupid' => $c['group']->id, 'created' => now()]);

        DB::table('background_tasks')->insert(['task_type' => 'refer_to_support', 'data' => json_encode(['chatid' => $chat, 'userid' => $legacyMod->id]), 'created_at' => now()]);
        DB::table('background_tasks')->insert(['task_type' => 'refer_to_support', 'data' => json_encode(['chatid' => $c['chat'], 'userid' => $c['referrer']->id, 'caseid' => $c['case']]), 'created_at' => now()]);
        $this->mock(\App\Services\PushNotificationService::class);

        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])->assertSuccessful();
        $this->artisan('mail:spool:process')->assertSuccessful();

        $legacy = Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->chatId === $chat);
        $this->assertCount(1, $legacy);
        $this->assertNull($legacy->first()->caseId);
        $this->assertStringContainsString("/support/refer/{$chat}", $this->bodyOf($legacy->first()));
        $this->assertCount(0, Mail::sent(ReferToSupportMail::class, fn (ReferToSupportMail $m) => $m->chatId === $c['chat']));
    }

    private function message(string $role, ?User $author, string $body = 'Hello there'): int
    {
        return DB::table('support_case_messages')->insertGetId(['caseid' => $this->caseForMessages['case'], 'userid' => $author?->id, 'role' => $role, 'body' => $body]);
    }

    private array $caseForMessages;

    private function runMessageTask(int $caseId, int $msgId): void
    {
        DB::table('background_tasks')->insert(['task_type' => 'support_case_message', 'data' => json_encode(['caseid' => $caseId, 'messageid' => $msgId]), 'created_at' => now()]);
        $this->mock(\App\Services\PushNotificationService::class);
        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])->assertSuccessful();
        $this->artisan('mail:spool:process')->assertSuccessful();
    }

    public function test_a_support_message_notifies_the_referrer_only_and_carries_no_brief(): void
    {
        Mail::fake();
        $this->caseForMessages = $c = $this->makeCase(['firstpass_status' => 'Done', 'brief' => json_encode(['answer' => 'SECRET BRIEF TEXT'])]);
        $supporter = $this->createTestUser(['fullname' => 'Sam Support', 'systemrole' => 'Support']);
        $msg = $this->message('Support', $supporter, 'Try asking them to refresh the app.');

        $this->runMessageTask($c['case'], $msg);

        $sent = Mail::sent(SupportCaseMessageMail::class, fn (SupportCaseMessageMail $m) => $m->caseId === $c['case']);
        $this->assertCount(1, $sent);
        $mail = $sent->first();
        $this->assertTrue($mail->hasTo($c['referrer']->email_preferred));
        $this->assertFalse($mail->hasTo($supporter->email_preferred));
        $this->assertFalse($mail->hasTo(config('freegle.mail.support_addr')));
        $this->assertSame(config('freegle.mail.noreply_addr'), $mail->envelope()->replyTo[0]->address);
        $text = $this->bodyOf($mail);
        $this->assertStringContainsString('Try asking them to refresh the app.', $text);
        $this->assertStringContainsString('Sam Support', $text);
        $this->assertStringContainsString('Reply in ModTools', $text);
        $this->assertStringContainsString('/support/cases/'.$c['case'], $text);
        $this->assertStringNotContainsString('SECRET BRIEF TEXT', $text);
        $this->assertStringNotContainsString('SECRET BRIEF TEXT', (string) $mail->envelope()->subject);
    }

    public function test_a_referrer_message_notifies_the_support_address_and_never_the_author(): void
    {
        Mail::fake();
        $this->caseForMessages = $c = $this->makeCase();
        $msg = $this->message('Referrer', $c['referrer'], 'They still cannot log in.');

        $this->runMessageTask($c['case'], $msg);

        $sent = Mail::sent(SupportCaseMessageMail::class, fn (SupportCaseMessageMail $m) => $m->caseId === $c['case']);
        $this->assertCount(1, $sent);
        $this->assertTrue($sent->first()->hasTo(config('freegle.mail.support_addr')));
        $this->assertFalse($sent->first()->hasTo($c['referrer']->email_preferred));
        $this->assertStringContainsString('They still cannot log in.', $this->bodyOf($sent->first()));
    }

    public function test_a_referrer_who_is_also_on_the_support_address_is_not_mailed_their_own_message(): void
    {
        Mail::fake();
        $this->caseForMessages = $c = $this->makeCase();
        $own = $c['referrer']->email_preferred;
        config()->set('freegle.mail.support_addr', $own.',other-support-'.uniqid().'@test.invalid');
        $msg = $this->message('Referrer', $c['referrer']);

        $this->runMessageTask($c['case'], $msg);

        $sent = Mail::sent(SupportCaseMessageMail::class, fn (SupportCaseMessageMail $m) => $m->caseId === $c['case']);
        $this->assertCount(1, $sent);
        $this->assertFalse($sent->first()->hasTo($own));
    }

    public function test_a_support_message_written_by_the_referrer_themselves_mails_nobody(): void
    {
        Mail::fake();
        $this->caseForMessages = $c = $this->makeCase();
        $msg = $this->message('Support', $c['referrer']);

        $this->runMessageTask($c['case'], $msg);

        $this->assertCount(0, Mail::sent(SupportCaseMessageMail::class, fn (SupportCaseMessageMail $m) => $m->caseId === $c['case']));
        $this->assertNotNull(DB::table('background_tasks')->where('task_type', 'support_case_message')->orderByDesc('id')->first()->processed_at);
    }

    public function test_a_case_message_task_for_a_missing_message_is_dropped_and_a_malformed_one_fails(): void
    {
        Mail::fake();
        $this->caseForMessages = $c = $this->makeCase();

        $this->runMessageTask($c['case'], 999999999);
        $task = DB::table('background_tasks')->where('task_type', 'support_case_message')->orderByDesc('id')->first();
        $this->assertNotNull($task->processed_at);
        $this->assertCount(0, Mail::sent(SupportCaseMessageMail::class, fn ($m) => $m->caseId === $c['case']));

        DB::table('background_tasks')->insert(['task_type' => 'support_case_message', 'data' => json_encode(['caseid' => 0]), 'created_at' => now()]);
        $this->mock(\App\Services\PushNotificationService::class);
        $this->artisan('queue:background-tasks', ['--max-iterations' => 1, '--sleep' => 0])->assertSuccessful();
        $bad = DB::table('background_tasks')->where('task_type', 'support_case_message')->orderByDesc('id')->first();
        $this->assertNull($bad->processed_at);
        $this->assertStringContainsString('requires caseid and messageid', (string) $bad->error_message);
    }

    public function test_the_purge_deletes_old_handled_cases_and_clears_old_briefs(): void
    {
        $oldHandled = $this->makeCase(['status' => 'Handled', 'open_key' => null, 'firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'created_at' => DB::raw('NOW() - INTERVAL 3 YEAR'), 'handled_at' => DB::raw('NOW() - INTERVAL 30 month')]);
        DB::table('support_case_messages')->insert(['caseid' => $oldHandled['case'], 'userid' => $oldHandled['referrer']->id, 'role' => 'Referrer', 'body' => 'hi']);
        $recentHandled = $this->makeCase(['status' => 'Handled', 'open_key' => null, 'firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'created_at' => DB::raw('NOW() - INTERVAL 18 month'), 'handled_at' => DB::raw('NOW() - INTERVAL 20 day')]);
        $oldOpen = $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'created_at' => DB::raw('NOW() - INTERVAL 3 YEAR')]);
        $fresh = $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}']);

        $this->artisan('support:purge-cases')->assertSuccessful();

        $this->assertNull($this->case($oldHandled['case']), 'handled over two years ago is deleted');
        $this->assertSame(0, DB::table('support_case_messages')->where('caseid', $oldHandled['case'])->count(), 'its thread goes with it');
        $this->assertNotNull($this->case($recentHandled['case']), 'handled recently is kept');
        $this->assertNull($this->case($recentHandled['case'])->brief, 'but its brief is over a year old');
        $this->assertNotNull($this->case($oldOpen['case']), 'an open case is never deleted');
        $this->assertNull($this->case($oldOpen['case'])->brief);
        $this->assertNotNull($this->case($fresh['case'])->brief);
    }

    public function test_the_purge_clears_old_run_analysis_and_transcript_deletes_a_deleted_cases_runs_and_old_idle_open_cases(): void
    {
        $run = fn (string $query, string $age, string $kind = 'FirstPass') => DB::table('support_ai_runs')->insertGetId([
            'kind' => $kind, 'query' => $query, 'analysis' => 'facts', 'transcript' => 'talk', 'status' => 'Success',
            'created_at' => DB::raw("NOW() - INTERVAL {$age}"),
        ]);

        $oldRun = $run('First pass for support case 1', '13 month');
        $newRun = $run('First pass for support case 2', '2 month');
        $interactive = $run('typed by a volunteer', '13 month', 'Interactive');

        $handled = $this->makeCase(['status' => 'Handled', 'open_key' => null, 'firstpass_status' => 'Done', 'created_at' => DB::raw('NOW() - INTERVAL 3 YEAR'), 'handled_at' => DB::raw('NOW() - INTERVAL 30 month')]);
        $ownRun = $run("First pass for support case {$handled['case']}", '1 day');
        $failedAttempt = $run("First pass for support case {$handled['case']}", '1 day');
        $replay = $run("Replay of support case {$handled['case']}", '1 day');
        $other = $run('First pass for support case 999999', '1 day');

        $idle = $this->makeCase(['created_at' => DB::raw('NOW() - INTERVAL 3 YEAR'), 'updated_at' => DB::raw('NOW() - INTERVAL 30 month')]);
        $talked = $this->makeCase(['created_at' => DB::raw('NOW() - INTERVAL 3 YEAR'), 'updated_at' => DB::raw('NOW() - INTERVAL 30 month')]);
        DB::table('support_case_messages')->insert(['caseid' => $talked['case'], 'userid' => $talked['referrer']->id, 'role' => 'Referrer', 'body' => 'still here']);

        $briefed = $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'created_at' => DB::raw('NOW() - INTERVAL 14 month'), 'updated_at' => DB::raw('NOW() - INTERVAL 40 day')]);
        $before = DB::table('support_cases')->where('id', $briefed['case'])->value('updated_at');

        $this->artisan('support:purge-cases')->assertSuccessful();

        $get = fn (int $id) => DB::table('support_ai_runs')->find($id);
        $this->assertNull($get($oldRun)->analysis);
        $this->assertNull($get($oldRun)->transcript);
        $this->assertSame('facts', $get($newRun)->analysis, 'a recent run keeps its analysis');
        $this->assertSame('facts', $get($interactive)->analysis, 'only first pass runs are cleared here');

        $this->assertNull($this->case($handled['case']));
        foreach ([$ownRun, $failedAttempt, $replay] as $gone) {
            $this->assertNull($get($gone), 'the deleted case\'s runs go with it');
        }
        $this->assertNotNull($get($other));

        $this->assertNull($this->case($idle['case']), 'open for two years with no activity is deleted');
        $this->assertNotNull($this->case($talked['case']), 'a recent message is activity');

        $this->assertNull($this->case($briefed['case'])->brief);
        $this->assertSame($before, $this->case($briefed['case'])->updated_at, 'clearing a brief is not activity');
    }

    public function test_replay_refuses_while_a_case_is_pending_or_running_and_writes_nothing(): void
    {
        Mail::fake();
        $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}']);
        $pending = $this->makeCase();

        $this->artisan('support:first-pass-replay')->assertFailed();
        $this->assertCount(0, $this->helperRequests);

        DB::table('support_cases')->where('id', $pending['case'])->update(['firstpass_status' => 'Running']);
        $this->artisan('support:first-pass-replay')->assertFailed();
        $this->assertCount(0, $this->helperRequests);
    }

    public function test_replay_runs_one_at_a_time_reports_ids_only_and_writes_nothing(): void
    {
        Mail::fake();
        $a = $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'mailed_at' => now()]);
        $b = $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'mailed_at' => now()]);
        $runsBefore = DB::table('support_ai_runs')->count();
        $casesBefore = DB::table('support_cases')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();

        \Illuminate\Support\Facades\Artisan::call('support:first-pass-replay', ['--limit' => 2]);
        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $this->artisan('mail:spool:process')->assertSuccessful();

        $this->assertCount(2, $this->helperRequests);
        $this->assertSame([$b['case'], $a['case']], array_column($report['passes'], 'caseid'));
        $this->assertSame(1200, $report['passes'][0]['inputTokens']);
        $this->assertSame(300, $report['passes'][0]['outputTokens']);
        $this->assertSame(4500, $report['passes'][0]['durationMs']);
        $this->assertSame('Usage', $report['passes'][0]['classification']);
        $this->assertSame('High', $report['passes'][0]['confidence']);
        $this->assertSame(1, $report['passes'][0]['openQuestions']);
        $this->assertSame(1200.0, (float) $report['averages']['inputTokens']);
        $this->assertStringContainsString("today's data", $report['note']);
        $this->assertStringNotContainsString('The member could not find', json_encode($report));
        $runs = DB::table('support_ai_runs')->orderBy('id')->get()->slice($runsBefore)->values();
        $this->assertCount(2, $runs, 'each replayed pass leaves a run row so the tokens are visible');
        $this->assertSame("Replay of support case {$b['case']}", $runs[0]->query);
        $this->assertSame('FirstPass', $runs[0]->kind);
        $this->assertNull($runs[0]->modid, 'never counts against a referrer');
        $this->assertSame('Success', $runs[0]->status);
        $this->assertSame(1200, (int) $runs[0]->input_tokens);
        $this->assertSame(300, (int) $runs[0]->output_tokens);
        $this->assertSame('test-model', $runs[0]->model);
        $this->assertNull($runs[0]->analysis);
        $this->assertNull($runs[0]->transcript);
        $this->assertSame($casesBefore, DB::table('support_cases')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertCount(0, Mail::sent(ReferToSupportMail::class));
    }

    public function test_replay_stops_if_a_real_case_appears_and_reports_a_failed_pass_by_reason(): void
    {
        Mail::fake();
        $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'mailed_at' => now()]);
        $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'mailed_at' => now()]);
        $this->helper = ['status' => 502, 'body' => ['error' => 'x', 'code' => 'CREDIT'], 'throw' => null];
        $this->onHelperCall = fn () => $this->makeCase();

        \Illuminate\Support\Facades\Artisan::call('support:first-pass-replay', ['--limit' => 5]);
        $report = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);

        $this->assertCount(1, $this->helperRequests);
        $this->assertSame('A support case appeared, so the replay stopped.', $report['stopped']);
        $this->assertSame(FirstPassService::REASON_CREDIT, $report['passes'][0]['error']);
        $run = DB::table('support_ai_runs')->where('query', 'like', 'Replay of support case%')->first();
        $this->assertSame('Error', $run->status);
        $this->assertSame(FirstPassService::REASON_CREDIT, $run->error);
        $this->assertNull($run->modid);
    }

    public function test_replay_limit_is_capped_at_twenty(): void
    {
        Mail::fake();
        for ($i = 0; $i < 22; $i++) {
            $this->makeCase(['firstpass_status' => 'Done', 'brief' => '{"answer":"x"}', 'mailed_at' => now()]);
        }

        $this->artisan('support:first-pass-replay', ['--limit' => 50])->assertSuccessful();

        $this->assertCount(20, $this->helperRequests);
    }

    public function test_the_config_block_reads_through_envsetting_with_blank_as_the_default(): void
    {
        $read = function (array $env) {
            $saved = [];
            foreach ($env as $name => $value) {
                $saved[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null, getenv($name)];
                if ($value === null) {
                    putenv($name);
                    unset($_ENV[$name], $_SERVER[$name]);
                } else {
                    putenv("{$name}={$value}");
                    $_ENV[$name] = $_SERVER[$name] = $value;
                }
            }
            try {
                return (require base_path('config/freegle.php'))['support']['first_pass'];
            } finally {
                foreach ($saved as $name => [$e, $s, $g]) {
                    $g === false ? putenv($name) : putenv("{$name}={$g}");
                    $e === null ? null : $_ENV[$name] = $e;
                    $s === null ? null : $_SERVER[$name] = $s;
                    if ($e === null) {
                        unset($_ENV[$name]);
                    }
                    if ($s === null) {
                        unset($_SERVER[$name]);
                    }
                }
            }
        };
        $names = ['SUPPORT_FIRST_PASS_ENABLED', 'SUPPORT_FIRST_PASS_TIMEOUT', 'SUPPORT_FIRST_PASS_PER_REFERRER_PER_DAY', 'SUPPORT_FIRST_PASS_MAX_AGE_MINUTES', 'SUPPORT_FIRST_PASS_RUN_MINUTES'];

        $blank = $read(array_fill_keys($names, ''));
        $this->assertFalse($blank['enabled']);
        $this->assertSame(510, $blank['timeout']);
        $this->assertSame(10, $blank['per_referrer_per_day']);
        $this->assertSame(60, $blank['max_age_minutes']);
        $this->assertSame(10, $blank['run_minutes']);

        $set = $read(['SUPPORT_FIRST_PASS_ENABLED' => '1', 'SUPPORT_FIRST_PASS_TIMEOUT' => '400', 'SUPPORT_FIRST_PASS_PER_REFERRER_PER_DAY' => '3', 'SUPPORT_FIRST_PASS_MAX_AGE_MINUTES' => '30', 'SUPPORT_FIRST_PASS_RUN_MINUTES' => '5']);
        $this->assertTrue($set['enabled']);
        $this->assertSame([400, 3, 30, 5], [$set['timeout'], $set['per_referrer_per_day'], $set['max_age_minutes'], $set['run_minutes']]);
    }
}

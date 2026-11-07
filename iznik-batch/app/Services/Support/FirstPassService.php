<?php

namespace App\Services\Support;

use App\Mail\Chat\ReferToSupportMail;
use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The batch side of the support case first pass: builds the helper request from a case row, calls
 * the helper once, and builds the referral mail.
 *
 * The helper investigates the case itself; this class supplies the chat, recent cases,
 * its latest images and the mailbox history. It never sends anything to a member.
 */
class FirstPassService
{
    // The only reasons ever stored on a case or shown in a mail.
    public const REASON_OFF = 'The first pass is turned off';

    public const REASON_BUSY = 'The AI helper was busy';

    public const REASON_CREDIT = 'The AI helper is out of credit';

    public const REASON_TOO_LONG = 'The first pass took too long';

    public const REASON_LOCKDOWN = "The member's data could not be read during a lockdown";

    public const REASON_STEPS = 'The first pass ran out of steps before it could answer';

    public const REASON_NOT_SET_UP = 'The AI helper is not set up on this server';

    public const REASON_LIMIT = 'The first pass limit for today was reached';

    public const REASON_INTERRUPTED = 'The first pass was interrupted';

    public const REASON_FAILED = 'The AI helper failed';

    public const REASON_NO_CHAT = 'The chat is no longer there';

    /** Helper error codes (502 body) to the plain reason. */
    private const CODE_REASONS = [
        'DEADLINE' => self::REASON_TOO_LONG,
        'TOO_MANY_STEPS' => self::REASON_STEPS,
        'CREDIT' => self::REASON_CREDIT,
        'DUMP_LOCKDOWN' => self::REASON_LOCKDOWN,
        'DUMP_AUTH' => self::REASON_NOT_SET_UP,
    ];

    public const MESSAGE_LIMIT = 50;

    public const MESSAGE_TEXT_CHARS = 2000;

    public const IMAGE_LIMIT = 5;

    public const IMAGE_FETCH_TIMEOUT = 8;

    /** One image is skipped above this many bytes, and the images together stay under the total. */
    public const IMAGE_MAX_BYTES = 3_000_000;

    public const IMAGE_TOTAL_BYTES = 12_000_000;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly MailboxHistory $mailbox) {}

    /**
     * The request for POST /internal/support-first-pass.
     *
     * @return array<string, mixed>
     */
    public function buildRequest(object $case): array
    {
        $chat = $case->chatid ? DB::table('chat_rooms')->where('id', $case->chatid)->first() : null;
        $group = ($chat && $chat->groupid) ? DB::table('groups')->where('id', $chat->groupid)->first(['id', 'nameshort', 'namefull']) : null;

        [$images, $skipped] = $chat ? $this->chatImages((int) $chat->id) : [[], 0];

        return [
            'caseid' => (int) $case->id,
            'memberid' => $case->userid ? (int) $case->userid : null,
            'source' => [
                'kind' => strtolower((string) ($case->source ?? 'Referral')),
                'chatid' => $case->chatid ? (int) $case->chatid : null,
                'referrerid' => $case->referrerid ? (int) $case->referrerid : null,
                'reason' => $case->reason !== null && $case->reason !== '' ? (string) $case->reason : null,
                'referredAt' => $this->referredAt($case),
            ],
            'chat' => [
                'id' => $chat ? (int) $chat->id : null,
                'type' => $chat ? $chat->chattype : null,
                'group' => $group ? ['id' => (int) $group->id, 'name' => (string) ($group->namefull ?: $group->nameshort)] : null,
                'messages' => $chat ? $this->chatMessages($chat, $case) : [],
            ],
            'images' => $images,
            'imagesSkipped' => $skipped,
            'mailHistory' => $this->mailbox->forMember($case->userid ? (int) $case->userid : null),
            'recentCases' => $this->recentCases($case),
        ];
    }

    public const RECENT_CASE_LIMIT = 20;

    public const RECENT_CASE_DAYS = 14;

    public const SUPPORT_REPLY_CHARS = 600;

    /** When the case was referred, UTC, as the helper searches logs around it. */
    private function referredAt(object $case): ?string
    {
        if (empty($case->created_at)) {
            return null;
        }

        return \Carbon\Carbon::parse($case->created_at, config('app.timezone'))->utc()->format('Y-m-d H:i:s');
    }

    /**
     * Other support cases from the last two weeks that have a brief or a Support reply, newest
     * first, so one incident behind many referrals is answered once.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentCases(object $case): array
    {
        $rows = DB::table('support_cases as c')
            ->where('c.id', '!=', $case->id)
            ->where('c.created_at', '>=', now()->subDays(self::RECENT_CASE_DAYS))
            ->where(function ($q) {
                $q->whereNotNull('c.brief')
                    ->orWhereExists(function ($s) {
                        $s->select(DB::raw(1))->from('support_case_messages as m')
                            ->whereColumn('m.caseid', 'c.id')->where('m.role', 'Support');
                    });
            })
            ->orderByDesc('c.id')
            ->limit(self::RECENT_CASE_LIMIT)
            ->get(['c.id', 'c.created_at', 'c.brief']);

        $replies = $rows->isEmpty() ? collect() : DB::table('support_case_messages')
            ->whereIn('caseid', $rows->pluck('id'))->where('role', 'Support')
            ->orderBy('id')->get(['caseid', 'body'])->keyBy('caseid');

        return $rows->map(function ($r) use ($replies) {
            $brief = $r->brief ? json_decode((string) $r->brief, true) : null;
            $brief = is_array($brief) ? $brief : [];
            $reply = $replies->get($r->id);

            return [
                'id' => (int) $r->id,
                'created' => \Carbon\Carbon::parse($r->created_at, config('app.timezone'))->utc()->format('Y-m-d H:i:s'),
                'classification' => is_string($brief['classification'] ?? null) ? $brief['classification'] : '',
                'question' => is_string($brief['question'] ?? null) ? $brief['question'] : '',
                'supportReply' => $reply ? mb_substr((string) $reply->body, 0, self::SUPPORT_REPLY_CHARS) : '',
            ];
        })->all();
    }

    /**
     * Messages that nobody has been held back from: not awaiting review, rejected, deleted or held.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function visibleMessages(int $chatId)
    {
        return DB::table('chat_messages as cm')
            ->leftJoin('chat_messages_held as h', 'h.msgid', '=', 'cm.id')
            ->where('cm.chatid', $chatId)
            ->where('cm.reviewrequired', 0)
            ->where('cm.reviewrejected', 0)
            ->where('cm.deleted', 0)
            ->whereNull('h.id');
    }

    /** Whether the chat has a visible message newer than the given time. */
    public function hasVisibleMessageAfter(int $chatId, string $since): bool
    {
        return $this->visibleMessages($chatId)->where('cm.date', '>', $since)->exists();
    }

    /** @return array<int, array<string, mixed>> */
    private function chatMessages(object $chat, object $case): array
    {
        $rows = $this->visibleMessages((int) $chat->id)
            ->orderByDesc('cm.date')->orderByDesc('cm.id')
            ->limit(self::MESSAGE_LIMIT)
            ->get(['cm.id', 'cm.userid', 'cm.type', 'cm.date', 'cm.message'])
            ->reverse()->values();

        $authors = $rows->pluck('userid')->filter()->unique()->values()->all();
        $mods = [];
        if ($authors) {
            $mods = DB::table('users')->whereIn('id', $authors)->whereIn('systemrole', ['Moderator', 'Support', 'Admin'])->pluck('id')->all();
            if ($chat->groupid) {
                $mods = array_merge($mods, DB::table('memberships')->where('groupid', $chat->groupid)->whereIn('userid', $authors)->whereIn('role', ['Moderator', 'Owner'])->pluck('userid')->all());
            }
        }
        $mods = array_flip($mods);

        return $rows->map(function ($r) use ($case, $mods) {
            $uid = $r->userid ? (int) $r->userid : 0;
            if ($uid === 0 || $r->type === 'System') {
                $from = 'system';
            } elseif ($case->userid && $uid === (int) $case->userid) {
                $from = 'member';
            } elseif (($case->referrerid && $uid === (int) $case->referrerid) || isset($mods[$uid])) {
                $from = 'moderator';
            } else {
                $from = 'other';
            }

            return [
                'id' => (int) $r->id,
                'date' => $r->date ? \Carbon\Carbon::parse($r->date)->toIso8601String() : null,
                'from' => $from,
                'type' => (string) $r->type,
                'text' => mb_substr((string) $r->message, 0, self::MESSAGE_TEXT_CHARS),
            ];
        })->all();
    }

    /**
     * The URL the Go chat API gives a chat image (misc.BuildChatImageUrl), as the batch can fetch it.
     */
    public function chatImageUrl(object $img): string
    {
        $uid = (string) ($img->externaluid ?? '');
        if ($uid !== '') {
            $delivery = rtrim(preg_replace('/\?url=$/', '', (string) config('freegle.delivery.base_url', 'https://delivery.ilovefreegle.org')), '/');
            $uploads = (string) config('freegle.support.first_pass.uploads_url', 'https://uploads.ilovefreegle.org:8080/');
            $url = $delivery.'?url='.$uploads.(strlen($uid) > 12 ? substr($uid, 12) : $uid);
            $mods = json_decode((string) ($img->externalmods ?? ''), true);
            if (is_array($mods)) {
                $url .= '&ro='.(int) ($mods['rotate'] ?? 0);
            }

            return $url;
        }
        $domain = (int) ($img->archived ?? 0) > 0
            ? rtrim((string) config('freegle.images.archived_domain'), '/')
            : rtrim((string) config('freegle.images.domain'), '/');

        return $domain.'/mimg_'.$img->id.'.jpg';
    }

    /**
     * The latest images in the chat, fetched and base64 encoded. A failed or oversized image is
     * skipped and counted; nothing is logged about the image or the member.
     *
     * @return array{0: array<int, array<string, string>>, 1: int}
     */
    private function chatImages(int $chatId): array
    {
        $rows = $this->visibleMessages($chatId)
            ->join('chat_images as ci', 'ci.chatmsgid', '=', 'cm.id')
            ->orderByDesc('cm.date')->orderByDesc('cm.id')
            ->limit(self::IMAGE_LIMIT)
            ->get(['ci.id', 'ci.archived', 'ci.externaluid', 'ci.externalmods', 'ci.contenttype'])
            ->reverse()->values();

        $images = [];
        $skipped = 0;
        $total = 0;
        foreach ($rows as $i => $img) {
            try {
                $response = Http::timeout(self::IMAGE_FETCH_TIMEOUT)->get($this->chatImageUrl($img));
                $bytes = $response->successful() ? (string) $response->body() : '';
                // The type comes from the bytes: a mislabelled image would make the helper refuse the request.
                $info = $bytes === '' ? false : @getimagesizefromstring($bytes);
                $mime = $info ? strtolower((string) ($info['mime'] ?? '')) : '';
                if ($bytes === '' || strlen($bytes) > self::IMAGE_MAX_BYTES || $total + strlen($bytes) > self::IMAGE_TOTAL_BYTES || ! in_array($mime, self::IMAGE_MIMES, true)) {
                    $skipped++;

                    continue;
                }
                $total += strlen($bytes);
                $images[] = ['name' => 'chat-image-'.($i + 1), 'mime' => $mime, 'data' => base64_encode($bytes)];
            } catch (\Throwable) {
                $skipped++;
            }
        }

        return [$images, $skipped];
    }

    /**
     * One call to the helper. Returns the decoded 200 body.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     *
     * @throws FirstPassFailure
     */
    public function ask(array $request, ?int $timeout = null): array
    {
        $ux = (array) config('freegle.ux.prompts', []);
        $secret = (string) ($ux['internal_secret'] ?? '');
        if ($secret === '') {
            throw new FirstPassFailure('NOT_SET_UP', self::REASON_NOT_SET_UP, false, true);
        }
        $timeout ??= (int) config('freegle.support.first_pass.timeout', 510);
        $url = rtrim((string) ($ux['helper_url'] ?? 'http://ai-support-helper:3000'), '/').'/internal/support-first-pass';

        try {
            $response = Http::withHeaders(['X-Freegle-Internal' => '1', 'X-Freegle-Internal-Secret' => $secret])
                ->timeout($timeout)->acceptJson()->asJson()->post($url, $request);
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'timed out') || str_contains($e->getMessage(), 'cURL error 28');
            throw new FirstPassFailure('CONNECTION', $timedOut ? self::REASON_TOO_LONG : self::REASON_FAILED);
        }

        $status = $response->status();
        if ($status === 429) {
            throw new FirstPassFailure('BUSY', self::REASON_BUSY);
        }
        if ($status === 403) {
            throw new FirstPassFailure('REFUSED', self::REASON_NOT_SET_UP, false, true);
        }
        if (! $response->successful()) {
            $code = $status === 502 ? (string) ($response->json('code') ?? '') : '';
            throw new FirstPassFailure(
                $code !== '' ? $code : 'HTTP_'.$status,
                self::CODE_REASONS[$code] ?? self::REASON_FAILED,
                $status === 502,
                // The helper answered, so trying again would only spend the model and the dump again.
                $status === 502,
            );
        }

        $json = $response->json();
        if (! is_array($json) || ! is_array($json['brief'] ?? null) || ! is_string($json['brief']['answer'] ?? null)) {
            throw new FirstPassFailure('BAD_OUTPUT', self::REASON_FAILED, true, true);
        }

        return $json;
    }

    /**
     * The referral mail for a case, with the brief when there is one, or the reason there is not.
     *
     * @param  array<string, mixed>|null  $brief
     */
    public function referralMail(object $case, ?array $brief, ?string $skipReason): ReferToSupportMail
    {
        $chat = $case->chatid ? DB::table('chat_rooms')->where('id', $case->chatid)->first() : null;

        $referrer = $case->referrerid ? User::find($case->referrerid) : null;
        $otherId = $case->userid ?: ($chat ? ($chat->user1 == $case->referrerid ? $chat->user2 : $chat->user1) : null);
        $other = $otherId ? User::find($otherId) : null;

        $replyToAddress = config('freegle.mail.noreply_addr');
        $replyToName = config('freegle.branding.name');
        if ($chat && $chat->groupid) {
            $group = DB::table('groups')->where('id', $chat->groupid)->first();
            if ($group) {
                $short = $group->nameshort ?? '';
                $replyToAddress = $short.'-volunteers@'.config('freegle.mail.group_domain', 'groups.ilovefreegle.org');
                $replyToName = ($group->namefull ?: $short).' Volunteers';
            }
        }

        return new ReferToSupportMail(
            userName: $referrer ? ($referrer->fullname ?: 'Unknown') : 'Unknown',
            userId: (int) ($case->referrerid ?? 0),
            chatId: $chat ? (int) $chat->id : null,
            otherUserName: $other ? ($other->fullname ?: 'Unknown') : 'Unknown',
            otherUserId: (int) ($otherId ?? 0),
            replyToAddress: $replyToAddress,
            replyToName: $replyToName,
            brief: $brief,
            caseId: (int) $case->id,
            skipReason: $skipReason,
            reason: $case->reason !== null && $case->reason !== '' ? (string) $case->reason : null,
        );
    }

    /**
     * Write a support_ai_runs row for a pass. Only fixed reasons are stored as the error; the
     * helper's own text is never kept.
     *
     * @param  array<string, mixed>|null  $response
     * @param  array<string, mixed>|null  $brief
     */
    public function storeRun(?int $modid, ?int $userid, string $query, ?array $response, ?string $error, ?array $brief = null): int
    {
        $usage = is_array($response['usage'] ?? null) ? $response['usage'] : [];
        $quota = is_array($response['quota'] ?? null) ? $response['quota'] : [];
        $before = is_array($response['quotaBefore'] ?? null) ? $response['quotaBefore'] : [];
        $pct = fn ($v) => is_numeric($v) ? max(0, min(100, round((float) $v, 2))) : null;

        return (int) DB::table('support_ai_runs')->insertGetId([
            'modid' => $modid,
            'kind' => 'FirstPass',
            'userid' => $userid,
            'query' => $query,
            'analysis' => $brief !== null ? BriefFormatter::markdown($brief) : null,
            'transcript' => isset($response['transcript']) && is_string($response['transcript']) ? mb_substr($response['transcript'], 0, 1_000_000) : null,
            'status' => $error === null ? 'Success' : 'Error',
            'error' => $error,
            'driver' => isset($response['driver']) ? mb_substr((string) $response['driver'], 0, 16) : null,
            'model' => isset($response['model']) ? mb_substr((string) $response['model'], 0, 64) : null,
            'input_tokens' => max(0, (int) ($usage['inputTokens'] ?? 0)),
            'output_tokens' => max(0, (int) ($usage['outputTokens'] ?? 0)),
            'cache_creation_tokens' => max(0, (int) ($usage['cacheCreation'] ?? 0)),
            'cache_read_tokens' => max(0, (int) ($usage['cacheRead'] ?? 0)),
            'duration_ms' => max(0, (int) ($usage['durationMs'] ?? 0)),
            'cost_usd' => is_numeric($response['costUsd'] ?? null) ? max(0, min(999999, (float) $response['costUsd'])) : 0,
            'quota_5h_before' => $pct($before['fiveHour'] ?? null),
            'quota_5h_after' => $pct($quota['fiveHour'] ?? null),
            'quota_7d_before' => $pct($before['sevenDay'] ?? null),
            'quota_7d_after' => $pct($quota['sevenDay'] ?? null),
        ]);
    }
}

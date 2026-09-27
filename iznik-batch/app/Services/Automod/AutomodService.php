<?php

namespace App\Services\Automod;

use App\Models\MessageAutomod;
use App\Models\MessageGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends a Pending post's facts and text to the automod Node service (automod/, POST /review),
 * walks it through the chart (automod/chart.json) and stores the verdict on messages_automod
 * (plans/active/automod-flowchart.md). One row per (msgid, groupid); a rerun (edit, or the
 * messages:automod command picking it up again) replaces the row rather than adding another.
 *
 * The service call can fail (network, timeout, backend down) without that being a moderation
 * decision: on failure this records end_node=UNAVAILABLE and leaves the post to ordinary
 * moderation rather than guessing hold or approve.
 */
class AutomodService
{
    public function __construct(
        private readonly ?AutomodFactsService $factsService = null
    ) {
    }

    /**
     * @param bool $persist whether to write the messages_automod row. False for
     *                       automod:evaluate's live-compare mode, where the sampled posts are
     *                       already decided (Approved/Rejected, not Pending) and writing a
     *                       verdict for them would be noise rather than a moderation record.
     * @return array<string, mixed>|null the decoded /review response on success, or null if
     *                                    the service could not be reached (a row is still
     *                                    written either way, when $persist is true).
     */
    public function review(int $msgid, int $groupid, string $mode, ?string $backend = null, bool $persist = true): ?array
    {
        $message = DB::table('messages')
            ->where('id', $msgid)
            ->first(['subject', 'textbody', 'type']);

        if (!$message) {
            Log::warning('AutomodService: message not found', ['msgid' => $msgid, 'groupid' => $groupid]);

            return null;
        }

        $facts = $this->factsService()->facts($msgid, $groupid);
        $rules = $this->factsService()->rules($groupid);

        $payload = [
            'msgid' => $msgid,
            'groupid' => $groupid,
            'subject' => $message->subject ?? '',
            'body' => $message->textbody ?? '',
            'type' => $message->type ?? '',
            // Objects, not arrays: an empty PHP array encodes as [] and the service wants {}.
            'facts' => (object) $facts,
            'rules' => (object) $rules,
        ];

        if ($backend) {
            $payload['backend'] = $backend;
        }

        $url = rtrim((string) config('freegle.automod.url'), '/') . '/review';
        $timeout = (int) config('freegle.automod.timeout', 20);

        try {
            $response = Http::timeout($timeout)->post($url, $payload);

            if (!$response->successful()) {
                Log::warning('AutomodService: /review returned an error', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                if ($persist) {
                    $this->recordUnavailable($msgid, $groupid, $mode);
                }

                return null;
            }

            $decoded = $response->json();
            if (!is_array($decoded)) {
                Log::warning('AutomodService: /review returned an unparseable body', [
                    'msgid' => $msgid,
                    'groupid' => $groupid,
                ]);

                if ($persist) {
                    $this->recordUnavailable($msgid, $groupid, $mode);
                }

                return null;
            }

            if ($persist) {
                $this->record($msgid, $groupid, $mode, $decoded);
            }

            return $decoded;
        } catch (\Exception $e) {
            Log::warning('AutomodService: /review call failed', [
                'msgid' => $msgid,
                'groupid' => $groupid,
                'error' => $e->getMessage(),
            ]);

            if ($persist) {
                $this->recordUnavailable($msgid, $groupid, $mode);
            }

            return null;
        }
    }

    /**
     * Picks up Pending posts in shadow/approve groups once their content check has run, and
     * runs review() for any that have no messages_automod row yet or were edited since their
     * last one (mirrors AutoApproveCleanService::process()'s candidate query/loop shape).
     *
     * @return array{reviewed: int, unavailable: int, errors: int}
     */
    public function process(int $limit = 200): array
    {
        $stats = ['reviewed' => 0, 'unavailable' => 0, 'errors' => 0];

        $eligibleGroupIds = AutomodMode::eligibleGroupIds();
        if ($eligibleGroupIds === []) {
            // Automod is dark everywhere - nothing to do.
            return $stats;
        }

        $candidates = DB::table('messages_groups as mg')
            ->join('messages as m', 'm.id', '=', 'mg.msgid')
            ->leftJoin('messages_automod as ma', function ($join) {
                $join->on('ma.msgid', '=', 'mg.msgid')
                    ->on('ma.groupid', '=', 'mg.groupid');
            })
            ->where('mg.collection', MessageGroup::COLLECTION_PENDING)
            ->where('mg.deleted', 0)
            ->where('mg.rippled_in', 0)
            ->whereNotNull('mg.contentcheck_checked_at')
            ->where(function ($query) {
                // No automod row yet, or the post was edited after the row was last written.
                $query->whereNull('ma.id')->orWhereColumn('m.editedat', '>', 'ma.created');
            })
            ->when(
                $eligibleGroupIds !== null,
                fn ($query) => $query->whereIn('mg.groupid', $eligibleGroupIds)
            )
            ->orderBy('mg.msgid')
            ->orderBy('mg.groupid')
            ->limit($limit)
            ->get(['mg.msgid', 'mg.groupid']);

        foreach ($candidates as $row) {
            $msgid = (int) $row->msgid;
            $groupid = (int) $row->groupid;

            $mode = AutomodMode::for($groupid);
            if ($mode === null) {
                // Config changed between the query and this row being processed - skip it,
                // the next run will pick it up correctly (or not at all).
                continue;
            }

            try {
                $result = $this->review($msgid, $groupid, $mode);
                if ($result === null) {
                    $stats['unavailable']++;
                }
                $stats['reviewed']++;
            } catch (\Exception $e) {
                Log::error("AutomodService: error reviewing message #{$msgid} group #{$groupid}: " . $e->getMessage());
                $stats['errors']++;
            }
        }

        return $stats;
    }

    private function factsService(): AutomodFactsService
    {
        return $this->factsService ?? new AutomodFactsService();
    }

    /**
     * @param array<string, mixed> $decoded the /review response body.
     */
    private function record(int $msgid, int $groupid, string $mode, array $decoded): void
    {
        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => (string) ($decoded['version'] ?? ''),
                'verdict' => $decoded['verdict'] ?? MessageAutomod::VERDICT_HOLD,
                'end_node' => $decoded['end'] ?? 'UNKNOWN',
                'reason' => $decoded['reason'] ?? null,
                'path' => $decoded['path'] ?? [],
                'created' => now(),
            ]
        );
    }

    private function recordUnavailable(int $msgid, int $groupid, string $mode): void
    {
        MessageAutomod::updateOrCreate(
            ['msgid' => $msgid, 'groupid' => $groupid],
            [
                'mode' => $mode,
                'chart_version' => '',
                'verdict' => MessageAutomod::VERDICT_HOLD,
                'end_node' => 'UNAVAILABLE',
                'reason' => 'Automated review unavailable',
                'path' => [],
                'created' => now(),
            ]
        );
    }
}

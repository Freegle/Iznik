<?php

namespace App\Services;

use App\Models\ChatMessage;
use Illuminate\Support\Facades\DB;

/**
 * Finds the same chat text arriving from many different senders in a short window, which
 * is what a scam wave looks like, and writes a plain-text report of it. Read-only.
 *
 * A cluster is messages that share a signature: the first 60 characters of the text once
 * spacing, case and styled letters are folded away. Messages are also counted by any
 * domain they name, because a wave that varies its wording usually still names one site.
 */
class ChatScamWaveService
{
    /** A cluster needs this many messages, from this many different senders. */
    public const MIN_MESSAGES = 8;

    public const MIN_SENDERS = 5;

    /** A domain is listed once this many messages name it. */
    public const MIN_DOMAIN_MESSAGES = 5;

    /**
     * Folded text shorter than this is an everyday reply ("ok", "yes please", "thank you")
     * that many unrelated members send at once. It only forms a cluster when new accounts
     * sent at least half of it.
     */
    public const MIN_SIGNATURE_LENGTH = 30;

    public const SIGNATURE_LENGTH = 60;

    public const NEW_ACCOUNT_HOURS = 48;

    public const MAX_CLUSTERS = 8;

    public const SAMPLES_PER_CLUSTER = 3;

    /** A sample in the report is cut to this many characters. */
    public const SAMPLE_REPORT_LENGTH = 400;

    /** Sites that members name all the time for ordinary reasons. */
    private const IGNORED_DOMAINS = '/^(www\.)?(ilovefreegle\.org|trashnothing\.com|freegle\.org|freegle\.in|gmail\.com|yahoo\.co\.uk|hotmail\.com|outlook\.com)$/';

    /** First code point of each styled Latin alphabet in Mathematical Alphanumeric Symbols. */
    private const STYLED_ALPHABETS = [0x1D400, 0x1D434, 0x1D468, 0x1D49C, 0x1D4D0, 0x1D504, 0x1D538, 0x1D56C, 0x1D5A0, 0x1D5D4, 0x1D608, 0x1D63C, 0x1D670];

    /**
     * Delivered member chat messages of the last $windowMinutes, clustered.
     *
     * @return array{window:int, until:string, messages:int, senders:int, newSenders:int, clusters:array<int,array>, domains:array<string,array>}
     */
    public function detect(int $windowMinutes): array
    {
        $now = now();
        $rows = DB::table('chat_messages as cm')
            ->join('users as u', 'u.id', '=', 'cm.userid')
            ->where('cm.date', '>=', $now->copy()->subMinutes($windowMinutes))
            ->whereIn('cm.type', [ChatMessage::TYPE_INTERESTED, ChatMessage::TYPE_DEFAULT, ChatMessage::TYPE_REPORTEDUSER])
            ->where('cm.reviewrejected', 0)
            ->whereNotNull('cm.message')
            ->orderBy('cm.id')
            ->get(['cm.id', 'cm.chatid', 'cm.userid', 'cm.message', 'u.added']);

        return ['window' => $windowMinutes, 'until' => $now->format('Y-m-d H:i')]
            + $this->cluster($rows, $now->getTimestamp());
    }

    /**
     * @param  iterable<object>  $rows  each with id, chatid, userid, message and added
     * @return array{messages:int, senders:int, newSenders:int, clusters:array<int,array>, domains:array<string,array>}
     */
    public function cluster(iterable $rows, int $now): array
    {
        $messages = 0;
        $senders = [];
        $newSenders = [];
        $bySignature = [];
        $byDomain = [];

        foreach ($rows as $row) {
            $messages++;
            $senders[$row->userid] = true;
            $isNew = $row->added !== null && strtotime($row->added) > $now - self::NEW_ACCOUNT_HOURS * 3600;
            if ($isNew) {
                $newSenders[$row->userid] = true;
            }

            $folded = $this->fold($row->message);
            $signature = mb_substr($folded, 0, self::SIGNATURE_LENGTH);
            $bySignature[$signature] ??= ['n' => 0, 'senders' => [], 'new' => 0, 'rooms' => [], 'samples' => []];
            $entry = &$bySignature[$signature];
            $entry['n']++;
            $entry['senders'][$row->userid] = true;
            $entry['rooms'][$row->chatid] = true;
            if ($isNew) {
                $entry['new']++;
            }
            if (count($entry['samples']) < self::SAMPLES_PER_CLUSTER) {
                $entry['samples'][] = ['id' => (int) $row->id, 'text' => (string) $row->message];
            }
            unset($entry);

            foreach ($this->domainsIn($folded) as $domain) {
                $byDomain[$domain] ??= ['n' => 0, 'senders' => [], 'new' => 0];
                $byDomain[$domain]['n']++;
                $byDomain[$domain]['senders'][$row->userid] = true;
                if ($isNew) {
                    $byDomain[$domain]['new']++;
                }
            }
        }

        $clusters = [];
        foreach ($bySignature as $signature => $entry) {
            if ($entry['n'] < self::MIN_MESSAGES || count($entry['senders']) < self::MIN_SENDERS) {
                continue;
            }
            if (mb_strlen((string) $signature) < self::MIN_SIGNATURE_LENGTH && $entry['new'] * 2 < $entry['n']) {
                continue;
            }
            $clusters[] = [
                'n' => $entry['n'],
                'senders' => count($entry['senders']),
                'new' => $entry['new'],
                'rooms' => count($entry['rooms']),
                'samples' => $entry['samples'],
            ];
        }
        usort($clusters, fn ($a, $b) => $b['n'] <=> $a['n']);

        $domains = [];
        foreach ($byDomain as $domain => $entry) {
            if ($entry['n'] >= self::MIN_DOMAIN_MESSAGES) {
                $domains[$domain] = ['n' => $entry['n'], 'senders' => count($entry['senders']), 'new' => $entry['new']];
            }
        }
        uasort($domains, fn ($a, $b) => $b['n'] <=> $a['n']);

        return [
            'messages' => $messages,
            'senders' => count($senders),
            'newSenders' => count($newSenders),
            'clusters' => array_slice($clusters, 0, self::MAX_CLUSTERS),
            'domains' => $domains,
        ];
    }

    /**
     * The report as plain text, for the console and for the alert mail. Every cluster
     * shows what was actually said, so a reader can tell a scam from a coincidence
     * without looking anything up.
     */
    public function report(array $result): string
    {
        $newLabel = 'with accounts under '.self::NEW_ACCOUNT_HOURS.' hours old';
        $lines = [sprintf(
            'In the %d minutes to %s: %d chat messages from %d senders (%d %s).',
            $result['window'], $result['until'], $result['messages'], $result['senders'], $result['newSenders'], $newLabel
        )];

        foreach ($result['clusters'] as $i => $cluster) {
            $lines[] = '';
            $lines[] = sprintf(
                'Cluster %d: %d messages from %d senders (%d %s) in %d chats',
                $i + 1, $cluster['n'], $cluster['senders'], $cluster['new'], $newLabel, $cluster['rooms']
            );
            $lines[] = 'What they said:';
            foreach ($cluster['samples'] as $sample) {
                $lines[] = sprintf('  [%d] "%s"', $sample['id'], $this->forReport($sample['text']));
            }
        }

        if (! empty($result['domains'])) {
            $lines[] = '';
            $lines[] = 'Sites named by several messages:';
            foreach ($result['domains'] as $domain => $entry) {
                $lines[] = sprintf('  %s: %d messages from %d senders (%d %s)', $domain, $entry['n'], $entry['senders'], $entry['new'], $newLabel);
            }
        }

        if (empty($result['clusters'])) {
            $lines[] = '';
            $lines[] = 'No clustered messages.';
        }

        return implode("\n", $lines)."\n";
    }

    /** Spacing collapsed, styled letters and digits turned into plain ones, lower case. */
    private function fold(?string $message): string
    {
        $text = preg_replace('/\s+/u', ' ', trim((string) $message)) ?? '';
        $text = preg_replace_callback('/[\x{1D400}-\x{1D7FF}]/u', function ($match) {
            $codePoint = mb_ord($match[0]);
            foreach (self::STYLED_ALPHABETS as $base) {
                if ($codePoint >= $base && $codePoint < $base + 52) {
                    $offset = $codePoint - $base;

                    return $offset < 26 ? chr(65 + $offset) : chr(97 + $offset - 26);
                }
            }
            if ($codePoint >= 0x1D7CE) {
                return chr(48 + (($codePoint - 0x1D7CE) % 10));
            }

            return $match[0];
        }, $text) ?? $text;

        return mb_strtolower($text);
    }

    /** @return string[] the distinct domains named in folded text, less the everyday ones */
    private function domainsIn(string $folded): array
    {
        if (! preg_match_all('/\b([a-z0-9-]+(?:\.[a-z0-9-]+)*\.[a-z]{2,10})\b/', $folded, $matches)) {
            return [];
        }

        return array_values(array_filter(
            array_unique($matches[1]),
            fn ($domain) => ! preg_match(self::IGNORED_DOMAINS, $domain)
        ));
    }

    /** One line, cut to a readable length, saying how long the whole message was. */
    private function forReport(string $text): string
    {
        $oneLine = preg_replace('/\s+/u', ' ', trim($text)) ?? '';
        $length = mb_strlen($oneLine);
        if ($length <= self::SAMPLE_REPORT_LENGTH) {
            return $oneLine;
        }

        return mb_substr($oneLine, 0, self::SAMPLE_REPORT_LENGTH).'... ('.$length.' characters in all)';
    }
}

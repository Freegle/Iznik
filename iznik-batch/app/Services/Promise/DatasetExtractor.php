<?php

namespace App\Services\Promise;

class DatasetExtractor
{
    private PiiNormaliser $normaliser;

    public function __construct()
    {
        $this->normaliser = new PiiNormaliser();
    }

    /**
     * Extract windowed training rows from a room's messages.
     *
     * @param int $roomId The chat_rooms.id
     * @param string $postType 'Offer' or 'Wanted'
     * @param array $messages Array of ['type'=>..., 'role'=>'GIVER'|'TAKER', 'text'=>...]
     * @param array $opts Options: window (default 8), tolerance (default 1), negativesPerRoom (default null)
     * @return array Array of rows with keys: room_id, post_type, end_turn, promise_turn, label, span
     */
    public function extractRoom(
        int $roomId,
        string $postType,
        array $messages,
        array $opts = []
    ): array {
        $window = $opts['window'] ?? 8;
        $tolerance = $opts['tolerance'] ?? 1;
        $negativesPerRoom = $opts['negativesPerRoom'] ?? null;

        $eventTypes = ['Promised', 'Address', 'Reneged', 'Completed', 'System', 'Nudge', 'Reminder', 'Schedule', 'ModMail'];
        $realTextTypes = ['Default', 'Interested', 'Image'];

        // Filter to real-text turns and normalize text
        $realTextTurns = [];
        foreach ($messages as $msg) {
            if (!in_array($msg['type'], $realTextTypes, true)) {
                continue;
            }

            $normalizedText = $this->normaliser->normalise($msg['text']);
            if (trim($normalizedText) === '') {
                continue;
            }

            $realTextTurns[] = [
                'role' => $msg['role'],
                'text' => $normalizedText,
            ];
        }

        // Find promise_turn: index of the last real-text turn before the Promised event
        $promiseTurn = -1;
        $realTextIndex = 0;
        foreach ($messages as $msg) {
            if (in_array($msg['type'], $eventTypes, true)) {
                if ($msg['type'] === 'Promised') {
                    // Promise occurred after realTextIndex-1 (the last turn we processed)
                    // Only set promise_turn if we've seen at least one real-text turn
                    if ($realTextIndex > 0) {
                        $promiseTurn = $realTextIndex - 1;
                    }
                    break;
                }
            } elseif (in_array($msg['type'], $realTextTypes, true)) {
                $normalizedText = $this->normaliser->normalise($msg['text']);
                if (trim($normalizedText) !== '') {
                    $realTextIndex++;
                }
            }
        }

        // Generate rows for each real-text turn
        $rows = [];
        foreach (array_keys($realTextTurns) as $j) {
            // Build span: last <= window turns up to and including j
            $spanStart = max(0, $j - $window + 1);
            $spanTurns = array_slice($realTextTurns, $spanStart, $j - $spanStart + 1);
            $spanParts = [];
            foreach ($spanTurns as $turn) {
                $spanParts[] = "[{$turn['role']}] {$turn['text']}";
            }
            $span = implode(' ', $spanParts);

            // Determine label
            $label = 0;
            if ($promiseTurn >= 0 && $j >= $promiseTurn) {
                $label = 1;
            }

            // Drop if within tolerance of promise_turn
            if ($promiseTurn >= 0 && abs($j - $promiseTurn) <= $tolerance) {
                continue;
            }

            $rows[] = [
                'room_id' => $roomId,
                'post_type' => $postType,
                'end_turn' => $j,
                'promise_turn' => $promiseTurn,
                'label' => $label,
                'span' => $span,
            ];
        }

        // Apply negativesPerRoom cap
        if ($negativesPerRoom !== null) {
            $positives = array_filter($rows, fn($r) => $r['label'] === 1);
            $negatives = array_filter($rows, fn($r) => $r['label'] === 0);

            // Keep all positives
            $kept = $positives;

            // Cap negatives, keeping those closest to promise or evenly sampled
            if (count($negatives) > $negativesPerRoom) {
                $negatives = array_slice($negatives, 0, $negativesPerRoom);
            }

            $rows = array_merge($kept, $negatives);
            usort($rows, fn($a, $b) => $a['end_turn'] <=> $b['end_turn']);
        }

        return $rows;
    }
}

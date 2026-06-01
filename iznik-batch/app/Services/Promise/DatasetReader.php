<?php

namespace App\Services\Promise;

/**
 * Reads a promise detection CSV file (contract format).
 *
 * Returns:
 *   [
 *     'samples' => string[],       // span strings only
 *     'labels' => int[],            // 0 or 1
 *     'groups' => int[],            // room_id
 *     'endTurns' => int[],          // end_turn metadata
 *     'promiseTurns' => int[],      // promise_turn metadata
 *     'postTypes' => string[],      // 'Offer' or 'Wanted'
 *   ]
 */
class DatasetReader
{
    /**
     * Read a CSV file in the contract format.
     *
     * @param string $filePath
     * @return array{
     *   samples: string[],
     *   labels: int[],
     *   groups: int[],
     *   endTurns: int[],
     *   promiseTurns: int[],
     *   postTypes: string[]
     * }
     */
    public function read(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Dataset file not found: $filePath");
        }

        $samples = [];
        $labels = [];
        $groups = [];
        $endTurns = [];
        $promiseTurns = [];
        $postTypes = [];

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new \RuntimeException("Cannot open file: $filePath");
        }

        try {
            // Skip header row
            $header = fgetcsv($handle, escape: "\\");
            if ($header === false) {
                throw new \RuntimeException("Empty file: $filePath");
            }

            // Verify header structure
            $expectedHeaders = ['room_id', 'post_type', 'end_turn', 'promise_turn', 'label', 'span'];
            if ($header !== $expectedHeaders) {
                throw new \RuntimeException(
                    "CSV header mismatch. Expected: " . json_encode($expectedHeaders) .
                    "; Got: " . json_encode($header)
                );
            }

            // Read data rows
            while (($row = fgetcsv($handle, escape: "\\")) !== false) {
                if (count($row) < 6) {
                    continue; // Skip incomplete rows
                }

                $roomId = (int)$row[0];
                $postType = $row[1];
                $endTurn = (int)$row[2];
                $promiseTurn = (int)$row[3];
                $label = (int)$row[4];
                $span = $row[5];

                // Ensure UTF-8 validity (as per contract)
                $span = mb_convert_encoding($span, 'UTF-8', 'UTF-8');

                $groups[] = $roomId;
                $postTypes[] = $postType;
                $endTurns[] = $endTurn;
                $promiseTurns[] = $promiseTurn;
                $labels[] = $label;
                $samples[] = $span;
            }
        } finally {
            fclose($handle);
        }

        return [
            'samples' => $samples,
            'labels' => $labels,
            'groups' => $groups,
            'endTurns' => $endTurns,
            'promiseTurns' => $promiseTurns,
            'postTypes' => $postTypes,
        ];
    }
}

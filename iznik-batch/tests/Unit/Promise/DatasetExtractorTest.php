<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\DatasetExtractor;
use PHPUnit\Framework\TestCase;

class DatasetExtractorTest extends TestCase
{
    private DatasetExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new DatasetExtractor();
    }

    public function test_extract_room_basic()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Is this available?'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Yes, you can have it.'],
            ['type' => 'Promised', 'role' => 'TAKER', 'text' => ''],
        ];

        // With tolerance 0, we drop the promised turn itself
        $rows = $this->extractor->extractRoom(
            roomId: 123,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 0, 'negativesPerRoom' => null]
        );

        $this->assertCount(1, $rows);
        $this->assertEqual(123, $rows[0]['room_id']);
        $this->assertEqual('Offer', $rows[0]['post_type']);
        $this->assertEqual(0, $rows[0]['end_turn']);
        $this->assertEqual(1, $rows[0]['promise_turn']);
        $this->assertEqual(0, $rows[0]['label']);
    }

    public function test_exclude_event_types()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Is this available?'],
            ['type' => 'Address', 'role' => 'GIVER', 'text' => ''],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Address sent.'],
            ['type' => 'Promised', 'role' => 'TAKER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 124,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        // Only 2 real-text turns: "Is this available?" and "Address sent."
        // Promised event is after "Address sent.", so promise_turn = 1
        $this->assertCount(1, $rows);
        $this->assertEqual(1, $rows[0]['promise_turn']);
    }

    public function test_window_size_smaller_than_available_turns()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 125,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 2, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        // 3 real-text turns (indices 0, 1, 2)
        // Promised after turn 2, so promise_turn = 2
        // Drop |j - 2| <= 1, i.e., j in [1, 2, 3]
        // Keep j in [0]
        $this->assertCount(1, $rows);
        $this->assertEqual(0, $rows[0]['end_turn']);
    }

    public function test_span_format_speaker_tagged()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Is this available?'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Yes it is.'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 126,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        $this->assertStringContainsString('[TAKER]', $rows[0]['span']);
        $this->assertStringContainsString('[GIVER]', $rows[0]['span']);
        $this->assertStringContainsString('Is this available?', $rows[0]['span']);
        $this->assertStringContainsString('Yes it is.', $rows[0]['span']);
    }

    public function test_no_promised_event()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Is this available?'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Yes it is.'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 127,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        $this->assertEqual(-1, $rows[0]['promise_turn']);
        $this->assertEqual(0, $rows[0]['label']);
    }

    public function test_label_before_promise_turn()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 3'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 128,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 0, 'negativesPerRoom' => null]
        );

        // 4 real-text turns (0, 1, 2, 3), promise_turn = 2 (last turn before Promised)
        // Rows with j < 2 should have label 0, rows with j >= 2 should have label 1
        $beforePromise = collect($rows)->firstWhere('end_turn', '<', 2);
        $atPromise = collect($rows)->firstWhere('end_turn', '>=', 2);

        if ($beforePromise) {
            $this->assertEqual(0, $beforePromise['label']);
        }
        if ($atPromise) {
            $this->assertEqual(1, $atPromise['label']);
        }
    }

    public function test_drop_within_tolerance()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 129,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        // promise_turn = 2 (last turn before Promised), tolerance = 1
        // Rows with end_turn in [1, 2, 3] should be dropped (|j-2|<=1)
        // Keep end_turn = 0
        $this->assertCount(1, $rows);
        $this->assertEqual(0, $rows[0]['end_turn']);
    }

    public function test_negatives_per_room_cap()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 3'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 4'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 130,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 100, 'negativesPerRoom' => 2]
        );

        // No promised event, so all are negatives (label 0)
        // Should be capped to 2
        $negatives = collect($rows)->filter(fn($r) => $r['label'] === 0);
        $this->assertLessThanOrEqual(2, $negatives->count());
    }

    public function test_positives_never_dropped_by_cap()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 3'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 131,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 0, 'negativesPerRoom' => 1]
        );

        $positives = collect($rows)->filter(fn($r) => $r['label'] === 1);
        // Positives should never be dropped, even with cap
        $this->assertGreaterThan(0, $positives->count());
    }

    public function test_empty_text_turns_skipped()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => ''],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => '   '],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 132,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 0, 'negativesPerRoom' => null]
        );

        // Only "Turn 0" is a real turn (index 0 in real-text space)
        // Promised happens after it, so promise_turn = 0
        $this->assertCount(1, $rows);
        $this->assertEqual(0, $rows[0]['promise_turn']);
        $this->assertEqual(0, $rows[0]['end_turn']);
        $this->assertEqual(1, $rows[0]['label']);  // j >= promise_turn
    }

    public function test_window_does_not_exceed_available_turns()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Only turn'],
            ['type' => 'Promised', 'role' => 'GIVER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 133,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 100, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        // Window is 100 but only 1 turn available, span should contain only that 1 turn
        $this->assertStringContainsString('Only turn', $rows[0]['span']);
    }

    public function test_wanted_post_type()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'I have this.'],
            ['type' => 'Promised', 'role' => 'TAKER', 'text' => ''],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 134,
            postType: 'Wanted',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        $this->assertEqual('Wanted', $rows[0]['post_type']);
    }

    public function test_include_positive_and_negative_with_wider_tolerance()
    {
        $messages = [
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 0'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 1'],
            ['type' => 'Default', 'role' => 'TAKER', 'text' => 'Turn 2'],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 3'],
            ['type' => 'Promised', 'role' => 'TAKER', 'text' => ''],
            ['type' => 'Default', 'role' => 'GIVER', 'text' => 'Turn 4'],
        ];

        $rows = $this->extractor->extractRoom(
            roomId: 135,
            postType: 'Offer',
            messages: $messages,
            opts: ['window' => 8, 'tolerance' => 1, 'negativesPerRoom' => null]
        );

        // promise_turn = 3 (last turn before Promised)
        // Drop |j - 3| <= 1, i.e., j in [2, 3, 4]
        // Keep j in [0, 1, 5]
        $this->assertGreaterThan(0, count($rows));

        // Check for at least one positive and one negative
        $positives = collect($rows)->filter(fn($r) => $r['label'] === 1);
        $negatives = collect($rows)->filter(fn($r) => $r['label'] === 0);

        $this->assertGreaterThan(0, $positives->count());
        $this->assertGreaterThan(0, $negatives->count());
    }
}

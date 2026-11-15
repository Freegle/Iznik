<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\DatasetReader;
use PHPUnit\Framework\TestCase;

class DatasetReaderTest extends TestCase
{
    private string $fixtureFile;

    protected function setUp(): void
    {
        $this->fixtureFile = __DIR__ . '/../../Fixtures/Promise/dataset_fixture.csv';
    }

    public function test_reads_fixture_csv(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        $this->assertIsArray($dataset);
        $this->assertArrayHasKey('samples', $dataset);
        $this->assertArrayHasKey('labels', $dataset);
        $this->assertArrayHasKey('groups', $dataset);
        $this->assertArrayHasKey('endTurns', $dataset);
        $this->assertArrayHasKey('promiseTurns', $dataset);
        $this->assertArrayHasKey('postTypes', $dataset);
    }

    public function test_fixture_counts(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        // Fixture has 22 data rows (excluding header)
        $this->assertCount(22, $dataset['samples']);
        $this->assertCount(22, $dataset['labels']);
        $this->assertCount(22, $dataset['groups']);
        $this->assertCount(22, $dataset['endTurns']);
        $this->assertCount(22, $dataset['promiseTurns']);
        $this->assertCount(22, $dataset['postTypes']);
    }

    public function test_samples_are_spans(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        $samples = $dataset['samples'];

        // First sample should be the span from first data row
        $this->assertStringContainsString('TAKER', $samples[0]);
        $this->assertStringContainsString('hi is this still available please', $samples[0]);
    }

    public function test_labels_are_0_or_1(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        foreach ($dataset['labels'] as $label) {
            $this->assertThat($label, $this->logicalOr(
                $this->equalTo(0),
                $this->equalTo(1)
            ), 'Labels must be 0 or 1');
        }
    }

    public function test_groups_are_room_ids(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        $groups = $dataset['groups'];

        // Should have room IDs
        $this->assertTrue(in_array(101, $groups));
        $this->assertTrue(in_array(102, $groups));
        $this->assertTrue(in_array(103, $groups));
    }

    public function test_end_turns_and_promise_turns_are_ints(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        foreach ($dataset['endTurns'] as $turn) {
            $this->assertIsInt($turn);
            $this->assertGreaterThanOrEqual(0, $turn);
        }

        foreach ($dataset['promiseTurns'] as $turn) {
            $this->assertIsInt($turn);
            // -1 means no promise turn
            $this->assertGreaterThanOrEqual(-1, $turn);
        }
    }

    public function test_post_types_are_offer_or_wanted(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        foreach ($dataset['postTypes'] as $type) {
            $this->assertThat($type, $this->logicalOr(
                $this->equalTo('Offer'),
                $this->equalTo('Wanted')
            ), 'Post types must be Offer or Wanted');
        }
    }

    public function test_room_101_has_correct_labels(): void
    {
        // Room 101 rows: indices 0-5 (promise_turn=4)
        // Row 0: end_turn=0, label=0 ✓
        // Row 1: end_turn=1, label=0 ✓
        // Row 2: end_turn=2, label=0 ✓
        // Row 5: end_turn=5, label=1 ✓ (end_turn >= promise_turn)
        // Row 6: end_turn=6, label=1 ✓

        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        // Filter room 101 samples
        $room101Indices = array_keys(
            array_filter($dataset['groups'], fn($g) => $g === 101)
        );

        $room101Labels = array_map(fn($i) => $dataset['labels'][$i], $room101Indices);
        $room101EndTurns = array_map(fn($i) => $dataset['endTurns'][$i], $room101Indices);

        // First 3 should be 0
        $this->assertEquals([0, 0, 0], array_slice($room101Labels, 0, 3));

        // Last 2 should be 1
        $this->assertEquals([1, 1], array_slice($room101Labels, -2));
    }

    public function test_room_102_has_no_promise_turn(): void
    {
        // Room 102: promise_turn=-1, so all labels should be 0
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        $room102Indices = array_keys(
            array_filter($dataset['groups'], fn($g) => $g === 102)
        );

        $room102Labels = array_map(fn($i) => $dataset['labels'][$i], $room102Indices);

        foreach ($room102Labels as $label) {
            $this->assertEquals(0, $label);
        }
    }

    public function test_multibyte_characters_preserved(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        // Fixture has emoji in several spans
        $hasEmoji = array_some(
            $dataset['samples'],
            fn($sample) => preg_match('/\p{Emoji}/u', $sample)
        );

        $this->assertTrue($hasEmoji, 'Fixture should contain emoji and they should be preserved');
    }

    public function test_pii_placeholders_preserved(): void
    {
        $reader = new DatasetReader();
        $dataset = $reader->read($this->fixtureFile);

        $hasAddress = array_some(
            $dataset['samples'],
            fn($sample) => strpos($sample, '<ADDRESS>') !== false
        );

        $this->assertTrue($hasAddress, 'Fixture should have <ADDRESS> placeholders');
    }
}

// Helper functions
if (!function_exists('array_some')) {
    function array_some(array $array, callable $callback): bool
    {
        foreach ($array as $item) {
            if ($callback($item)) {
                return true;
            }
        }
        return false;
    }
}

<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\Evaluator;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase
{
    private Evaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new Evaluator();
    }

    public function test_perfect_predictions(): void
    {
        $yTrue = [0, 0, 1, 1];
        $yProba = [0.1, 0.2, 0.8, 0.9];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        $this->assertEquals(1.0, $metrics['precision']);
        $this->assertEquals(1.0, $metrics['recall']);
        $this->assertEquals(1.0, $metrics['f1']);
        $this->assertEquals(2, $metrics['confusion_matrix']['tp']);
        $this->assertEquals(2, $metrics['confusion_matrix']['tn']);
        $this->assertEquals(0, $metrics['confusion_matrix']['fp']);
        $this->assertEquals(0, $metrics['confusion_matrix']['fn']);
    }

    public function test_all_false_positives(): void
    {
        $yTrue = [0, 0, 0, 0];
        $yProba = [0.9, 0.8, 0.7, 0.6];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        // No true positives, so precision/recall are 0
        $this->assertEquals(0, $metrics['confusion_matrix']['tp']);
        $this->assertEquals(4, $metrics['confusion_matrix']['fp']);
        $this->assertEquals(0, $metrics['confusion_matrix']['tn']);
        $this->assertEquals(0, $metrics['confusion_matrix']['fn']);
    }

    public function test_all_false_negatives(): void
    {
        $yTrue = [1, 1, 1, 1];
        $yProba = [0.1, 0.2, 0.3, 0.4];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        $this->assertEquals(0, $metrics['confusion_matrix']['tp']);
        $this->assertEquals(0, $metrics['confusion_matrix']['fp']);
        $this->assertEquals(0, $metrics['confusion_matrix']['tn']);
        $this->assertEquals(4, $metrics['confusion_matrix']['fn']);
    }

    public function test_precision_recall_f1_calculation(): void
    {
        // TP=2, FP=1, FN=1, TN=2
        // Precision = TP / (TP + FP) = 2/3 ≈ 0.667
        // Recall = TP / (TP + FN) = 2/3 ≈ 0.667
        // F1 = 2 * (P * R) / (P + R) = 2 * (0.667 * 0.667) / 1.334 ≈ 0.667
        $yTrue = [0, 0, 0, 1, 1, 1];
        $yProba = [0.2, 0.6, 0.3, 0.7, 0.8, 0.4];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        $this->assertEqualsWithDelta(2/3, $metrics['precision'], 0.01);
        $this->assertEqualsWithDelta(2/3, $metrics['recall'], 0.01);
        $this->assertEqualsWithDelta(2/3, $metrics['f1'], 0.01);
    }

    public function test_roc_auc_perfect(): void
    {
        // Perfect ranking: all positives ranked higher than negatives
        $yTrue = [0, 0, 0, 1, 1, 1];
        $yProba = [0.1, 0.2, 0.3, 0.7, 0.8, 0.9];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba);

        $this->assertEquals(1.0, $metrics['roc_auc']);
    }

    public function test_roc_auc_random(): void
    {
        // Random ordering should be ~0.5
        $yTrue = [0, 1, 0, 1, 0, 1];
        $yProba = [0.3, 0.4, 0.5, 0.2, 0.6, 0.1];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba);

        // This is approximately random, so AUC should be near 0.5
        $this->assertGreaterThan(0.3, $metrics['roc_auc']);
        $this->assertLessThan(0.7, $metrics['roc_auc']);
    }

    public function test_pr_auc_present(): void
    {
        $yTrue = [0, 0, 0, 1, 1, 1];
        $yProba = [0.1, 0.2, 0.3, 0.7, 0.8, 0.9];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba);

        $this->assertArrayHasKey('pr_auc', $metrics);
        $this->assertGreaterThanOrEqual(0.0, $metrics['pr_auc']);
        $this->assertLessThanOrEqual(1.0, $metrics['pr_auc']);
    }

    public function test_threshold_sweep(): void
    {
        $yTrue = [0, 0, 1, 1, 1];
        $yProba = [0.1, 0.4, 0.5, 0.7, 0.9];

        $sweep = $this->evaluator->thresholdSweep($yTrue, $yProba);

        // Should have multiple thresholds (at least 5)
        $this->assertGreaterThanOrEqual(5, count($sweep));

        // Each entry should have threshold, precision, recall, f1
        foreach ($sweep as $entry) {
            $this->assertArrayHasKey('threshold', $entry);
            $this->assertArrayHasKey('precision', $entry);
            $this->assertArrayHasKey('recall', $entry);
            $this->assertArrayHasKey('f1', $entry);
            $this->assertGreaterThanOrEqual(0.0, $entry['threshold']);
            $this->assertLessThanOrEqual(1.0, $entry['threshold']);
        }
    }

    public function test_timing_offset_perfect(): void
    {
        // Room with 5 turns, promise at turn 3
        $roomTurns = [
            ['end_turn' => 0, 'proba' => 0.1],
            ['end_turn' => 1, 'proba' => 0.2],
            ['end_turn' => 2, 'proba' => 0.3],
            ['end_turn' => 3, 'proba' => 0.8], // Promise detected here
            ['end_turn' => 4, 'proba' => 0.9],
        ];

        $offsets = $this->evaluator->timingOffsets(
            roomTurns: $roomTurns,
            actualPromiseTurn: 3,
            threshold: 0.5
        );

        // Should detect promise at turn 3 with threshold 0.5
        $this->assertEquals([0], $offsets);
    }

    public function test_timing_offset_missed(): void
    {
        $roomTurns = [
            ['end_turn' => 0, 'proba' => 0.1],
            ['end_turn' => 1, 'proba' => 0.2],
            ['end_turn' => 2, 'proba' => 0.3],
            ['end_turn' => 3, 'proba' => 0.4],
            ['end_turn' => 4, 'proba' => 0.4],
        ];

        $offsets = $this->evaluator->timingOffsets(
            roomTurns: $roomTurns,
            actualPromiseTurn: 3,
            threshold: 0.5
        );

        // Never crosses threshold
        $this->assertEmpty($offsets);
    }

    public function test_timing_offset_late(): void
    {
        $roomTurns = [
            ['end_turn' => 0, 'proba' => 0.1],
            ['end_turn' => 1, 'proba' => 0.2],
            ['end_turn' => 2, 'proba' => 0.3],
            ['end_turn' => 3, 'proba' => 0.4],
            ['end_turn' => 4, 'proba' => 0.8], // Detected here, but promise was at 3
        ];

        $offsets = $this->evaluator->timingOffsets(
            roomTurns: $roomTurns,
            actualPromiseTurn: 3,
            threshold: 0.5
        );

        // Detected at turn 4, promise at 3 -> offset = 1
        $this->assertEquals([1], $offsets);
    }

    public function test_timing_offset_early(): void
    {
        $roomTurns = [
            ['end_turn' => 0, 'proba' => 0.8], // Detected here early
            ['end_turn' => 1, 'proba' => 0.8],
            ['end_turn' => 2, 'proba' => 0.8],
            ['end_turn' => 3, 'proba' => 0.8], // Actual promise at 3
        ];

        $offsets = $this->evaluator->timingOffsets(
            roomTurns: $roomTurns,
            actualPromiseTurn: 3,
            threshold: 0.5
        );

        // Detected at turn 0, promise at 3 -> offset = -3
        $this->assertEquals([-3], $offsets);
    }

    public function test_timing_offset_distribution(): void
    {
        // Multiple rooms with different offsets
        $distribution = [];

        // Room 1: offset 0
        $offsets1 = $this->evaluator->timingOffsets(
            roomTurns: [
                ['end_turn' => 0, 'proba' => 0.1],
                ['end_turn' => 1, 'proba' => 0.8],
            ],
            actualPromiseTurn: 1,
            threshold: 0.5
        );
        $distribution = array_merge($distribution, $offsets1);

        // Room 2: offset 1
        $offsets2 = $this->evaluator->timingOffsets(
            roomTurns: [
                ['end_turn' => 0, 'proba' => 0.1],
                ['end_turn' => 1, 'proba' => 0.3],
                ['end_turn' => 2, 'proba' => 0.8],
            ],
            actualPromiseTurn: 1,
            threshold: 0.5
        );
        $distribution = array_merge($distribution, $offsets2);

        $this->assertCount(2, $distribution);
        $this->assertContains(0, $distribution);
        $this->assertContains(1, $distribution);
    }

    public function test_confusion_matrix_all_zeros(): void
    {
        $yTrue = [0, 0, 0];
        $yProba = [0.1, 0.2, 0.3];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        $cm = $metrics['confusion_matrix'];
        $this->assertEquals(0, $cm['tp']);
        $this->assertEquals(0, $cm['fp']);
        $this->assertEquals(3, $cm['tn']);
        $this->assertEquals(0, $cm['fn']);
    }

    public function test_all_ones(): void
    {
        $yTrue = [1, 1, 1];
        $yProba = [0.7, 0.8, 0.9];

        $metrics = $this->evaluator->evaluate($yTrue, $yProba, threshold: 0.5);

        $cm = $metrics['confusion_matrix'];
        $this->assertEquals(3, $cm['tp']);
        $this->assertEquals(0, $cm['fp']);
        $this->assertEquals(0, $cm['tn']);
        $this->assertEquals(0, $cm['fn']);
    }
}

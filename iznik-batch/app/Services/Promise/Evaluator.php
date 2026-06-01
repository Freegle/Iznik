<?php

namespace App\Services\Promise;

/**
 * Evaluation metrics for promise detection classifier.
 *
 * Computes: precision, recall, F1, confusion matrix, ROC-AUC, PR-AUC,
 * threshold sweep, and timing offsets.
 */
class Evaluator
{
    /**
     * Evaluate predictions against ground truth.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @param float $threshold
     * @return array{
     *   precision: float,
     *   recall: float,
     *   f1: float,
     *   confusion_matrix: array{tp: int, tn: int, fp: int, fn: int},
     *   roc_auc: float,
     *   pr_auc: float,
     * }
     */
    public function evaluate(array $yTrue, array $yProba, float $threshold = 0.5): array
    {
        $yPred = array_map(fn($p) => $p >= $threshold ? 1 : 0, $yProba);

        $cm = $this->confusionMatrix($yTrue, $yPred);

        $precision = $this->precision($cm);
        $recall = $this->recall($cm);
        $f1 = $this->f1Score($precision, $recall);

        $rocAuc = $this->rocAuc($yTrue, $yProba);
        $prAuc = $this->prAuc($yTrue, $yProba);

        return [
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'confusion_matrix' => $cm,
            'roc_auc' => $rocAuc,
            'pr_auc' => $prAuc,
        ];
    }

    /**
     * Compute confusion matrix.
     *
     * @param int[] $yTrue
     * @param int[] $yPred
     * @return array{tp: int, tn: int, fp: int, fn: int}
     */
    private function confusionMatrix(array $yTrue, array $yPred): array
    {
        $tp = $tn = $fp = $fn = 0;

        foreach ($yTrue as $i => $true) {
            $pred = $yPred[$i];

            if ($pred === 1 && $true === 1) {
                $tp++;
            } elseif ($pred === 0 && $true === 0) {
                $tn++;
            } elseif ($pred === 1 && $true === 0) {
                $fp++;
            } elseif ($pred === 0 && $true === 1) {
                $fn++;
            }
        }

        return ['tp' => $tp, 'tn' => $tn, 'fp' => $fp, 'fn' => $fn];
    }

    /**
     * Precision = TP / (TP + FP)
     */
    private function precision(array $cm): float
    {
        $denominator = $cm['tp'] + $cm['fp'];
        if ($denominator === 0) {
            return 0.0;
        }
        return $cm['tp'] / $denominator;
    }

    /**
     * Recall = TP / (TP + FN)
     */
    private function recall(array $cm): float
    {
        $denominator = $cm['tp'] + $cm['fn'];
        if ($denominator === 0) {
            return 0.0;
        }
        return $cm['tp'] / $denominator;
    }

    /**
     * F1 = 2 * (precision * recall) / (precision + recall)
     */
    private function f1Score(float $precision, float $recall): float
    {
        $denominator = $precision + $recall;
        if ($denominator <= 0.0) {
            return 0.0;
        }
        return 2.0 * ($precision * $recall) / $denominator;
    }

    /**
     * Compute ROC-AUC (area under receiver operating characteristic curve).
     *
     * Uses the trapezoidal rule to approximate AUC from sorted probabilities.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return float
     */
    public function rocAuc(array $yTrue, array $yProba): float
    {
        // Sort by probability (descending)
        $indices = array_keys($yProba);
        usort($indices, fn($i, $j) => $yProba[$j] <=> $yProba[$i]);

        $sorted = array_map(fn($i) => $yTrue[$i], $indices);

        // Count positives and negatives
        $nPositives = array_sum($sorted);
        $nNegatives = count($sorted) - $nPositives;

        if ($nPositives === 0 || $nNegatives === 0) {
            return 0.0;
        }

        // Compute true positive rate and false positive rate at each threshold
        $tpCounts = [];
        $fpCounts = [];
        $tp = $fp = 0;

        foreach ($sorted as $label) {
            if ($label === 1) {
                $tp++;
            } else {
                $fp++;
            }
            $tpCounts[] = $tp;
            $fpCounts[] = $fp;
        }

        // Normalize to rates
        $tpr = array_map(fn($tp) => $tp / $nPositives, $tpCounts);
        $fpr = array_map(fn($fp) => $fp / $nNegatives, $fpCounts);

        // Compute AUC using trapezoidal rule
        $auc = 0.0;
        $prevFpr = 0.0;
        $prevTpr = 0.0;

        for ($i = 0; $i < count($tpr); $i++) {
            $auc += ($fpr[$i] - $prevFpr) * ($tpr[$i] + $prevTpr) / 2.0;
            $prevFpr = $fpr[$i];
            $prevTpr = $tpr[$i];
        }

        return min(1.0, max(0.0, $auc));
    }

    /**
     * Compute PR-AUC (area under precision-recall curve).
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return float
     */
    public function prAuc(array $yTrue, array $yProba): float
    {
        // Sort by probability (descending)
        $indices = array_keys($yProba);
        usort($indices, fn($i, $j) => $yProba[$j] <=> $yProba[$i]);

        $sorted = array_map(fn($i) => $yTrue[$i], $indices);

        $nPositives = array_sum($sorted);

        if ($nPositives === 0) {
            return 0.0;
        }

        // Compute precision and recall at each threshold
        $precisions = [];
        $recalls = [];
        $tp = 0;

        for ($i = 0; $i < count($sorted); $i++) {
            if ($sorted[$i] === 1) {
                $tp++;
            }
            $precision = $tp / ($i + 1);
            $recall = $tp / $nPositives;

            $precisions[] = $precision;
            $recalls[] = $recall;
        }

        // Compute AUC using trapezoidal rule
        $auc = 0.0;
        $prevRecall = 0.0;
        $prevPrecision = 1.0; // Start at 1.0 before any predictions

        for ($i = 0; $i < count($recalls); $i++) {
            $auc += ($recalls[$i] - $prevRecall) * ($precisions[$i] + $prevPrecision) / 2.0;
            $prevRecall = $recalls[$i];
            $prevPrecision = $precisions[$i];
        }

        return min(1.0, max(0.0, $auc));
    }

    /**
     * Sweep through thresholds and compute P/R/F1 at each.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return array<array{threshold: float, precision: float, recall: float, f1: float}>
     */
    public function thresholdSweep(array $yTrue, array $yProba): array
    {
        $thresholds = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8, 0.9];

        $results = [];
        foreach ($thresholds as $threshold) {
            $metrics = $this->evaluate($yTrue, $yProba, $threshold);

            $results[] = [
                'threshold' => $threshold,
                'precision' => $metrics['precision'],
                'recall' => $metrics['recall'],
                'f1' => $metrics['f1'],
            ];
        }

        return $results;
    }

    /**
     * Compute timing offsets: when (first turn threshold crossed) - (actual promise turn).
     *
     * Returns array of offsets per room, or empty if promise never detected.
     *
     * @param array $roomTurns [ ['end_turn' => int, 'proba' => float], ... ]
     * @param int $actualPromiseTurn
     * @param float $threshold
     * @return int[] array of offsets
     */
    public function timingOffsets(array $roomTurns, int $actualPromiseTurn, float $threshold = 0.5): array
    {
        // Find first turn where probability >= threshold
        $detectedTurn = null;

        foreach ($roomTurns as $turn) {
            if ($turn['proba'] >= $threshold) {
                $detectedTurn = $turn['end_turn'];
                break; // First crossing
            }
        }

        if ($detectedTurn === null) {
            // Never detected
            return [];
        }

        // Offset = detected - actual
        $offset = $detectedTurn - $actualPromiseTurn;

        return [$offset];
    }
}

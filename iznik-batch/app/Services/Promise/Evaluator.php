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
     * Cumulative (tp, fp) after each block of tied scores, highest score first.
     * Tied scores form one point on the curve, so the result does not depend on
     * the order the input happens to be in.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return array<int, array{0: int, 1: int}>
     */
    private function cumulativeCounts(array $yTrue, array $yProba): array
    {
        $indices = array_keys($yProba);
        usort($indices, fn($i, $j) => $yProba[$j] <=> $yProba[$i]);

        $points = [];
        $tp = $fp = 0;
        $n = count($indices);

        for ($k = 0; $k < $n; $k++) {
            if ($yTrue[$indices[$k]] === 1) {
                $tp++;
            } else {
                $fp++;
            }

            $isBlockEnd = $k === $n - 1 || $yProba[$indices[$k + 1]] != $yProba[$indices[$k]];
            if ($isBlockEnd) {
                $points[] = [$tp, $fp];
            }
        }

        return $points;
    }

    /**
     * Compute ROC-AUC (area under receiver operating characteristic curve).
     *
     * Uses the trapezoidal rule over one point per distinct score.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return float
     */
    public function rocAuc(array $yTrue, array $yProba): float
    {
        $nPositives = array_sum($yTrue);
        $nNegatives = count($yTrue) - $nPositives;

        if ($nPositives === 0 || $nNegatives === 0) {
            return 0.0;
        }

        $auc = 0.0;
        $prevFpr = 0.0;
        $prevTpr = 0.0;

        foreach ($this->cumulativeCounts($yTrue, $yProba) as [$tp, $fp]) {
            $tpr = $tp / $nPositives;
            $fpr = $fp / $nNegatives;
            $auc += ($fpr - $prevFpr) * ($tpr + $prevTpr) / 2.0;
            $prevFpr = $fpr;
            $prevTpr = $tpr;
        }

        return min(1.0, max(0.0, $auc));
    }

    /**
     * Compute PR-AUC (area under precision-recall curve).
     *
     * Uses one point per distinct score, starting from precision 1.0 at recall 0.
     *
     * @param int[] $yTrue
     * @param float[] $yProba
     * @return float
     */
    public function prAuc(array $yTrue, array $yProba): float
    {
        $nPositives = array_sum($yTrue);

        if ($nPositives === 0) {
            return 0.0;
        }

        $auc = 0.0;
        $prevRecall = 0.0;
        $prevPrecision = 1.0; // Start at 1.0 before any predictions

        foreach ($this->cumulativeCounts($yTrue, $yProba) as [$tp, $fp]) {
            $precision = $tp / ($tp + $fp);
            $recall = $tp / $nPositives;
            $auc += ($recall - $prevRecall) * ($precision + $prevPrecision) / 2.0;
            $prevRecall = $recall;
            $prevPrecision = $precision;
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
        // The first crossing is the earliest turn, whatever order the rows arrive in.
        usort($roomTurns, fn($a, $b) => $a['end_turn'] <=> $b['end_turn']);

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

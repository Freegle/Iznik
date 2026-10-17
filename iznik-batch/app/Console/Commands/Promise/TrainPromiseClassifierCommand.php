<?php

namespace App\Console\Commands\Promise;

use App\Services\Promise\DatasetReader;
use App\Services\Promise\FeaturePipeline;
use App\Services\Promise\KeywordBaseline;
use App\Services\Promise\Evaluator;
use Illuminate\Console\Command;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\PersistentModel;
use Rubix\ML\Persisters\Filesystem;
use Rubix\ML\Datasets\Labeled;

class TrainPromiseClassifierCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'promise:train
        {--csv=storage/promise/dataset.csv : Path to training CSV}
        {--test-fraction=0.2 : Fraction of rooms for test set}
        {--threshold=0.5 : Classification threshold for evaluation}
        {--out=storage/promise : Output directory for model and report}
    ';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Train the promise detection classifier';

    public function handle(): int
    {
        $csvPath = $this->option('csv');
        $testFraction = (float)$this->option('test-fraction');
        $threshold = (float)$this->option('threshold');
        $outDir = $this->option('out');

        // Ensure output directory exists
        if (!is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }

        try {
            $this->info('Loading dataset...');
            $reader = new DatasetReader();
            $dataset = $reader->read($csvPath);

            // Split by room_id to avoid leakage
            $this->info('Splitting by room_id...');
            [$trainDataset, $testDataset] = $this->splitByRoom(
                $dataset,
                $testFraction
            );

            $this->info(sprintf(
                'Train: %d samples (%d rooms), Test: %d samples (%d rooms)',
                count($trainDataset['samples']),
                count(array_unique($trainDataset['groups'])),
                count($testDataset['samples']),
                count(array_unique($testDataset['groups'])),
            ));

            // Train the main classifier
            $this->info('Training FeaturePipeline...');
            $pipeline = new FeaturePipeline();

            // Convert integer labels to categorical (Rubix requirement)
            $categoricalLabels = array_map(
                fn($l) => $l === 1 ? '1' : '0',
                $trainDataset['labels']
            );

            $rubixDataset = new Labeled(
                samples: array_map(fn ($s) => [$s], $trainDataset['samples']),
                labels: $categoricalLabels
            );
            $pipeline->train($rubixDataset);

            // Predict on test set
            $this->info('Evaluating on test set...');

            // For prediction, use unlabeled dataset
            $testRubixDataset = new \Rubix\ML\Datasets\Unlabeled(
                samples: array_map(fn ($s) => [$s], $testDataset['samples'])
            );

            $predictions = array_map('intval', $pipeline->predict($testRubixDataset));

            // Real positive-class probabilities from the logistic regression.
            $probaClass1 = $pipeline->probaPositive($testRubixDataset);

            // Evaluate
            $evaluator = new Evaluator();
            $metrics = $evaluator->evaluate($testDataset['labels'], $probaClass1, $threshold);

            // Baseline evaluation
            $baseline = new KeywordBaseline();
            $baselinePreds = array_map(fn($span) => $baseline->predict($span), $testDataset['samples']);
            $baslineProba = array_map(fn($pred) => (float) $pred, $baselinePreds);
            $baselineMetrics = $evaluator->evaluate($testDataset['labels'], $baslineProba, $threshold);

            // Threshold sweep
            $sweep = $evaluator->thresholdSweep($testDataset['labels'], $probaClass1);

            // Timing offsets
            $this->info('Computing timing offsets...');
            $allOffsets = [];
            $missCount = 0;
            $totalRooms = 0;

            $uniqueRooms = array_unique($testDataset['groups']);
            foreach ($uniqueRooms as $roomId) {
                $indices = array_keys($testDataset['groups'], $roomId);
                $roomEndTurns = array_map(fn($i) => $testDataset['endTurns'][$i], $indices);
                $roomProba = array_map(fn($i) => $probaClass1[$i], $indices);
                $actualPromiseTurn = $testDataset['promiseTurns'][$indices[0]]; // Same for all turns in room

                if ($actualPromiseTurn >= 0) {
                    $totalRooms++;

                    $roomTurns = array_map(
                        fn($turn, $proba) => ['end_turn' => $turn, 'proba' => $proba],
                        $roomEndTurns,
                        $roomProba
                    );

                    $offsets = $evaluator->timingOffsets(
                        roomTurns: $roomTurns,
                        actualPromiseTurn: $actualPromiseTurn,
                        threshold: $threshold
                    );

                    if (empty($offsets)) {
                        $missCount++;
                    } else {
                        $allOffsets = array_merge($allOffsets, $offsets);
                    }
                }
            }

            $missRate = $totalRooms > 0 ? $missCount / $totalRooms : 0;

            // Top n-grams by coefficient
            $topNgrams = $pipeline->topNgrams(20);

            // False positives and false negatives
            $fp = $this->falseExamples($testDataset['samples'], $testDataset['labels'], $predictions, 1, 0, 3);
            $fn = $this->falseExamples($testDataset['samples'], $testDataset['labels'], $predictions, 0, 1, 3);

            // Print report
            $this->printReport(
                metrics: $metrics,
                baselineMetrics: $baselineMetrics,
                sweep: $sweep,
                offsets: $allOffsets,
                missRate: $missRate,
                topNgrams: $topNgrams,
                falsePositives: $fp,
                falseNegatives: $fn
            );

            // Save report to JSON
            $reportData = [
                'model' => 'FeaturePipeline (WordCountVectorizer + TfIdfTransformer + LogisticRegression)',
                'test_metrics' => $metrics,
                'baseline_metrics' => $baselineMetrics,
                'threshold' => $threshold,
                'threshold_sweep' => $sweep,
                'timing_offsets' => [
                    'offsets' => $allOffsets,
                    'miss_rate' => $missRate,
                    'total_rooms_with_promise' => $totalRooms,
                ],
                'top_ngrams' => $topNgrams,
                'test_set_size' => count($testDataset['samples']),
                'train_set_size' => count($trainDataset['samples']),
            ];

            file_put_contents(
                $outDir . '/report.json',
                json_encode($reportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
            );

            $this->info("Report saved to: $outDir/report.json");

            // Persist the model
            $this->info('Persisting model...');
            $modelPath = $outDir . '/model.phpser';
            $pipeline->save($modelPath);

            $this->info("Model saved to: $modelPath");

            return Command::SUCCESS;
        } catch (\Exception $e) {
            $this->error('Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return Command::FAILURE;
        }
    }

    /**
     * Split dataset by room_id to avoid leakage.
     *
     * @return array<array{samples: string[], labels: int[], groups: int[], endTurns: int[], promiseTurns: int[], postTypes: string[]}>
     */
    private function splitByRoom(array $dataset, float $testFraction): array
    {
        $uniqueRooms = array_unique($dataset['groups']);
        shuffle($uniqueRooms);

        $testCount = (int)ceil(count($uniqueRooms) * $testFraction);
        $testRooms = array_slice($uniqueRooms, 0, $testCount);

        $trainSamples = [];
        $trainLabels = [];
        $trainGroups = [];
        $trainEndTurns = [];
        $trainPromiseTurns = [];
        $trainPostTypes = [];

        $testSamples = [];
        $testLabels = [];
        $testGroups = [];
        $testEndTurns = [];
        $testPromiseTurns = [];
        $testPostTypes = [];

        foreach ($dataset['groups'] as $i => $roomId) {
            if (in_array($roomId, $testRooms, true)) {
                $testSamples[] = $dataset['samples'][$i];
                $testLabels[] = $dataset['labels'][$i];
                $testGroups[] = $dataset['groups'][$i];
                $testEndTurns[] = $dataset['endTurns'][$i];
                $testPromiseTurns[] = $dataset['promiseTurns'][$i];
                $testPostTypes[] = $dataset['postTypes'][$i];
            } else {
                $trainSamples[] = $dataset['samples'][$i];
                $trainLabels[] = $dataset['labels'][$i];
                $trainGroups[] = $dataset['groups'][$i];
                $trainEndTurns[] = $dataset['endTurns'][$i];
                $trainPromiseTurns[] = $dataset['promiseTurns'][$i];
                $trainPostTypes[] = $dataset['postTypes'][$i];
            }
        }

        return [
            [
                'samples' => $trainSamples,
                'labels' => $trainLabels,
                'groups' => $trainGroups,
                'endTurns' => $trainEndTurns,
                'promiseTurns' => $trainPromiseTurns,
                'postTypes' => $trainPostTypes,
            ],
            [
                'samples' => $testSamples,
                'labels' => $testLabels,
                'groups' => $testGroups,
                'endTurns' => $testEndTurns,
                'promiseTurns' => $testPromiseTurns,
                'postTypes' => $testPostTypes,
            ],
        ];
    }

    /**
     * Extract top n-grams by absolute coefficient value.
     *
     * @return array<array{ngram: string, coefficient: float}>
     */
    private function extractTopCoefficients(FeaturePipeline $pipeline, int $n): array
    {
        // This would require access to the fitted vectorizer vocabulary and classifier weights.
        // For now, return placeholder; implement if Rubix provides coefficient access.
        return [];
    }

    /**
     * Get examples of false positives or false negatives.
     */
    private function falseExamples(
        array $samples,
        array $yTrue,
        array $yPred,
        int $actualLabel,
        int $predictedLabel,
        int $limit
    ): array {
        $examples = [];
        $count = 0;

        foreach ($samples as $i => $span) {
            if ($yTrue[$i] === $actualLabel && $yPred[$i] === $predictedLabel) {
                $examples[] = [
                    'span' => $span,
                    'true' => $yTrue[$i],
                    'pred' => $yPred[$i],
                ];
                $count++;

                if ($count >= $limit) {
                    break;
                }
            }
        }

        return $examples;
    }

    /**
     * Print human-readable report to console.
     */
    private function printReport(
        array $metrics,
        array $baselineMetrics,
        array $sweep,
        array $offsets,
        float $missRate,
        array $topNgrams,
        array $falsePositives,
        array $falseNegatives
    ): void {
        $this->line('');
        $this->line('=== Promise Detection Model Report ===');
        $this->line('');

        $this->line('Model Metrics (at threshold ' . $this->option('threshold') . '):');
        $this->table(
            ['Metric', 'FeaturePipeline', 'KeywordBaseline'],
            [
                ['Precision', number_format($metrics['precision'], 3), number_format($baselineMetrics['precision'], 3)],
                ['Recall', number_format($metrics['recall'], 3), number_format($baselineMetrics['recall'], 3)],
                ['F1', number_format($metrics['f1'], 3), number_format($baselineMetrics['f1'], 3)],
                ['ROC-AUC', number_format($metrics['roc_auc'], 3), 'N/A'],
                ['PR-AUC', number_format($metrics['pr_auc'], 3), 'N/A'],
            ]
        );

        $this->line('');
        $this->line('Confusion Matrix:');
        $this->table(
            ['', 'Predicted 0', 'Predicted 1'],
            [
                ['Actual 0', $metrics['confusion_matrix']['tn'], $metrics['confusion_matrix']['fp']],
                ['Actual 1', $metrics['confusion_matrix']['fn'], $metrics['confusion_matrix']['tp']],
            ]
        );

        $this->line('');
        $this->line('Threshold Sweep:');
        $this->table(
            ['Threshold', 'Precision', 'Recall', 'F1'],
            array_map(fn($s) => [
                number_format($s['threshold'], 1),
                number_format($s['precision'], 3),
                number_format($s['recall'], 3),
                number_format($s['f1'], 3),
            ], $sweep)
        );

        $this->line('');
        $this->line(sprintf('Timing Offsets: %d detected, miss rate %.1f%%', count($offsets), $missRate * 100));
        if (!empty($offsets)) {
            $this->line('  Min offset: ' . min($offsets));
            $this->line('  Max offset: ' . max($offsets));
            $this->line('  Mean offset: ' . number_format(array_sum($offsets) / count($offsets), 2));
        }

        $this->line('');
        $this->line('Top influential n-grams (importance | pos-rate | docs):');
        $this->table(
            ['n-gram', 'importance', 'pos-rate', 'docs'],
            array_map(fn ($r) => [
                $r['token'],
                number_format($r['importance'], 4),
                number_format($r['pos_rate'], 2),
                $r['docs'],
            ], array_slice($topNgrams, 0, 20))
        );

        $this->line('');
        $this->line('Sample False Positives:');
        foreach ($falsePositives as $fp) {
            $this->line('  "' . substr($fp['span'], 0, 60) . '..."');
        }

        $this->line('');
        $this->line('Sample False Negatives:');
        foreach ($falseNegatives as $fn) {
            $this->line('  "' . substr($fn['span'], 0, 60) . '..."');
        }
    }
}

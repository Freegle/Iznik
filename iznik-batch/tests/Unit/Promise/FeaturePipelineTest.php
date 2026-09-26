<?php

namespace Tests\Unit\Promise;

use App\Services\Promise\FeaturePipeline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;
use Rubix\ML\Exceptions\RuntimeException as RubixRuntimeException;

/**
 * Deliberately small, strongly-separated toy corpus (not real chat data).
 * WordCountVectorizer requires a token to appear in >=3 documents and in
 * <=80% of documents to survive into the vocabulary, so the two classes
 * need several samples each and a shared word ("thanks") to exercise the
 * mixed pos_rate branch in recordTokenStats().
 *
 * Rubix's LogisticRegression training is not seeded, so importance VALUES,
 * top-k ORDER between equally-important tokens, and even individual
 * per-sample predictions on a corpus this small vary run to run (verified
 * empirically over 200 runs: exact-prediction accuracy occasionally dropped
 * to chance level on an unlucky weight init). Assertions below only rely on
 * things verified stable across runs: vocabulary membership and pos_rate/docs
 * (plain counting, not the classifier), aggregate mean separation between
 * the two classes (never flipped in 200 trial runs), and output shape/type.
 */
class FeaturePipelineTest extends TestCase
{
    private const POSITIVE = [
        'yes please take it',
        'yes you can have it',
        'yes thanks thats brilliant',
        'ill collect it yes thanks',
        'great yes see you thursday',
        'yes definitely come round',
        'yes of course have it',
        'brilliant yes come by later',
    ];

    private const NEGATIVE = [
        'no thanks not needed',
        'no its gone already sorry',
        'no sorry too late now',
        'no its taken already mate',
        'sorry no longer available',
        'no not interested sorry',
        'no already spoken for',
        'no its already gone mate',
    ];

    private function samples(): array
    {
        return array_map(fn (string $s) => [$s], array_merge(self::POSITIVE, self::NEGATIVE));
    }

    private function labels(): array
    {
        return array_merge(
            array_fill(0, count(self::POSITIVE), '1'),
            array_fill(0, count(self::NEGATIVE), '0'),
        );
    }

    private function trainedPipeline(): FeaturePipeline
    {
        $pipeline = new FeaturePipeline();
        $pipeline->train(new Labeled($this->samples(), $this->labels()));

        return $pipeline;
    }

    public function test_top_ngrams_before_training_returns_empty_array(): void
    {
        $pipeline = new FeaturePipeline();

        $this->assertSame([], $pipeline->topNgrams());
    }

    public function test_top_ngrams_before_training_ignores_k(): void
    {
        $pipeline = new FeaturePipeline();

        $this->assertSame([], $pipeline->topNgrams(5));
    }

    public function test_predict_before_training_throws(): void
    {
        $pipeline = new FeaturePipeline();

        $this->expectException(RubixRuntimeException::class);

        $pipeline->predict(new Unlabeled([['yes please']]));
    }

    public function test_proba_positive_before_training_throws(): void
    {
        $pipeline = new FeaturePipeline();

        $this->expectException(RubixRuntimeException::class);

        $pipeline->probaPositive(new Unlabeled([['yes please']]));
    }

    public function test_get_classifier_returns_logistic_regression_before_training(): void
    {
        $pipeline = new FeaturePipeline();

        $this->assertInstanceOf(LogisticRegression::class, $pipeline->getClassifier());
    }

    public function test_train_then_predict_returns_a_binary_label_for_every_sample(): void
    {
        $pipeline = $this->trainedPipeline();

        $predictions = $pipeline->predict(new Unlabeled($this->samples()));

        $this->assertCount(16, $predictions);
        foreach ($predictions as $prediction) {
            $this->assertContains((int) $prediction, [0, 1]);
        }
    }

    public function test_proba_positive_scores_the_positive_class_higher_on_average(): void
    {
        // Individual borderline samples can occasionally land on either side
        // of 0.5 depending on the classifier's random weight init (verified
        // over 200 runs), so this asserts the robust invariant instead: on
        // average, positive-labelled spans score higher than negative ones.
        $pipeline = $this->trainedPipeline();

        $probas = $pipeline->probaPositive(new Unlabeled($this->samples()));

        $this->assertCount(16, $probas);
        foreach ($probas as $p) {
            $this->assertIsFloat($p);
            $this->assertGreaterThanOrEqual(0.0, $p);
            $this->assertLessThanOrEqual(1.0, $p);
        }

        $positiveAverage = array_sum(array_slice($probas, 0, 8)) / 8;
        $negativeAverage = array_sum(array_slice($probas, 8, 8)) / 8;
        $this->assertGreaterThan(
            $negativeAverage,
            $positiveAverage,
            'positive-labelled spans should score higher on average than negative-labelled ones'
        );
    }

    public static function kLimitProvider(): array
    {
        return [
            'k=1' => [1],
            'k=3' => [3],
            'k=5' => [5],
            'k=0' => [0],
        ];
    }

    #[DataProvider('kLimitProvider')]
    public function test_top_ngrams_respects_the_k_limit(int $k): void
    {
        $pipeline = $this->trainedPipeline();

        $this->assertCount($k, $pipeline->topNgrams($k));
    }

    public function test_top_ngrams_defaults_k_to_twenty(): void
    {
        $pipeline = $this->trainedPipeline();

        // The toy vocabulary is much smaller than 20, so the default just
        // needs to not truncate what little vocabulary survived filtering.
        $this->assertLessThanOrEqual(20, count($pipeline->topNgrams()));
        $this->assertNotEmpty($pipeline->topNgrams());
    }

    public function test_top_ngrams_sorted_by_importance_descending(): void
    {
        $pipeline = $this->trainedPipeline();

        $importances = array_column($pipeline->topNgrams(100), 'importance');

        $sortedDescending = $importances;
        rsort($sortedDescending);

        $this->assertSame($sortedDescending, $importances);
    }

    public function test_top_ngrams_reports_expected_shape(): void
    {
        $pipeline = $this->trainedPipeline();

        $top = $pipeline->topNgrams(100);

        $this->assertNotEmpty($top);
        foreach ($top as $row) {
            $this->assertArrayHasKey('token', $row);
            $this->assertArrayHasKey('importance', $row);
            $this->assertArrayHasKey('pos_rate', $row);
            $this->assertArrayHasKey('docs', $row);
            $this->assertIsString($row['token']);
            $this->assertIsFloat($row['importance']);
            $this->assertIsFloat($row['pos_rate']);
            $this->assertIsInt($row['docs']);
            $this->assertGreaterThan(0, $row['docs']);
        }
    }

    public function test_top_ngrams_pos_rate_reflects_label_purity(): void
    {
        $pipeline = $this->trainedPipeline();

        $byToken = [];
        foreach ($pipeline->topNgrams(100) as $row) {
            $byToken[$row['token']] = $row;
        }

        // 'yes' appears only in the 8 positive spans -> pure positive token.
        $this->assertArrayHasKey('yes', $byToken);
        $this->assertEqualsWithDelta(1.0, $byToken['yes']['pos_rate'], 0.0005);
        $this->assertSame(8, $byToken['yes']['docs']);

        // 'no' appears only in the 8 negative spans -> pure negative token.
        $this->assertArrayHasKey('no', $byToken);
        $this->assertEqualsWithDelta(0.0, $byToken['no']['pos_rate'], 0.0005);
        $this->assertSame(8, $byToken['no']['docs']);

        // 'thanks' appears in 2 positive + 1 negative span -> a mixed token,
        // exercising the branch where 0 < pos_rate < 1.
        $this->assertArrayHasKey('thanks', $byToken);
        $this->assertEqualsWithDelta(2 / 3, $byToken['thanks']['pos_rate'], 0.0005);
        $this->assertSame(3, $byToken['thanks']['docs']);
    }

    public function test_save_and_load_round_trips_a_trained_pipeline(): void
    {
        $pipeline = $this->trainedPipeline();
        $expectedPredictions = $pipeline->predict(new Unlabeled($this->samples()));
        $expectedTopToken = $pipeline->topNgrams(1)[0]['token'] ?? null;

        $path = tempnam(sys_get_temp_dir(), 'feature_pipeline_test_');

        try {
            $pipeline->save($path);
            $loaded = FeaturePipeline::load($path);

            $this->assertInstanceOf(FeaturePipeline::class, $loaded);
            $this->assertSame($expectedPredictions, $loaded->predict(new Unlabeled($this->samples())));
            $this->assertSame($expectedTopToken, $loaded->topNgrams(1)[0]['token'] ?? null);
        } finally {
            unlink($path);
        }
    }

    public function test_save_and_load_round_trips_an_untrained_pipeline(): void
    {
        $pipeline = new FeaturePipeline();
        $path = tempnam(sys_get_temp_dir(), 'feature_pipeline_untrained_');

        try {
            $pipeline->save($path);
            $loaded = FeaturePipeline::load($path);

            $this->assertInstanceOf(FeaturePipeline::class, $loaded);
            $this->assertSame([], $loaded->topNgrams());
        } finally {
            unlink($path);
        }
    }
}

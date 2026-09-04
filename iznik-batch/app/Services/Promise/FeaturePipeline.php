<?php

namespace App\Services\Promise;

use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Transformers\WordCountVectorizer;
use Rubix\ML\Transformers\TfIdfTransformer;
use Rubix\ML\Datasets\Dataset;
use Rubix\ML\Datasets\Labeled;
use Rubix\ML\Datasets\Unlabeled;

/**
 * Feature engineering + linear model for promise detection (§5.2 baseline).
 *
 * Chain: CharNgramTokenizer (word 1-2 + char 3-5) -> WordCountVectorizer
 *        -> TfIdfTransformer -> LogisticRegression.
 *
 * We deliberately do NOT use Rubix\ML\Pipeline: its proba() double-transforms
 * the test set (IncorrectDatasetDimensionality) for this transformer chain.
 * Instead the transformers are fitted once on train and applied transform-only
 * to any later data, so predict()/proba() are consistent and real probabilities
 * are available (needed for AUC, the threshold sweep, and timing).
 *
 * Samples are single-column string rows: [[span], [span], ...].
 */
class FeaturePipeline
{
    private WordCountVectorizer $vectorizer;
    private TfIdfTransformer $tfidf;
    private LogisticRegression $classifier;
    private bool $fitted = false;

    /** token => ['doc' => int, 'pos' => int] over the training set (for n-gram direction) */
    private array $tokenStats = [];

    public function __construct()
    {
        // Rubix vectorises densely (one int per vocab term per sample), so the
        // char-n-gram vocabulary must be capped or the matrix exhausts memory.
        $this->vectorizer = new WordCountVectorizer(
            maxVocabularySize: 8000,
            minDocumentCount: 3,
            maxDocumentRatio: 0.8,
            tokenizer: new CharNgramTokenizer(),
        );
        $this->tfidf = new TfIdfTransformer();
        $this->classifier = new LogisticRegression(
            batchSize: 128,
            l2Penalty: 1e-4,
            epochs: 300,
            minChange: 1e-4,
        );
    }

    /**
     * Fit the transformers on the training samples, then train the classifier.
     * Labels must be categorical strings ('0' / '1') for Rubix classification.
     */
    public function train(Labeled $dataset): void
    {
        $samples = $dataset->samples();
        $labels = array_map('strval', $dataset->labels());

        // Record token -> document/positive-document counts BEFORE vectorising,
        // so we can annotate the top n-grams with their positive association.
        $this->recordTokenStats($samples, $labels);

        // Fit + apply the transformers in place.
        $this->vectorizer->fit(new Unlabeled($samples));
        $this->vectorizer->transform($samples);
        $this->tfidf->fit(new Unlabeled($samples));
        $this->tfidf->transform($samples);

        $this->classifier->train(new Labeled($samples, $labels));
        $this->fitted = true;
    }

    /** Transform new raw samples with the already-fitted transformers (no re-fit). */
    private function transformSamples(array $samples): array
    {
        $this->vectorizer->transform($samples);
        $this->tfidf->transform($samples);
        return $samples;
    }

    /** Predicted class labels ('0'/'1') for each sample. */
    public function predict(Dataset $dataset): array
    {
        return $this->classifier->predict(new Unlabeled($this->transformSamples($dataset->samples())));
    }

    /** Probability of the positive class (label '1') for each sample. */
    public function probaPositive(Dataset $dataset): array
    {
        $proba = $this->classifier->proba(new Unlabeled($this->transformSamples($dataset->samples())));

        return array_map(static function (array $row): float {
            return (float) ($row['1'] ?? $row[1] ?? 0.0);
        }, $proba);
    }

    /**
     * Top influential n-grams by model feature importance, each annotated with the
     * empirical P(label=1 | token present) on the training set. Doubles as a
     * leakage smoke-test: a label-tell shows up here with extreme importance.
     *
     * @return array<int, array{token: string, importance: float, pos_rate: float, docs: int}>
     */
    public function topNgrams(int $k = 20): array
    {
        if (!$this->fitted) {
            return [];
        }

        // vocabularies()[0] = [offset => token] for our single text column.
        $vocabularies = $this->vectorizer->vocabularies();
        $offsetToToken = $vocabularies[0] ?? [];

        $importances = $this->classifier->featureImportances(); // [offset => importance]

        $rows = [];
        foreach ($importances as $offset => $importance) {
            $token = $offsetToToken[$offset] ?? null;
            if ($token === null) {
                continue;
            }
            $stat = $this->tokenStats[$token] ?? ['doc' => 0, 'pos' => 0];
            $rows[] = [
                'token' => $token,
                'importance' => (float) $importance,
                'pos_rate' => $stat['doc'] > 0 ? round($stat['pos'] / $stat['doc'], 3) : 0.0,
                'docs' => $stat['doc'],
            ];
        }

        usort($rows, static fn ($a, $b) => $b['importance'] <=> $a['importance']);

        return array_slice($rows, 0, $k);
    }

    private function recordTokenStats(array $samples, array $labels): void
    {
        $tokenizer = new CharNgramTokenizer();
        foreach ($samples as $i => $row) {
            $text = is_array($row) ? ($row[0] ?? '') : (string) $row;
            $tokens = array_unique($tokenizer->tokenize((string) $text));
            $isPos = ($labels[$i] ?? '0') === '1';
            foreach ($tokens as $t) {
                if (!isset($this->tokenStats[$t])) {
                    $this->tokenStats[$t] = ['doc' => 0, 'pos' => 0];
                }
                $this->tokenStats[$t]['doc']++;
                if ($isPos) {
                    $this->tokenStats[$t]['pos']++;
                }
            }
        }
    }

    public function getClassifier(): LogisticRegression
    {
        return $this->classifier;
    }

    /** Persist the fitted pipeline (vectorizer + tf-idf + classifier) to disk. */
    public function save(string $path): void
    {
        file_put_contents($path, serialize($this));
    }

    /** Load a previously saved pipeline. */
    public static function load(string $path): self
    {
        return unserialize(file_get_contents($path));
    }
}

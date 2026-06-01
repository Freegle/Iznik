<?php

namespace App\Services\Promise;

use Rubix\ML\Pipeline;
use Rubix\ML\Classifiers\LogisticRegression;
use Rubix\ML\Transformers\WordCountVectorizer;
use Rubix\ML\Transformers\TfIdfTransformer;
use Rubix\ML\Datasets\Dataset;

/**
 * Feature engineering and model pipeline for promise detection.
 *
 * Combines:
 *   1. Word + character n-gram tokenization (CharNgramTokenizer)
 *   2. Word count vectorization
 *   3. TF-IDF transformation
 *   4. Logistic regression classifier
 *
 * The pipeline is wrapped in a Pipeline estimator so transformations
 * are automatically applied to training and prediction data.
 */
class FeaturePipeline
{
    private Pipeline $pipeline;

    public function __construct()
    {
        // Create the tokenizer with word and character n-grams
        $tokenizer = new CharNgramTokenizer();

        // WordCountVectorizer: builds vocabulary from training data
        $vectorizer = new WordCountVectorizer(
            maxVocabularySize: 5000,
            minDocumentCount: 2,
            maxDocumentRatio: 0.8,
            tokenizer: $tokenizer
        );

        // TF-IDF transformation
        $tfidf = new TfIdfTransformer();

        // Logistic regression classifier
        // Note: Rubix ML doesn't have built-in class_weight balance, but we handle
        // class imbalance via the dataset preparation (group split, stratification if needed).
        // L2 regularization helps prevent overfitting.
        $classifier = new LogisticRegression(
            batchSize: 32,
            l2Penalty: 0.0001, // Light regularization
            epochs: 100,
            minChange: 1e-4,
        );

        // Build the pipeline: tokenize -> vectorize -> tfidf -> classify
        $this->pipeline = new Pipeline(
            transformers: [$vectorizer, $tfidf],
            base: $classifier,
            elastic: false // Don't update transformers during online learning
        );
    }

    /**
     * Get the underlying Rubix Pipeline (for training/predicting).
     */
    public function make(): Pipeline
    {
        return $this->pipeline;
    }

    /**
     * Train the pipeline on a dataset.
     *
     * @param Dataset $dataset
     */
    public function train(Dataset $dataset): void
    {
        $this->pipeline->train($dataset);
    }

    /**
     * Predict class labels (0 or 1).
     *
     * @param Dataset $dataset
     * @return int[]
     */
    public function predict(Dataset $dataset): array
    {
        return $this->pipeline->predict($dataset);
    }

    /**
     * Predict class probabilities.
     *
     * Manually transforms data through the pipeline transformers,
     * then calls proba on the base classifier.
     *
     * @param \Rubix\ML\Datasets\Dataset $dataset
     * @return array<array{0: float, 1: float}>
     */
    public function proba(\Rubix\ML\Datasets\Dataset $dataset): array
    {
        // Access the pipeline's transformers and base classifier
        $reflection = new \ReflectionClass($this->pipeline);

        $transformersProperty = $reflection->getProperty('transformers');
        $transformersProperty->setAccessible(true);
        $transformers = $transformersProperty->getValue($this->pipeline);

        $baseProperty = $reflection->getProperty('base');
        $baseProperty->setAccessible(true);
        $base = $baseProperty->getValue($this->pipeline);

        // Get the raw samples and labels from the dataset
        $samples = $dataset->samples();
        if ($dataset instanceof \Rubix\ML\Datasets\Labeled) {
            $labels = $dataset->labels();
        } else {
            $labels = array_fill(0, count($samples), null);
        }

        // Apply transformations to the samples array
        $transformed = $samples;
        foreach ($transformers as $transformer) {
            $transformer->transform($transformed);
        }

        // Reconstruct dataset with transformed samples
        if ($dataset instanceof \Rubix\ML\Datasets\Labeled) {
            $transformedDataset = new \Rubix\ML\Datasets\Labeled(
                samples: $transformed,
                labels: $labels
            );
        } else {
            $transformedDataset = new \Rubix\ML\Datasets\Unlabeled(
                samples: $transformed
            );
        }

        // Call proba on the transformed data
        return $base->proba($transformedDataset);
    }

    /**
     * Get the underlying LogisticRegression classifier for coefficient inspection.
     *
     * @return LogisticRegression
     */
    public function getClassifier(): LogisticRegression
    {
        // The base estimator of the pipeline
        // Access via reflection or direct property (depends on Rubix version)
        $reflection = new \ReflectionClass($this->pipeline);
        $baseProperty = $reflection->getProperty('base');
        $baseProperty->setAccessible(true);
        return $baseProperty->getValue($this->pipeline);
    }
}

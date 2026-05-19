<?php

namespace Tests\Unit\Services;

use App\Services\ContentEmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContentEmbeddingServiceTest extends TestCase
{
    private ContentEmbeddingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentEmbeddingService();
    }

    public function test_returns_false_when_no_sidecar_url_configured(): void
    {
        // No EMBEDDING_SIDECAR_URL in test environment → conservative no-suppress.
        $this->assertFalse($this->service->isInnocentContext('gun for sale', 'substance_regulated'));
    }

    public function test_returns_false_for_unknown_category(): void
    {
        config(['app.embedding_sidecar_url' => 'http://fake:3200']);
        $this->app['env'] = 'testing';

        Http::fake(['*' => Http::response(['embeddings' => [array_fill(0, 256, 0.1)]], 200)]);

        // Category not in PROTOTYPES → conservative no-suppress.
        $result = $this->service->isInnocentContext('some text', 'unknown_category');
        $this->assertFalse($result);
    }

    public function test_returns_false_when_sidecar_unavailable(): void
    {
        Http::fake(['*' => Http::response([], 500)]);

        // Even with a URL set, sidecar failure → conservative no-suppress.
        putenv('EMBEDDING_SIDECAR_URL=http://nonexistent-sidecar:3200');
        try {
            $result = $this->service->isInnocentContext('hot glue gun', 'substance_regulated');
            $this->assertFalse($result);
        } finally {
            putenv('EMBEDDING_SIDECAR_URL=');
        }
    }

    public function test_suppresses_when_innocent_similarity_exceeds_threshold(): void
    {
        // Craft embeddings where innocent cluster is clearly more similar.
        // The text embedding is [1, 0, ...] — same direction as innocent prototypes.
        $dim = 256;
        $textVec      = array_fill(0, $dim, 0.0);
        $textVec[0]   = 1.0; // points along dimension 0

        $innocentVec  = array_fill(0, $dim, 0.0);
        $innocentVec[0] = 1.0; // same direction as text → cosine = 1.0

        $concerningVec = array_fill(0, $dim, 0.0);
        $concerningVec[1] = 1.0; // orthogonal to text → cosine = 0.0

        // The sidecar is called twice: once for prototypes, once for the text.
        // First call (prototypes): concerning × 5 + innocent × 6 embeddings.
        // Second call (text): just the text embedding.
        // We return a uniform vector for all prototypes, then vary per cluster.
        $concerningCount = 5;
        $innocentCount   = 6;
        $protoResponse   = array_merge(
            array_fill(0, $concerningCount, $concerningVec),
            array_fill(0, $innocentCount, $innocentVec),
        );

        Http::fake([
            '*/embed' => Http::sequence()
                ->push(['embeddings' => $protoResponse], 200)
                ->push(['embeddings' => [$textVec]], 200),
        ]);

        putenv('EMBEDDING_SIDECAR_URL=http://fake-sidecar:3200');
        try {
            // Static cache persists between test calls — reset it via reflection.
            $this->resetProtoCache();
            $result = $this->service->isInnocentContext('hot glue gun for crafts', 'substance_regulated');
            $this->assertTrue($result, 'Clearly innocent context should be suppressed');
        } finally {
            putenv('EMBEDDING_SIDECAR_URL=');
            $this->resetProtoCache();
        }
    }

    public function test_does_not_suppress_when_concerning_similarity_dominates(): void
    {
        $dim = 256;
        $textVec      = array_fill(0, $dim, 0.0);
        $textVec[1]   = 1.0; // same direction as concerning prototypes

        $concerningVec = array_fill(0, $dim, 0.0);
        $concerningVec[1] = 1.0; // cosine(text, concerning) = 1.0

        $innocentVec = array_fill(0, $dim, 0.0);
        $innocentVec[0] = 1.0; // cosine(text, innocent) = 0.0

        $concerningCount = 5;
        $innocentCount   = 6;
        $protoResponse   = array_merge(
            array_fill(0, $concerningCount, $concerningVec),
            array_fill(0, $innocentCount, $innocentVec),
        );

        Http::fake([
            '*/embed' => Http::sequence()
                ->push(['embeddings' => $protoResponse], 200)
                ->push(['embeddings' => [$textVec]], 200),
        ]);

        putenv('EMBEDDING_SIDECAR_URL=http://fake-sidecar:3200');
        try {
            $this->resetProtoCache();
            $result = $this->service->isInnocentContext('handgun pistol for sale', 'substance_regulated');
            $this->assertFalse($result, 'Concerning context should not be suppressed');
        } finally {
            putenv('EMBEDDING_SIDECAR_URL=');
            $this->resetProtoCache();
        }
    }

    /** Reset the static prototype cache between tests to avoid cross-test pollution. */
    private function resetProtoCache(): void
    {
        $ref = new \ReflectionClass(ContentEmbeddingService::class);
        $prop = $ref->getProperty('prototypeCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }
}

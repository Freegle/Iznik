<?php

namespace Tests\Unit\Services;

use App\Services\PollinationsService;
use App\Services\TusService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Covers PollinationsService's pure prompt/URL builders, the file-backed
 * failure/hash caches (via the real cache file paths — reflected out of the
 * private constants so tests stay in sync if they ever change), the GD
 * duotone filter, and uploadImageAndCache()'s three branches (duotone
 * failure / TUS failure / success) via TusService::fake().
 *
 * fetchBatch()'s network body and checkForPeople()'s OpenAI call are not
 * covered beyond their early-return guards: both talk to the outside world
 * via file_get_contents()/curl directly (no injectable HTTP client), so
 * exercising their request/response branches would mean hitting real
 * external services — out of scope for a unit test.
 */
class PollinationsServiceTest extends TestCase
{
    private PollinationsService $service;

    private string $failedCacheFile;

    private string $hashCacheFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PollinationsService(new TusService('https://test-tus-server.example.com'));

        $ref = new ReflectionClass(PollinationsService::class);
        $this->failedCacheFile = $ref->getConstant('FAILED_CACHE_FILE');
        $this->hashCacheFile = $ref->getConstant('HASH_CACHE_FILE');
        $this->clearCacheFiles();
    }

    protected function tearDown(): void
    {
        TusService::clearFake();
        $this->clearCacheFiles();
        parent::tearDown();
    }

    private function clearCacheFiles(): void
    {
        foreach ([$this->failedCacheFile, $this->hashCacheFile] as $f) {
            if (file_exists($f)) {
                unlink($f);
            }
        }
    }

    /** A minimal valid PNG, generated with GD, as raw string data. */
    private function tinyPng(): string
    {
        $img = imagecreatetruecolor(4, 4);
        imagefill($img, 0, 0, imagecolorallocate($img, 120, 60, 200));
        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);
        return $data;
    }

    /**
     * Minimal valid JPEG bytes for a solid-colour 2x2 image, built via GD so
     * the test has no binary fixture file to maintain.
     */
    private function solidImageJpeg(int $r, int $g, int $b): string
    {
        $img = imagecreatetruecolor(2, 2);
        $color = imagecolorallocate($img, $r, $g, $b);
        imagefill($img, 0, 0, $color);
        ob_start();
        imagejpeg($img, null, 100);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    // =========================================================================
    // Prompt / URL builders — pure functions
    // =========================================================================

    public static function promptStrippingProvider(): array
    {
        return [
            'plain name' => ['wooden chair', 'wooden chair'],
            'strips CRITICAL: marker' => ['CRITICAL: wooden chair', ' wooden chair'],
            'strips Draw only marker' => ['Draw only a red bicycle', ' a red bicycle'],
            'strips both markers' => ['CRITICAL: Draw only a lamp', '  a lamp'],
        ];
    }

    #[DataProvider('promptStrippingProvider')]
    public function test_build_message_prompt_strips_control_markers(string $input, string $expectedClean): void
    {
        $prompt = $this->service->buildMessagePrompt($input);
        $this->assertStringContainsString("single isolated {$expectedClean} centered on plain dark green background", $prompt);
        $this->assertStringContainsString('UK audience', $prompt);
    }

    public function test_build_message_prompt_embeds_item_name(): void
    {
        $prompt = $this->service->buildMessagePrompt('a red bicycle');

        $this->assertStringContainsString('a red bicycle', $prompt);
        $this->assertStringContainsString('dark green background', $prompt);
        $this->assertStringContainsString('single object only.', $prompt);
    }

    #[DataProvider('promptStrippingProvider')]
    public function test_build_job_prompt_strips_control_markers(string $input, string $expectedClean): void
    {
        $prompt = $this->service->buildJobPrompt($input);
        $this->assertStringContainsString("single isolated {$expectedClean} centered on plain dark green background", $prompt);
        $this->assertStringContainsString('Square format', $prompt);
    }

    public function test_build_job_prompt_embeds_object_name(): void
    {
        $prompt = $this->service->buildJobPrompt('a blue kettle');

        $this->assertStringContainsString('a blue kettle', $prompt);
        $this->assertStringContainsString('Square format.', $prompt);
    }

    public function test_build_image_url_encodes_prompt_and_dimensions(): void
    {
        $url = $this->service->buildImageUrl('a red & blue chair', 640, 480);

        $this->assertStringStartsWith('https://image.pollinations.ai/prompt/', $url);
        $this->assertStringContainsString(rawurlencode('a red & blue chair'), $url);
        $this->assertStringContainsString('width=640', $url);
        $this->assertStringContainsString('height=480', $url);
        $this->assertStringContainsString('nologo=true&model=flux', $url);
        $this->assertMatchesRegularExpression('/seed=\d+/', $url);
    }

    public function test_build_image_url_varies_seed_between_calls(): void
    {
        // Not a strict guarantee (rand() could coincide), but with a 1..999999
        // range across two calls a collision is astronomically unlikely and
        // this is what actually distinguishes otherwise-identical requests.
        $url1 = $this->service->buildImageUrl('same prompt', 100, 100);
        $url2 = $this->service->buildImageUrl('same prompt', 100, 100);

        preg_match('/seed=(\d+)/', $url1, $m1);
        preg_match('/seed=(\d+)/', $url2, $m2);

        $this->assertNotSame($m1[1], $m2[1]);
    }

    // =========================================================================
    // fetchBatch — the only branch reachable without real network I/O
    // =========================================================================

    public function test_fetch_batch_returns_empty_immediately_for_no_items(): void
    {
        $result = $this->service->fetchBatch([]);
        $this->assertSame(['results' => [], 'failed' => []], $result);
    }

    // =========================================================================
    // checkForPeople — only the "no API key configured" guard is reachable
    // without a real OpenAI call.
    // =========================================================================

    public function test_check_for_people_returns_null_when_no_api_key_configured(): void
    {
        config(['services.openai.key' => null]);
        $this->assertNull($this->service->checkForPeople('irrelevant image bytes', 'test item'));
    }

    // =========================================================================
    // Failure cache — shouldSkipItem() / recordFailure()
    // =========================================================================

    public function test_should_skip_item_is_false_with_no_cache_file(): void
    {
        $this->assertFalse($this->service->shouldSkipItem('never seen before'));
    }

    public function test_record_failure_returns_false_until_max_failures_reached(): void
    {
        $this->assertFalse($this->service->recordFailure('flaky item'));
        $this->assertFalse($this->service->shouldSkipItem('flaky item'));

        $this->assertFalse($this->service->recordFailure('flaky item'));
        $this->assertFalse($this->service->shouldSkipItem('flaky item'));

        // Third failure reaches MAX_FAILURES (3) — now it should be skipped.
        $this->assertTrue($this->service->recordFailure('flaky item'));
        $this->assertTrue($this->service->shouldSkipItem('flaky item'));
    }

    public function test_record_failure_logs_only_once_threshold_is_reached(): void
    {
        Log::shouldReceive('info')
            ->once()
            ->withArgs(function (string $message) {
                return str_contains($message, "'threshold-item'")
                    && str_contains($message, 'failed 3 times');
            });

        $this->service->recordFailure('threshold-item');
        $this->service->recordFailure('threshold-item');
        $this->service->recordFailure('threshold-item');
    }

    public function test_record_failure_tracks_separate_items_independently(): void
    {
        $this->service->recordFailure('item-a');
        $this->service->recordFailure('item-a');
        $this->service->recordFailure('item-a');

        // item-b has never failed, so it must not be affected by item-a's count.
        $this->assertFalse($this->service->shouldSkipItem('item-b'));
        $this->assertTrue($this->service->shouldSkipItem('item-a'));
    }

    public function test_expired_failure_entries_are_not_counted(): void
    {
        // Write a failure cache entry whose timestamp is outside the 1-day expiry.
        file_put_contents($this->failedCacheFile, json_encode([
            'stale item' => ['count' => 5, 'timestamp' => time() - 172800], // 2 days ago
        ]));

        $this->assertFalse(
            $this->service->shouldSkipItem('stale item'),
            'an expired failure record must not count towards the skip threshold'
        );
    }

    public function test_should_skip_item_false_for_corrupt_cache_file(): void
    {
        file_put_contents($this->failedCacheFile, 'not valid json {{{');
        $this->assertFalse($this->service->shouldSkipItem('anything'));
    }

    // =========================================================================
    // cacheImage — plain DB upsert
    // =========================================================================

    public function test_cache_image_upserts_ai_images_row(): void
    {
        $this->service->cacheImage('my-item', 'freegletusd-abc123', md5('some data'));

        $this->assertDatabaseHas('ai_images', [
            'name' => 'my-item',
            'externaluid' => 'freegletusd-abc123',
            'imagehash' => md5('some data'),
        ]);

        // Upsert on the same name must update, not duplicate.
        $this->service->cacheImage('my-item', 'freegletusd-xyz999', md5('other data'));
        $this->assertSame(1, DB::table('ai_images')->where('name', 'my-item')->count());
        $this->assertDatabaseHas('ai_images', ['name' => 'my-item', 'externaluid' => 'freegletusd-xyz999']);
    }

    public function test_cache_image_inserts_new_row(): void
    {
        $name = 'widget-'.uniqid();

        $this->service->cacheImage($name, 'freegletusd-abc123', 'hash1');

        $row = DB::table('ai_images')->where('name', $name)->first();
        $this->assertNotNull($row);
        $this->assertSame('freegletusd-abc123', $row->externaluid);
        $this->assertSame('hash1', $row->imagehash);
    }

    // =========================================================================
    // applyDuotoneGreen — real GD image manipulation
    // =========================================================================

    public function test_apply_duotone_green_returns_valid_jpeg_for_valid_image(): void
    {
        $result = $this->service->applyDuotoneGreen($this->tinyPng());

        $this->assertNotFalse($result);
        // JPEG magic bytes.
        $this->assertSame("\xFF\xD8", substr($result, 0, 2));
    }

    public function test_apply_duotone_green_returns_false_for_invalid_image_data(): void
    {
        $this->assertFalse($this->service->applyDuotoneGreen('not an image, just garbage bytes'));
    }

    public function test_apply_duotone_green_maps_black_towards_dark_green(): void
    {
        $black = $this->solidImageJpeg(0, 0, 0);

        $result = $this->service->applyDuotoneGreen($black);

        $this->assertIsString($result);
        $img = imagecreatefromstring($result);
        $this->assertNotFalse($img);
        $rgb = imagecolorat($img, 0, 0);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        imagedestroy($img);

        // Black (gray=0, t=0) maps to (13, 51, 17) exactly; allow slack for
        // JPEG recompression at quality 90.
        $this->assertEqualsWithDelta(13, $r, 15);
        $this->assertEqualsWithDelta(51, $g, 15);
        $this->assertEqualsWithDelta(17, $b, 15);
    }

    public function test_apply_duotone_green_maps_white_towards_white(): void
    {
        $white = $this->solidImageJpeg(255, 255, 255);

        $result = $this->service->applyDuotoneGreen($white);

        $this->assertIsString($result);
        $img = imagecreatefromstring($result);
        $this->assertNotFalse($img);
        $rgb = imagecolorat($img, 0, 0);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        imagedestroy($img);

        // White (gray=255, t=1) maps to (255, 255, 255) exactly.
        $this->assertEqualsWithDelta(255, $r, 5);
        $this->assertEqualsWithDelta(255, $g, 5);
        $this->assertEqualsWithDelta(255, $b, 5);
    }

    // =========================================================================
    // uploadImageAndCache — composes duotone + TusService::upload + cacheImage
    // =========================================================================

    public function test_upload_image_and_cache_returns_null_when_duotone_fails(): void
    {
        $result = $this->service->uploadImageAndCache('bad-item', 'garbage', md5('garbage'));

        $this->assertNull($result);
        $this->assertDatabaseMissing('ai_images', ['name' => 'bad-item']);
    }

    public function test_upload_image_and_cache_returns_null_when_tus_upload_fails(): void
    {
        TusService::fake([
            ['status' => 500],
        ]);

        $result = $this->service->uploadImageAndCache('tus-fail-item', $this->tinyPng(), md5('hash-a'));

        $this->assertNull($result);
        $this->assertDatabaseMissing('ai_images', ['name' => 'tus-fail-item']);
    }

    public function test_upload_image_and_cache_succeeds_and_caches(): void
    {
        TusService::fake([
            ['status' => 201, 'headers' => ['Location' => 'https://test-tus-server.example.com/files/img42']],
            ['status' => 200, 'headers' => ['Upload-Offset' => '0']],
            ['status' => 204],
        ]);

        $hash = md5('hash-b');
        $result = $this->service->uploadImageAndCache('good-item', $this->tinyPng(), $hash);

        $this->assertSame('freegletusd-img42', $result);
        $this->assertDatabaseHas('ai_images', [
            'name' => 'good-item',
            'externaluid' => 'freegletusd-img42',
            'imagehash' => $hash,
        ]);
    }
}

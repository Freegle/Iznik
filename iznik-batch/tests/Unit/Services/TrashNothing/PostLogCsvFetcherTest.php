<?php

namespace Tests\Unit\Services\TrashNothing;

use App\Services\TrashNothing\Sync\PostLogCsvFetcher;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The post-log CSV cache.
 *
 * The file is ~15MB and TN's origin has been seen serving it at ~13KB/s, so whether a run
 * re-downloads it is the difference between a parity check that starts now and one that
 * starts in twenty minutes. The caching decision is the behaviour worth pinning: reuse by
 * default, download only when asked, and never cache a truncated file.
 */
class PostLogCsvFetcherTest extends TestCase
{
    private const CSV = "Body,Date,From\n\"item\",\"2026-07-07T12:00:00+00:00\",\"a@user.trashnothing.com\"\n";

    private PostLogCsvFetcher $fetcher;

    /** Contents of a real cached CSV, if the container had one, so a test run cannot cost 15MB. */
    private ?string $savedCache = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fetcher = new PostLogCsvFetcher();

        if (is_file($this->fetcher->cachePath())) {
            $this->savedCache = (string) file_get_contents($this->fetcher->cachePath());
        }
    }

    protected function tearDown(): void
    {
        $path = $this->fetcher->cachePath();

        if ($this->savedCache !== null) {
            file_put_contents($path, $this->savedCache);
        } elseif (is_file($path)) {
            @unlink($path);
        }

        parent::tearDown();
    }

    public function test_a_cached_copy_is_reused_without_touching_the_network(): void
    {
        Http::fake();
        $this->writeCache(self::CSV);

        $this->assertTrue($this->fetcher->isCached());
        $this->assertSame(self::CSV, $this->fetcher->fetch());

        Http::assertNothingSent();
    }

    /**
     * An empty file is not a cache hit. Left as one it would be reused for ever, and every
     * run over it would report that TN posted nothing.
     */
    public function test_an_empty_cache_file_does_not_count_as_cached(): void
    {
        $this->writeCache('');

        $this->assertFalse($this->fetcher->isCached());
    }

    public function test_it_downloads_and_caches_when_there_is_nothing_cached(): void
    {
        $this->clearCache();
        Http::fake(['*fd-post-log.csv*' => Http::response(self::CSV, 200)]);

        $this->assertSame(self::CSV, $this->fetcher->fetch());
        $this->assertSame(self::CSV, file_get_contents($this->fetcher->cachePath()));
    }

    public function test_refresh_replaces_a_cached_copy(): void
    {
        $this->writeCache("stale\n");
        Http::fake(['*fd-post-log.csv*' => Http::response(self::CSV, 200)]);

        $this->assertSame(self::CSV, $this->fetcher->fetch(forceRefresh: true));
        $this->assertSame(self::CSV, file_get_contents($this->fetcher->cachePath()));
    }

    /**
     * The URL carries a random suffix so a proxy or CDN cannot answer with yesterday's file -
     * which would look exactly like TN having published nothing since.
     */
    public function test_each_download_defeats_an_intermediate_cache(): void
    {
        $this->clearCache();
        Http::fake(['*fd-post-log.csv*' => Http::response(self::CSV, 200)]);

        $this->fetcher->fetch(forceRefresh: true);

        Http::assertSent(function ($request) {
            return (bool) preg_match('/fd-post-log\.csv\?_=[0-9a-f]{16}$/', $request->url());
        });
    }

    /**
     * One attempt, exercised directly rather than through fetch(): the retry loop sleeps 15
     * seconds between attempts, so driving a failure through it would cost a minute of wall
     * clock to prove something this states in a second.
     *
     * The contract is that an attempt never throws. It is called in a loop whose whole point
     * is to survive a flaky origin, so an exception escaping here would abandon the remaining
     * attempts and lose a download that the next try would have completed.
     */
    public function test_a_failed_attempt_reports_false_rather_than_throwing(): void
    {
        $tmp = sys_get_temp_dir().'/tn-csv-attempt-'.uniqid('', true);

        Http::fake(['*fd-post-log.csv*' => Http::response('', 503)]);
        $this->assertFalse($this->attemptDownload($tmp), 'an error response is not a download');

        Http::fake(['*fd-post-log.csv*' => fn () => throw new \RuntimeException('connection reset')]);
        $this->assertFalse($this->attemptDownload($tmp), 'a transport error must be swallowed and retried');

        @unlink($tmp);
    }

    /**
     * A 200 that delivered nothing is not a download. Treating it as one would cache an empty
     * file, and every later run would reuse it and report that TN published nothing.
     */
    public function test_an_empty_body_is_not_a_successful_attempt(): void
    {
        $tmp = sys_get_temp_dir().'/tn-csv-empty-'.uniqid('', true);

        Http::fake(['*fd-post-log.csv*' => Http::response('', 200)]);

        $this->assertFalse($this->attemptDownload($tmp));

        @unlink($tmp);
    }

    private function attemptDownload(string $tmpPath): bool
    {
        $method = new \ReflectionMethod(PostLogCsvFetcher::class, 'attemptDownload');
        $method->setAccessible(true);

        return $method->invoke($this->fetcher, $tmpPath, 1);
    }

    private function writeCache(string $contents): void
    {
        $path = $this->fetcher->cachePath();
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }
        file_put_contents($path, $contents);
    }

    private function clearCache(): void
    {
        if (is_file($this->fetcher->cachePath())) {
            @unlink($this->fetcher->cachePath());
        }
    }
}

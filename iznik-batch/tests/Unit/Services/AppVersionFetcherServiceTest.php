<?php

namespace Tests\Unit\Services;

use App\Services\AppVersionFetcherService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppVersionFetcherServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('config')->whereIn('key', [
            'app_fd_version_ios_latest', 'app_fd_version_ios_date',
            'app_mt_version_ios_latest', 'app_mt_version_ios_date',
            'app_fd_version_android_latest', 'app_fd_version_android_date',
            'app_mt_version_android_latest', 'app_mt_version_android_date',
        ])->delete();
    }

    private function fakeItunesResponse(string $version, string $date): array
    {
        return [
            'resultCount' => 1,
            'results' => [
                [
                    'version' => $version,
                    'currentVersionReleaseDate' => $date,
                ],
            ],
        ];
    }

    private function fakeAndroidHtml(string $version, string $date): string
    {
        return "AF_initDataCallback({key: 'ds:5', isError: false, hash: '1', data: [null, [[null, [{$version}]], null, null, null, null, null, null, null, null, null, null, null, null, null, null, [{$date}]]]]}); something";
    }

    public function test_fetches_ios_versions_and_stores_in_config(): void
    {
        Http::fake([
            'itunes.apple.com/*iphone*' => Http::response(json_encode($this->fakeItunesResponse('3.1.4', '2024-03-01T09:00:00Z')), 200),
            'itunes.apple.com/*modtools*' => Http::response(json_encode($this->fakeItunesResponse('2.7.1', '2024-02-15T12:00:00Z')), 200),
            'play.google.com/*direct*' => Http::response($this->fakeAndroidHtml('3.1.4', 'Mar 1, 2024'), 200),
            'play.google.com/*modtools*' => Http::response($this->fakeAndroidHtml('2.7.1', 'Feb 15, 2024'), 200),
        ]);

        $service = new AppVersionFetcherService();
        $result = $service->fetchAll();

        $this->assertContains('ios_fd', $result['fetched']);
        $this->assertContains('ios_mt', $result['fetched']);

        $this->assertEquals('3.1.4', DB::table('config')->where('key', 'app_fd_version_ios_latest')->value('value'));
        $this->assertEquals('2024-03-01T09:00:00Z', DB::table('config')->where('key', 'app_fd_version_ios_date')->value('value'));
        $this->assertEquals('2.7.1', DB::table('config')->where('key', 'app_mt_version_ios_latest')->value('value'));
        $this->assertEquals('2024-02-15T12:00:00Z', DB::table('config')->where('key', 'app_mt_version_ios_date')->value('value'));
    }

    public function test_fetches_android_versions_and_stores_in_config(): void
    {
        Http::fake([
            'itunes.apple.com/*iphone*' => Http::response(json_encode(['resultCount' => 0, 'results' => []]), 200),
            'itunes.apple.com/*modtools*' => Http::response(json_encode(['resultCount' => 0, 'results' => []]), 200),
            'play.google.com/*direct*' => Http::response($this->fakeAndroidHtml('5.2.0', 'Apr 10, 2024'), 200),
            'play.google.com/*modtools*' => Http::response($this->fakeAndroidHtml('4.1.3', 'Apr 5, 2024'), 200),
        ]);

        $service = new AppVersionFetcherService();
        $result = $service->fetchAll();

        $this->assertContains('android_fd', $result['fetched']);
        $this->assertContains('android_mt', $result['fetched']);

        $this->assertEquals('5.2.0', DB::table('config')->where('key', 'app_fd_version_android_latest')->value('value'));
        $this->assertEquals('Apr 10, 2024', DB::table('config')->where('key', 'app_fd_version_android_date')->value('value'));
        $this->assertEquals('4.1.3', DB::table('config')->where('key', 'app_mt_version_android_latest')->value('value'));
        $this->assertEquals('Apr 5, 2024', DB::table('config')->where('key', 'app_mt_version_android_date')->value('value'));
    }

    public function test_partial_failure_stores_successful_and_reports_failed(): void
    {
        Http::fake([
            'itunes.apple.com/*iphone*' => Http::response(json_encode($this->fakeItunesResponse('3.1.4', '2024-03-01T09:00:00Z')), 200),
            'itunes.apple.com/*modtools*' => Http::response('error', 500),
            'play.google.com/*direct*' => Http::response('no af_initdatacallback here', 200),
            'play.google.com/*modtools*' => Http::response($this->fakeAndroidHtml('4.1.3', 'Apr 5, 2024'), 200),
        ]);

        $service = new AppVersionFetcherService();
        $result = $service->fetchAll();

        $this->assertContains('ios_fd', $result['fetched']);
        $this->assertContains('ios_mt', $result['failed']);
        $this->assertContains('android_fd', $result['failed']);
        $this->assertContains('android_mt', $result['fetched']);

        $this->assertEquals('3.1.4', DB::table('config')->where('key', 'app_fd_version_ios_latest')->value('value'));
        $this->assertNull(DB::table('config')->where('key', 'app_mt_version_ios_latest')->value('value'));
    }

    public function test_upserts_existing_config_values(): void
    {
        DB::table('config')->insert(['key' => 'app_fd_version_ios_latest', 'value' => '1.0.0']);

        Http::fake([
            'itunes.apple.com/*iphone*' => Http::response(json_encode($this->fakeItunesResponse('3.2.0', '2024-04-01T00:00:00Z')), 200),
            'itunes.apple.com/*modtools*' => Http::response(json_encode(['resultCount' => 0, 'results' => []]), 200),
            'play.google.com/*' => Http::response('no version here', 200),
        ]);

        $service = new AppVersionFetcherService();
        $service->fetchAll();

        $this->assertEquals('3.2.0', DB::table('config')->where('key', 'app_fd_version_ios_latest')->value('value'));
        $this->assertEquals(1, DB::table('config')->where('key', 'app_fd_version_ios_latest')->count());
    }
}

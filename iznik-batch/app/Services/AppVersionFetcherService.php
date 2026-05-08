<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AppVersionFetcherService
{
    private const IOS_BUNDLES = [
        'fd' => 'org.ilovefreegle.iphone',
        'mt' => 'org.ilovefreegle.modtools',
    ];

    private const ANDROID_PACKAGES = [
        'fd' => 'org.ilovefreegle.direct',
        'mt' => 'org.ilovefreegle.modtools',
    ];

    private const IOS_STORE_REGION = [
        'fd' => 'gb',
        'mt' => 'us',
    ];

    public function fetchAll(bool $dryRun = false): array
    {
        $fetched = [];
        $failed = [];
        $writes = [];

        foreach (self::IOS_BUNDLES as $app => $bundleId) {
            $key = "ios_{$app}";
            $region = self::IOS_STORE_REGION[$app];
            $result = $this->fetchIosVersion($bundleId, $region);
            if ($result) {
                $writes["app_{$app}_version_ios_latest"] = $result['version'];
                $writes["app_{$app}_version_ios_date"] = $result['date'];
                if (!$dryRun) {
                    $this->storeConfig("app_{$app}_version_ios_latest", $result['version']);
                    $this->storeConfig("app_{$app}_version_ios_date", $result['date']);
                }
                $fetched[] = $key;
                Log::info("AppVersionFetcher: iOS {$app} = {$result['version']} ({$result['date']})");
            } else {
                $failed[] = $key;
                Log::warning("AppVersionFetcher: iOS {$app} fetch failed");
            }
        }

        foreach (self::ANDROID_PACKAGES as $app => $packageId) {
            $key = "android_{$app}";
            $result = $this->fetchAndroidVersion($packageId);
            if ($result) {
                $writes["app_{$app}_version_android_latest"] = $result['version'];
                $writes["app_{$app}_version_android_date"] = $result['date'];
                if (!$dryRun) {
                    $this->storeConfig("app_{$app}_version_android_latest", $result['version']);
                    $this->storeConfig("app_{$app}_version_android_date", $result['date']);
                }
                $fetched[] = $key;
                Log::info("AppVersionFetcher: Android {$app} = {$result['version']} ({$result['date']})");
            } else {
                $failed[] = $key;
                Log::warning("AppVersionFetcher: Android {$app} fetch failed");
            }
        }

        return ['fetched' => $fetched, 'failed' => $failed, 'writes' => $writes];
    }

    private function fetchIosVersion(string $bundleId, string $region = 'us'): ?array
    {
        try {
            $url = "https://itunes.apple.com/{$region}/lookup?bundleId={$bundleId}";
            $response = Http::timeout(30)->get($url);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            if (($data['resultCount'] ?? 0) !== 1) {
                return null;
            }

            $result = $data['results'][0];
            return [
                'version' => $result['version'],
                'date' => $result['currentVersionReleaseDate'],
            ];
        } catch (\Exception $e) {
            Log::error("AppVersionFetcher: iOS fetch exception", ['error' => $e->getMessage(), 'bundleId' => $bundleId]);
            return null;
        }
    }

    private function fetchAndroidVersion(string $packageId): ?array
    {
        try {
            $url = "https://play.google.com/store/apps/details?id={$packageId}&hl=en";
            $response = Http::timeout(30)->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (compatible; Freegle/1.0)',
            ])->get($url);

            if (!$response->successful()) {
                return null;
            }

            $html = $response->body();

            if (!preg_match("/AF_initDataCallback\(\{key: 'ds:5'.*?}\);/s", $html, $ds5Match)) {
                return null;
            }

            $ds5Data = $ds5Match[0];
            $version = null;
            $date = null;

            if (preg_match('/\d+\.\d+\.\d+/', $ds5Data, $verMatch)) {
                $version = $verMatch[0];
            }

            if (preg_match_all('/(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) \d+, \d{4}/', $ds5Data, $dateMatches)) {
                $date = end($dateMatches[0]);
            }

            if (!$version || !$date) {
                return null;
            }

            return ['version' => $version, 'date' => $date];
        } catch (\Exception $e) {
            Log::error("AppVersionFetcher: Android fetch exception", ['error' => $e->getMessage(), 'packageId' => $packageId]);
            return null;
        }
    }

    private function storeConfig(string $key, string $value): void
    {
        DB::table('config')->upsert(
            ['key' => $key, 'value' => $value],
            ['key'],
            ['value']
        );
    }
}

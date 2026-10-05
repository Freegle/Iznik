<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PollinationsService
{
    private const MAX_FAILURES = 3;
    private const FAILED_CACHE_FILE = '/tmp/pollinations_failed.json';
    private const HASH_CACHE_FILE = '/tmp/pollinations_hashes.json';
    private const CACHE_EXPIRY = 86400;
    private const FAILED_CACHE_EXPIRY = 86400;
    private const OPENAI_API_URL = 'https://api.openai.com/v1/chat/completions';

    /** @var array<string,string> Hash => name seen in this process */
    private array $seenHashes = [];

    public function __construct(private TusService $tus) {}

    public function buildMessagePrompt(string $itemName): string
    {
        $clean = str_replace(['CRITICAL:', 'Draw only'], '', $itemName);

        return "Product illustration: single isolated {$clean} centered on plain dark green background. " .
               "Style: friendly cartoon white line drawing, moderate shading, cute and quirky, UK audience. " .
               "The object sits alone on a simple surface or floats in space. " .
               "Simple illustration style, clean lines, single object only.";
    }

    public function buildJobPrompt(string $objectName): string
    {
        $clean = str_replace(['CRITICAL:', 'Draw only'], '', $objectName);

        return "Product illustration: single isolated {$clean} centered on plain dark green background. " .
               "Style: friendly cartoon white line drawing, moderate shading, cute and quirky. " .
               "Simple illustration style, clean lines, single object only. Square format.";
    }

    public function shouldSkipItem(string $name): bool
    {
        $cache = $this->loadFailedCache();

        return isset($cache[$name]) && $cache[$name]['count'] >= self::MAX_FAILURES;
    }

    public function recordFailure(string $name): bool
    {
        $cache = $this->loadFailedCache();

        if (! isset($cache[$name])) {
            $cache[$name] = ['count' => 0, 'timestamp' => time()];
        }

        $cache[$name]['count']++;
        $cache[$name]['timestamp'] = time();
        $this->saveFailedCache($cache);

        $shouldSkip = $cache[$name]['count'] >= self::MAX_FAILURES;
        if ($shouldSkip) {
            Log::info("PollinationsService: item '{$name}' has failed {$cache[$name]['count']} times, skipping for 1 day");
        }

        return $shouldSkip;
    }

    public function cacheImage(string $name, string $uid, string $hash): void
    {
        DB::table('ai_images')->upsert(
            ['name' => $name, 'externaluid' => $uid, 'imagehash' => $hash],
            ['name'],
            ['externaluid', 'imagehash']
        );
    }

    /**
     * Apply green duotone filter to raw image data using GD.
     * Dark green (#0D3311) → white (#FFFFFF).
     *
     * @return string|false Processed JPEG data, or false on failure.
     */
    public function applyDuotoneGreen(string $imageData): string|false
    {
        $img = @imagecreatefromstring($imageData);
        if (! $img) {
            return false;
        }

        $width = imagesx($img);
        $height = imagesy($img);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;

                $gray = intval(0.299 * $r + 0.587 * $g + 0.114 * $b);
                $t = $gray / 255.0;

                $nr = intval(13 + $t * (255 - 13));
                $ng = intval(51 + $t * (255 - 51));
                $nb = intval(17 + $t * (255 - 17));

                $color = imagecolorallocate($img, $nr, $ng, $nb);
                imagesetpixel($img, $x, $y, $color);
            }
        }

        ob_start();
        imagejpeg($img, null, 90);
        $output = ob_get_clean();
        imagedestroy($img);

        return $output ?: false;
    }

    /**
     * Apply duotone, upload to TUS, cache in ai_images, and return the uid.
     *
     * @return string|null The externaluid (e.g. 'freegletusd-abc123'), or null on failure.
     */
    public function uploadImageAndCache(string $name, string $imageData, string $hash): ?string
    {
        $processed = $this->applyDuotoneGreen($imageData);
        if (! $processed) {
            Log::warning("PollinationsService: failed to apply duotone for '{$name}'");
            return null;
        }

        $url = $this->tus->upload($processed, 'image/jpeg');
        if (! $url) {
            Log::warning("PollinationsService: TUS upload failed for '{$name}'");
            return null;
        }

        $uid = 'freegletusd-'.basename($url);
        $this->cacheImage($name, $uid, $hash);

        return $uid;
    }

    /**
     * Generate a batch of images with Cloudflare Workers AI (Flux Schnell), the same model and
     * request the Go API uses (iznik-server-go/aiimage). Pollinations' anonymous tier refused
     * 40-45% of requests with HTTP 402, so posts went without a picture.
     *
     * @param  array  $items  Each: ['name'=>string, 'prompt'=>string, 'msgid'=>?int]. Flux Schnell
     *                        always returns 1024x1024; any width/height in the item is ignored.
     * @param  int  $timeout  HTTP timeout in seconds
     * @return array{results: array, failed: array}|false False on rate-limit; otherwise results+failed arrays.
     */
    public function fetchBatch(array $items, int $timeout = 120): array|false
    {
        if (empty($items)) {
            return ['results' => [], 'failed' => []];
        }

        $results = [];
        $failed = [];
        $batchHashes = [];

        foreach ($items as $item) {
            $name = $item['name'];

            Log::debug("PollinationsService: generating image for '{$name}' (Cloudflare)");
            $data = $this->generateImage($item['prompt'], $name, $timeout);

            if ($data === false) {
                return false;
            }
            if ($data === null) {
                $failed[$name] = true;
                continue;
            }

            $hash = md5($data);

            if (isset($batchHashes[$hash]) && $batchHashes[$hash] !== $name) {
                Log::warning("PollinationsService: rate limited (duplicate hash) for '{$name}'");
                $this->cleanupRateLimitedHash($hash);
                return false;
            }

            $existingPrompt = $this->checkHashCache($hash, $name);
            if ($existingPrompt !== false) {
                Log::warning("PollinationsService: rate limited (hash cache) for '{$name}'");
                $this->cleanupRateLimitedHash($hash);
                return false;
            }

            $dbDuplicate = DB::table('ai_images')
                ->where('imagehash', $hash)
                ->where('name', '!=', $name)
                ->value('name');

            if ($dbDuplicate) {
                Log::warning("PollinationsService: rate limited (DB hash) for '{$name}'");
                $this->addToHashCache($hash, $dbDuplicate);
                $this->cleanupRateLimitedHash($hash);
                return false;
            }

            $hasPeople = $this->checkForPeople($data, $name);
            if ($hasPeople === true) {
                Log::info("PollinationsService: image rejected for '{$name}' — contains people");
                $failed[$name] = true;
                continue;
            }

            $batchHashes[$hash] = $name;
            $results[] = [
                'name' => $name,
                'data' => $data,
                'hash' => $hash,
                'msgid' => $item['msgid'] ?? null,
            ];

            sleep(1);
        }

        foreach ($results as $result) {
            $this->seenHashes[$result['hash']] = $result['name'];
            $this->addToHashCache($result['hash'], $result['name']);
        }

        return ['results' => $results, 'failed' => $failed];
    }

    /**
     * One Flux Schnell image from Cloudflare Workers AI.
     *
     * Flux Schnell's input schema is closed: it takes only prompt, steps (max 8) and seed, and
     * rejects width/height. The response is a JSON envelope with a base64 image.
     *
     * @return string|false|null Image bytes; false when rate limited (stop the batch); null when
     *                           this item failed (content refused, error, or not configured).
     */
    public function generateImage(string $prompt, string $name, int $timeout = 120): string|false|null
    {
        $account = config('freegle.cloudflare_ai.account_id');
        $token = config('freegle.cloudflare_ai.token');
        if (! $account || ! $token) {
            Log::error('PollinationsService: CLOUDFLARE_ACCOUNT_ID and CLOUDFLARE_AI_TOKEN must be set');

            return null;
        }

        $url = rtrim(config('freegle.cloudflare_ai.base', 'https://api.cloudflare.com'), '/') .
            "/client/v4/accounts/{$account}/ai/run/@cf/black-forest-labs/flux-1-schnell";

        try {
            $response = Http::withToken($token)
                ->timeout($timeout)
                ->post($url, ['prompt' => $prompt, 'steps' => 8, 'seed' => random_int(1, 999999)]);
        } catch (\Throwable $e) {
            Log::warning("PollinationsService: Cloudflare request failed for '{$name}': " . $e->getMessage());

            return null;
        }

        if ($response->status() === 429) {
            Log::warning("PollinationsService: rate limited (HTTP 429) for '{$name}'");

            return false;
        }

        if (! $response->successful()) {
            // Cloudflare's content filter refuses some item names outright (code 8007, "NSFW").
            // That needs a different description, so it is a per-item failure, not an outage.
            Log::warning("PollinationsService: HTTP {$response->status()} for '{$name}': " .
                substr($response->body(), 0, 200));

            return null;
        }

        $image = $response->json('result.image');
        if (! is_string($image) || $image === '') {
            Log::warning("PollinationsService: no image in Cloudflare response for '{$name}'");

            return null;
        }

        $data = base64_decode($image, true);

        return $data === false || $data === '' ? null : $data;
    }

    /**
     * Check if an image contains people using OpenAI vision (optional).
     *
     * @return bool|null TRUE if people detected, FALSE if not, NULL if check unavailable.
     */
    public function checkForPeople(string $imageData, string $itemName = ''): ?bool
    {
        $apiKey = config('services.openai.key', env('OPENAI_API_KEY'));
        if (! $apiKey) {
            return null;
        }

        $base64 = base64_encode($imageData);
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($imageData) ?: 'image/jpeg';
        $dataUrl = "data:{$mimeType};base64,{$base64}";

        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => 'Does this image contain any people, human figures, hands, arms, legs, or body parts? Answer only YES or NO.'],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'low']],
                ],
            ]],
            'max_tokens' => 10,
        ];

        $ch = curl_init(self::OPENAI_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer {$apiKey}",
            ],
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || ! $response) {
            return null;
        }

        $data = json_decode($response, true);
        if (! isset($data['choices'][0]['message']['content'])) {
            return null;
        }

        $answer = strtoupper(trim($data['choices'][0]['message']['content']));

        return strpos($answer, 'YES') !== false;
    }

    private function loadFailedCache(): array
    {
        if (! file_exists(self::FAILED_CACHE_FILE)) {
            return [];
        }

        $data = @file_get_contents(self::FAILED_CACHE_FILE);
        if (! $data) {
            return [];
        }

        $cache = @json_decode($data, true);
        if (! is_array($cache)) {
            return [];
        }

        $now = time();

        return array_filter($cache, fn ($e) => isset($e['timestamp']) && ($now - $e['timestamp']) < self::FAILED_CACHE_EXPIRY);
    }

    private function saveFailedCache(array $cache): void
    {
        $fp = @fopen(self::FAILED_CACHE_FILE, 'c');
        if (! $fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($cache));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private function loadHashCache(): array
    {
        if (! file_exists(self::HASH_CACHE_FILE)) {
            return [];
        }

        $data = @file_get_contents(self::HASH_CACHE_FILE);
        if (! $data) {
            return [];
        }

        $cache = @json_decode($data, true);
        if (! is_array($cache)) {
            return [];
        }

        $now = time();

        return array_filter($cache, fn ($e) => isset($e['timestamp']) && ($now - $e['timestamp']) < self::CACHE_EXPIRY);
    }

    private function saveHashCache(array $cache): void
    {
        $fp = @fopen(self::HASH_CACHE_FILE, 'c');
        if (! $fp) {
            return;
        }
        if (flock($fp, LOCK_EX)) {
            ftruncate($fp, 0);
            fwrite($fp, json_encode($cache));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
        fclose($fp);
    }

    private function checkHashCache(string $hash, string $name): string|false
    {
        $cache = $this->loadHashCache();
        if (isset($cache[$hash]) && $cache[$hash]['name'] !== $name) {
            return $cache[$hash]['name'];
        }

        return false;
    }

    private function addToHashCache(string $hash, string $name): void
    {
        $cache = $this->loadHashCache();
        $cache[$hash] = ['name' => $name, 'timestamp' => time()];
        $this->saveHashCache($cache);
    }

    private function cleanupRateLimitedHash(string $hash): void
    {
        // Remove from DB any recently added entries with this hash.
        DB::table('ai_images')
            ->where('imagehash', $hash)
            ->where('created', '>=', now()->subMinutes(5))
            ->delete();
    }
}

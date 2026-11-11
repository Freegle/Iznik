<?php

namespace App\Services\AzureArchive;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One anonymous GET of one blob from the public Azure container. No listing, no HEAD first, no
 * re-read to verify: the blob's own Content-MD5, which Azure sends with it, is what the bytes are
 * checked against.
 */
class BlobFetcher
{
    /**
     * Bytes counted on top of the body for each request: the request line, the headers both ways.
     * Azure's egress figure includes them, and so does our count.
     */
    public const REQUEST_OVERHEAD_BYTES = 1024;

    public function __construct(
        private readonly string $sourceUrl,
        private readonly int $timeoutSeconds = 60,
    ) {
    }

    public static function fromConfig(): self
    {
        return new self((string) config('freegle.azure_archive.source_url'));
    }

    public function fetch(string $blobName): FetchResult
    {
        $url = rtrim($this->sourceUrl, '/') . '/' . rawurlencode($blobName);

        try {
            $response = Http::withHeaders(['x-ms-version' => '2021-08-06'])
                ->timeout($this->timeoutSeconds)
                ->get($url);
        } catch (ConnectionException $e) {
            // How much arrived before it broke is unknown, so it is counted at the worst case.
            return new FetchResult(0, null, ArchiveLedger::UNKNOWN_FETCH_BYTES, null, null, 'No answer from Azure: ' . $e->getMessage());
        }

        $body = $response->body();
        $md5 = $response->header('Content-MD5');
        $length = $response->header('Content-Length');

        return new FetchResult(
            $response->status(),
            $response->successful() ? $body : null,
            strlen($body) + self::REQUEST_OVERHEAD_BYTES,
            $md5 === '' ? null : $md5,
            $response->header('x-ms-error-code') ?: null,
            null,
            is_numeric($length) ? (int) $length : null,
        );
    }
}

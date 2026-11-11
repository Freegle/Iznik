<?php

namespace App\Services\AzureArchive;

use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * One anonymous GET of one blob from the public Azure container. No listing, no HEAD first, no
 * re-read to verify: the blob's own Content-MD5, which Azure sends with it, is what the bytes are
 * checked against.
 *
 * One curl handler serves every fetch of a run, so the connection to Azure is kept open rather
 * than paying a TLS handshake per blob. Bodies are taken exactly as Azure sends them (no
 * decompression), because the MD5 is of the stored bytes.
 */
class BlobFetcher
{
    /** Bytes counted on top of each body: the request line and headers both ways. */
    public const REQUEST_OVERHEAD_BYTES = 1024;

    /** And this share of the body again, for TCP/IP and TLS framing Azure may bill for. */
    public const FRAMING_SHARE = 0.04;

    private CurlHandler $handler;

    public function __construct(
        private readonly string $sourceUrl,
        private readonly int $timeoutSeconds = 60,
    ) {
        $this->handler = new CurlHandler();
    }

    public static function fromConfig(): self
    {
        return new self((string) config('freegle.azure_archive.source_url'));
    }

    /** What a body of this many bytes is counted as against the egress budget. */
    public static function counted(int $bodyBytes): int
    {
        return (int) ceil($bodyBytes * (1 + self::FRAMING_SHARE)) + self::REQUEST_OVERHEAD_BYTES;
    }

    public function fetch(string $blobName): FetchResult
    {
        $url = rtrim($this->sourceUrl, '/') . '/' . rawurlencode($blobName);

        try {
            $response = Http::setHandler($this->handler)
                ->withOptions(['decode_content' => false])
                // The version header makes Azure name the error (BlobNotFound) in x-ms-error-code.
                ->withHeaders(['x-ms-version' => '2021-08-06'])
                ->timeout($this->timeoutSeconds)
                ->get($url);
        } catch (ConnectionException $e) {
            // How much arrived before it broke is unknown, so it is counted at the worst case.
            return new FetchResult(0, null, ArchiveLedger::UNKNOWN_FETCH_BYTES, null, null, 'No answer from Azure: ' . $e->getMessage());
        }

        $body = $response->body();
        $md5 = $response->header('Content-MD5');
        $length = $response->header('Content-Length');
        $code = $response->header('x-ms-error-code');

        if ($code === '' && ! $response->successful() && preg_match('#<Code>([^<]+)</Code>#', $body, $m)) {
            $code = $m[1];
        }

        return new FetchResult(
            $response->status(),
            $response->successful() ? $body : null,
            self::counted(strlen($body)),
            $md5 === '' ? null : $md5,
            $code === '' ? null : $code,
            null,
            is_numeric($length) ? (int) $length : null,
        );
    }
}

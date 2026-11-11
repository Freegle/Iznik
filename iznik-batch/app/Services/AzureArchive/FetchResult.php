<?php

namespace App\Services\AzureArchive;

final class FetchResult
{
    public function __construct(
        // 0 when Azure did not answer.
        public readonly int $status,
        public readonly ?string $body,
        // What this fetch cost in egress, by our count.
        public readonly int $bytesCounted,
        // Azure's Content-MD5 header, base64.
        public readonly ?string $contentMd5,
        public readonly ?string $errorCode,
        public readonly ?string $error,
        // Azure's Content-Length header.
        public readonly ?int $contentLength = null,
    ) {
    }

    /**
     * No Content-MD5 to check against (a blob written in blocks may have none), but every byte
     * Azure said it would send arrived. Weaker than the hash, which is why it is recorded.
     */
    public function lengthMatched(): bool
    {
        return $this->body !== null && $this->contentLength !== null && $this->contentLength === strlen($this->body);
    }

    /** The bytes match the MD5 Azure stored with the blob. */
    public function verified(): bool
    {
        return $this->body !== null
            && $this->contentMd5 !== null
            && hash_equals($this->contentMd5, base64_encode(md5($this->body, true)));
    }

    /** No answer, a throttle or a server error: Azure's problem, worth one retry later. */
    public function transient(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}

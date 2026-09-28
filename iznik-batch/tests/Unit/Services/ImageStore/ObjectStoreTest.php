<?php

namespace Tests\Unit\Services\ImageStore;

use App\Services\ImageStore\ObjectStore;
use App\Services\ImageStore\ObjectStoreUnavailable;
use Aws\Command;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Filesystem\Filesystem;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;
use PHPUnit\Framework\TestCase;

/**
 * ObjectStore must tell "no such key" from "the store is not answering". The
 * first means copy; the second means stop, because every further object would
 * get the same answer and the callers would otherwise count a dead store as
 * thousands of failed objects while nothing named the cause.
 */
class ObjectStoreTest extends TestCase
{
    private function s3(string $operation, ?int $status): S3Exception
    {
        $context = $status === null ? [] : ['response' => new Response($status)];

        return new S3Exception("{$operation} failed", new Command($operation), $context);
    }

    private function diskWhoseSizeThrows(\Throwable $e): Filesystem
    {
        $disk = $this->createMock(Filesystem::class);
        $disk->method('size')->willThrowException($e);

        return $disk;
    }

    private function diskWhosePutThrows(\Throwable $e): Filesystem
    {
        $disk = $this->createMock(Filesystem::class);
        $disk->method('put')->willThrowException($e);

        return $disk;
    }

    public function test_a_404_is_no_such_key(): void
    {
        $store = new ObjectStore($this->diskWhoseSizeThrows(
            UnableToRetrieveMetadata::fileSize('k', 'gone', $this->s3('HeadObject', 404))
        ));

        $this->assertNull($store->sizeOf('k'));
    }

    public function test_a_403_on_a_read_is_the_store_being_unavailable(): void
    {
        $store = new ObjectStore($this->diskWhoseSizeThrows(
            UnableToRetrieveMetadata::fileSize('k', 'denied', $this->s3('HeadObject', 403))
        ));

        try {
            $store->sizeOf('k');
            $this->fail('expected ObjectStoreUnavailable');
        } catch (ObjectStoreUnavailable $e) {
            $this->assertStringContainsString('HeadObject k got HTTP 403', $e->getMessage());
            $this->assertSame(403, $e->getCode());
        }
    }

    public function test_no_answer_on_a_read_is_the_store_being_unavailable(): void
    {
        $store = new ObjectStore($this->diskWhoseSizeThrows(
            UnableToRetrieveMetadata::fileSize('k', 'cURL error 7', $this->s3('HeadObject', null))
        ));

        $this->expectException(ObjectStoreUnavailable::class);
        $this->expectExceptionMessage('got no answer');

        $store->sizeOf('k');
    }

    public function test_a_5xx_on_a_read_is_the_store_being_unavailable(): void
    {
        $store = new ObjectStore($this->diskWhoseSizeThrows(
            UnableToRetrieveMetadata::fileSize('k', 'slow down', $this->s3('HeadObject', 503))
        ));

        $this->expectException(ObjectStoreUnavailable::class);

        $store->sizeOf('k');
    }

    public function test_a_denied_write_is_the_store_being_unavailable(): void
    {
        $store = new ObjectStore($this->diskWhosePutThrows(
            UnableToWriteFile::atLocation('k', 'AccessDenied', $this->s3('PutObject', 403))
        ));

        $this->expectException(ObjectStoreUnavailable::class);
        $this->expectExceptionMessage('PutObject k got HTTP 403');

        $store->put('k', 'bytes', 'image/jpeg');
    }

    public function test_a_write_the_store_refused_for_this_one_object_is_not_an_outage(): void
    {
        // A 400 is about the request (too large, bad key), not about the
        // store: the caller counts it as one failure and carries on.
        $store = new ObjectStore($this->diskWhosePutThrows(
            UnableToWriteFile::atLocation('k', 'EntityTooLarge', $this->s3('PutObject', 400))
        ));

        $this->expectException(UnableToWriteFile::class);

        $store->put('k', 'bytes', 'image/jpeg');
    }
}

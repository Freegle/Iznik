<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\IncomingArchiveService;
use Tests\TestCase;

/**
 * IncomingArchiveService was covered only by mail tests that happened to archive into
 * the real storage directory, so whether its "create today's directory" line ran
 * depended on whether an earlier run that day had already made it. These tests give
 * it a directory of its own.
 */
class IncomingArchiveServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/incoming-archive-test-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->dir);
        }
        @unlink($this->dir.'-file');
        parent::tearDown();
    }

    private function serviceArchivingTo(string $dir): IncomingArchiveService
    {
        $service = new IncomingArchiveService();
        (new \ReflectionProperty(IncomingArchiveService::class, 'archiveDir'))->setValue($service, $dir);

        return $service;
    }

    public function test_creates_the_day_directory_and_writes_the_record(): void
    {
        $service = $this->serviceArchivingTo($this->dir);

        $path = $service->archive("Subject: hi\r\n\r\nbody", 'from@example.com', 'to@example.com');

        $this->assertNotNull($path);
        $this->assertDirectoryExists($this->dir.'/'.date('Y-m-d'), "today's directory is created when missing");
        $data = json_decode(file_get_contents($path), true);
        $this->assertSame(3, $data['version']);
        $this->assertSame(['from' => 'from@example.com', 'to' => 'to@example.com'], $data['envelope']);
        $this->assertSame("Subject: hi\r\n\r\nbody", base64_decode($data['raw_email']));
    }

    public function test_returns_null_when_the_directory_cannot_be_made(): void
    {
        // A file where the archive directory should be makes mkdir fail.
        file_put_contents($this->dir.'-file', 'not a directory');
        $service = $this->serviceArchivingTo($this->dir.'-file');

        $this->assertNull($service->archive('raw', 'from@example.com', 'to@example.com'));
    }

    public function test_records_the_routing_outcome(): void
    {
        $service = $this->serviceArchivingTo($this->dir);
        $path = $service->archive('raw', 'from@example.com', 'to@example.com');

        $service->recordOutcome($path, 'ToGroup');

        $this->assertSame('ToGroup', json_decode(file_get_contents($path), true)['routing_outcome']);
    }
}

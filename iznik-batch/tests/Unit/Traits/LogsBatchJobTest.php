<?php

namespace Tests\Unit\Traits;

use App\Services\LokiService;
use App\Traits\LogsBatchJob;
use Illuminate\Console\Command;
use Mockery;
use Tests\TestCase;

class LogsBatchJobTest extends TestCase
{
    private $lokiMock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lokiMock = Mockery::mock(LokiService::class);
        $this->app->instance(LokiService::class, $this->lokiMock);
    }

    public function test_logs_job_started_event(): void
    {
        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'started', Mockery::type('array'));

        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'completed', Mockery::type('array'));

        $command = new TestCommand();
        $command->setLaravel($this->app);

        $result = $command->handle();

        $this->assertEquals(Command::SUCCESS, $result);
    }

    public function test_logs_job_completed_with_duration(): void
    {
        $this->lokiMock->shouldReceive('logBatchJob')
            ->with('test:command', 'started', Mockery::any());

        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'completed', Mockery::on(function ($context) {
                return isset($context['duration_seconds'])
                    && is_numeric($context['duration_seconds'])
                    && isset($context['exit_code'])
                    && $context['exit_code'] === Command::SUCCESS;
            }));

        $command = new TestCommand();
        $command->setLaravel($this->app);
        $command->handle();
    }

    public function test_logs_job_failed_on_exception(): void
    {
        $this->lokiMock->shouldReceive('logBatchJob')
            ->with('test:command', 'started', Mockery::any());

        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'failed', Mockery::on(function ($context) {
                return isset($context['error'])
                    && $context['error'] === 'Test exception'
                    && isset($context['error_class'])
                    && isset($context['duration_seconds']);
            }));

        $command = new TestCommandThatFails();
        $command->setLaravel($this->app);

        $this->expectException(\RuntimeException::class);
        $command->handle();
    }

    public function test_passes_custom_context_to_logs(): void
    {
        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'started', Mockery::on(function ($context) {
                return isset($context['custom_key']) && $context['custom_key'] === 'custom_value';
            }));

        $this->lokiMock->shouldReceive('logBatchJob')
            ->once()
            ->with('test:command', 'completed', Mockery::on(function ($context) {
                return isset($context['custom_key']) && $context['custom_key'] === 'custom_value';
            }));

        $command = new TestCommandWithContext();
        $command->setLaravel($this->app);
        $command->handle();
    }

    public function test_extracts_job_name_from_signature(): void
    {
        $this->lokiMock->shouldReceive('logBatchJob')
            ->with('mail:digest:unified', 'started', Mockery::any());

        $this->lokiMock->shouldReceive('logBatchJob')
            ->with('mail:digest:unified', 'completed', Mockery::any());

        $command = new TestCommandWithComplexSignature();
        $command->setLaravel($this->app);
        $command->handle();

        // Mockery expectations above verify the correct job name extraction.
        $this->addToAssertionCount(1);
    }
}

/**
 * Test command that uses the LogsBatchJob trait.
 */
class TestCommand extends Command
{
    use LogsBatchJob;

    protected $signature = 'test:command';

    public function handle(): int
    {
        return $this->runWithLogging(function () {
            return Command::SUCCESS;
        });
    }
}

/**
 * Test command that throws an exception.
 */
class TestCommandThatFails extends Command
{
    use LogsBatchJob;

    protected $signature = 'test:command';

    public function handle(): int
    {
        return $this->runWithLogging(function () {
            throw new \RuntimeException('Test exception');
        });
    }
}

/**
 * Test command with custom context.
 */
class TestCommandWithContext extends Command
{
    use LogsBatchJob;

    protected $signature = 'test:command';

    public function handle(): int
    {
        return $this->runWithLogging(function () {
            return Command::SUCCESS;
        }, ['custom_key' => 'custom_value']);
    }
}

/**
 * Test command with complex signature.
 */
class TestCommandWithComplexSignature extends Command
{
    use LogsBatchJob;

    protected $signature = 'mail:digest:unified
                            {--mode=daily : Digest mode}
                            {--limit=1000 : Max users}';

    public function handle(): int
    {
        return $this->runWithLogging(function () {
            return Command::SUCCESS;
        });
    }
}

<?php

namespace Tests\Unit\Console;

use App\Console\UsageError;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * artisan reports bootstrap failures to Sentry. A mistyped command line is not one: the
 * person typing it already saw the message.
 */
class UsageErrorTest extends TestCase
{
    public function test_an_unknown_option_is_a_usage_error(): void
    {
        $this->assertTrue(UsageError::is(new ConsoleRuntimeException('The "--dry-run" option does not exist.')));
        $this->assertTrue(UsageError::is(new InvalidOptionException('The "--x" option does not accept a value.')));
    }

    public function test_an_unknown_command_is_a_usage_error(): void
    {
        $this->assertTrue(UsageError::is(new CommandNotFoundException('Command "nope" is not defined.')));
    }

    public function test_an_application_fault_is_not(): void
    {
        $this->assertFalse(UsageError::is(new \RuntimeException('Database is down')));
        $this->assertFalse(UsageError::is(new \Error('Call to undefined method')));
    }

    public function test_artisan_checks_it_before_reporting(): void
    {
        $artisan = file_get_contents(__DIR__.'/../../../artisan');

        $this->assertStringContainsString('! \App\Console\UsageError::is($e)', $artisan);
    }
}

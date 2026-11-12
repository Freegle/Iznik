<?php

namespace Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Exception\InvalidOptionException;
use Symfony\Component\Console\Exception\RuntimeException as ConsoleRuntimeException;

/**
 * artisan reports bootstrap failures to Sentry. A mistyped command line is not one: the
 * person typing it already saw the message. The decision lives in a plain file that artisan
 * loads inside its reporting code, so it can be run here exactly as artisan runs it.
 */
class ArtisanUsageErrorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../../../bootstrap/artisan-usage-error.php';
    }

    public function test_an_unknown_option_is_a_usage_error(): void
    {
        $this->assertTrue(artisanIsUsageError(new ConsoleRuntimeException('The "--dry-run" option does not exist.')));
        $this->assertTrue(artisanIsUsageError(new InvalidOptionException('The "--x" option does not accept a value.')));
    }

    public function test_an_unknown_command_is_a_usage_error(): void
    {
        $this->assertTrue(artisanIsUsageError(new CommandNotFoundException('Command "nope" is not defined.')));
    }

    public function test_an_application_fault_is_not(): void
    {
        $this->assertFalse(artisanIsUsageError(new \RuntimeException('Database is down')));
        $this->assertFalse(artisanIsUsageError(new \Error('Call to undefined method')));
    }

    public function test_it_does_not_need_the_interface_to_be_loadable(): void
    {
        // The check must not trigger the autoloader: with a broken autoloader the interface
        // is simply not there, and the answer is "report it".
        $loader = static function (string $class): void {
            throw new \LogicException("autoload attempted for {$class}");
        };
        spl_autoload_register($loader, true, true);

        try {
            $this->assertFalse(artisanIsUsageError(new \RuntimeException('x')));
        } finally {
            spl_autoload_unregister($loader);
        }
    }
}

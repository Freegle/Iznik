<?php

namespace App\Console;

use Symfony\Component\Console\Exception\ExceptionInterface;

/**
 * Tells a mistake in how a command was typed from a fault in the application.
 *
 * An unknown option, a missing argument or a misspelt command name is a person at a
 * terminal getting the call wrong. It already reached them on stderr; sending it to Sentry as
 * well only adds an event nobody can act on.
 */
final class UsageError
{
    public static function is(\Throwable $e): bool
    {
        return $e instanceof ExceptionInterface;
    }
}

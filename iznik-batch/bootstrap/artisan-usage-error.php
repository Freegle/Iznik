<?php

/**
 * Tells a mistake in how a command was typed from a fault in the application.
 *
 * artisan loads this inside the code that reports failures, so it is a plain function in a
 * plain file: nothing in it needs the autoloader or the framework, and if the failure being
 * reported is itself an autoload problem this still works. An unknown option, a missing
 * argument or a misspelt command is a person at a terminal getting the call wrong. It already
 * reached them on stderr; sending it to Sentry as well only adds an event nobody can act on.
 */
function artisanIsUsageError(\Throwable $e): bool
{
    $usage = 'Symfony\Component\Console\Exception\ExceptionInterface';

    return interface_exists($usage, false) && $e instanceof $usage;
}

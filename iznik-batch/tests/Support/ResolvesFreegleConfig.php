<?php

namespace Tests\Support;

/**
 * Re-evaluates config/freegle.php with one environment variable set to a given value (or removed),
 * so a test can see what the file makes of it, the way a container's environment would: a value
 * that is set but blank ("") is not the same as one that is not set, which is the whole point of
 * several of these tests (docker-compose passes an unset `${NAME:-}` as an empty string).
 *
 * Reads the file directly rather than the booted app's config, so it tests the resolution itself.
 */
trait ResolvesFreegleConfig
{
    /**
     * @param string|null $value the value to set; null removes the variable
     * @return array<string, mixed> the whole resolved config/freegle.php
     */
    private function freegleConfigWith(string $name, ?string $value): array
    {
        $saved = [
            'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
            'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
            'getenv' => getenv($name),
        ];

        try {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }

            return require base_path('config/freegle.php');
        } finally {
            if ($saved['getenv'] === false) {
                putenv($name);
            } else {
                putenv("{$name}={$saved['getenv']}");
            }
            if ($saved['env'] === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $saved['env'];
            }
            if ($saved['server'] === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $saved['server'];
            }
        }
    }
}

<?php

namespace App\Console;

use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Command;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Arr;
use ReflectionClass;

class Kernel extends ConsoleKernel
{
    /**
     * Command classes found under each set of discovery paths, kept for the
     * life of the process.
     *
     * @var array<string, list<class-string<Command>>>
     */
    protected static array $discoveredCommands = [];

    /**
     * Discover commands under the paths bootstrap/app.php configured, exactly
     * as the framework kernel does. The framework only discovers when it is
     * used directly, on the assumption that an application with its own kernel
     * registers commands itself; this one changes nothing about where commands
     * come from, only how often the directory is walked.
     *
     * @return bool
     */
    protected function shouldDiscoverCommands()
    {
        return true;
    }

    /**
     * Register the commands found under the given paths.
     *
     * The framework walks app/Console/Commands with Finder and reflects on
     * every file it finds, each time the application boots. For a process that
     * boots it once, which is every artisan run, that is the same work as
     * before. The test suite boots it once per test, and the answer cannot
     * change between those boots, so the walk is done once per process and
     * its result reused.
     *
     * @param  array|string  $paths
     * @return void
     */
    protected function load($paths)
    {
        $paths = array_unique(Arr::wrap($paths));

        $paths = array_values(array_filter($paths, function ($path) {
            return is_dir($path);
        }));

        if (empty($paths)) {
            return;
        }

        $this->loadedPaths = array_values(
            array_unique(array_merge($this->loadedPaths, $paths))
        );

        $key = implode('|', $paths);

        if (! isset(static::$discoveredCommands[$key])) {
            $namespace = $this->app->getNamespace();
            $commands = [];

            foreach ($this->findCommands($paths) as $file) {
                $command = $this->commandClassFromFile($file, $namespace);

                if (is_subclass_of($command, Command::class) &&
                    ! (new ReflectionClass($command))->isAbstract()) {
                    $commands[] = $command;
                }
            }

            static::$discoveredCommands[$key] = $commands;
        }

        foreach (static::$discoveredCommands[$key] as $command) {
            Artisan::starting(function ($artisan) use ($command) {
                $artisan->resolve($command);
            });
        }
    }
}

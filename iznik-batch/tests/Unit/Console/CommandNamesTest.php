<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every command under app/Console/Commands carries an #[AsCommand] attribute
 * naming it. Artisan registers a command with that attribute by name and only
 * instantiates it when it is run; without the attribute it instantiates the
 * command, and every other one, whenever Artisan starts, which is every
 * artisan invocation and every test that calls one.
 *
 * The attribute's name has to match the name in the command's $signature. If
 * they differ, Symfony registers the instance under the signature's name and
 * then cannot find it under the attribute's, and the command is reported as
 * "registered under multiple names" when it is run.
 */
class CommandNamesTest extends TestCase
{
    /**
     * @return list<class-string<Command>>
     */
    private function commandClasses(): array
    {
        $classes = [];
        $base = realpath(app_path()).DIRECTORY_SEPARATOR;

        foreach (Finder::create()->in(app_path('Console/Commands'))->name('*.php')->files() as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], Str::after($file->getRealPath(), $base));

            if (is_subclass_of($class, Command::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[] = $class;
            }
        }

        sort($classes);

        return $classes;
    }

    public function test_every_command_declares_the_name_it_is_registered_under(): void
    {
        $classes = $this->commandClasses();
        $this->assertNotEmpty($classes);

        $registered = Artisan::all();
        $missing = [];
        $mismatched = [];

        foreach ($classes as $class) {
            $attributes = (new ReflectionClass($class))->getAttributes(AsCommand::class);

            if ($attributes === []) {
                $missing[] = $class;
                continue;
            }

            $declared = $attributes[0]->newInstance()->name;
            $actual = app($class)->getName();

            if ($declared !== $actual) {
                $mismatched[] = "{$class}: attribute says '{$declared}', signature says '{$actual}'";
                continue;
            }

            $this->assertArrayHasKey($declared, $registered, "{$class} is not registered with Artisan as '{$declared}'");
            $this->assertInstanceOf($class, $registered[$declared]);
        }

        $this->assertSame([], $missing, "Commands without an #[AsCommand(name: ...)] attribute:\n  ".implode("\n  ", $missing));
        $this->assertSame([], $mismatched, "Commands whose attribute and signature disagree:\n  ".implode("\n  ", $mismatched));
    }

    public function test_command_names_are_unique(): void
    {
        $byName = [];

        foreach ($this->commandClasses() as $class) {
            $name = app($class)->getName();
            $byName[$name][] = $class;
        }

        $duplicates = array_filter($byName, fn ($classes) => count($classes) > 1);

        $this->assertSame([], $duplicates, 'Two commands share a name: '.json_encode($duplicates));
    }
}

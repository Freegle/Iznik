<?php

namespace Tests\Unit\Console;

use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
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

    /**
     * routes/console.php is loaded when the schedule is first resolved, and
     * when Artisan is built, whichever comes first, and only once: a second
     * load would register every scheduled job twice.
     */
    public function test_the_schedule_is_defined_once_however_it_is_reached(): void
    {
        $schedule = app(Schedule::class);
        $defined = count($schedule->events());
        $this->assertGreaterThan(20, $defined, 'resolving the schedule loads routes/console.php');

        Artisan::all();
        $this->assertCount($defined, $schedule->events(), 'building Artisan does not load routes/console.php a second time');

        $this->assertNotNull(
            collect($schedule->events())->first(fn ($e) => str_contains((string) $e->command, 'mail:welcome:send')),
            'the full Freegle schedule is what was loaded',
        );
    }

    public function test_building_artisan_first_also_defines_the_schedule(): void
    {
        Artisan::all();

        $this->assertGreaterThan(20, count(app(Schedule::class)->events()));
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

<?php

namespace Tests\Unit\Support;

use App\Support\EloquentUtils;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

/**
 * Standalone unit tests for EloquentUtils using an isolated in-memory SQLite
 * connection (via Capsule) and a fake Log facade binding, so these never touch
 * the shared MySQL test database.
 */
class EloquentUtilsTest extends TestCase
{
    private TestLoggerForEloquentUtils $logger;

    /** @var mixed Facade app in effect before this test, restored in tearDown(). */
    private mixed $previousFacadeApplication;

    /** @var mixed Eloquent connection resolver in effect before this test, restored in tearDown(). */
    private mixed $previousConnectionResolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $this->previousConnectionResolver = Model::getConnectionResolver();

        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $capsule::schema()->dropIfExists('reparent_plain');
        $capsule::schema()->create('reparent_plain', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id');
        });

        $capsule::schema()->dropIfExists('reparent_unique');
        $capsule::schema()->create('reparent_unique', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('parent_id')->unique();
        });

        $this->logger = new TestLoggerForEloquentUtils;
        $container = new Container;
        $container->instance('log', $this->logger);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        // Restore whatever facade application (Laravel's real app, or none) and
        // Eloquent connection resolver were in effect before this test, so other
        // tests in the same PHPUnit process don't resolve facades/models against
        // this test's throwaway sqlite container.
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Facade::clearResolvedInstances();
        if ($this->previousConnectionResolver !== null) {
            Model::setConnectionResolver($this->previousConnectionResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    public function test_reparent_row_updates_the_matching_row(): void
    {
        ReparentPlainModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRow(ReparentPlainModel::class, 'parent_id', 5, 99);

        $this->assertSame(0, ReparentPlainModel::where('parent_id', 5)->count());
        $this->assertSame(1, ReparentPlainModel::where('parent_id', 99)->count());
    }

    public function test_reparent_row_updates_all_matching_rows(): void
    {
        ReparentPlainModel::create(['parent_id' => 5]);
        ReparentPlainModel::create(['parent_id' => 5]);
        ReparentPlainModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRow(ReparentPlainModel::class, 'parent_id', 5, 99);

        $this->assertSame(0, ReparentPlainModel::where('parent_id', 5)->count());
        $this->assertSame(3, ReparentPlainModel::where('parent_id', 99)->count());
    }

    public function test_reparent_row_does_not_persist_when_dry_run(): void
    {
        ReparentPlainModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRow(ReparentPlainModel::class, 'parent_id', 5, 99, true);

        $this->assertSame(1, ReparentPlainModel::where('parent_id', 5)->count());
        $this->assertSame(0, ReparentPlainModel::where('parent_id', 99)->count());
    }

    public function test_reparent_row_is_a_noop_when_nothing_matches(): void
    {
        ReparentPlainModel::create(['parent_id' => 1]);

        EloquentUtils::reparentRow(ReparentPlainModel::class, 'parent_id', 5, 99);

        $this->assertSame(1, ReparentPlainModel::where('parent_id', 1)->count());
        $this->assertSame(0, ReparentPlainModel::where('parent_id', 99)->count());
    }

    public function test_reparent_row_logs_the_write_it_is_about_to_make(): void
    {
        ReparentPlainModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRow(ReparentPlainModel::class, 'parent_id', 5, 99);

        $this->assertNotEmpty($this->logger->logs);
        [$level, $message] = $this->logger->logs[0];
        $this->assertSame('info', $level);
        $this->assertStringContainsString('parent_id=5', $message);
        $this->assertStringContainsString('parent_id=99', $message);
    }

    public function test_reparent_row_ignore_updates_the_matching_row_when_no_conflict(): void
    {
        ReparentIgnoreModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRowIgnore(ReparentIgnoreModel::class, 'parent_id', 5, 99);

        $this->assertSame(0, ReparentIgnoreModel::where('parent_id', 5)->count());
        $this->assertSame(1, ReparentIgnoreModel::where('parent_id', 99)->count());
        $this->assertEmpty($this->logger->logs('warning'));
    }

    public function test_reparent_row_ignore_does_not_persist_when_dry_run(): void
    {
        ReparentIgnoreModel::create(['parent_id' => 5]);

        EloquentUtils::reparentRowIgnore(ReparentIgnoreModel::class, 'parent_id', 5, 99, true);

        $this->assertSame(1, ReparentIgnoreModel::where('parent_id', 5)->count());
        $this->assertSame(0, ReparentIgnoreModel::where('parent_id', 99)->count());
        $this->assertEmpty($this->logger->logs('warning'));
    }

    public function test_reparent_row_ignore_suppresses_a_unique_constraint_conflict(): void
    {
        // The row being reparented from 5 would collide with a pre-existing,
        // unrelated row that already occupies the target value 99. The save()
        // must be swallowed rather than throwing, leaving both rows as they were.
        $row = ReparentIgnoreModel::create(['parent_id' => 5]);
        $occupant = ReparentIgnoreModel::create(['parent_id' => 99]);

        EloquentUtils::reparentRowIgnore(ReparentIgnoreModel::class, 'parent_id', 5, 99);

        $this->assertSame(5, $row->fresh()->parent_id);
        $this->assertSame(99, $occupant->fresh()->parent_id);
        $this->assertSame(1, ReparentIgnoreModel::where('parent_id', 5)->count());
        $this->assertSame(1, ReparentIgnoreModel::where('parent_id', 99)->count());

        $warnings = $this->logger->logs('warning');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Reparent conflict', $warnings[0]);
        $this->assertStringContainsString(ReparentIgnoreModel::class, $warnings[0]);
    }
}

class ReparentPlainModel extends Model
{
    protected $table = 'reparent_plain';

    public $timestamps = false;

    protected $guarded = [];
}

class ReparentIgnoreModel extends Model
{
    protected $table = 'reparent_unique';

    public $timestamps = false;

    protected $guarded = [];
}

class TestLoggerForEloquentUtils extends AbstractLogger
{
    /** @var array<int, array{0: string, 1: string}> */
    public array $logs = [];

    public function log($level, $message, array $context = []): void
    {
        $this->logs[] = [(string) $level, (string) $message];
    }

    /**
     * @return string[]
     */
    public function logs(string $level): array
    {
        return array_values(array_map(
            fn ($entry) => $entry[1],
            array_filter($this->logs, fn ($entry) => $entry[0] === $level)
        ));
    }
}

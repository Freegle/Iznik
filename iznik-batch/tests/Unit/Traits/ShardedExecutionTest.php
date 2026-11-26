<?php

namespace Tests\Unit\Traits;

use App\Models\User;
use App\Traits\ShardedExecution;
use Tests\TestCase;

class ShardedExecutionTest extends TestCase
{
    use ShardedExecution;

    protected array $mockOptions = [];
    protected array $infoMessages = [];

    /**
     * Mock option() method for testing parseShardingOptions.
     */
    public function option(?string $key = null)
    {
        return $this->mockOptions[$key] ?? null;
    }

    /**
     * Mock info() method for testing logShardingConfig.
     */
    public function info($message, $verbosity = null): void
    {
        $this->infoMessages[] = $message;
    }

    public function test_apply_sharding_with_mod_1(): void
    {
        // With mod=1, no sharding should be applied.
        $this->mod = 1;
        $this->val = 0;

        $query = User::query();
        $result = $this->applySharding($query);

        // The query should be unchanged.
        $this->assertSame($query, $result);
    }

    public function test_apply_sharding_with_mod_greater_than_1(): void
    {
        // Create some test users.
        for ($i = 0; $i < 10; $i++) {
            User::create([
                'firstname' => 'Test',
                'lastname' => 'User' . $i,
                'fullname' => 'Test User' . $i,
                'added' => now(),
            ]);
        }

        $this->mod = 2;
        $this->val = 0;

        $query = User::query();
        $shardedQuery = $this->applySharding($query);

        $results = $shardedQuery->get();

        // All results should have id % 2 = 0.
        foreach ($results as $user) {
            $this->assertEquals(0, $user->id % 2);
        }
    }

    public function test_apply_sharding_with_custom_column(): void
    {
        // Create some test users.
        for ($i = 0; $i < 10; $i++) {
            User::create([
                'firstname' => 'Test',
                'lastname' => 'User' . $i,
                'fullname' => 'Test User' . $i,
                'added' => now(),
            ]);
        }

        $this->mod = 3;
        $this->val = 1;

        $query = User::query();
        $shardedQuery = $this->applySharding($query, 'id');

        $results = $shardedQuery->get();

        // All results should have id % 3 = 1.
        foreach ($results as $user) {
            $this->assertEquals(1, $user->id % 3);
        }
    }

    public function test_get_sharding_options(): void
    {
        $options = $this->getShardingOptions();

        $this->assertCount(2, $options);
        $this->assertEquals('mod', $options[0][0]);
        $this->assertEquals('val', $options[1][0]);
    }

    public function test_log_sharding_config_with_mod_1(): void
    {
        $this->mod = 1;
        $this->val = 0;

        // This should not throw an exception.
        $this->logShardingConfig();

        // If we get here, the method executed successfully.
        $this->assertTrue(true);
    }

    public function test_log_sharding_config_with_mod_greater_than_1(): void
    {
        $this->mod = 4;
        $this->val = 2;

        // This should not throw an exception.
        $this->logShardingConfig();

        // If we get here, the method executed successfully.
        $this->assertTrue(true);
    }

    public function test_parse_sharding_options_with_values(): void
    {
        $this->mockOptions = [
            'mod' => 4,
            'val' => 2,
        ];

        $this->parseShardingOptions();

        $this->assertEquals(4, $this->mod);
        $this->assertEquals(2, $this->val);
    }

    public function test_parse_sharding_options_with_null_values(): void
    {
        $this->mockOptions = [
            'mod' => null,
            'val' => null,
        ];

        $this->parseShardingOptions();

        $this->assertEquals(1, $this->mod);
        $this->assertEquals(0, $this->val);
    }

    public function test_log_sharding_config_logs_message(): void
    {
        $this->mod = 4;
        $this->val = 2;
        $this->infoMessages = [];

        $this->logShardingConfig();

        $this->assertCount(1, $this->infoMessages);
        $this->assertStringContainsString('MOD 4', $this->infoMessages[0]);
        $this->assertStringContainsString('= 2', $this->infoMessages[0]);
    }
}

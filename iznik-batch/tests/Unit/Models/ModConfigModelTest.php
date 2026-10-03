<?php

namespace Tests\Unit\Models;

use App\Models\ModConfig;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModConfigModelTest extends TestCase
{
    public function test_returns_own_configid_when_set(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $configId = $this->createModConfig($mod->id);
        $mod->update(['modconfigid' => $configId]);

        $result = ModConfig::getForMod($mod->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_other_mods_configid_when_own_is_null(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $otherMod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $configId = $this->createModConfig($otherMod->id);
        $otherMod->update(['modconfigid' => $configId]);

        $result = ModConfig::getForMod($mod->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_own_created_config_when_no_other_mod_has_one(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $configId = $this->createModConfig($mod->id);

        $result = ModConfig::getForMod($mod->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_default_config_when_nothing_else_exists(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $defaultConfigId = $this->createModConfig(null, ['default' => true]);

        $result = ModConfig::getForMod($mod->id);
        $this->assertEquals($defaultConfigId, $result);
    }

    public function test_returns_null_when_no_configs_exist(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);

        $result = ModConfig::getForMod($mod->id);
        $this->assertNull($result);
    }

    public function test_returns_null_when_mod_not_found(): void
    {
        $result = ModConfig::getForMod(999999999);
        $this->assertNull($result);
    }

    public function test_saves_fallback_configid_to_mod(): void
    {
        $mod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $otherMod = $this->createTestUser(['systemrole' => User::SYSTEMROLE_MODERATOR]);
        $configId = $this->createModConfig($otherMod->id);
        $otherMod->update(['modconfigid' => $configId]);

        ModConfig::getForMod($mod->id);
        $this->assertEquals($configId, $mod->fresh()->modconfigid);
    }

    public function test_creator_relationship(): void
    {
        $mod = $this->createTestUser();
        $configId = $this->createModConfig($mod->id);
        $config = ModConfig::find($configId);
        $this->assertEquals($mod->id, $config->creator->id);
    }

    private function createModConfig(?int $createdBy, array $attributes = []): int
    {
        return (int) DB::table('mod_configs')->insertGetId(array_merge([
            'createdby' => $createdBy,
            'name' => 'TestConfig_' . uniqid('', true),
            'default' => false,
            'protected' => false,
        ], $attributes));
    }
}

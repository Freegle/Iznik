<?php

namespace Tests\Unit\Models;

use App\Models\Membership;
use App\Models\ModConfig;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModConfigModelTest extends TestCase
{
    public function test_returns_own_configid_when_set(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $configId = $this->createModConfig($mod->id);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => $configId,
        ]);
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_other_mods_configid_when_own_is_null(): void
    {
        $mod = $this->createTestUser();
        $otherMod = $this->createTestUser();
        $group = $this->createTestGroup();
        $configId = $this->createModConfig($otherMod->id);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => null,
        ]);
        $this->createMembership($otherMod, $group, [
            'role' => Membership::ROLE_OWNER,
            'configid' => $configId,
        ]);
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_own_created_config_when_group_has_none(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => null,
        ]);
        $configId = $this->createModConfig($mod->id);
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertEquals($configId, $result);
    }

    public function test_falls_back_to_default_config_when_nothing_else_exists(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => null,
        ]);
        $defaultConfigId = $this->createModConfig(null, ['default' => true]);
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertEquals($defaultConfigId, $result);
    }

    public function test_returns_null_when_no_configs_exist(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => null,
        ]);
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertNull($result);
    }

    public function test_returns_null_when_user_has_no_membership(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $result = ModConfig::getForGroup($mod->id, $group->id);
        $this->assertNull($result);
    }

    public function test_saves_fallback_configid_to_membership(): void
    {
        $mod = $this->createTestUser();
        $otherMod = $this->createTestUser();
        $group = $this->createTestGroup();
        $configId = $this->createModConfig($otherMod->id);
        $membership = $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => null,
        ]);
        $this->createMembership($otherMod, $group, [
            'role' => Membership::ROLE_OWNER,
            'configid' => $configId,
        ]);
        ModConfig::getForGroup($mod->id, $group->id);
        $this->assertEquals($configId, $membership->fresh()->configid);
    }

    public function test_creator_relationship(): void
    {
        $mod = $this->createTestUser();
        $configId = $this->createModConfig($mod->id);
        $config = ModConfig::find($configId);
        $this->assertEquals($mod->id, $config->creator->id);
    }

    public function test_memberships_relationship(): void
    {
        $mod = $this->createTestUser();
        $group = $this->createTestGroup();
        $configId = $this->createModConfig($mod->id);
        $this->createMembership($mod, $group, [
            'role' => Membership::ROLE_MODERATOR,
            'configid' => $configId,
        ]);
        $config = ModConfig::find($configId);
        $this->assertEquals(1, $config->memberships()->count());
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

<?php

namespace Tests\Unit\Models;

use App\Models\GroupAttachment;
use Tests\TestCase;

class GroupAttachmentModelTest extends TestCase
{
    public function test_get_path_returns_gimg_url(): void
    {
        $attachment = new GroupAttachment(['id' => 42, 'archived' => false]);

        $url = $attachment->getPath(false);

        $this->assertStringContainsString('gimg_42.jpg', $url);
        $this->assertStringNotContainsString('tgimg', $url);
    }

    public function test_get_path_returns_tgimg_url_for_thumb(): void
    {
        $attachment = new GroupAttachment(['id' => 99, 'archived' => false]);

        $url = $attachment->getPath(true);

        $this->assertStringContainsString('tgimg_99.jpg', $url);
    }

    public function test_get_path_uses_provided_id_over_model_id(): void
    {
        $attachment = new GroupAttachment(['id' => 1, 'archived' => false]);

        $url = $attachment->getPath(false, 77);

        $this->assertStringContainsString('gimg_77.jpg', $url);
    }

    public function test_get_path_returns_null_when_no_id(): void
    {
        $attachment = new GroupAttachment(['archived' => false]);
        $attachment->id = null;

        $result = $attachment->getPath(false);

        $this->assertNull($result);
    }

    public function test_get_path_uses_archived_domain_when_archived(): void
    {
        $attachment = new GroupAttachment(['id' => 5, 'archived' => true]);

        config(['freegle.images.archived_domain' => 'https://archived.example.com/']);
        config(['freegle.images.domain' => 'https://live.example.com/']);

        $url = $attachment->getPath(false);

        $this->assertStringStartsWith('https://archived.example.com', $url);
        $this->assertStringNotContainsString('live.example.com', $url);
    }

    public function test_get_path_uses_live_domain_when_not_archived(): void
    {
        $attachment = new GroupAttachment(['id' => 5, 'archived' => false]);

        config(['freegle.images.domain' => 'https://live.example.com/']);

        $url = $attachment->getPath(false);

        $this->assertStringStartsWith('https://live.example.com', $url);
    }

    public function test_get_path_returns_null_for_external_uid(): void
    {
        $attachment = new GroupAttachment(['id' => 10, 'archived' => false, 'externaluid' => 'some-uid']);

        $result = $attachment->getPath(false);

        $this->assertNull($result);
    }

    public function test_get_path_returns_null_for_external_url(): void
    {
        $attachment = new GroupAttachment(['id' => 10, 'archived' => false, 'externalurl' => 'https://external.com/img.jpg']);

        $result = $attachment->getPath(false);

        $this->assertNull($result);
    }

    public function test_group_relationship(): void
    {
        $attachment = new GroupAttachment();
        $this->assertTrue(method_exists($attachment, 'group'));
        $relation = $attachment->group();
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $relation);
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\GroupAttachment;
use Tests\TestCase;

class GroupAttachmentModelTest extends TestCase
{
    public function test_get_path_returns_gimg_url(): void
    {
        // id is guarded against mass-assignment; set it directly.
        $attachment = new GroupAttachment(['archived' => false]);
        $attachment->id = 42;

        $url = $attachment->getPath(false);

        $this->assertStringContainsString('gimg_42.jpg', $url);
        $this->assertStringNotContainsString('tgimg', $url);
    }

    public function test_get_path_returns_tgimg_url_for_thumb(): void
    {
        $attachment = new GroupAttachment(['archived' => false]);
        $attachment->id = 99;

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
        $attachment = new GroupAttachment(['archived' => true]);
        $attachment->id = 5;

        config(['freegle.images.archived_domain' => 'https://archived.example.com/']);
        config(['freegle.images.domain' => 'https://live.example.com/']);

        $url = $attachment->getPath(false);

        $this->assertStringStartsWith('https://archived.example.com', $url);
        $this->assertStringNotContainsString('live.example.com', $url);
    }

    public function test_an_archived_thumbnail_is_made_by_the_resizer_not_read_from_a_v1_thumbnail(): void
    {
        $attachment = new GroupAttachment(['archived' => true]);
        $attachment->id = 5;

        config(['freegle.images.archived_domain' => 'https://images.example.com/archive']);
        config(['freegle.delivery.base_url' => 'https://delivery.example.com']);

        $this->assertSame('https://images.example.com/archive/gimg_5.jpg', $attachment->getPath(false));
        $this->assertSame(
            'https://delivery.example.com?w=250&h=250&url=' . rawurlencode('https://images.example.com/archive/gimg_5.jpg'),
            $attachment->getPath(true)
        );
    }

    public function test_an_archived_thumbnail_still_on_azure_is_v1s_own(): void
    {
        $attachment = new GroupAttachment(['archived' => true]);
        $attachment->id = 5;

        config(['freegle.images.archived_domain' => 'https://freegle.blob.core.windows.net/images']);

        $this->assertSame('https://freegle.blob.core.windows.net/images/tgimg_5.jpg', $attachment->getPath(true));
    }

    public function test_get_path_uses_live_domain_when_not_archived(): void
    {
        $attachment = new GroupAttachment(['archived' => false]);
        $attachment->id = 5;

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

<?php

namespace Tests\Unit\Mail\Traits;

use App\Mail\Traits\AvatarResolver;
use App\Models\User;
use Tests\TestCase;

/**
 * Public host so we can call the protected trait method from tests.
 */
class AvatarResolverHost
{
    use AvatarResolver {
        resolveAvatarUrl as public;
    }
}

class AvatarResolverTest extends TestCase
{
    private AvatarResolverHost $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new AvatarResolverHost();
    }

    public function test_returns_absolute_url_when_no_avatar_server_configured(): void
    {
        // Regression: when freegle.avatar_server_url is empty (the default), the
        // fallback used to produce a relative path like "/bolarinb.png?size=40"
        // which is broken in email clients. Must fall back to the user site.
        config(['freegle.avatar_server_url' => '']);
        config(['freegle.sites.user' => 'https://www.ilovefreegle.org']);

        $user = new User();
        $user->fullname = 'bolarinb';

        $url = $this->host->resolveAvatarUrl($user, 40);

        $this->assertStringStartsWith('https://', $url, 'Avatar URL must be absolute');
        $this->assertStringContainsString('bolarinb.png', $url);
        $this->assertStringContainsString('size=40', $url);
    }

    public function test_uses_avatar_server_url_when_configured(): void
    {
        config(['freegle.avatar_server_url' => 'https://avatars.example.com/api']);

        $user = new User();
        $user->fullname = 'someone';

        $url = $this->host->resolveAvatarUrl($user, 48);

        $this->assertStringStartsWith('https://avatars.example.com/api/', $url);
        $this->assertStringContainsString('someone.png', $url);
        $this->assertStringNotContainsString('size=', $url, 'default size 48 omits query param');
    }

    public function test_url_encodes_name_with_special_chars(): void
    {
        config(['freegle.avatar_server_url' => '']);
        config(['freegle.sites.user' => 'https://www.ilovefreegle.org']);

        $user = new User();
        $user->fullname = 'first last';

        $url = $this->host->resolveAvatarUrl($user, 40);

        $this->assertStringContainsString('first%20last.png', $url);
    }

    public function test_falls_back_to_user_literal_when_user_null(): void
    {
        config(['freegle.avatar_server_url' => '']);
        config(['freegle.sites.user' => 'https://www.ilovefreegle.org']);

        $url = $this->host->resolveAvatarUrl(null, 40);

        $this->assertStringStartsWith('https://www.ilovefreegle.org/', $url);
        $this->assertStringContainsString('user.png', $url);
    }

    public function test_avatar_server_url_is_configured_so_avatars_resolve_to_real_endpoint(): void
    {
        // Regression: config/freegle.php had no avatar_server_url key, so config()
        // returned an empty string and every poster without an uploaded photo
        // resolved to https://www.ilovefreegle.org/{name}.png - a 404 that renders
        // the broken-image icon in emails. The key must be populated (env + real
        // default), and a no-photo user must resolve to the avatar server.
        $this->assertNotEmpty(config('freegle.avatar_server_url'));

        $user = new User();
        $user->fullname = 'craigj1385';
        $url = $this->host->resolveAvatarUrl($user, 36);

        $this->assertStringStartsWith(rtrim((string) config('freegle.avatar_server_url'), '/'), $url);
        $this->assertStringNotContainsString('ilovefreegle.org/craigj1385.png', $url);
    }
}

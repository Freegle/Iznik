<?php

namespace Tests\Unit\Config;

use Tests\TestCase;

/**
 * The Freegle chat (first-reply lever 3) is switched off, and deliberately not
 * env-driven any more.
 *
 * Both halves of that matter. A deployed environment file still carries
 * FIRSTREPLY_CHAT_ENABLED=true from when the lever was live, so a config that
 * read the env would have Freegle start messaging members again on the next
 * deploy - a member-facing change nobody asked for and nobody would see coming.
 * Turning it back on is a decision; this test is here so that restoring the env
 * read has to be a visible one rather than a tidy-up.
 *
 * Everything that implements the chat is intact and all of it reads this flag,
 * so this is the whole switch. See docs/developers/reference/first-reply.md.
 */
class FirstReplyChatDisabledTest extends TestCase
{
    /**
     * Re-evaluate config/freegle.php with FIRSTREPLY_CHAT_ENABLED forced on, and
     * return the resolved firstreply.chat block. Reads the file directly rather
     * than the booted app's config so this tests the resolution itself.
     *
     * @return array<string,mixed>
     */
    private function chatConfigWithEnvForcedOn(): array
    {
        $key = 'FIRSTREPLY_CHAT_ENABLED';

        $saved = [
            'env' => array_key_exists($key, $_ENV) ? $_ENV[$key] : null,
            'server' => array_key_exists($key, $_SERVER) ? $_SERVER[$key] : null,
            'getenv' => getenv($key),
        ];

        try {
            putenv("{$key}=true");
            $_ENV[$key] = 'true';
            $_SERVER[$key] = 'true';

            $config = require base_path('config/freegle.php');

            return $config['firstreply']['chat'];
        } finally {
            if ($saved['getenv'] === false) {
                putenv($key);
            } else {
                putenv("{$key}={$saved['getenv']}");
            }
            if ($saved['env'] === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $saved['env'];
            }
            if ($saved['server'] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $saved['server'];
            }
        }
    }

    public function test_chat_is_off_and_the_env_var_cannot_turn_it_on(): void
    {
        $chat = $this->chatConfigWithEnvForcedOn();

        $this->assertFalse(
            $chat['enabled'],
            'the Freegle chat is switched off in config; FIRSTREPLY_CHAT_ENABLED must not revive it'
        );
    }

    public function test_the_rest_of_the_chat_settings_are_left_intact(): void
    {
        // Switched off, not dismantled: the settings the services read are still
        // here, so turning the flag back on needs nothing else restored.
        $chat = $this->chatConfigWithEnvForcedOn();

        foreach (['system_user_email', 'system_user_name', 'schedule', 'expiry_days'] as $key) {
            $this->assertArrayHasKey($key, $chat);
        }

        $this->assertSame(
            ['photo', 'delivery', 'views', 'deadline'],
            array_keys($chat['schedule']),
            'the prompt cadence must survive the switch-off, in order'
        );
    }
}

<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

/**
 * An AMP token authorises exactly one (user, resource) pair.
 */
class AmpEmailTokenTest extends TestCase
{
    private function createAmpEmailInstance(): object
    {
        return new class {
            use \App\Mail\Traits\AmpEmail;

            public function token(int $userId, int $resourceId): array
            {
                return $this->generateToken($userId, $resourceId);
            }
        };
    }

    /**
     * Mint two tokens in the same second, so the expiry is the same and only the ids differ.
     */
    private function mintPair(int $userA, int $resourceA, int $userB, int $resourceB): array
    {
        $instance = $this->createAmpEmailInstance();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $a = $instance->token($userA, $resourceA);
            $b = $instance->token($userB, $resourceB);

            if ($a['expiry'] === $b['expiry']) {
                return [$a, $b];
            }
        }

        $this->fail('Could not mint two tokens with the same expiry.');
    }

    public function test_tokens_for_different_user_and_resource_splits_of_the_same_digits_differ(): void
    {
        config(['freegle.amp.secret' => 'test-secret', 'freegle.amp.token_expiry_hours' => 168]);

        // User 12 with message 3, and user 1 with message 23, give the same digit string.
        [$a, $b] = $this->mintPair(12, 3, 1, 23);

        $this->assertSame($a['expiry'], $b['expiry']);
        $this->assertNotSame(
            $a['token'],
            $b['token'],
            'A token minted for one (user, message) pair must not also validate for another.'
        );
    }
}

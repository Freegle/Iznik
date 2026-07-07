<?php

namespace Tests\Unit\Models;

use App\Models\User;
use App\Models\UserEmail;
use Tests\TestCase;

class UserEmailModelTest extends TestCase
{
    public function test_user_email_can_be_created(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $email = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('newtest'),
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertDatabaseHas('users_emails', ['id' => $email->id]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();
        $email = UserEmail::where('userid', $user->id)->first();

        $this->assertEquals($user->id, $email->user->id);
    }

    public function test_validated_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $email1 = UserEmail::where('userid', $user1->id)->first();
        $email1->update(['validated' => now()]);

        $email2 = UserEmail::create([
            'userid' => $user2->id,
            'email' => $this->uniqueEmail('unvalidated'),
            'preferred' => 1,
            'added' => now(),
            'validated' => null,
        ]);

        $validated = UserEmail::validated()->get();

        $this->assertTrue($validated->contains('id', $email1->id));
        $this->assertFalse($validated->contains('id', $email2->id));
    }

    public function test_unvalidated_scope(): void
    {
        $user1 = $this->createTestUser();
        $user2 = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $email1 = UserEmail::where('userid', $user1->id)->first();
        $email1->update(['validated' => now()]);

        $email2 = UserEmail::create([
            'userid' => $user2->id,
            'email' => $this->uniqueEmail('unvalidated'),
            'preferred' => 1,
            'added' => now(),
            'validated' => null,
        ]);

        $unvalidated = UserEmail::unvalidated()->get();

        $this->assertFalse($unvalidated->contains('id', $email1->id));
        $this->assertTrue($unvalidated->contains('id', $email2->id));
    }

    public function test_preferred_scope(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $preferred = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('preferred'),
            'preferred' => 1,
            'added' => now(),
        ]);

        $notPreferred = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('secondary'),
            'preferred' => 0,
            'added' => now(),
        ]);

        $results = UserEmail::preferred()->get();

        $this->assertTrue($results->contains('id', $preferred->id));
        $this->assertFalse($results->contains('id', $notPreferred->id));
    }

    public function test_not_bounced_scope(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $notBounced = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('good'),
            'preferred' => 1,
            'added' => now(),
            'bounced' => null,
        ]);

        $bounced = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('bounced'),
            'preferred' => 0,
            'added' => now(),
            'bounced' => now(),
        ]);

        $results = UserEmail::notBounced()->get();

        $this->assertTrue($results->contains('id', $notBounced->id));
        $this->assertFalse($results->contains('id', $bounced->id));
    }

    public function test_is_validated(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        $validated = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('validated'),
            'preferred' => 1,
            'added' => now(),
            'validated' => now(),
        ]);

        $unvalidated = UserEmail::create([
            'userid' => $user->id,
            'email' => $this->uniqueEmail('unvalidated'),
            'preferred' => 0,
            'added' => now(),
            'validated' => null,
        ]);

        $this->assertTrue($validated->isValidated());
        $this->assertFalse($unvalidated->isValidated());
    }

    public function test_get_domain(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Use a unique prefix but fixed domain for testing getDomain().
        $uniquePrefix = 'test_' . uniqid('', true);
        $email = UserEmail::create([
            'userid' => $user->id,
            'email' => $uniquePrefix . '@example.com',
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertEquals('example.com', $email->getDomain());
    }

    public function test_get_domain_with_invalid_email(): void
    {
        $user = User::create([
            'firstname' => 'Test',
            'lastname' => 'User',
            'fullname' => 'Test User',
            'added' => now(),
        ]);

        // Use unique string without @ to test invalid email.
        $email = UserEmail::create([
            'userid' => $user->id,
            'email' => 'nodomainemail_' . uniqid('', true),
            'preferred' => 1,
            'added' => now(),
        ]);

        $this->assertEquals('', $email->getDomain());
    }
}

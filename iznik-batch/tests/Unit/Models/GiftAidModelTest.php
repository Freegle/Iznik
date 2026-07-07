<?php

namespace Tests\Unit\Models;

use App\Models\GiftAid;
use Tests\TestCase;

class GiftAidModelTest extends TestCase
{
    public function test_gift_aid_can_be_created(): void
    {
        $user = $this->createTestUser();

        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertDatabaseHas('giftaid', ['id' => $giftAid->id]);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertEquals($user->id, $giftAid->user->id);
    }

    public function test_is_declined(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();

        $declined = GiftAid::create([
            'userid' => $user1->id,
            'period' => GiftAid::PERIOD_DECLINED,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $active = GiftAid::create([
            'userid' => $user2->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
        ]);

        $this->assertTrue($declined->isDeclined());
        $this->assertFalse($active->isDeclined());
    }

    public function test_is_active(): void
    {
        $user1 = $this->createTestUser();
        $user2 = $this->createTestUser();
        $user3 = $this->createTestUser();

        $active = GiftAid::create([
            'userid' => $user1->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => null,
        ]);

        $declined = GiftAid::create([
            'userid' => $user2->id,
            'period' => GiftAid::PERIOD_DECLINED,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => null,
        ]);

        $deleted = GiftAid::create([
            'userid' => $user3->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Test User',
            'homeaddress' => '123 Test St',
            'deleted' => now(),
        ]);

        $this->assertTrue($active->isActive());
        $this->assertFalse($declined->isActive());
        $this->assertFalse($deleted->isActive());
    }

    public function test_period_constants(): void
    {
        $this->assertEquals('This', GiftAid::PERIOD_THIS);
        $this->assertEquals('Since', GiftAid::PERIOD_SINCE);
        $this->assertEquals('Future', GiftAid::PERIOD_FUTURE);
        $this->assertEquals('Declined', GiftAid::PERIOD_DECLINED);
        $this->assertEquals('Past4YearsAndFuture', GiftAid::PERIOD_PAST4_YEARS_AND_FUTURE);
    }

    public function test_get_firstname_uses_dedicated_column_when_set(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'John Smith',
            'homeaddress' => '1 Test St',
            'firstname' => 'Jon',
            'lastname' => 'Smyth',
        ]);

        $this->assertEquals('Jon', $giftAid->getFirstname());
        $this->assertEquals('Smyth', $giftAid->getLastname());
    }

    public function test_get_firstname_falls_back_to_splitting_fullname(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Jane Doe',
            'homeaddress' => '1 Test St',
        ]);

        $this->assertEquals('Jane', $giftAid->getFirstname());
        $this->assertEquals('Doe', $giftAid->getLastname());
    }

    public function test_get_firstname_with_multiple_word_lastname(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Maria Garcia Lopez',
            'homeaddress' => '1 Test St',
        ]);

        $this->assertEquals('Maria', $giftAid->getFirstname());
        $this->assertEquals('Garcia Lopez', $giftAid->getLastname());
    }

    public function test_get_firstname_with_single_name(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Sukarno',
            'homeaddress' => '1 Test St',
        ]);

        $this->assertEquals('Sukarno', $giftAid->getFirstname());
        $this->assertEquals('', $giftAid->getLastname());
    }

    public function test_has_valid_name_split_with_dedicated_columns(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Sukarno',
            'homeaddress' => '1 Test St',
            'firstname' => 'Sukarno',
            'lastname' => 'Sukarno',
        ]);

        $this->assertTrue($giftAid->hasValidNameSplit());
    }

    public function test_has_valid_name_split_with_spaced_fullname(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'John Smith',
            'homeaddress' => '1 Test St',
        ]);

        $this->assertTrue($giftAid->hasValidNameSplit());
    }

    public function test_has_valid_name_split_false_for_single_word_fullname_no_columns(): void
    {
        $user = $this->createTestUser();
        $giftAid = GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'Sukarno',
            'homeaddress' => '1 Test St',
        ]);

        $this->assertFalse($giftAid->hasValidNameSplit());
    }

    public function test_firstname_lastname_stored_in_database(): void
    {
        $user = $this->createTestUser();
        GiftAid::create([
            'userid' => $user->id,
            'period' => GiftAid::PERIOD_FUTURE,
            'timestamp' => now(),
            'fullname' => 'John Smith',
            'homeaddress' => '1 Test St',
            'firstname' => 'John',
            'lastname' => 'Smith',
        ]);

        $this->assertDatabaseHas('giftaid', [
            'userid' => $user->id,
            'firstname' => 'John',
            'lastname' => 'Smith',
        ]);
    }
}

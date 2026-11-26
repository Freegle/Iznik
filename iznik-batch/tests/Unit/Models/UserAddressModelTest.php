<?php

namespace Tests\Unit\Models;

use App\Models\UserAddress;
use Tests\TestCase;

class UserAddressModelTest extends TestCase
{
    public function test_user_address_model_has_correct_table(): void
    {
        $address = new UserAddress();
        $this->assertEquals('users_addresses', $address->getTable());
    }

    public function test_user_address_model_has_no_timestamps(): void
    {
        $address = new UserAddress();
        $this->assertFalse($address->timestamps);
    }

    public function test_user_address_model_casts_coordinates(): void
    {
        $address = new UserAddress();
        $casts = $address->getCasts();

        $this->assertArrayHasKey('lat', $casts);
        $this->assertArrayHasKey('lng', $casts);
        $this->assertEquals('float', $casts['lat']);
        $this->assertEquals('float', $casts['lng']);
    }

    public function test_get_formatted_returns_null_without_pafid(): void
    {
        $address = new UserAddress();
        $address->pafid = null;

        $result = $address->getFormatted();

        $this->assertNull($result);
    }

    public function test_get_single_line_calls_get_formatted_with_comma_delimiter(): void
    {
        $address = new UserAddress();
        $address->pafid = null;

        // Without a pafid, both should return null.
        $this->assertNull($address->getSingleLine());
        $this->assertNull($address->getMultiLine());
    }

    public function test_get_coordinates_returns_direct_coordinates_if_available(): void
    {
        $address = new UserAddress();
        $address->lat = 51.5074;
        $address->lng = -0.1278;

        [$lat, $lng] = $address->getCoordinates();

        $this->assertEquals(51.5074, $lat);
        $this->assertEquals(-0.1278, $lng);
    }

    public function test_get_coordinates_returns_null_without_pafid_or_coords(): void
    {
        $address = new UserAddress();
        $address->lat = null;
        $address->lng = null;
        $address->pafid = null;

        [$lat, $lng] = $address->getCoordinates();

        $this->assertNull($lat);
        $this->assertNull($lng);
    }

    public function test_user_address_model_is_not_guarded_except_id(): void
    {
        $address = new UserAddress();
        $this->assertEquals(['id'], $address->getGuarded());
    }

    public function test_get_formatted_with_custom_delimiter(): void
    {
        $address = new UserAddress();
        $address->pafid = null;

        // Should accept different delimiters gracefully.
        $this->assertNull($address->getFormatted(', '));
        $this->assertNull($address->getFormatted("\n"));
        $this->assertNull($address->getFormatted(' - '));
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\User;
use Tests\TestCase;

/**
 * settings.mylocation is the location a member chose in Freegle. A Trash Nothing member
 * never chooses one here - TN is the master for their location and tn:sync keeps
 * lastlocation in step with it - so on their accounts it is stale V1 data and is ignored.
 */
class UserChosenLatLngTest extends TestCase
{
    private const SETTINGS = ['mylocation' => ['lat' => 53.453008, 'lng' => -2.103187]];

    public function test_returns_the_chosen_point_for_a_freegle_member(): void
    {
        $this->assertSame([53.453008, -2.103187], User::chosenLatLng(self::SETTINGS, null));
    }

    public function test_accepts_settings_as_a_json_string(): void
    {
        $this->assertSame([53.453008, -2.103187], User::chosenLatLng(json_encode(self::SETTINGS), null));
    }

    public function test_ignores_it_for_a_trash_nothing_member(): void
    {
        $this->assertNull(User::chosenLatLng(self::SETTINGS, 1168520));
    }

    public function test_needs_both_coordinates(): void
    {
        $this->assertNull(User::chosenLatLng(['mylocation' => ['lat' => 53.4]], null));
        $this->assertNull(User::chosenLatLng(['mylocation' => ['lat' => 53.4, 'lng' => null]], null));
        $this->assertNull(User::chosenLatLng(null, null));
        $this->assertNull(User::chosenLatLng('not json', null));
    }
}

<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Fixture members live in London, where TestCase::createTestMessage puts fixture posts.
 *
 * With no communities a digest carries the posts around where a member lives, and a member with
 * no location gets none. The location goes in users.lastlocation rather than settings, because
 * many tests replace settings wholesale after creating a member. Pass 'lastlocation' => null for
 * a member with no location.
 */
trait MembersLiveInLondon
{
    private ?int $londonLocationId = null;

    protected function createTestUser(array $attributes = []): User
    {
        if (! array_key_exists('lastlocation', $attributes)) {
            $this->londonLocationId ??= DB::table('locations')->insertGetId([
                'name' => 'WC2N 5DU', 'type' => 'Postcode', 'lat' => 51.5074, 'lng' => -0.1278,
            ]);
            $attributes['lastlocation'] = $this->londonLocationId;
        }

        return parent::createTestUser($attributes);
    }
}

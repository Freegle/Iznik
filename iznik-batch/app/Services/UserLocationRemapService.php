<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserLocationRemapService
{
    public function remapLocations(bool $dryRun = false): array
    {
        $cutoff = now()->subHours(48)->format('Y-m-d H:i:s');

        $users = DB::table('users')
            ->where('lastaccess', '>=', $cutoff)
            ->whereNotNull('settings')
            ->select('id', 'settings')
            ->get();

        Log::info("UserLocationRemap: checking {$users->count()} recently-active users");

        $changed = 0;
        $samples = [];

        foreach ($users as $user) {
            $settings = json_decode($user->settings, true);

            if (!isset($settings['mylocation']['id'])) {
                continue;
            }

            $locationId = $settings['mylocation']['id'];
            $cachedName = $settings['mylocation']['name'] ?? null;

            $location = DB::table('locations')
                ->where('id', $locationId)
                ->select('id', 'osm_id', 'name', 'type', 'popularity', 'gridid', 'postcodeid', 'areaid', 'lat', 'lng', 'maxdimension')
                ->first();

            if (!$location) {
                continue;
            }

            if ($location->name === $cachedName) {
                continue;
            }

            Log::info("UserLocationRemap: user #{$user->id} {$cachedName} => {$location->name}");

            $settings['mylocation'] = (array) $location;

            if (!$dryRun) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['settings' => json_encode($settings)]);
            }

            if (count($samples) < 10) {
                $samples[] = ['userid' => $user->id, 'old' => $cachedName, 'new' => $location->name];
            }

            $changed++;
        }

        Log::info("UserLocationRemap: " . ($dryRun ? 'would change ' : 'changed ') . "{$changed} of {$users->count()}");

        return ['changed' => $changed, 'checked' => $users->count(), 'samples' => $samples];
    }
}

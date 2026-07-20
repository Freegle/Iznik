<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Get-or-create a user's persistent auto-login "Link" key (users_logins,
 * type='Link') — the same key the Go API validates for keyed one-click links
 * (login, forget, relevant-off). Get-or-create (not overwrite) so a key already
 * handed out in an earlier email keeps working.
 *
 * Mirrors iznik-server-go getOrCreateLoginKey.
 */
class LoginLinkService
{
    public function getOrCreateKey(int $userId): string
    {
        $existing = DB::table('users_logins')
            ->where('userid', $userId)
            ->where('type', 'Link')
            ->value('credentials');

        if (! empty($existing)) {
            return $existing;
        }

        $key = bin2hex(random_bytes(16));
        DB::table('users_logins')->insert([
            'userid' => $userId,
            'type' => 'Link',
            'uid' => (string) $userId,
            'credentials' => $key,
            'added' => now(),
        ]);

        return $key;
    }
}

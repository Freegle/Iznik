<?php

namespace App\Services\CookieYes;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Keeps the CookieYes OAuth login in the shared `config` table, encrypted with
 * APP_KEY, so it survives redeploys and is never readable as plain text there.
 */
class CookieYesTokenStore
{
    // Client id, token endpoint, refresh token and the cached access token.
    public const KEY = 'cookieyes.oauth';

    // A login started by cookieyes:authorize and not yet completed.
    public const PENDING_KEY = 'cookieyes.oauth_pending';

    public function credentials(): ?array
    {
        return $this->read(self::KEY);
    }

    public function saveCredentials(array $credentials): void
    {
        $this->write(self::KEY, $credentials);
    }

    public function pending(): ?array
    {
        return $this->read(self::PENDING_KEY);
    }

    public function savePending(array $pending): void
    {
        $this->write(self::PENDING_KEY, $pending);
    }

    public function forgetPending(): void
    {
        DB::table('config')->where('key', self::PENDING_KEY)->delete();
    }

    private function read(string $key): ?array
    {
        $value = DB::table('config')->where('key', $key)->value('value');
        if ($value === null) {
            return null;
        }

        try {
            $decoded = json_decode(Crypt::decryptString($value), true);
        } catch (DecryptException) {
            // Written under a different APP_KEY: as good as never authorised.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function write(string $key, array $value): void
    {
        DB::table('config')->upsert(
            [['key' => $key, 'value' => Crypt::encryptString(json_encode($value))]],
            ['key'],
            ['value'],
        );
    }
}

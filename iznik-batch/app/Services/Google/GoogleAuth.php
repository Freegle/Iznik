<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;

/**
 * Gets Google OAuth access tokens, in the two ways the housekeeping checks need.
 *
 * Search Console: a service account. The account is added as a user on each
 * Search Console property, so no person has to be logged in. We sign a JWT with
 * its private key and swap it for a token.
 *
 * Google Ads: the Ads API does not accept a bare service account, so it uses a
 * normal OAuth client plus a refresh token obtained once by the account owner.
 *
 * Tokens are not cached; the checks run weekly.
 */
class GoogleAuth
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * @param  list<string>  $scopes
     */
    public function serviceAccountToken(array $scopes): string
    {
        $key = $this->serviceAccountKey();

        $now = time();
        $claims = [
            'iss' => $key['client_email'],
            'scope' => implode(' ', $scopes),
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $signingInput = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])) . '.' . $this->b64(json_encode($claims));

        $private = openssl_pkey_get_private($key['private_key']);
        if ($private === false || ! openssl_sign($signingInput, $signature, $private, OPENSSL_ALGO_SHA256)) {
            throw new GoogleApiException('The Google service account private key could not be used to sign. Download a fresh JSON key for the service account.');
        }

        return $this->exchange([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $signingInput . '.' . $this->b64($signature),
        ], 'service account');
    }

    public function adsToken(): string
    {
        $missing = $this->missing([
            'GOOGLE_ADS_CUSTOMER_ID' => 'freegle.google.ads.customer_id',
            'GOOGLE_ADS_CLIENT_ID' => 'freegle.google.ads.client_id',
            'GOOGLE_ADS_CLIENT_SECRET' => 'freegle.google.ads.client_secret',
            'GOOGLE_ADS_REFRESH_TOKEN' => 'freegle.google.ads.refresh_token',
        ]);

        if ($missing !== []) {
            throw new GoogleApiException('Google Ads is not set up. Missing: ' . implode(', ', $missing) . '. See docs/developers/reference/google-housekeeping.md.');
        }

        return $this->exchange([
            'grant_type' => 'refresh_token',
            'client_id' => config('freegle.google.ads.client_id'),
            'client_secret' => config('freegle.google.ads.client_secret'),
            'refresh_token' => config('freegle.google.ads.refresh_token'),
        ], 'Google Ads refresh token');
    }

    /**
     * The names of the settings that are not set, so a person can be told exactly
     * what to add. Never the values.
     *
     * @param  array<string,string>  $settings  environment variable name => config key
     * @return list<string>
     */
    public function missing(array $settings): array
    {
        $missing = [];
        foreach ($settings as $env => $key) {
            if (trim((string) config($key)) === '') {
                $missing[] = $env;
            }
        }

        return $missing;
    }

    /**
     * @return array{client_email:string, private_key:string}
     */
    private function serviceAccountKey(): array
    {
        $json = null;
        $file = trim((string) config('freegle.google.service_account_file'));
        $b64 = trim((string) config('freegle.google.service_account_b64'));

        if ($b64 !== '') {
            $json = base64_decode($b64, true) ?: null;
        } elseif ($file !== '') {
            $json = is_readable($file) ? file_get_contents($file) : null;
            if ($json === null || $json === false) {
                throw new GoogleApiException('GOOGLE_SERVICE_ACCOUNT_FILE is set but the file cannot be read.');
            }
        }

        if ($json === null) {
            throw new GoogleApiException('Search Console is not set up. Missing: GOOGLE_SERVICE_ACCOUNT_B64 (or GOOGLE_SERVICE_ACCOUNT_FILE), the service account JSON key. See docs/developers/reference/google-housekeeping.md.');
        }

        $key = json_decode($json, true);
        if (! is_array($key) || empty($key['client_email']) || empty($key['private_key'])) {
            throw new GoogleApiException('The Google service account key is not valid JSON with client_email and private_key. Download a fresh JSON key.');
        }

        return $key;
    }

    /**
     * @param  array<string,string>  $form
     */
    private function exchange(array $form, string $what): string
    {
        $rsp = Http::asForm()->timeout(30)->post(self::TOKEN_URL, $form);

        if (! $rsp->successful() || empty($rsp->json('access_token'))) {
            $why = $rsp->json('error_description') ?? $rsp->json('error') ?? ('HTTP ' . $rsp->status());

            throw new GoogleApiException("Google would not give a token for the {$what}: {$why}");
        }

        return (string) $rsp->json('access_token');
    }

    private function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }
}

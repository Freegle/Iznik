<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the Freegle Go API (apiv2).
 *
 * Handles authentication via Link-based login and provides
 * methods for message creation and image upload.
 */
class FreegleApiClient
{
    private string $baseUrl;

    private ?string $jwt = null;

    /**
     * Mock responses for testing. When set, real HTTP calls are bypassed.
     *
     * @var array|null Array of ['status' => int, 'body' => array]
     */
    private static ?array $mockResponses = null;

    private static int $mockIndex = 0;

    public function __construct(?string $baseUrl = null)
    {
        $this->baseUrl = rtrim($baseUrl ?? config('freegle.api.v2_url'), '/');
    }

    /**
     * Set mock responses for testing.
     */
    public static function fake(array $responses): void
    {
        self::$mockResponses = $responses;
        self::$mockIndex = 0;
    }

    /**
     * Clear mock responses.
     */
    public static function clearFake(): void
    {
        self::$mockResponses = null;
        self::$mockIndex = 0;
    }

    /**
     * Authenticate to the API using a Link login key.
     *
     * @param  int  $userId  User ID
     * @param  string  $linkKey  Link login key from users_logins
     * @return bool True if authentication succeeded
     */
    public function authenticate(int $userId, string $linkKey): bool
    {
        $response = $this->post('/session', [
            'u' => $userId,
            'k' => $linkKey,
        ]);

        if ($response && ($response['ret'] ?? -1) === 0 && ! empty($response['jwt'])) {
            $this->jwt = $response['jwt'];

            return true;
        }

        Log::warning('FreegleApiClient: authentication failed', [
            'userId' => $userId,
            'response' => $response,
        ]);

        return false;
    }

    /**
     * Create a message attachment from a TUS upload.
     *
     * @param  string  $externalUid  TUS external UID (e.g. "freegletusd-abc123")
     * @return int|null Attachment ID, or null on failure
     */
    public function createImageAttachment(string $externalUid): ?int
    {
        $response = $this->post('/image', [
            'externaluid' => $externalUid,
            'type' => 'Message',
        ]);

        if ($response && ($response['ret'] ?? -1) === 0 && ! empty($response['id'])) {
            return (int) $response['id'];
        }

        Log::warning('FreegleApiClient: create image attachment failed', [
            'externalUid' => $externalUid,
            'response' => $response,
        ]);

        return null;
    }

    /**
     * Create a draft message.
     *
     * @return int|null Message ID, or null on failure
     */
    public function createMessage(array $params): ?int
    {
        $response = $this->put('/message', $params);

        if ($response && ($response['ret'] ?? -1) === 0 && ! empty($response['id'])) {
            return (int) $response['id'];
        }

        Log::warning('FreegleApiClient: create message failed', [
            'params' => $params,
            'response' => $response,
        ]);

        return null;
    }

    /**
     * Publish a draft message via JoinAndPost action.
     *
     * @param  bool  $forcePending  Force the message to Pending regardless of moderation status
     * @return bool True on success
     */
    public function publishMessage(int $messageId, int $groupId, bool $forcePending = false): bool
    {
        $params = [
            'id' => $messageId,
            'action' => 'JoinAndPost',
            'groupid' => $groupId,
        ];

        if ($forcePending) {
            $params['forcepending'] = true;
        }

        $response = $this->post('/message', $params);

        if ($response && ($response['ret'] ?? -1) === 0) {
            return true;
        }

        Log::warning('FreegleApiClient: publish message failed', [
            'messageId' => $messageId,
            'groupId' => $groupId,
            'response' => $response,
        ]);

        return false;
    }

    /**
     * Patch a message (e.g. to set deadline).
     *
     * @return bool True on success
     */
    public function patchMessage(int $messageId, array $params): bool
    {
        $params['id'] = $messageId;
        $response = $this->patch('/message', $params);

        return $response && ($response['ret'] ?? -1) === 0;
    }

    private function patch(string $path, array $data): ?array
    {
        return $this->request('PATCH', $path, $data);
    }

    private function post(string $path, array $data): ?array
    {
        return $this->request('POST', $path, $data);
    }

    private function put(string $path, array $data): ?array
    {
        return $this->request('PUT', $path, $data);
    }

    private function request(string $method, string $path, array $data): ?array
    {
        if (self::$mockResponses !== null) {
            if (isset(self::$mockResponses[self::$mockIndex])) {
                $mock = self::$mockResponses[self::$mockIndex++];

                return $mock['body'] ?? null;
            }

            return null;
        }

        $url = $this->baseUrl.$path;

        $headers = ['Content-Type: application/json'];
        if ($this->jwt) {
            $headers[] = 'Authorization: '.$this->jwt;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $result = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            Log::error('FreegleApiClient: curl error', [
                'method' => $method,
                'url' => $url,
                'errno' => $errno,
            ]);

            return null;
        }

        $decoded = json_decode($result, true);

        if ($decoded === null) {
            Log::error('FreegleApiClient: invalid JSON response', [
                'method' => $method,
                'url' => $url,
                'httpCode' => $httpCode,
                'body' => substr($result, 0, 500),
            ]);

            return null;
        }

        return $decoded;
    }

    /**
     * Check if the client has a valid JWT.
     */
    public function isAuthenticated(): bool
    {
        return $this->jwt !== null;
    }
}

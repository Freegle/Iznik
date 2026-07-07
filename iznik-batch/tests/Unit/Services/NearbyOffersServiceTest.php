<?php

namespace Tests\Unit\Services;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use App\Services\NearbyOffersService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NearbyOffersServiceTest extends TestCase
{
    private NearbyOffersService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NearbyOffersService();
    }

    public function test_service_can_be_instantiated(): void
    {
        $this->assertInstanceOf(NearbyOffersService::class, $this->service);
    }

    public function test_get_random_offers_returns_collection(): void
    {
        $result = $this->service->getRandomOffers();

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_random_offers_respects_limit(): void
    {
        // Create more offers than the limit.
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        for ($i = 0; $i < 10; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'type' => Message::TYPE_OFFER,
                'subject' => "OFFER: Test Item $i (London)",
            ]);
            $this->createMessageAttachment($message);
        }

        // Request only 3.
        $result = $this->service->getRandomOffers(3);

        $this->assertLessThanOrEqual(3, $result->count());
    }

    public function test_get_random_offers_returns_structured_data(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Test Widget (London)',
        ]);
        $this->createMessageAttachment($message);

        $result = $this->service->getRandomOffers(1);

        if ($result->isNotEmpty()) {
            $offer = $result->first();
            $this->assertArrayHasKey('id', $offer);
            $this->assertArrayHasKey('subject', $offer);
            $this->assertArrayHasKey('thumbnail_url', $offer);
            $this->assertArrayHasKey('url', $offer);
        }
    }

    public function test_get_nearby_offers_returns_empty_for_user_without_location(): void
    {
        $user = $this->createTestUser(['lastlocation' => null]);

        $result = $this->service->getNearbyOffers($user);

        // Should fall back to random offers but may be empty if no messages exist.
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_offers_near_location_returns_collection(): void
    {
        $result = $this->service->getOffersNearLocation(51.5074, -0.1278);

        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $result);
    }

    public function test_get_offers_near_location_respects_limit(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup([
            'lat' => 51.5074,
            'lng' => -0.1278,
        ]);
        $this->createMembership($user, $group);

        // Create messages near the location.
        for ($i = 0; $i < 10; $i++) {
            $message = $this->createTestMessage($user, $group, [
                'type' => Message::TYPE_OFFER,
                'subject' => "OFFER: Test Item $i (London)",
                'lat' => 51.5074 + ($i * 0.001),
                'lng' => -0.1278 + ($i * 0.001),
            ]);
            $this->createMessageAttachment($message);
        }

        $result = $this->service->getOffersNearLocation(51.5074, -0.1278, 3);

        $this->assertLessThanOrEqual(3, $result->count());
    }

    public function test_subject_truncation_removes_offer_prefix(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = $this->createTestMessage($user, $group, [
            'type' => Message::TYPE_OFFER,
            'subject' => 'OFFER: Widget',
        ]);
        $this->createMessageAttachment($message);

        $result = $this->service->getRandomOffers(1);

        if ($result->isNotEmpty()) {
            $offer = $result->first();
            $this->assertStringNotContainsString('OFFER:', $offer['subject']);
        }
    }

    /**
     * Invoke the private nearbyMessageIds() so we can assert the spatial-server
     * response handling directly (it has no observable effect on the public API
     * because the caller also falls back when the id list is short).
     */
    private function callNearbyMessageIds(float $lat, float $lng, int $limit): ?array
    {
        $method = new \ReflectionMethod(NearbyOffersService::class, 'nearbyMessageIds');
        $method->setAccessible(true);

        return $method->invoke($this->service, $lat, $lng, $limit);
    }

    public function test_malformed_spatial_response_is_treated_as_unavailable(): void
    {
        // 2xx but no "results" array — a protocol violation, not "no results".
        // Must return null so the caller falls back to the MySQL bounding box
        // rather than silently proceeding with an empty id list.
        Http::fake([
            '*/v1/messages/knn*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $this->assertNull($this->callNearbyMessageIds(51.5074, -0.1278, 30));
    }

    public function test_valid_empty_spatial_response_returns_empty_array(): void
    {
        // A well-formed empty result is a legitimate "nothing nearby" answer,
        // distinct from an unavailable/malformed server (which returns null).
        Http::fake([
            '*/v1/messages/knn*' => Http::response(['results' => []], 200),
        ]);

        $this->assertSame([], $this->callNearbyMessageIds(51.5074, -0.1278, 30));
    }

    public function test_valid_spatial_response_returns_ids(): void
    {
        Http::fake([
            '*/v1/messages/knn*' => Http::response(
                ['results' => [['id' => 11], ['id' => 22], ['id' => 33]]],
                200
            ),
        ]);

        $this->assertSame([11, 22, 33], $this->callNearbyMessageIds(51.5074, -0.1278, 30));
    }

    public function test_spatial_server_error_status_returns_null(): void
    {
        Http::fake([
            '*/v1/messages/knn*' => Http::response('boom', 500),
        ]);

        $this->assertNull($this->callNearbyMessageIds(51.5074, -0.1278, 30));
    }

    /**
     * Create a message attachment for testing.
     */
    private function createMessageAttachment(Message $message): MessageAttachment
    {
        return MessageAttachment::create([
            'msgid' => $message->id,
            'contenttype' => 'image/jpeg',
            'primary' => 1,
        ]);
    }
}

<?php

namespace Tests\Unit\Services;

use App\Services\FreegleApiClient;
use Tests\TestCase;

class FreegleApiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        FreegleApiClient::clearFake();
    }

    protected function tearDown(): void
    {
        FreegleApiClient::clearFake();
        parent::tearDown();
    }

    // =====================================================================
    // Initial state
    // =====================================================================

    public function test_is_not_authenticated_on_construction(): void
    {
        $client = new FreegleApiClient('http://example.test');

        $this->assertFalse($client->isAuthenticated());
    }

    public function test_constructor_accepts_explicit_base_url(): void
    {
        $client = new FreegleApiClient('http://test-api.example.com');

        // If no real calls are made, we just verify instantiation succeeds.
        $this->assertInstanceOf(FreegleApiClient::class, $client);
    }

    // =====================================================================
    // authenticate()
    // =====================================================================

    public function test_authenticate_returns_true_and_stores_jwt_on_success(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'test-jwt-token-xyz']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(42, 'link-key-abc');

        $this->assertTrue($result);
        $this->assertTrue($client->isAuthenticated());
    }

    public function test_authenticate_returns_false_when_ret_is_nonzero(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 1, 'status' => 'Authentication failed']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(99, 'bad-key');

        $this->assertFalse($result);
        $this->assertFalse($client->isAuthenticated());
    }

    public function test_authenticate_returns_false_when_jwt_is_missing(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],  // ret=0 but no jwt field
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(1, 'key');

        $this->assertFalse($result);
        $this->assertFalse($client->isAuthenticated());
    }

    public function test_authenticate_returns_false_when_jwt_is_empty_string(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => '']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(1, 'key');

        $this->assertFalse($result);
    }

    public function test_authenticate_returns_false_when_mock_exhausted(): void
    {
        FreegleApiClient::fake([]);  // No responses configured

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(1, 'key');

        $this->assertFalse($result);
    }

    public function test_authenticate_returns_false_when_body_is_null(): void
    {
        FreegleApiClient::fake([
            ['body' => null],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->authenticate(1, 'key');

        $this->assertFalse($result);
    }

    // =====================================================================
    // createImageAttachment()
    // =====================================================================

    public function test_create_image_attachment_returns_id_on_success(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'id' => 77]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('freegletusd-abc123');

        $this->assertSame(77, $id);
    }

    public function test_create_image_attachment_casts_id_to_int(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'id' => '99']],  // string id
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('freegletusd-xyz');

        $this->assertSame(99, $id);
        $this->assertIsInt($id);
    }

    public function test_create_image_attachment_returns_null_on_failure(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 1]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('bad-uid');

        $this->assertNull($id);
    }

    public function test_create_image_attachment_returns_null_when_id_missing(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],  // no id field
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('uid-no-id');

        $this->assertNull($id);
    }

    public function test_create_image_attachment_returns_null_when_body_is_null(): void
    {
        FreegleApiClient::fake([
            ['body' => null],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('uid');

        $this->assertNull($id);
    }

    // =====================================================================
    // createMessage()
    // =====================================================================

    public function test_create_message_returns_message_id_on_success(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'id' => 1234]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createMessage(['subject' => 'OFFER: sofa (London)', 'type' => 'Offer']);

        $this->assertSame(1234, $id);
    }

    public function test_create_message_returns_null_on_failure(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 2, 'status' => 'Missing required field']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createMessage([]);

        $this->assertNull($id);
    }

    public function test_create_message_returns_null_when_id_is_absent(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createMessage(['subject' => 'x']);

        $this->assertNull($id);
    }

    // =====================================================================
    // publishMessage()
    // =====================================================================

    public function test_publish_message_returns_true_on_success(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->publishMessage(1234, 56);

        $this->assertTrue($result);
    }

    public function test_publish_message_with_force_pending_returns_true(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->publishMessage(1234, 56, forcePending: true);

        $this->assertTrue($result);
    }

    public function test_publish_message_returns_false_on_api_failure(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 3, 'status' => 'Not found']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->publishMessage(9999, 56);

        $this->assertFalse($result);
    }

    public function test_publish_message_returns_false_when_body_is_null(): void
    {
        FreegleApiClient::fake([
            ['body' => null],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->publishMessage(1, 1);

        $this->assertFalse($result);
    }

    // =====================================================================
    // patchMessage()
    // =====================================================================

    public function test_patch_message_returns_true_on_success(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->patchMessage(42, ['deadline' => '2026-12-31']);

        $this->assertTrue($result);
    }

    public function test_patch_message_returns_false_on_failure(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 1]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->patchMessage(42, ['deadline' => 'bad']);

        $this->assertFalse($result);
    }

    public function test_patch_message_returns_false_when_body_is_null(): void
    {
        FreegleApiClient::fake([
            ['body' => null],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $result = $client->patchMessage(1, []);

        $this->assertFalse($result);
    }

    // =====================================================================
    // Mock sequencing — multiple calls consume successive mock entries
    // =====================================================================

    public function test_sequential_calls_consume_successive_mock_entries(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'token-1']],
            ['body' => ['ret' => 0, 'id' => 55]],
            ['body' => ['ret' => 0]],
        ]);

        $client = new FreegleApiClient('http://example.test');

        $authed = $client->authenticate(1, 'k');         // consumes mock[0]
        $imgId  = $client->createImageAttachment('uid');  // consumes mock[1]
        $pub    = $client->publishMessage(55, 1);         // consumes mock[2]

        $this->assertTrue($authed);
        $this->assertSame(55, $imgId);
        $this->assertTrue($pub);
    }

    public function test_calls_beyond_mock_count_return_null_or_false(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'id' => 1]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $client->createImageAttachment('first');   // consumes mock[0]
        $second = $client->createImageAttachment('second');  // no mock left

        $this->assertNull($second);
    }

    // =====================================================================
    // fake() / clearFake() lifecycle
    // =====================================================================

    public function test_clear_fake_resets_mock_state(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'tok']],
        ]);
        FreegleApiClient::clearFake();

        // After clearing, a second fake() should start from index 0 again.
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'id' => 99]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $id = $client->createImageAttachment('uid');

        $this->assertSame(99, $id);
    }

    public function test_multiple_clients_share_same_mock_sequence(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'tok-a']],
            ['body' => ['ret' => 0, 'jwt' => 'tok-b']],
        ]);

        $clientA = new FreegleApiClient('http://a.test');
        $clientB = new FreegleApiClient('http://b.test');

        $a = $clientA->authenticate(1, 'k');  // consumes mock[0]
        $b = $clientB->authenticate(2, 'k');  // consumes mock[1]

        $this->assertTrue($a);
        $this->assertTrue($b);
        $this->assertTrue($clientA->isAuthenticated());
        $this->assertTrue($clientB->isAuthenticated());
    }

    // =====================================================================
    // isAuthenticated() state transitions
    // =====================================================================

    public function test_is_authenticated_is_false_after_failed_auth(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 1]],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $client->authenticate(1, 'bad');

        $this->assertFalse($client->isAuthenticated());
    }

    public function test_is_authenticated_is_true_after_successful_auth(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'my-jwt']],
        ]);

        $client = new FreegleApiClient('http://example.test');
        $client->authenticate(7, 'good-key');

        $this->assertTrue($client->isAuthenticated());
    }

    public function test_two_separate_clients_have_independent_auth_state(): void
    {
        FreegleApiClient::fake([
            ['body' => ['ret' => 0, 'jwt' => 'tok']],
            ['body' => ['ret' => 1]],
        ]);

        $authed  = new FreegleApiClient('http://example.test');
        $unauthed = new FreegleApiClient('http://example.test');

        $authed->authenticate(1, 'k');    // consumes mock[0] → success
        $unauthed->authenticate(2, 'k'); // consumes mock[1] → failure

        $this->assertTrue($authed->isAuthenticated());
        $this->assertFalse($unauthed->isAuthenticated());
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\EmailTracking;
use App\Models\EmailTrackingClick;
use App\Models\EmailTrackingImage;
use Tests\TestCase;

class EmailTrackingModelTest extends TestCase
{
    public function test_generate_tracking_id_returns_32_char_string(): void
    {
        $trackingId = EmailTracking::generateTrackingId();

        $this->assertIsString($trackingId);
        $this->assertEquals(32, strlen($trackingId));
    }

    public function test_create_for_email_creates_tracking_record(): void
    {
        $user = $this->createTestUser();
        $group = $this->createTestGroup();

        $tracking = EmailTracking::createForEmail(
            'Digest',
            $user->email_preferred,
            $user->id,
            $group->id,
            'Test Subject',
            ['custom' => 'data']
        );

        $this->assertNotNull($tracking->id);
        $this->assertEquals('Digest', $tracking->email_type);
        $this->assertEquals($user->id, $tracking->userid);
        $this->assertEquals($group->id, $tracking->groupid);
        $this->assertEquals($user->email_preferred, $tracking->recipient_email);
        $this->assertEquals('Test Subject', $tracking->subject);
        $this->assertEquals(['custom' => 'data'], $tracking->metadata);
        $this->assertNotNull($tracking->sent_at);
        $this->assertEquals(32, strlen($tracking->tracking_id));
    }

    public function test_create_for_email_works_without_optional_params(): void
    {
        $email = $this->uniqueEmail('tracking');
        $tracking = EmailTracking::createForEmail(
            'Welcome',
            $email
        );

        $this->assertNotNull($tracking->id);
        $this->assertEquals('Welcome', $tracking->email_type);
        $this->assertEquals($email, $tracking->recipient_email);
        $this->assertNull($tracking->userid);
        $this->assertNull($tracking->groupid);
        $this->assertNull($tracking->subject);
        $this->assertNull($tracking->metadata);
        $this->assertFalse((bool) $tracking->has_amp);
    }

    public function test_create_for_email_with_amp_enabled(): void
    {
        $email = $this->uniqueEmail('tracking');
        $tracking = EmailTracking::createForEmail(
            'ChatNotification',
            $email,
            null,
            null,
            'Test Subject',
            null,
            true // hasAmp = true
        );

        $this->assertNotNull($tracking->id);
        $this->assertEquals('ChatNotification', $tracking->email_type);
        $this->assertTrue((bool) $tracking->has_amp);
    }

    public function test_create_for_email_with_amp_disabled(): void
    {
        $email = $this->uniqueEmail('tracking');
        $tracking = EmailTracking::createForEmail(
            'ChatNotification',
            $email,
            null,
            null,
            'Test Subject',
            null,
            false // hasAmp = false
        );

        $this->assertNotNull($tracking->id);
        $this->assertFalse((bool) $tracking->has_amp);
    }

    public function test_user_relationship(): void
    {
        $user = $this->createTestUser();

        $tracking = EmailTracking::createForEmail(
            'Test',
            $user->email_preferred,
            $user->id
        );

        $this->assertEquals($user->id, $tracking->user->id);
    }

    public function test_group_relationship(): void
    {
        $group = $this->createTestGroup();

        $tracking = EmailTracking::createForEmail(
            'Test',
            $this->uniqueEmail('tracking'),
            null,
            $group->id
        );

        $this->assertEquals($group->id, $tracking->group->id);
    }

    public function test_clicks_relationship(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        EmailTrackingClick::create([
            'email_tracking_id' => $tracking->id,
            'link_url' => 'https://example.com',
            'clicked_at' => now(),
        ]);

        $this->assertEquals(1, $tracking->clicks()->count());
    }

    public function test_images_relationship(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        EmailTrackingImage::create([
            'email_tracking_id' => $tracking->id,
            'image_position' => 'header',
            'loaded_at' => now(),
        ]);

        $this->assertEquals(1, $tracking->images()->count());
    }

    public function test_was_opened_returns_false_when_not_opened(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $this->assertFalse($tracking->wasOpened());
    }

    public function test_was_opened_returns_true_when_opened(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));
        $tracking->update(['opened_at' => now()]);
        $tracking->refresh();

        $this->assertTrue($tracking->wasOpened());
    }

    public function test_was_clicked_returns_false_when_not_clicked(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $this->assertFalse($tracking->wasClicked());
    }

    public function test_was_clicked_returns_true_when_clicked(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));
        $tracking->update(['clicked_at' => now()]);
        $tracking->refresh();

        $this->assertTrue($tracking->wasClicked());
    }

    public function test_did_unsubscribe_returns_false_when_not_unsubscribed(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $this->assertFalse($tracking->didUnsubscribe());
    }

    public function test_did_unsubscribe_returns_true_when_unsubscribed(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));
        $tracking->update(['unsubscribed_at' => now()]);
        $tracking->refresh();

        $this->assertTrue($tracking->didUnsubscribe());
    }

    public function test_get_pixel_url_returns_correct_format(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getPixelUrl();

        $this->assertStringContainsString('/e/d/p/', $url);
        $this->assertStringContainsString($tracking->tracking_id, $url);
    }

    public function test_get_tracked_link_url_returns_correct_format(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getTrackedLinkUrl('https://example.com/page');

        $this->assertStringContainsString('/e/d/r/', $url);
        $this->assertStringContainsString($tracking->tracking_id, $url);
        $this->assertStringContainsString('url=', $url);
    }

    public function test_get_tracked_link_url_includes_position(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getTrackedLinkUrl('https://example.com', 'cta_button');

        $this->assertStringContainsString('p=cta_button', $url);
    }

    public function test_get_tracked_link_url_includes_action(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getTrackedLinkUrl('https://example.com', null, 'unsubscribe');

        $this->assertStringContainsString('a=unsubscribe', $url);
    }

    public function test_get_tracked_image_url_returns_correct_format(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getTrackedImageUrl('https://example.com/image.jpg', 'header');

        $this->assertStringContainsString('/e/d/i/', $url);
        $this->assertStringContainsString($tracking->tracking_id, $url);
        $this->assertStringContainsString('p=header', $url);
    }

    public function test_get_tracked_image_url_includes_scroll_percent(): void
    {
        $tracking = EmailTracking::createForEmail('Test', $this->uniqueEmail('tracking'));

        $url = $tracking->getTrackedImageUrl('https://example.com/image.jpg', 'footer', 80);

        $this->assertStringContainsString('s=80', $url);
    }

    public function test_create_for_email_works_inside_transaction(): void
    {
        // createForEmail must work inside a transaction (e.g., admin mail sending).
        $email = $this->uniqueEmail('tracking-txn');

        // DatabaseTransactions trait already wraps us in a transaction.
        $tracking = EmailTracking::createForEmail('TxnTest', $email);

        $this->assertNotNull($tracking->id);
        $this->assertEquals('TxnTest', $tracking->email_type);
    }
}

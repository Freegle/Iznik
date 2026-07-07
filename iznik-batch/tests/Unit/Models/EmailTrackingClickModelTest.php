<?php

namespace Tests\Unit\Models;

use App\Models\EmailTracking;
use App\Models\EmailTrackingClick;
use Tests\TestCase;

class EmailTrackingClickModelTest extends TestCase
{
    public function test_email_tracking_click_has_correct_table(): void
    {
        $click = new EmailTrackingClick();
        $this->assertEquals('email_tracking_clicks', $click->getTable());
    }

    public function test_email_tracking_click_has_no_timestamps(): void
    {
        $click = new EmailTrackingClick();
        $this->assertFalse($click->timestamps);
    }

    public function test_email_tracking_click_casts(): void
    {
        $click = new EmailTrackingClick();
        $casts = $click->getCasts();

        $this->assertArrayHasKey('clicked_at', $casts);
        $this->assertEquals('datetime', $casts['clicked_at']);
    }

    public function test_email_tracking_click_fillable(): void
    {
        $click = new EmailTrackingClick();
        $fillable = $click->getFillable();

        $this->assertContains('email_tracking_id', $fillable);
        $this->assertContains('link_url', $fillable);
        $this->assertContains('link_position', $fillable);
        $this->assertContains('action', $fillable);
        $this->assertContains('ip_address', $fillable);
        $this->assertContains('user_agent', $fillable);
        $this->assertContains('clicked_at', $fillable);
    }

    public function test_email_tracking_click_belongs_to_tracking(): void
    {
        $user = $this->createTestUser();
        
        $tracking = EmailTracking::create([
            'user_id' => $user->id,
            'email_type' => 'chat_notification',
            'recipient_email' => $user->email_preferred,
        ]);

        $click = EmailTrackingClick::create([
            'email_tracking_id' => $tracking->id,
            'link_url' => 'https://example.com/test',
            'link_position' => 1,
            'action' => 'click',
            'clicked_at' => now(),
        ]);

        $this->assertInstanceOf(EmailTracking::class, $click->tracking);
        $this->assertEquals($tracking->id, $click->tracking->id);
    }
}

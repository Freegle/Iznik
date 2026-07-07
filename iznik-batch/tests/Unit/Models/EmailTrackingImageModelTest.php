<?php

namespace Tests\Unit\Models;

use App\Models\EmailTracking;
use App\Models\EmailTrackingImage;
use Tests\TestCase;

class EmailTrackingImageModelTest extends TestCase
{
    public function test_email_tracking_image_has_correct_table(): void
    {
        $image = new EmailTrackingImage();
        $this->assertEquals('email_tracking_images', $image->getTable());
    }

    public function test_email_tracking_image_has_no_timestamps(): void
    {
        $image = new EmailTrackingImage();
        $this->assertFalse($image->timestamps);
    }

    public function test_email_tracking_image_casts(): void
    {
        $image = new EmailTrackingImage();
        $casts = $image->getCasts();

        $this->assertArrayHasKey('loaded_at', $casts);
        $this->assertEquals('datetime', $casts['loaded_at']);
    }

    public function test_email_tracking_image_fillable(): void
    {
        $image = new EmailTrackingImage();
        $fillable = $image->getFillable();

        $this->assertContains('email_tracking_id', $fillable);
        $this->assertContains('image_position', $fillable);
        $this->assertContains('estimated_scroll_percent', $fillable);
        $this->assertContains('loaded_at', $fillable);
    }

    public function test_email_tracking_image_belongs_to_tracking(): void
    {
        $user = $this->createTestUser();
        
        $tracking = EmailTracking::create([
            'user_id' => $user->id,
            'email_type' => 'chat_notification',
            'recipient_email' => $user->email_preferred,
        ]);

        $image = EmailTrackingImage::create([
            'email_tracking_id' => $tracking->id,
            'image_position' => 1,
            'estimated_scroll_percent' => 25.0,
            'loaded_at' => now(),
        ]);

        $this->assertInstanceOf(EmailTracking::class, $image->tracking);
        $this->assertEquals($tracking->id, $image->tracking->id);
    }
}

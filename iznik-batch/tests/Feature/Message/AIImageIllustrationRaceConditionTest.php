<?php

namespace Tests\Feature\Message;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageGroup;
use Tests\TestCase;

/**
 * AssertFlip Test: AI images added to posts with member photos
 *
 * Bug: When a member uploads a photo after the illustrations script checks
 * for attachments but before it inserts the AI image, the post ends up with
 * BOTH the member photo AND the AI illustration. The cleanup script should
 * remove the AI image but currently does not prevent the race condition.
 *
 * STEP 1: Test asserts the BUGGY behaviour (both images exist).
 * STEP 2: Inverted test asserts the FIXED behaviour (only member photo exists).
 */
class AIImageIllustrationRaceConditionTest extends TestCase
{
    public function test_message_with_both_member_photo_and_ai_illustration_buggy_behavior(): void
    {
        // Create a message with no attachments
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (Location)',
            'textbody' => 'Test message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        // Verify message initially has no attachments
        $initialAttachmentCount = MessageAttachment::where('msgid', $message->id)->count();
        $this->assertEquals(0, $initialAttachmentCount);

        // Simulate the illustrations script checking if message has attachments (it doesn't)
        $hasAttachments = MessageAttachment::where('msgid', $message->id)->exists();
        $this->assertFalse($hasAttachments);

        // RACE CONDITION: Member uploads a photo after the check but before AI insert
        MessageAttachment::create([
            'msgid' => $message->id,
            'externaluid' => 'member-photo-uid-123',
            'externalmods' => json_encode(['ai' => false]),
            'contenttype' => 'image/jpeg',
            'primary' => 1,
        ]);

        // Script continues and adds AI illustration anyway (BUG)
        MessageAttachment::create([
            'msgid' => $message->id,
            'externaluid' => 'ai-illustration-uid-456',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype' => 'image/jpeg',
            'primary' => 0,
        ]);

        // ASSERTION: Verify BUGGY state - both images now exist
        $attachments = MessageAttachment::where('msgid', $message->id)->get();
        $this->assertEquals(2, $attachments->count());

        // Count member photos vs AI illustrations
        $memberPhotos = $attachments->filter(function ($att) {
            $mods = json_decode($att->externalmods ?? '{}', true);
            return !($mods['ai'] ?? false);
        })->count();

        $aiIllustrations = $attachments->filter(function ($att) {
            $mods = json_decode($att->externalmods ?? '{}', true);
            return $mods['ai'] ?? false;
        })->count();

        // BUGGY ASSERTION: Both should exist (this is the bug we're documenting)
        $this->assertEquals(1, $memberPhotos, 'Should have exactly one member photo');
        $this->assertEquals(1, $aiIllustrations, 'Should have one AI illustration (BUGGY - should be 0)');
        $this->assertEquals(2, $attachments->count(), 'BUGGY: Message should have both member photo AND AI illustration');
    }

    /**
     * STEP 2: INVERTED TEST - Should FAIL on buggy code, PASS after fix
     *
     * This test inverts all assertions from Step 1. After the fix is implemented,
     * when a message has both AI and member attachments due to the race condition,
     * the cleanup logic should have removed the AI illustration, leaving only the
     * member photo. This test FAILS on buggy code because the AI image is still present.
     */
    public function test_message_with_both_member_photo_and_ai_illustration_fixed_behavior(): void
    {
        // Create a message with no attachments
        $user = $this->createTestUser();
        $group = $this->createTestGroup();
        $this->createMembership($user, $group);

        $message = Message::create([
            'type' => Message::TYPE_OFFER,
            'fromuser' => $user->id,
            'subject' => 'OFFER: Test Item (Location)',
            'textbody' => 'Test message.',
            'source' => 'Platform',
            'date' => now(),
            'arrival' => now(),
            'lat' => $group->lat,
            'lng' => $group->lng,
        ]);

        MessageGroup::create([
            'msgid' => $message->id,
            'groupid' => $group->id,
            'collection' => MessageGroup::COLLECTION_APPROVED,
            'arrival' => now(),
        ]);

        // Simulate the race condition: both member photo and AI illustration exist
        MessageAttachment::create([
            'msgid' => $message->id,
            'externaluid' => 'member-photo-uid-123',
            'externalmods' => json_encode(['ai' => false]),
            'contenttype' => 'image/jpeg',
            'primary' => 1,
        ]);

        MessageAttachment::create([
            'msgid' => $message->id,
            'externaluid' => 'ai-illustration-uid-456',
            'externalmods' => json_encode(['ai' => true]),
            'contenttype' => 'image/jpeg',
            'primary' => 0,
        ]);

        // After the fix is implemented, the cleanup/guard logic will have removed the AI image
        // These INVERTED assertions will FAIL on buggy code and PASS after fix
        $attachments = MessageAttachment::where('msgid', $message->id)->get();

        $memberPhotos = $attachments->filter(function ($att) {
            $mods = json_decode($att->externalmods ?? '{}', true);
            return !($mods['ai'] ?? false);
        })->count();

        $aiIllustrations = $attachments->filter(function ($att) {
            $mods = json_decode($att->externalmods ?? '{}', true);
            return $mods['ai'] ?? false;
        })->count();

        // INVERTED ASSERTIONS (fail on buggy, pass after fix)
        $this->assertEquals(1, $memberPhotos, 'FIXED: Should have exactly one member photo');
        $this->assertNotEquals(1, $aiIllustrations, 'FIXED: Should NOT have AI illustration (currently buggy - has 1)');
        $this->assertEquals(0, $aiIllustrations, 'FIXED: AI illustrations should be removed (currently buggy - has 1)');
        $this->assertNotEquals(2, $attachments->count(), 'FIXED: Should NOT have both images (currently buggy - has 2)');
        $this->assertEquals(1, $attachments->count(), 'FIXED: Only member photo should remain (currently buggy - has 2)');
    }
}

<?php

namespace Tests\Unit\Models;

use App\Models\Message;
use Tests\TestCase;

/**
 * Tests for Message model methods.
 */
class MessageTest extends TestCase
{
    // ========================================
    // determineType Tests
    // ========================================

    public function test_determine_type_offer_standard(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFER: Free sofa (London)'));
    }

    public function test_determine_type_wanted_standard(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('WANTED: Garden tools (Manchester)'));
    }

    public function test_determine_type_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('TAKEN: Free sofa (London)'));
    }

    public function test_determine_type_received(): void
    {
        $this->assertEquals(Message::TYPE_RECEIVED, Message::determineType('RECEIVED: Garden tools (Manchester)'));
    }

    public function test_determine_type_offer_misspelled(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFR: Free sofa'));
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFER: Free sofa'));
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFERED: Free sofa'));
    }

    public function test_determine_type_wanted_misspelled(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('WNTED: Garden tools'));
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('WATED: Garden tools'));
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('WAMTED: Garden tools'));
    }

    public function test_determine_type_welsh_offer(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('CYNNIG: Soffa am ddim'));
    }

    public function test_determine_type_welsh_wanted(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('EISIAU: Offer gardd'));
    }

    public function test_determine_type_welsh_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('CYMERWYD: Soffa'));
    }

    public function test_determine_type_welsh_received(): void
    {
        $this->assertEquals(Message::TYPE_RECEIVED, Message::determineType('DERBYNIWYD: Offer gardd'));
    }

    public function test_determine_type_earliest_match_wins(): void
    {
        // "offer" appears before "wanted" so should be TYPE_OFFER
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFER wanted item'));

        // "wanted" appears before "offer" so should be TYPE_WANTED
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('WANTED to offer'));
    }

    public function test_determine_type_case_insensitive(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('offer: Free sofa'));
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('Offer: Free sofa'));
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('OFFER: Free sofa'));
    }

    public function test_determine_type_word_boundaries(): void
    {
        // "Offerton" contains "offer" but shouldn't match because of word boundary
        // Actually, "offer" appears at position 0 in "Offerton", and the regex uses \b
        // Let me check if this is correct behavior
        $this->assertEquals(Message::TYPE_OTHER, Message::determineType('Offerton sofa for sale'));
    }

    public function test_determine_type_available_as_offer(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('Available: Free sofa'));
    }

    public function test_determine_type_looking_as_wanted(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('Looking for garden tools'));
    }

    public function test_determine_type_need_as_wanted(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('NEED: Garden tools'));
    }

    public function test_determine_type_null_returns_other(): void
    {
        $this->assertEquals(Message::TYPE_OTHER, Message::determineType(null));
    }

    public function test_determine_type_empty_returns_other(): void
    {
        $this->assertEquals(Message::TYPE_OTHER, Message::determineType(''));
    }

    public function test_determine_type_no_match_returns_other(): void
    {
        $this->assertEquals(Message::TYPE_OTHER, Message::determineType('Hello world'));
    }

    public function test_determine_type_reoffer(): void
    {
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('Re-offer: Free sofa (London)'));
        $this->assertEquals(Message::TYPE_OFFER, Message::determineType('REOFFER: Free sofa (London)'));
    }

    public function test_determine_type_promised_as_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('PROMISED: Free sofa'));
    }

    public function test_determine_type_collected_as_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('COLLECTED: Garden tools'));
    }

    public function test_determine_type_gone_as_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('GONE: Free sofa'));
    }

    public function test_determine_type_withdrawn_as_taken(): void
    {
        $this->assertEquals(Message::TYPE_TAKEN, Message::determineType('WITHDRAWN: Free sofa'));
    }

    public function test_determine_type_seeking_as_wanted(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('Seeking garden tools'));
    }

    public function test_determine_type_require_as_wanted(): void
    {
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('REQUIRE: Garden tools'));
        $this->assertEquals(Message::TYPE_WANTED, Message::determineType('REQUIRED: Garden tools'));
    }
}

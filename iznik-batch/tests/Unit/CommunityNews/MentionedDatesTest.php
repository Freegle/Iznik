<?php

namespace Tests\Unit\CommunityNews;

use App\Services\CommunityNews\MentionedDates;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class MentionedDatesTest extends TestCase
{
    private Carbon $now;

    protected function setUp(): void
    {
        parent::setUp();
        $this->now = Carbon::parse('2026-08-14 11:00:00');
    }

    public function test_plain_day_month(): void
    {
        $this->assertSame(
            '2026-08-08',
            MentionedDates::latest('On Saturday 8 August, the town centre fills with stalls.', $this->now)->toDateString()
        );
    }

    public function test_ordinal_suffix_and_explicit_year(): void
    {
        $this->assertSame(
            '2027-03-01',
            MentionedDates::latest('Grand opening on the 1st March 2027.', $this->now)->toDateString()
        );
    }

    public function test_range_yields_the_end_date(): void
    {
        // "13 to" carries no month, so only "16 August" parses — the end date,
        // which is what an is-it-over judgement needs.
        $this->assertSame(
            '2026-08-16',
            MentionedDates::latest('From Thursday 13 to Sunday 16 August, dinosaurs stomp in.', $this->now)->toDateString()
        );
    }

    public function test_latest_of_several_mentions_wins(): void
    {
        $this->assertSame(
            '2026-09-14',
            MentionedDates::latest('Opens 20 August and runs until 14 September.', $this->now)->toDateString()
        );
    }

    public function test_yearless_date_months_behind_rolls_to_next_year(): void
    {
        $decemberNow = Carbon::parse('2026-12-28 09:00:00');
        $this->assertSame(
            '2027-01-08',
            MentionedDates::latest('Pantomime on 8 January at the Empire.', $decemberNow)->toDateString()
        );
    }

    public function test_no_dates_gives_null(): void
    {
        $this->assertNull(MentionedDates::latest('A lovely new footpath has opened along the canal.', $this->now));
        $this->assertNull(MentionedDates::latest('Open every Saturday morning.', $this->now));
    }

    public function test_invalid_day_is_ignored(): void
    {
        $this->assertNull(MentionedDates::latest('The 31 September deadline was a typo.', $this->now));
    }

    public function test_visibly_over_only_fires_on_undated_items_with_past_text(): void
    {
        $over = (object) ['event_date' => null, 'title' => 'Food festival', 'snippet' => 'On Saturday 8 August, stalls fill the square.'];
        $ahead = (object) ['event_date' => null, 'title' => 'Fun day', 'snippet' => 'On Saturday 29 August, the park hosts games.'];
        $undated = (object) ['event_date' => null, 'title' => 'New footpath', 'snippet' => 'A lovely new route along the canal.'];
        $dated = (object) ['event_date' => '2026-08-08', 'title' => 'Handled by the DB filter', 'snippet' => 'On Saturday 8 August.'];

        $this->assertTrue(MentionedDates::visiblyOver($over, $this->now));
        $this->assertFalse(MentionedDates::visiblyOver($ahead, $this->now));
        $this->assertFalse(MentionedDates::visiblyOver($undated, $this->now));
        $this->assertFalse(MentionedDates::visiblyOver($dated, $this->now));
    }
}

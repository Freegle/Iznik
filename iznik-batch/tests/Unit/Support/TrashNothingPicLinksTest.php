<?php

namespace Tests\Unit\Support;

use App\Support\TrashNothingPicLinks;
use PHPUnit\Framework\TestCase;

class TrashNothingPicLinksTest extends TestCase
{
    public function test_null_and_empty_are_returned_as_they_are(): void
    {
        $this->assertNull(TrashNothingPicLinks::strip(null));
        $this->assertSame('', TrashNothingPicLinks::strip(''));
    }

    public function test_the_check_out_the_pictures_block_at_the_end_goes(): void
    {
        $this->assertSame(
            'Sofas in great condition.',
            TrashNothingPicLinks::strip("Sofas in great condition.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/2vwPHsx")
        );
    }

    public function test_text_after_the_block_stays_with_one_blank_line_before_it(): void
    {
        $this->assertSame(
            "A spinner.\n\nFair Chance Policy: I'll wait.",
            TrashNothingPicLinks::strip("A spinner.\n\nCheck out the pictures at:\nhttps://trashnothing.com/pics/F8hYr77\n\nFair Chance Policy: I'll wait.")
        );
    }

    public function test_an_address_on_its_own_line_goes(): void
    {
        $this->assertSame(
            "A bed.\n\nFair Chance Policy: I'll wait.",
            TrashNothingPicLinks::strip("A bed.\n\nhttps://trashnothing.com/pics/67SKwaG\n\nFair Chance Policy: I'll wait.")
        );
    }

    public function test_other_trashnothing_addresses_and_text_stay(): void
    {
        $body = "See https://trashnothing.com/fd/about for how it works.\nPlease contact me.";
        $this->assertSame($body, TrashNothingPicLinks::strip($body));
    }
}

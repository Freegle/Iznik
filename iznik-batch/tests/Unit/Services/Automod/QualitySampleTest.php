<?php

namespace Tests\Unit\Services\Automod;

use App\Services\Automod\QualitySample;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The quality sample percentage is read like every other post-moderation setting: a whole number
 * from zero up, and anything else means the default of none rather than a half-read figure.
 */
class QualitySampleTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: int}> */
    public static function readings(): array
    {
        return [
            'plain' => ['5', 5],
            'padded' => [' 20 ', 20],
            'zero is none' => ['0', 0],
            'a whole number from a test' => [100, 100],
            'over a hundred is read as a hundred' => ['250', 100],
            'a percent sign is a typo' => ['5%', 0],
            'words' => ['five', 0],
            'decimal' => ['2.5', 0],
            'negative' => ['-5', 0],
            'blank' => ['', 0],
            'null' => [null, 0],
        ];
    }

    #[DataProvider('readings')]
    public function test_percent_reads_the_setting_strictly(mixed $value, int $expected): void
    {
        config(['freegle.autoapprove.quality_check_percent' => $value]);

        $this->assertSame($expected, QualitySample::percent());
    }
}

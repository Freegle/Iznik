<?php

namespace Tests\Unit\Services\Mail\Incoming;

use App\Services\Mail\Incoming\RoutingResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoutingResultTest extends TestCase
{
    public static function allCasesProvider(): array
    {
        return [
            'APPROVED' => [RoutingResult::APPROVED, true, false, 0],
            'PENDING' => [RoutingResult::PENDING, true, false, 0],
            'INCOMING_SPAM' => [RoutingResult::INCOMING_SPAM, true, false, 0],
            'TO_VOLUNTEERS' => [RoutingResult::TO_VOLUNTEERS, false, false, 0],
            'TO_USER' => [RoutingResult::TO_USER, false, false, 0],
            'TO_SYSTEM' => [RoutingResult::TO_SYSTEM, false, false, 0],
            'RECEIPT' => [RoutingResult::RECEIPT, false, false, 0],
            'TRYST' => [RoutingResult::TRYST, false, false, 0],
            'DROPPED' => [RoutingResult::DROPPED, false, true, 0],
            'FAILURE' => [RoutingResult::FAILURE, false, true, 75],
            'ERROR' => [RoutingResult::ERROR, false, false, 0],
        ];
    }

    #[DataProvider('allCasesProvider')]
    public function test_is_saved(RoutingResult $case, bool $expectedSaved): void
    {
        $this->assertSame($expectedSaved, $case->isSaved());
    }

    #[DataProvider('allCasesProvider')]
    public function test_is_discarded(RoutingResult $case, bool $expectedSaved, bool $expectedDiscarded): void
    {
        $this->assertSame($expectedDiscarded, $case->isDiscarded());
    }

    #[DataProvider('allCasesProvider')]
    public function test_get_exit_code(RoutingResult $case, bool $expectedSaved, bool $expectedDiscarded, int $expectedExitCode): void
    {
        $this->assertSame($expectedExitCode, $case->getExitCode());
    }

    public function test_saved_and_discarded_are_mutually_exclusive_for_every_case(): void
    {
        foreach (RoutingResult::cases() as $case) {
            $this->assertFalse(
                $case->isSaved() && $case->isDiscarded(),
                "{$case->name} should not be both saved and discarded"
            );
        }
    }

    public function test_only_failure_has_a_nonzero_exit_code(): void
    {
        foreach (RoutingResult::cases() as $case) {
            if ($case === RoutingResult::FAILURE) {
                $this->assertSame(75, $case->getExitCode());
            } else {
                $this->assertSame(0, $case->getExitCode());
            }
        }
    }

    public function test_backing_values_match_legacy_v1_strings(): void
    {
        $this->assertSame('Approved', RoutingResult::APPROVED->value);
        $this->assertSame('Pending', RoutingResult::PENDING->value);
        $this->assertSame('IncomingSpam', RoutingResult::INCOMING_SPAM->value);
        $this->assertSame('ToVolunteers', RoutingResult::TO_VOLUNTEERS->value);
        $this->assertSame('ToUser', RoutingResult::TO_USER->value);
        $this->assertSame('ToSystem', RoutingResult::TO_SYSTEM->value);
        $this->assertSame('Receipt', RoutingResult::RECEIPT->value);
        $this->assertSame('Tryst', RoutingResult::TRYST->value);
        $this->assertSame('Dropped', RoutingResult::DROPPED->value);
        $this->assertSame('Failure', RoutingResult::FAILURE->value);
        $this->assertSame('Error', RoutingResult::ERROR->value);
    }

    public function test_from_value_round_trips(): void
    {
        foreach (RoutingResult::cases() as $case) {
            $this->assertSame($case, RoutingResult::from($case->value));
        }
    }

    public function test_all_eleven_cases_are_covered_by_the_provider(): void
    {
        $this->assertCount(11, RoutingResult::cases());
        $this->assertCount(11, self::allCasesProvider());
    }
}

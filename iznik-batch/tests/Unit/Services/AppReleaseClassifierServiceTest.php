<?php

namespace Tests\Unit\Services;

use App\Services\AppReleaseClassifierService;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class AppReleaseClassifierServiceTest extends TestCase
{
    protected AppReleaseClassifierService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AppReleaseClassifierService();
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_picks_up_lowercase_prefix(): void
    {
        $commits = [
            ['hash' => 'a', 'message' => 'hotfix: critical auth bug', 'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'b', 'message' => 'feat: new feature',         'author' => 'x', 'date' => '2026-04-17'],
        ];

        $result = $this->service->findHotfixCommits($commits);

        $this->assertCount(1, $result);
        $this->assertSame('a', $result[0]['hash']);
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_is_case_insensitive(): void
    {
        $commits = [
            ['hash' => 'a', 'message' => 'HOTFIX: uppercase',   'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'b', 'message' => 'HotFix: MixedCase',   'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'c', 'message' => 'hotfix: lowercase',   'author' => 'x', 'date' => '2026-04-17'],
        ];

        $result = $this->service->findHotfixCommits($commits);

        $this->assertCount(3, $result);
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_ignores_hotfix_not_at_start(): void
    {
        $commits = [
            ['hash' => 'a', 'message' => 'feat: add hotfix: workflow', 'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'b', 'message' => 'docs: mention hotfix',       'author' => 'x', 'date' => '2026-04-17'],
        ];

        $result = $this->service->findHotfixCommits($commits);

        $this->assertEmpty($result);
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_trims_whitespace_before_matching(): void
    {
        $commits = [
            ['hash' => 'a', 'message' => "   hotfix: indented",  'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'b', 'message' => "\nhotfix: newline",    'author' => 'x', 'date' => '2026-04-17'],
        ];

        $result = $this->service->findHotfixCommits($commits);

        $this->assertCount(2, $result);
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_returns_empty_for_no_commits(): void
    {
        $this->assertSame([], $this->service->findHotfixCommits([]));
    }

    #[Group('Unit')]
    public function test_findHotfixCommits_returns_empty_when_no_hotfix_present(): void
    {
        $commits = [
            ['hash' => 'a', 'message' => 'feat: x',    'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'b', 'message' => 'fix: y',     'author' => 'x', 'date' => '2026-04-17'],
            ['hash' => 'c', 'message' => 'chore: z',   'author' => 'x', 'date' => '2026-04-17'],
        ];

        $this->assertEmpty($this->service->findHotfixCommits($commits));
    }

    #[Group('Unit')]
    public function test_isWeeklyReleaseTime_true_wednesday_at_22(): void
    {
        $this->assertTrue($this->service->isWeeklyReleaseTime('2026-04-15 22:00:00'));
    }

    #[Group('Unit')]
    public function test_isWeeklyReleaseTime_true_wednesday_at_23(): void
    {
        $this->assertTrue($this->service->isWeeklyReleaseTime('2026-04-15 23:30:00'));
    }

    #[Group('Unit')]
    public function test_isWeeklyReleaseTime_false_wednesday_before_22(): void
    {
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-15 21:59:59'));
    }

    #[Group('Unit')]
    public function test_isWeeklyReleaseTime_false_other_weekdays(): void
    {
        // 2026-04-13 is Monday, 2026-04-14 Tuesday, 2026-04-16 Thursday, 2026-04-17 Friday
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-13 22:00:00'));
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-14 22:00:00'));
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-16 22:00:00'));
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-17 22:00:00'));
    }

    #[Group('Unit')]
    public function test_isWeeklyReleaseTime_false_weekends(): void
    {
        // 2026-04-18 Saturday, 2026-04-19 Sunday
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-18 22:00:00'));
        $this->assertFalse($this->service->isWeeklyReleaseTime('2026-04-19 22:00:00'));
    }

    #[Group('Unit')]
    public function test_classification_constants_are_distinct(): void
    {
        $this->assertNotEquals(
            AppReleaseClassifierService::CLASSIFICATION_URGENT,
            AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT
        );
        $this->assertNotEquals(
            AppReleaseClassifierService::CLASSIFICATION_URGENT,
            AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES
        );
        $this->assertNotEquals(
            AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT,
            AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES
        );
    }

    #[Group('Unit')]
    public function test_classification_constants_are_expected_strings(): void
    {
        $this->assertSame('URGENT',     AppReleaseClassifierService::CLASSIFICATION_URGENT);
        $this->assertSame('CAN_WAIT',   AppReleaseClassifierService::CLASSIFICATION_CAN_WAIT);
        $this->assertSame('NO_CHANGES', AppReleaseClassifierService::CLASSIFICATION_NO_CHANGES);
    }
}

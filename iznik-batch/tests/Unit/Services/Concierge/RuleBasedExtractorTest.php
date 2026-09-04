<?php

namespace Tests\Unit\Services\Concierge;

use App\Services\Concierge\Extractor;
use App\Services\Concierge\RuleBasedExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * RuleBasedExtractor is deliberately pure/deterministic (no AI, no network -
 * see its class docstring: "the thing the replay test pins, so the pipeline
 * is testable without a network call"). Plain PHPUnit unit tests, no DB.
 *
 * Same latent autoload bug as documented on TemplateDrafterTest:
 * RuleBasedExtractor (and LlmExtractor) are declared in Extractor.php
 * alongside the `Extractor` interface, so Composer's PSR-4/classmap
 * autoloader never finds them (confirmed absent from
 * vendor/composer/autoload_classmap.php even with --optimize-autoloader).
 * Worked around here with an explicit require so the class's own logic can
 * still be exercised and covered; not fixed here per the no-production-code
 * rule for this PR.
 */
class RuleBasedExtractorTest extends TestCase
{
    private RuleBasedExtractor $extractor;

    /** Catalogue with all ten numbered items the rule table can match. */
    private const ITEMS = [
        1 => ['num' => 1, 'name' => 'Stacking chair'],
        2 => ['num' => 2, 'name' => 'Filing cabinet'],
        3 => ['num' => 3, 'name' => 'Corner desk'],
        4 => ['num' => 4, 'name' => 'Bisley unit'],
        5 => ['num' => 5, 'name' => 'Round meeting table'],
        6 => ['num' => 6, 'name' => 'Desk return cabinet'],
        7 => ['num' => 7, 'name' => 'Meeting table'],
        8 => ['num' => 8, 'name' => 'Green armchair'],
        9 => ['num' => 9, 'name' => 'Cupboard'],
        10 => ['num' => 10, 'name' => 'Dining table'],
    ];

    protected function setUp(): void
    {
        if (!class_exists(RuleBasedExtractor::class)) {
            require_once __DIR__ . '/../../../../app/Services/Concierge/Extractor.php';
        }
        $this->extractor = new RuleBasedExtractor();
    }

    public function test_implements_the_extractor_interface(): void
    {
        $this->assertInstanceOf(Extractor::class, $this->extractor);
    }

    public function test_explicit_hash_number_is_recognised(): void
    {
        $result = $this->extractor->extract('I would like #2 please', 'RE: offer', self::ITEMS);

        $this->assertSame([2], $result['wants']);
    }

    public function test_explicit_hash_number_for_unknown_item_is_ignored(): void
    {
        $result = $this->extractor->extract('I would like #99 please', 'RE: offer', self::ITEMS);

        $this->assertSame([], $result['wants']);
    }

    public function test_multiple_explicit_hash_numbers_are_returned_in_ascending_order(): void
    {
        $result = $this->extractor->extract('please can I have #9 and #2', 'RE: offer', self::ITEMS);

        $this->assertSame([2, 9], $result['wants']);
    }

    public static function namedItemProvider(): array
    {
        return [
            'filing cabinet -> 2' => ['I would like the filing cabinet', [2]],
            'bisley -> 4' => ['is the bisley still available?', [4]],
            'corner desk -> 3' => ['I could use the corner desk', [3]],
            'green armchair -> 8' => ['the green armchair would be lovely', [8]],
            'bare armchair -> 8' => ['can I take the armchair', [8]],
            'stacking chair -> 1' => ['a stacking chair would help', [1]],
            'sticking chair typo -> 1' => ['that sticking chair please', [1]],
            'wooden chair -> 1' => ['the wooden chair looks great', [1]],
            'cupboard -> 9' => ['I need the cupboard', [9]],
            'bare table -> 10' => ['can I have the table', [10]],
            'bare chairs -> 1 as last resort' => ['do you have any chairs', [1]],
        ];
    }

    #[DataProvider('namedItemProvider')]
    public function test_named_item_keywords_map_to_expected_item_numbers(string $body, array $expectedWants): void
    {
        $result = $this->extractor->extract($body, 'RE: offer', self::ITEMS);

        $this->assertSame($expectedWants, $result['wants']);
    }

    public function test_round_meeting_table_matches_item_5_not_the_shorter_table_rules(): void
    {
        $result = $this->extractor->extract('the round meeting table would be great', 'RE: offer', self::ITEMS);

        // Consumed by the [5, ...] rule first, so the later 'meeting table' (7)
        // and bare 'table' (10) rules must not also match the same span.
        $this->assertSame([5], $result['wants']);
    }

    public function test_meeting_table_without_round_matches_item_7_not_bare_table(): void
    {
        $result = $this->extractor->extract('the meeting table would suit us', 'RE: offer', self::ITEMS);

        $this->assertSame([7], $result['wants']);
    }

    public function test_desk_return_cabinet_variants_all_match_item_6(): void
    {
        foreach (['desk return cabinet', 'desk-return', 'desk return'] as $phrase) {
            $result = $this->extractor->extract("I'd like the $phrase please", 'RE: offer', self::ITEMS);
            $this->assertSame([6], $result['wants'], "phrase '$phrase' should match item 6");
        }
    }

    public function test_named_item_not_in_catalogue_is_excluded_from_wants(): void
    {
        $items = [1 => self::ITEMS[1]]; // only the chair is in this catalogue
        $result = $this->extractor->extract('I would like the filing cabinet and a chair', 'RE: offer', $items);

        $this->assertSame([1], $result['wants']);
    }

    public function test_no_recognised_item_returns_empty_wants(): void
    {
        $result = $this->extractor->extract('just saying hello, no items mentioned', 'RE: offer', self::ITEMS);

        $this->assertSame([], $result['wants']);
    }

    public static function declinedProvider(): array
    {
        return [
            'already sorted' => ["thanks, we're already sorted"],
            'no longer need' => ['we no longer need it'],
            'no longer require' => ['we no longer require this'],
            "don't need contraction" => ["don't need it now, thanks"],
            'not required' => ['not required any more'],
            'not needed' => ['not needed thanks'],
            'we have enough' => ['we have enough already'],
            'we have new' => ['we have new ones now'],
            'discontinued' => ['discontinued, sorry'],
            'no thank you' => ['no thank you'],
            'no comma thank you' => ['no, thank you'],
            'kindly decline' => ['I must kindly decline'],
            'case insensitive' => ['ALREADY SORTED, thanks'],
        ];
    }

    #[DataProvider('declinedProvider')]
    public function test_decline_phrases_are_detected(string $body): void
    {
        $result = $this->extractor->extract($body, 'RE: offer', self::ITEMS);

        $this->assertTrue($result['declined']);
    }

    public function test_a_genuine_interest_message_is_not_declined(): void
    {
        $result = $this->extractor->extract('yes please, I would love the filing cabinet', 'RE: offer', self::ITEMS);

        $this->assertFalse($result['declined']);
    }

    public function test_collect_days_extracts_named_month_dates_sorted_and_deduplicated(): void
    {
        $result = $this->extractor->extract(
            'I could collect on the 15th July or 3 August, or repeat 15th July',
            'RE: offer',
            self::ITEMS
        );

        $this->assertSame(['2026-07-15', '2026-08-03'], $result['collectDays']);
    }

    public function test_collect_days_with_no_dates_mentioned_is_empty(): void
    {
        $result = $this->extractor->extract('I would like the cupboard', 'RE: offer', self::ITEMS);

        $this->assertSame([], $result['collectDays']);
    }

    public function test_collect_days_uses_default_month_for_bare_ordinal_day(): void
    {
        $result = $this->extractor->extract(
            'could I collect on the 20th?',
            'RE: offer',
            self::ITEMS,
            ['defaultMonth' => '2026-09']
        );

        $this->assertSame(['2026-09-20'], $result['collectDays']);
    }

    public function test_collect_days_without_default_month_ignores_bare_ordinal_day(): void
    {
        $result = $this->extractor->extract('could I collect on the 20th?', 'RE: offer', self::ITEMS);

        $this->assertSame([], $result['collectDays']);
    }

    public function test_collect_days_combines_named_month_and_default_month_dates(): void
    {
        // The two passes are independent regexes with no cross-deduplication:
        // the named-month pass matches "15th July" -> 2026-07-15, and the
        // bare-ordinal defaultMonth pass separately matches every ordinal day
        // in the body (including the "15th" already consumed above) -> both
        // 2026-09-15 and 2026-09-20. This documents the actual behaviour.
        $result = $this->extractor->extract(
            'the 15th July works, or the 20th if easier',
            'RE: offer',
            self::ITEMS,
            ['defaultMonth' => '2026-09']
        );

        $this->assertSame(['2026-07-15', '2026-09-15', '2026-09-20'], $result['collectDays']);
    }
}

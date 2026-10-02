<?php

namespace Tests\Unit\Services\Concierge;

use App\Services\Concierge\ConciergeEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * ConciergeEngine is deliberately pure (no I/O, no DB - see its class
 * docstring), so these are plain PHPUnit unit tests against fixture arrays.
 */
class ConciergeEngineTest extends TestCase
{
    private ConciergeEngine $engine;

    protected function setUp(): void
    {
        $this->engine = new ConciergeEngine();
    }

    // --- classifyInbound() ------------------------------------------------

    public static function bounceProvider(): array
    {
        return [
            'mailer-daemon from address' => [['From' => 'Mail Delivery System <mailer-daemon@example.com>'], 'hello'],
            'postmaster from address' => [['From' => 'postmaster@example.com'], 'hello'],
            'delivery status notification subject' => [['From' => 'a@b.com'], 'Delivery Status Notification (Failure)'],
            'undeliverable subject' => [['From' => 'a@b.com'], 'Undeliverable: your message'],
            'mail delivery failed subject' => [['From' => 'a@b.com'], 'Mail delivery failed'],
            'failure notice subject' => [['From' => 'a@b.com'], 'Failure notice'],
            'could not be delivered subject' => [['From' => 'a@b.com'], 'Your message could not be delivered'],
            'returned to sender subject' => [['From' => 'a@b.com'], 'Returned to sender'],
            'delivery incomplete subject' => [['From' => 'a@b.com'], 'Delivery incomplete'],
            'delivery has failed subject' => [['From' => 'a@b.com'], 'Delivery has failed'],
        ];
    }

    #[DataProvider('bounceProvider')]
    public function test_classify_inbound_detects_bounces(array $headers, string $subject): void
    {
        $this->assertSame(ConciergeEngine::IN_BOUNCE, $this->engine->classifyInbound($headers, $subject));
    }

    public static function autoReplyProvider(): array
    {
        return [
            'Auto-Submitted header set to auto-replied' => [['Auto-Submitted' => 'auto-replied'], 'RE: your item'],
            'X-Autoreply header present' => [['X-Autoreply' => 'yes'], 'RE: your item'],
            'X-Autorespond header present' => [['X-Autorespond' => '1'], 'RE: your item'],
            'X-Autoresponder header present' => [['X-Autoresponder' => '1'], 'RE: your item'],
            'automatic reply subject' => [[], 'Automatic reply: Out of office'],
            'auto-reply subject with hyphen' => [[], 'Auto-Reply: I am away'],
            'autoresponse subject' => [[], 'Auto response'],
            'out of office subject' => [[], 'Out of Office'],
            'out-of-office subject with hyphens' => [[], 'Out-of-Office: back Monday'],
            'away from the office subject' => [[], 'Away from the office until Monday'],
            'undelivered leading subject' => [[], 'Undelivered: courier attempted delivery twice'],
        ];
    }

    #[DataProvider('autoReplyProvider')]
    public function test_classify_inbound_detects_auto_replies(array $headers, string $subject): void
    {
        $this->assertSame(ConciergeEngine::IN_AUTO, $this->engine->classifyInbound($headers, $subject));
    }

    public function test_classify_inbound_treats_genuine_message_as_reply(): void
    {
        $this->assertSame(
            ConciergeEngine::IN_REPLY,
            $this->engine->classifyInbound(['From' => 'donor@example.com'], 'RE: yes please, I can collect')
        );
    }

    public function test_classify_inbound_header_lookup_is_case_insensitive(): void
    {
        $this->assertSame(
            ConciergeEngine::IN_BOUNCE,
            $this->engine->classifyInbound(['FROM' => 'MAILER-DAEMON@example.com'], 'hello')
        );
    }

    public function test_classify_inbound_explicit_auto_submitted_no_is_not_auto(): void
    {
        $this->assertSame(
            ConciergeEngine::IN_REPLY,
            $this->engine->classifyInbound(['Auto-Submitted' => 'no'], 'RE: yes please')
        );
    }

    public function test_classify_inbound_empty_auto_submitted_value_is_not_auto(): void
    {
        $this->assertSame(
            ConciergeEngine::IN_REPLY,
            $this->engine->classifyInbound(['Auto-Submitted' => ''], 'RE: yes please')
        );
    }

    // --- itemKind() ---------------------------------------------------------

    public static function itemKindProvider(): array
    {
        return [
            'filing cabinet -> cabinet' => ['Grey filing cabinet', 'cabinet'],
            'cupboard' => ['Kitchen cupboard', 'cupboard'],
            'table' => ['Dining table', 'table'],
            'chair' => ['Office chair', 'seating'],
            'armchair' => ['Leather armchair', 'seating'],
            'desk' => ['Computer desk', 'desk'],
            'unrecognised name falls back to other' => ['Bag of cables', 'other'],
            'case insensitive match' => ['DINING TABLE', 'table'],
        ];
    }

    #[DataProvider('itemKindProvider')]
    public function test_item_kind_maps_name_to_functional_kind(string $name, string $expectedKind): void
    {
        $this->assertSame($expectedKind, $this->engine->itemKind($name));
    }

    // --- reconcile(): low-confidence availability ---------------------------

    public function test_reconcile_when_availability_not_confident_holds_everyone_except_declines(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $repliers = [
            ['id' => 'r1'],
            ['id' => 'r2', 'declined' => true],
        ];

        $actions = $this->engine->reconcile($items, $repliers, [], ['availabilityConfident' => false]);

        $byReplier = $this->indexByReplier($actions);
        $this->assertSame(ConciergeEngine::A_HOLDING_NOTE, $byReplier['r1']['kind']);
        $this->assertSame(ConciergeEngine::A_DECLINE_ACK, $byReplier['r2']['kind']);
    }

    // --- reconcile(): firm commitments ---------------------------------------

    public function test_reconcile_firm_commitment_for_available_item_confirms_collection_on_earliest_day(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $repliers = [['id' => 'r1', 'collect' => ['Wednesday', 'Monday']]];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'r1', 'items' => [1], 'qty' => 1],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $confirm = $this->findAction($actions, ConciergeEngine::A_CONFIRM_COLLECTION);
        $this->assertNotNull($confirm);
        $this->assertSame('r1', $confirm['replier']);
        $this->assertSame(1, $confirm['item']);
        $this->assertSame('Monday', $confirm['day'], 'should pick the earliest sorted collection day');
    }

    public function test_reconcile_firm_commitment_without_collect_days_does_not_confirm_collection(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $repliers = [['id' => 'r1']];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'r1', 'items' => [1], 'qty' => 1],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $this->assertNull($this->findAction($actions, ConciergeEngine::A_CONFIRM_COLLECTION));
    }

    public function test_reconcile_firm_commitment_for_gone_item_raises_renege_alert(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => false]];
        $repliers = [['id' => 'r1']];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'r1', 'items' => [1], 'qty' => 1],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $alert = $this->findAction($actions, ConciergeEngine::A_RENEGE_ALERT);
        $this->assertNotNull($alert);
        $this->assertSame('r1', $alert['replier']);
        $this->assertSame(1, $alert['item']);
    }

    public function test_reconcile_firm_commitment_with_insufficient_quantity_apologises_for_shortfall(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Chairs', 'qty' => 2, 'available' => true]];
        $repliers = [['id' => 'r1']];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'r1', 'items' => [1], 'qty' => 4],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $shortfall = $this->findAction($actions, ConciergeEngine::A_APOLOGISE_SHORTFALL);
        $this->assertNotNull($shortfall);
        $this->assertSame(2, $shortfall['have']);
        $this->assertSame(4, $shortfall['promised']);
    }

    public function test_reconcile_ignores_commitment_for_unknown_replier(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'ghost', 'items' => [1], 'qty' => 1],
        ];

        $actions = $this->engine->reconcile($items, [], $commitments);

        $this->assertSame([], $actions);
    }

    // --- reconcile(): menu commitments ---------------------------------------

    public function test_reconcile_menu_commitment_splits_still_available_from_gone_items(): void
    {
        $items = [
            1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true],
            2 => ['num' => 2, 'name' => 'Desk', 'qty' => 1, 'available' => false],
        ];
        $repliers = [['id' => 'r1']];
        $commitments = [
            ['type' => ConciergeEngine::C_MENU, 'replier' => 'r1', 'items' => [1, 2]],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $menu = $this->findAction($actions, ConciergeEngine::A_OFFER_MENU);
        $this->assertNotNull($menu);
        $this->assertSame([1], $menu['items']);
        $this->assertSame([2], $menu['gone']);
    }

    public function test_reconcile_menu_item_already_reserved_firm_is_excluded_from_reoffer(): void
    {
        $items = [
            1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true],
        ];
        $repliers = [['id' => 'r1'], ['id' => 'r2']];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'r1', 'items' => [1], 'qty' => 1],
            ['type' => ConciergeEngine::C_MENU, 'replier' => 'r2', 'items' => [1]],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $menu = $this->findAction($actions, ConciergeEngine::A_OFFER_MENU);
        $this->assertSame([], $menu['items']);
        $this->assertSame([1], $menu['gone'], 'item reserved to another replier reads as gone for this menu');
    }

    // --- reconcile(): free-item allocation by need ---------------------------

    public function test_reconcile_allocates_free_item_matching_wanted_kind(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Dining Table', 'qty' => 1, 'available' => true]];
        $repliers = [['id' => 'r1', 'wants' => [1], 'need' => 5]];

        $actions = $this->engine->reconcile($items, $repliers, []);

        $offer = $this->findAction($actions, ConciergeEngine::A_OFFER_ALT);
        $this->assertNotNull($offer);
        $this->assertSame('r1', $offer['replier']);
        $this->assertSame(1, $offer['item']);
    }

    public function test_reconcile_flexible_replier_takes_any_free_item(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Random gadget', 'qty' => 1, 'available' => true]];
        $repliers = [['id' => 'r1', 'wants' => [99], 'flexible' => true, 'need' => 1]];

        $actions = $this->engine->reconcile($items, $repliers, []);

        $offer = $this->findAction($actions, ConciergeEngine::A_OFFER_ALT);
        $this->assertNotNull($offer);
        $this->assertSame(1, $offer['item']);
    }

    public function test_reconcile_needer_with_no_fit_and_pending_menu_org_is_held(): void
    {
        $items = [
            1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true],
        ];
        $repliers = [
            ['id' => 'menu-holder'],
            ['id' => 'org-needer', 'wants' => ['desk'], 'need' => 1, 'kind' => 'org'],
        ];
        $commitments = [
            ['type' => ConciergeEngine::C_MENU, 'replier' => 'menu-holder', 'items' => [1]],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $hold = $this->findAction($actions, ConciergeEngine::A_HOLD, 'org-needer');
        $this->assertNotNull($hold);
        $this->assertSame('pending menu picks', $hold['reason']);
    }

    public function test_reconcile_needer_with_no_fit_and_no_pending_menu_is_waitlisted(): void
    {
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $repliers = [['id' => 'r1', 'wants' => ['desk'], 'need' => 1]];

        $actions = $this->engine->reconcile($items, $repliers, []);

        $waitlist = $this->findAction($actions, ConciergeEngine::A_WAITLIST);
        $this->assertNotNull($waitlist);
        $this->assertSame('r1', $waitlist['replier']);
    }

    public function test_reconcile_excludes_declined_repliers_and_those_already_committed_from_needers(): void
    {
        $items = [
            1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true],
            2 => ['num' => 2, 'name' => 'Table', 'qty' => 1, 'available' => true],
        ];
        $repliers = [
            ['id' => 'declined', 'declined' => true, 'wants' => [1], 'need' => 10],
            ['id' => 'already-firm', 'wants' => [2], 'need' => 10],
            ['id' => 'no-wants'],
        ];
        $commitments = [
            ['type' => ConciergeEngine::C_FIRM, 'replier' => 'already-firm', 'items' => [2], 'qty' => 1],
        ];

        $actions = $this->engine->reconcile($items, $repliers, $commitments);

        $this->assertNull($this->findAction($actions, ConciergeEngine::A_OFFER_ALT, 'declined'));
        $this->assertNull($this->findAction($actions, ConciergeEngine::A_WAITLIST, 'declined'));
        $this->assertNull($this->findAction($actions, ConciergeEngine::A_OFFER_ALT, 'no-wants'));
        $this->assertNull($this->findAction($actions, ConciergeEngine::A_WAITLIST, 'no-wants'));
    }

    public function test_reconcile_prioritises_needer_by_need_then_org_over_individual_then_earliest_reply(): void
    {
        // Only one free item, "table"; three needers competing for it.
        $items = [1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true]];
        $repliers = [
            ['id' => 'low-need', 'wants' => ['table'], 'need' => 1, 'kind' => 'org', 'firstAt' => '2026-01-01'],
            ['id' => 'high-need-individual', 'wants' => ['table'], 'need' => 10, 'kind' => 'individual', 'firstAt' => '2026-01-02'],
            ['id' => 'high-need-org-later', 'wants' => ['table'], 'need' => 10, 'kind' => 'org', 'firstAt' => '2026-01-03'],
        ];

        $actions = $this->engine->reconcile($items, $repliers, []);

        // Highest need wins; among equal need, org beats individual.
        $offer = $this->findAction($actions, ConciergeEngine::A_OFFER_ALT);
        $this->assertNotNull($offer);
        $this->assertSame('high-need-org-later', $offer['replier']);

        // The other two needers get waitlisted (nothing free left).
        $this->assertNotNull($this->findAction($actions, ConciergeEngine::A_WAITLIST, 'low-need'));
        $this->assertNotNull($this->findAction($actions, ConciergeEngine::A_WAITLIST, 'high-need-individual'));
    }

    public function test_reconcile_returns_actions_sorted_deterministically_by_kind_then_replier(): void
    {
        $items = [
            1 => ['num' => 1, 'name' => 'Table', 'qty' => 1, 'available' => true],
            2 => ['num' => 2, 'name' => 'Desk', 'qty' => 1, 'available' => true],
        ];
        $repliers = [
            ['id' => 'zzz', 'wants' => ['desk'], 'need' => 1],
            ['id' => 'aaa', 'wants' => ['table'], 'need' => 1],
        ];

        $actions = $this->engine->reconcile($items, $repliers, []);

        $sorted = $actions;
        usort($sorted, function ($a, $b) {
            $c = strcmp($a['kind'], $b['kind']);

            return $c !== 0 ? $c : strcmp((string) $a['replier'], (string) $b['replier']);
        });
        $this->assertSame($sorted, $actions);
    }

    // --- helpers -------------------------------------------------------------

    private function indexByReplier(array $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            $out[$a['replier']] = $a;
        }

        return $out;
    }

    private function findAction(array $actions, string $kind, ?string $replier = null): ?array
    {
        foreach ($actions as $a) {
            if ($a['kind'] === $kind && ($replier === null || $a['replier'] === $replier)) {
                return $a;
            }
        }

        return null;
    }
}

<?php

namespace Tests\Unit\Commands\Data;

use App\Services\ContentCheckService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * data:migrate-concern-keywords - spam_keywords.action carries three values,
 * and each has to land in a different concern_keywords category.
 *
 * 'Whitelist' is the one that matters here. Those rows are protective: place
 * and shop names that exist so a shorter blocked word does not flag them,
 * such as Cock Lane against "cock". Filing them as 'review' does not merely
 * fail to protect the name, it turns the name itself into a flag word. That
 * is how 'grass' came to be a live worry word on production.
 */
class MigrateConcernKeywordsWhitelistTest extends TestCase
{
    /** @var string[] */
    private array $words = [];

    private ContentCheckService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService();
    }

    private function seedSpamKeyword(string $suffix, string $action): string
    {
        $word = 'ckmigrate' . $suffix . uniqid();
        DB::table('spam_keywords')->insert([
            'word'   => $word,
            'action' => $action,
            'type'   => 'Literal',
        ]);
        $this->words[] = $word;

        return $word;
    }

    private function categoryOf(string $word): ?object
    {
        return DB::table('concern_keywords')
            ->where('keyword', $word)
            ->first(['category', 'action', 'match_mode']);
    }

    protected function tearDown(): void
    {
        if ($this->words) {
            DB::table('concern_keywords')->whereIn('keyword', $this->words)->delete();
            DB::table('spam_keywords')->whereIn('word', $this->words)->delete();
        }

        parent::tearDown();
    }

    public function test_whitelist_becomes_allowed_not_a_flag_word(): void
    {
        $whitelisted = $this->seedSpamKeyword('_wl', 'Whitelist');

        $this->artisan('data:migrate-concern-keywords --force')->assertSuccessful();

        $row = $this->categoryOf($whitelisted);
        $this->assertNotNull($row, 'the whitelisted word should reach concern_keywords');
        $this->assertSame('allowed', $row->category,
            'a whitelisted word must be allowed, or the protected name becomes a flag word itself');
    }

    /**
     * Why 'grass' is deleted rather than allowed.
     *
     * Allowed phrases are cut out of the text before scanning. So an allowed
     * phrase that is a whole word inside a keyword which still flags stops that
     * keyword ever matching. On production 'grass' sat inside four Schedule 9
     * plant keywords that block rather than flag, such as purple pampas grass.
     */
    public function test_an_allowed_word_inside_a_longer_keyword_disables_it(): void
    {
        // A flag keyword: the whitelist is removed from the text before a flag
        // keyword is tried, so an allowed word inside it blinds it. (A block
        // keyword is matched against the text as written and is immune - see
        // the end of this test.)
        $plant = 'purple pampas grass';
        DB::table('concern_keywords')->insert([
            'keyword'    => $plant,
            'category'   => 'substance_regulated',
            'match_mode' => 'fuzzy',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'flag',
        ]);
        $this->words[] = $plant;

        // Without 'grass' whitelisted, the plant is caught.
        $caught = $this->service->checkConcernKeywords('Free plants', "Offering {$plant}", 1);
        $this->assertNotNull($caught, 'the regulated plant should be caught to begin with');

        // Whitelisting 'grass' cuts the word out of the text first, and the
        // plant keyword can no longer match.
        DB::table('concern_keywords')->insert([
            'keyword'    => 'grass',
            'category'   => 'allowed',
            'match_mode' => 'literal',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'flag',
        ]);
        $this->words[] = 'grass';

        $missed = $this->service->checkConcernKeywords('Free plants', "Offering {$plant}", 1);
        $this->assertNull($missed,
            'this is the harm the migration avoids: allowing "grass" blinds the plant keyword');

        // A block keyword containing the allowed word is not blinded: the
        // whitelist holds everyday words, and stripping one out of the middle of
        // a block keyword would switch that keyword off with no error.
        $blocked = 'blocked pampas grass';
        DB::table('concern_keywords')->insert([
            'keyword'    => $blocked,
            'category'   => 'substance_regulated',
            'match_mode' => 'fuzzy',
            'scope'      => 'global',
            'group_id'   => 0,
            'action'     => 'block',
        ]);
        $this->words[] = $blocked;

        $stillCaught = $this->service->checkConcernKeywords('Free plants', "Offering {$blocked}", 1);
        $this->assertNotNull($stillCaught, 'an allowed word must not blind a block keyword');
        $this->assertSame('block', $stillCaught['action']);
    }

    public function test_review_and_spam_actions_keep_their_own_categories(): void
    {
        $review = $this->seedSpamKeyword('_rv', 'Review');
        $spam   = $this->seedSpamKeyword('_sp', 'Spam');

        $this->artisan('data:migrate-concern-keywords --force')->assertSuccessful();

        $reviewRow = $this->categoryOf($review);
        $this->assertNotNull($reviewRow);
        $this->assertSame('review', $reviewRow->category);
        $this->assertSame('flag', $reviewRow->action);

        $spamRow = $this->categoryOf($spam);
        $this->assertNotNull($spamRow);
        $this->assertSame('scam', $spamRow->category);
        $this->assertSame('block', $spamRow->action);
    }
}

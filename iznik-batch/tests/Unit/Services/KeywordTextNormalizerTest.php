<?php

namespace Tests\Unit\Services;

use App\Services\ContentCheckService;
use App\Services\KeywordTextNormalizer;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Styled or obfuscated text must not dodge a block keyword. Every spelling trick
 * below is folded before matching, so the plain ASCII keyword catches all of
 * them; and ordinary text that happens to contain the same invisible characters
 * (emoji sequences, phone signatures) matches nothing.
 */
class KeywordTextNormalizerTest extends TestCase
{
    private ContentCheckService $service;
    private string $domain = 'ilovefreegle.shop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContentCheckService();

        DB::table('concern_keywords')->insertOrIgnore([
            'keyword' => $this->domain, 'category' => 'scam', 'action' => 'block',
            'match_mode' => 'literal', 'scope' => 'global', 'group_id' => 0,
        ]);
    }

    // --- the normaliser itself ---

    public function test_normalize_folds_mathematical_bold_to_plain_letters(): void
    {
        $this->assertSame('ilovefreegle.shop', KeywordTextNormalizer::normalize('𝐢𝐥𝐨𝐯𝐞𝐟𝐫𝐞𝐞𝐠𝐥𝐞.𝐬𝐡𝐨𝐩'));
        $this->assertSame('the value is 100 gbp', KeywordTextNormalizer::normalize('𝐓𝐡𝐞 𝐯𝐚𝐥𝐮𝐞 𝐢𝐬 𝟏𝟎𝟎 𝐆𝐁𝐏'));
    }

    public function test_normalize_folds_fullwidth_circled_and_ligatures(): void
    {
        $this->assertSame('ilove.shop', KeywordTextNormalizer::normalize('ｉｌｏｖｅ．ｓｈｏｐ'));
        $this->assertSame('ilove', KeywordTextNormalizer::normalize('ⓘⓛⓞⓥⓔ'));
        $this->assertSame('file', KeywordTextNormalizer::normalize('ﬁle'));
    }

    public function test_normalize_strips_invisible_characters_and_combining_marks(): void
    {
        $this->assertSame(
            'ilovefreegle.shop',
            KeywordTextNormalizer::normalize("i\u{200B}love\u{200D}free\u{00AD}gle\u{2063}.\u{FEFF}sh\u{202E}op\u{2060}")
        );
        $this->assertSame('ilove', KeywordTextNormalizer::normalize("i\u{0336}l\u{0336}o\u{0336}v\u{0336}e\u{0336}"));
        $this->assertSame('café', KeywordTextNormalizer::normalize('café'), 'a precomposed accent is a letter, not a mark');
    }

    public function test_normalize_folds_dot_lookalikes(): void
    {
        foreach (["ilovefreegle\u{3002}shop", "ilovefreegle\u{00B7}shop", "ilovefreegle\u{2022}shop",
                  "ilovefreegle\u{2219}shop", "ilovefreegle\u{30FB}shop", "ilovefreegle\u{FF0E}shop",
                  "ilovefreegle\u{2024}shop", 'ilovefreegle[.]shop', 'ilovefreegle(.)shop',
                  'ilovefreegle [.] shop', 'ilovefreegle dot shop', 'ILOVEFREEGLE DOT SHOP'] as $spelling) {
            $this->assertSame('ilovefreegle.shop', KeywordTextNormalizer::normalize($spelling), json_encode($spelling));
        }
    }

    public function test_normalize_folds_cyrillic_greek_and_small_capital_lookalikes(): void
    {
        $this->assertSame('ilovefreegle.shop', KeywordTextNormalizer::normalize("\u{0456}l\u{043E}v\u{0435}fr\u{0435}\u{0435}gl\u{0435}.\u{0455}h\u{043E}\u{0440}"));
        $this->assertSame('paypal', KeywordTextNormalizer::normalize("\u{03C1}\u{03B1}y\u{03C1}\u{03B1}l"));
        $this->assertSame('ilovefreegle.shop', KeywordTextNormalizer::normalize('ɪʟᴏᴠᴇꜰʀᴇᴇɢʟᴇ.ꜱʜᴏᴘ'));
        $this->assertSame('paypal', KeywordTextNormalizer::normalize("\u{0420}\u{0410}Y\u{0420}\u{0410}L"), 'upper-case look-alikes fold too');
    }

    public function test_skeleton_keeps_only_letters_and_digits(): void
    {
        $this->assertSame('ilovefreegleshop', KeywordTextNormalizer::skeleton('i l o v e f r e e g l e . s h o p'));
        $this->assertSame('ilovefreegleshop', KeywordTextNormalizer::skeleton('𝐢-𝐥-𝐨-𝐯-𝐞 freegle [.] SHOP!'));
    }

    // --- one message per trick, all blocked by the plain ASCII keyword ---

    /** @return array<string, array{string}> */
    public static function obfuscatedSpellings(): array
    {
        return [
            'mathematical bold'        => ['claim at 𝐢𝐥𝐨𝐯𝐞𝐟𝐫𝐞𝐞𝐠𝐥𝐞.𝐬𝐡𝐨𝐩 now'],
            'mathematical sans italic' => ['claim at 𝘪𝘭𝘰𝘷𝘦𝘧𝘳𝘦𝘦𝘨𝘭𝘦.𝘴𝘩𝘰𝘱 now'],
            'fullwidth'                => ['claim at ｉｌｏｖｅｆｒｅｅｇｌｅ．ｓｈｏｐ now'],
            'zero-width characters'    => ["claim at i\u{200B}love\u{200C}freegle\u{200D}.\u{2060}shop now"],
            'soft hyphen and bom'      => ["claim at ilove\u{00AD}freegle.\u{FEFF}shop now"],
            'combining strikethrough'  => ["claim at i\u{0336}l\u{0336}o\u{0336}v\u{0336}e\u{0336}freegle.shop now"],
            'bidi override'            => ["claim at \u{202E}ilovefreegle.shop\u{202C} now"],
            'ideographic full stop'    => ["claim at ilovefreegle\u{3002}shop now"],
            'middle dot'               => ["claim at ilovefreegle\u{00B7}shop now"],
            'bracketed dot'            => ['claim at ilovefreegle[.]shop now'],
            'parenthesised dot'        => ['claim at ilovefreegle(.)shop now'],
            'dot spelled out'          => ['claim at ilovefreegle dot shop now'],
            'cyrillic look-alikes'     => ["claim at \u{0456}l\u{043E}v\u{0435}fr\u{0435}\u{0435}gl\u{0435}.sh\u{043E}\u{0440} now"],
            'greek look-alikes'        => ["claim at \u{03B9}l\u{03BF}vefreegle.sh\u{03BF}p now"],
            'small capitals'           => ['claim at ɪʟᴏᴠᴇꜰʀᴇᴇɢʟᴇ.ꜱʜᴏᴘ now'],
            'upper case'               => ['claim at ILOVEFREEGLE.SHOP now'],
            'letters spaced apart'     => ['claim at i l o v e f r e e g l e . s h o p now'],
            'letters punctuated apart' => ['claim at i-l-o-v-e-f-r-e-e-g-l-e[.]s-h-o-p now'],
        ];
    }

    #[DataProvider('obfuscatedSpellings')]
    public function test_every_obfuscated_spelling_is_blocked_by_the_plain_keyword(string $text): void
    {
        $hit = $this->service->checkBlockKeywords('', $text);
        $this->assertNotNull($hit, 'must be blocked: ' . json_encode($text));
        $this->assertSame('block', $hit['action']);
        $this->assertSame($this->domain, $hit['keyword']);

        $this->assertNotNull($this->service->checkChatMessage($text), 'and the chat check must agree');
    }

    public function test_the_skeleton_pass_is_only_for_long_block_keywords(): void
    {
        $short = 'pornhubx' . substr(uniqid(), -1);
        DB::table('concern_keywords')->insertOrIgnore([
            ['keyword' => $short, 'category' => 'scam', 'action' => 'block',
             'match_mode' => 'literal', 'scope' => 'global', 'group_id' => 0],
            ['keyword' => 'ilovefreegle.flag', 'category' => 'scam', 'action' => 'flag',
             'match_mode' => 'literal', 'scope' => 'global', 'group_id' => 0],
        ]);

        $this->assertNull(
            $this->service->checkBlockKeywords('', 'see ' . implode(' ', str_split($short)) . ' here'),
            'a short block keyword gets no skeleton pass'
        );
        $this->assertNull(
            $this->service->checkConcernKeywords('', 'see i l o v e f r e e g l e . f l a g here', 0),
            'a flag keyword gets no skeleton pass'
        );
    }

    public function test_the_spaced_pass_wants_the_whole_keyword_with_nothing_stuck_to_it(): void
    {
        // The domain's letters appear in order inside each of these, but as part
        // of a longer word. A member writing them must not be dropped.
        foreach ([
            'I love Freegle! Shopping for a sofa this weekend',
            'ilovefreegleshopper here, is it still available?',
            'see xilovefreegle.shop for details',
            'ilovefreegle.shopx is not the domain',
        ] as $text) {
            $this->assertNull($this->service->checkBlockKeywords('', $text), json_encode($text));
        }

        // Up to three separator characters between letters is the domain
        // spaced out; more is not.
        $this->assertNotNull(
            $this->service->checkBlockKeywords('', 'claim at i - l - o - v - e - f - r - e - e - g - l - e . s - h - o - p now')
        );
        $this->assertNull(
            $this->service->checkBlockKeywords('', 'i    l    o    v    e    f    r    e    e    g    l    e    s    h    o    p')
        );
    }

    // --- ordinary text with the same invisible characters matches nothing ---

    public function test_emoji_sequences_and_phone_signatures_match_nothing(): void
    {
        $word = 'testflagword' . uniqid();
        DB::table('concern_keywords')->insert([
            'keyword' => $word, 'category' => 'review', 'action' => 'flag',
            'match_mode' => 'literal', 'scope' => 'global', 'group_id' => 0,
        ]);

        // Family emoji (ZWJ sequence), a heart with a variation selector, a flag
        // (regional indicators), and a signature with an invisible separator.
        $emoji = "Thanks so much \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467} \u{2764}\u{FE0F} \u{1F1EC}\u{1F1E7} see you Saturday";
        $signature = "Yes still available, come at 6\n\nSent from my phone\u{2063}\n07700 900123";

        foreach ([$emoji, $signature] as $text) {
            $this->assertNull($this->service->checkBlockKeywords('', $text), json_encode($text));
            $this->assertNull($this->service->checkConcernKeywords('', $text, 0), json_encode($text));
        }

        // And the folding does not stop a plain flag keyword from matching plain text.
        $this->assertNotNull($this->service->checkConcernKeywords('', "about {$word} please", 0));
    }
}

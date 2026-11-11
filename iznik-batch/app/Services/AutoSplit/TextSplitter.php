<?php

namespace App\Services\AutoSplit;

/**
 * Reads one post's subject and description and says whether it offers several separate things,
 * listing them when it does. The whole post is read, not searched for separators: in the
 * 2026-11 sample nearly half the posts that split had no comma or "and" in the subject ("Bikes",
 * with three bikes in the description).
 *
 * The prompt and the model were tuned and backtested together on recorded posts; change either
 * and re-run `php artisan autosplit:backtest`. docs/developers/reference/auto-split.md.
 */
class TextSplitter
{
    public const KIND_SEVERAL = 'several_items';

    public const KINDS = ['single', 'multiples_of_one', 'set', 'bundle', 'item_with_accessories', self::KIND_SEVERAL];

    /** A list longer than this is a house clearance in one post, not a few things to tell apart. */
    public const MAX_ITEMS = 12;

    public const MAX_NAME = 80;

    public const MAX_QUANTITY = 99;

    private const DESCRIPTION_CHARS = 2000;

    public const PROMPT = <<<'PROMPT'
You read one post on Freegle, a UK site where people give things away free. Decide whether the post offers several SEPARATE things that different people could each ask for on their own. If so, list them.

Choose one kind:
- "single": one thing.
- "multiples_of_one": several of the same thing that are interchangeable ("4 dining chairs", "2 bags of compost", "3 identical lamps"). One item with its quantity.
- "set": things named or sold as a set, suite, pair or kit, or that only make sense together ("dining set", "sofa set", "3 piece suite", "salt and pepper set", "bunk beds", "pair of curtains", "knife block with knives"). If the member calls it a set, it is a set.
- "bundle": a mixed lot meant to go to one person ("box of toys", "bag of baby clothes 0-3 months", "assorted kitchen bits", "craft supplies", "men's running clothes, size L: 2 shorts, a jacket, a t-shirt"). Clothes, books, toys or similar small things of one kind listed together are a bundle unless the post says they can go separately.
- "item_with_accessories": one main thing with its parts, accessories, remote, charger, cables, refills or contents ("drill with bits", "pram with raincover", "TV with remote", "printer and ink", "fish tank with filter and gravel", "two TVs and their remotes").
- "several_items": two or more separately usable things that different people might each want ("sofa, bed and two chairs", "cot and a pram", "table and four chairs", "sofa and armchair", "lawnmower and strimmer", "a 3 seater and a 2 seater sofa").

Rules:
- Only list things the post definitely offers. Ignore things it only might offer ("I may have some plants too"), things it wants, and things that are not included.
- Accessories, parts and remotes go with their main thing; they are never items of their own.
- Things of the same kind are one item with a quantity ("curtains", "slabs", "blinds of two sizes", "plant pots", "soft toys"). The exception is substantial things that the post describes one by one with a different make, model or size for each, such as three different bikes, a large and a small sofa, or a chest freezer and an upright freezer: those are separate items, each named so it can be told apart. Furniture, appliances, bikes, prams and large tools can be substantial; records, CDs, DVDs, books, games, cables, clothes, shoes, toys and small hardware never are, even when each one is named.
- If the subject and the description seem to describe different things, set confident to false.
- What the member says about going together decides it. "Must go together", "as one lot" or "all to one person" means it is not split. "Can go separately", "happy to split", "say which you want" or "I'll split" supports splitting.
- Small, cheap or used-up things listed together are a bundle unless the member says they can go separately: leftover building materials and offcuts, stationery, toiletries, first aid bits, party bits, serviettes, bottles of oil.
- A list of more than four small things is a bundle, even when each is named.
- A vague lot that does not describe the things one by one is a bundle ("books and prints", "frames and art", "a mixture of doors").
- Parts of one installation or system go together, as a set or as one thing with its parts ("kitchen cupboards and worktop", "boiler and its controls", "handrail and brackets").
- When unsure whether to split, do not split.

For "several_items": list each separate thing once, in the order written. name is short (1 to 5 words) in the member's own words, without condition, price or place; no two names may be the same. quantity is how many (default 1). Do not invent things.
For any other kind: items has exactly one entry describing the whole post.
confident is true when the kind is clear from the post.
PROMPT;

    public const SCHEMA = [
        'type' => 'OBJECT',
        'properties' => [
            'kind' => ['type' => 'STRING', 'enum' => self::KINDS],
            'confident' => ['type' => 'BOOLEAN'],
            'items' => [
                'type' => 'ARRAY',
                'items' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'name' => ['type' => 'STRING'],
                        'quantity' => ['type' => 'INTEGER'],
                    ],
                    'required' => ['name', 'quantity'],
                ],
            ],
        ],
        'required' => ['kind', 'confident', 'items'],
    ];

    public function __construct(private GeminiJson $gemini) {}

    /** Tokens the last call used: [in, out]. */
    public function usage(): array
    {
        return $this->gemini->lastUsage;
    }

    /**
     * @return array{kind:string, confident:bool, items:list<array{name:string,quantity:int}>, split:bool, why:?string}|null
     *                                                                                                                     null when there was no answer to trust, so the post is tried again next run
     */
    public function split(string $subject, ?string $description): ?array
    {
        $prompt = self::PROMPT."\n\nSubject: ".self::oneLine($subject)
            ."\nDescription: ".mb_substr((string) $description, 0, self::DESCRIPTION_CHARS);

        $answer = $this->gemini->ask([['text' => $prompt]], self::SCHEMA, 30);
        if ($answer === null) {
            return null;
        }

        return self::judge($answer);
    }

    /**
     * Turn the model's answer into a decision. Pure, so the rules that sit on top of the model
     * are tested without it: a split needs the several-items kind, confidence, 2 to MAX_ITEMS
     * items and names that differ.
     *
     * @return array{kind:string, confident:bool, items:list<array{name:string,quantity:int}>, split:bool, why:?string}|null
     */
    public static function judge(array $answer): ?array
    {
        $kind = $answer['kind'] ?? null;
        if (! in_array($kind, self::KINDS, true) || ! is_array($answer['items'] ?? null)) {
            return null;
        }

        $items = [];
        foreach ($answer['items'] as $item) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) ($item['name'] ?? '')));
            if ($name === '') {
                continue;
            }
            $items[] = [
                'name' => mb_substr($name, 0, self::MAX_NAME),
                'quantity' => max(1, min(self::MAX_QUANTITY, (int) ($item['quantity'] ?? 1))),
            ];
        }

        $confident = (bool) ($answer['confident'] ?? false);
        $out = ['kind' => $kind, 'confident' => $confident, 'items' => $items, 'split' => false, 'why' => null];

        if ($kind !== self::KIND_SEVERAL) {
            return $out;
        }

        $names = array_map(fn ($i) => mb_strtolower($i['name']), $items);
        $out['why'] = match (true) {
            ! $confident => 'not_confident',
            count($items) < 2 => 'too_few',
            count($items) > self::MAX_ITEMS => 'too_many',
            count(array_unique($names)) !== count($names) => 'same_names',
            default => null,
        };
        $out['split'] = $out['why'] === null;

        return $out;
    }

    private static function oneLine(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', $s));
    }
}

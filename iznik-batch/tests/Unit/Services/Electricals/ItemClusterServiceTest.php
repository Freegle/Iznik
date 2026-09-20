<?php

namespace Tests\Unit\Services\Electricals;

use App\Services\Desirability\TitleCanonicalService;
use App\Services\Electricals\ItemClusterService;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the folding behind the item lists on /electricals.
 *
 * The catalogue stores what the member typed, so the same thing arrives under a
 * dozen spellings. Counting those separately is what made "Beko Fridge Freezer"
 * look like a rare item on a site where fridge freezers are among the commonest
 * things offered, so that case is pinned here directly.
 */
class ItemClusterServiceTest extends TestCase
{
    private ItemClusterService $svc;

    protected function setUp(): void
    {
        parent::setUp();

        // Off by default so the word test is measured on its own; the tests that
        // exercise the embedding arm switch it on and fake the sidecar.
        config(['freegle.electricals.sidecar_url' => '']);

        $this->svc = new ItemClusterService(new TitleCanonicalService);
    }

    /** @param array<int, array{0:string,1:int,2:int,3:int}> $rows name, msgid, user, group */
    private function rows(array $rows): array
    {
        return array_map(
            fn($r) => (object) ['name' => $r[0], 'msgid' => $r[1], 'fromuser' => $r[2], 'groupid' => $r[3]],
            $rows
        );
    }

    /** @return array{canonical:string, name:string, count:int, users:int, groups:int} */
    private function cluster(string $canonical, int $count, int $users = 3, int $groups = 2): array
    {
        return [
            'canonical' => $canonical,
            'name'      => $canonical,
            'count'     => $count,
            'users'     => $users,
            'groups'    => $groups,
        ];
    }

    /**
     * The reported bug: three ways of typing the same appliance counted as three
     * different items, each of them rare, while the item itself was common.
     */
    #[Test]
    public function it_folds_brand_variants_into_one_item_type(): void
    {
        $clusters = $this->svc->cluster($this->rows([
            ['Beko Fridge Freezer', 1, 11, 21],
            ['Bosch fridge freezer', 2, 12, 22],
            ['Fridge/Freezer', 3, 13, 23],
        ]));

        $this->assertCount(1, $clusters);

        $only = reset($clusters);

        $this->assertSame('fridge freezer', $only['canonical']);
        $this->assertSame(3, $only['count']);
        $this->assertSame(3, $only['users']);
        $this->assertSame(3, $only['groups']);
    }

    /** The printed label should be the plain name, not somebody's brand. */
    #[Test]
    public function it_labels_a_cluster_with_a_name_carrying_no_brand(): void
    {
        $clusters = $this->svc->cluster($this->rows([
            ['Beko Fridge Freezer', 1, 11, 21],
            ['Beko Fridge Freezer', 2, 12, 22],
            ['Fridge Freezer', 3, 13, 23],
        ]));

        $this->assertSame('Fridge Freezer', reset($clusters)['name'], 'the branded name is commoner but says less');
    }

    /**
     * A title naming a consignment should not become the name everybody else's
     * item is filed under. "2 X sanders" was published as an item on the page
     * because it happened to be the first row back for its cluster.
     */
    #[Test]
    public function it_labels_a_cluster_with_a_name_carrying_no_quantity(): void
    {
        $clusters = $this->svc->cluster($this->rows([
            ['2 X sanders', 1, 11, 21],
            ['Lampshades x 2', 2, 12, 22],
            ['Sander', 3, 13, 23],
        ]));

        $names = array_column($clusters, 'name');
        $this->assertContains('Sander', $names, 'a plain name beats a counted one');
        $this->assertNotContains('2 X sanders', $names);
    }

    /**
     * Among names that rank equally the winner must not be whichever row the
     * database happened to return first, or the published label changes between
     * runs with no change in the data.
     */
    #[Test]
    public function the_label_does_not_depend_on_row_order(): void
    {
        $rows = [
            ['Toaster', 1, 11, 21],
            ['toaster', 2, 12, 22],
            ['TOASTER', 3, 13, 23],
        ];

        $forwards = $this->svc->cluster($this->rows($rows));
        $backwards = $this->svc->cluster($this->rows(array_reverse($rows)));

        $first = reset($forwards)['name'];
        $second = reset($backwards)['name'];

        $this->assertSame($first, $second);
        $this->assertSame('Toaster', $first, 'a title in capitals is the member\'s emphasis, not the item\'s name');
    }

    /**
     * Rows arrive one per (post, group), so a post that rippled to three groups
     * arrives three times. Summing would treble it.
     */
    #[Test]
    public function it_counts_a_post_once_however_many_groups_it_reached(): void
    {
        $clusters = $this->svc->cluster($this->rows([
            ['Kettle', 1, 11, 21],
            ['Kettle', 1, 11, 22],
            ['Kettle', 1, 11, 23],
        ]));

        $only = reset($clusters);

        $this->assertSame(1, $only['count']);
        $this->assertSame(1, $only['users']);
        $this->assertSame(3, $only['groups']);
    }

    /** A name the canonicaliser cannot make sense of still has to be counted. */
    #[Test]
    public function it_keeps_a_name_the_canonicaliser_rejects(): void
    {
        $clusters = $this->svc->cluster($this->rows([
            ['???', 1, 11, 21],
            ['???', 2, 12, 22],
        ]));

        $this->assertSame(2, reset($clusters)['count']);
    }

    /** A table lamp is not a curiosity on a site where lamps are everywhere. */
    #[Test]
    public function it_drops_a_rare_item_that_is_a_qualified_version_of_a_common_one(): void
    {
        $all = [
            'lamp'       => $this->cluster('lamp', 40),
            'table lamp' => $this->cluster('table lamp', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['table lamp' => $all['table lamp']], $all);

        $this->assertSame([], $kept);
    }

    /**
     * Containment is a test of what the words say, not of what they sound like.
     * A sewing machine is genuinely unusual however many washing machines there
     * are, so the shared word must not be enough on its own.
     */
    #[Test]
    public function it_keeps_a_rare_item_that_merely_relates_to_a_common_one(): void
    {
        $all = [
            'washing machine' => $this->cluster('washing machine', 40),
            'sewing machine'  => $this->cluster('sewing machine', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['sewing machine' => $all['sewing machine']], $all);

        $this->assertSame(['sewing machine'], array_keys($kept));
    }

    /** An item nothing common enough sits above stays whatever its words are. */
    #[Test]
    public function it_keeps_a_rare_item_with_no_popular_rival(): void
    {
        $all = [
            'lamp'       => $this->cluster('lamp', 6),
            'table lamp' => $this->cluster('table lamp', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['table lamp' => $all['table lamp']], $all);

        $this->assertSame(['table lamp'], array_keys($kept), 'six offers is not common enough to make anything a variant');
    }

    /**
     * The re-phrasings words cannot see: "breadmaker" and "bread maker" share no
     * token, so only the embedding catches them.
     */
    #[Test]
    public function it_drops_a_near_identical_rephrasing_when_the_sidecar_answers(): void
    {
        $this->fakeSidecar(['breadmaker' => [1.0, 0.0], 'bread maker' => [0.95, 0.3122498999199199]]);

        $all = [
            'bread maker' => $this->cluster('bread maker', 40),
            'breadmaker'  => $this->cluster('breadmaker', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['breadmaker' => $all['breadmaker']], $all);

        $this->assertSame([], $kept);
    }

    /**
     * Below near-identity cosine stops meaning "the same item" and starts meaning
     * "a related thing", so the second arm must not fire there either.
     */
    #[Test]
    public function it_keeps_an_item_the_sidecar_scores_below_near_identity(): void
    {
        $this->fakeSidecar(['washing machine' => [1.0, 0.0], 'sewing machine' => [0.82, 0.5723635747635177]]);

        $all = [
            'washing machine' => $this->cluster('washing machine', 40),
            'sewing machine'  => $this->cluster('sewing machine', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['sewing machine' => $all['sewing machine']], $all);

        $this->assertSame(['sewing machine'], array_keys($kept));
    }

    /** A sidecar that cannot answer must not silently empty the list. */
    #[Test]
    public function it_keeps_everything_when_the_sidecar_is_unavailable(): void
    {
        config(['freegle.electricals.sidecar_url' => 'http://sidecar.test']);
        Http::fake(['*' => Http::response('', 500)]);

        $all = [
            'washing machine' => $this->cluster('washing machine', 40),
            'sewing machine'  => $this->cluster('sewing machine', 3),
        ];

        $kept = $this->svc->suppressVariantsOfPopular(['sewing machine' => $all['sewing machine']], $all);

        $this->assertSame(['sewing machine'], array_keys($kept));
    }

    /** @param array<string, float[]> $vectors unit vectors keyed by the text asked about */
    private function fakeSidecar(array $vectors): void
    {
        config(['freegle.electricals.sidecar_url' => 'http://sidecar.test']);

        Http::fake([
            '*' => function ($request) use ($vectors) {
                return Http::response([
                    'embeddings' => array_map(fn($t) => $vectors[$t] ?? [0.0, 0.0], $request['texts']),
                ]);
            },
        ]);
    }

    /**
     * Real spellings taken from a year of live offers, where "Tv" was published as
     * the commonest electrical at 73 while 287 posts in the same sample were TVs.
     *
     * Each of these is a television. None of the extra words changes what the thing
     * is: a brand, a screen size, a panel type, a condition, or a size adjective.
     */
    #[Test]
    public function it_folds_the_ways_people_type_a_television(): void
    {
        $names = [
            'Tv',
            'Samsung TV',
            'Television',
            'LG Smart TV 32"',
            'Toshiba TV',
            'Samsung 21 inch tv',
            'Sony Bravia tv',
            'Panasonic tv',
            'Small tv',
            'Flat screen TV',
            'Toshiba 40inch TV',
            '50" Plasma TV',
            'Portable TV',
        ];

        $rows = [];
        foreach ($names as $n => $name) {
            $rows[] = [$name, $n + 1, 100 + $n, 200 + $n];
        }

        $clusters = $this->svc->cluster($this->rows($rows));

        $counts = [];
        foreach ($clusters as $key => $c) {
            $counts[$key] = $c['count'];
        }
        arsort($counts);

        $this->assertSame(
            12,
            $counts['tv'] ?? null,
            'brand, screen size, panel type and size words should all fold into one item: '
            . json_encode($counts)
        );

        // "Sony Bravia tv" is left on its own, on purpose. Bravia is a model, and telling
        // a model from an item needs a catalogue this does not have; guessing would merge
        // things that are genuinely different. It is the known edge of the folding.
        $this->assertArrayHasKey('bravia tv', $counts);
    }
}

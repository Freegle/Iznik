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
}

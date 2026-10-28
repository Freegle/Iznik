<?php

namespace Tests\Unit\Services;

use App\Services\AiImageMatcherService;
use App\Services\ItemCleanerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiImageMatcherServiceTest extends TestCase
{
    /** @var array<string,string> name => item the fake cleaner answers */
    private array $items = [];

    private int $cleanCalls = 0;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('ai_image_aliases')->delete();
        DB::table('ai_images')->delete();
    }

    private function matcher(): AiImageMatcherService
    {
        $test = $this;

        return new AiImageMatcherService(new class($test) extends ItemCleanerService
        {
            public function __construct(private $test) {}

            public function clean(array $names): array
            {
                return $this->test->fakeClean($names);
            }
        });
    }

    public function fakeClean(array $names): array
    {
        if ($names) {
            $this->cleanCalls++;
        }

        return array_intersect_key($this->items, array_flip($names));
    }

    private function picture(string $name, string $status = 'active', int $usage = 1): int
    {
        return DB::table('ai_images')->insertGetId([
            'name' => $name, 'externaluid' => 'freegletusd-'.md5($name), 'status' => $status, 'usage_count' => $usage,
        ]);
    }

    public function test_a_variant_reuses_the_picture_of_its_item_and_is_remembered(): void
    {
        $id = $this->picture('Walking frame');
        $this->items['Aluminium walking frame'] = 'walking frame';

        $m = $this->matcher()->matchMany(['Aluminium walking frame']);

        $this->assertSame(['Aluminium walking frame' => 'freegletusd-'.md5('Walking frame')], $m['reuse']);
        $this->assertSame([], $m['items']);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'Aluminium walking frame', 'aiimageid' => $id, 'method' => 'item']);
    }

    public function test_an_item_is_found_through_its_key(): void
    {
        $id = $this->picture('Some knitting needles I had');
        DB::table('ai_image_aliases')->insert(['name' => 'knitting needle', 'aiimageid' => $id, 'method' => 'itemkey']);
        $this->items['Knitting needles any size'] = 'knitting needles';

        $m = $this->matcher()->matchMany(['Knitting needles any size']);

        $this->assertArrayHasKey('Knitting needles any size', $m['reuse']);
    }

    public function test_an_item_never_drawn_is_named_for_drawing(): void
    {
        $this->items['PUCHING WELCHIAR'] = 'wheelchair';

        $m = $this->matcher()->matchMany(['PUCHING WELCHIAR']);

        $this->assertSame([], $m['reuse']);
        $this->assertSame(['PUCHING WELCHIAR' => 'wheelchair'], $m['items']);
    }

    public function test_a_name_with_no_item_is_left_to_be_drawn_as_written(): void
    {
        $this->items['Hello can anyone help'] = 'none';

        $this->assertSame(['reuse' => [], 'items' => []], $this->matcher()->matchMany(['Hello can anyone help']));
    }

    public function test_an_item_whose_picture_is_not_active_is_neither_reused_nor_drawn_over(): void
    {
        $this->picture('wheelchair', 'rejected');
        $this->items['Wheel chair'] = 'wheelchair';

        $this->assertSame(['reuse' => [], 'items' => []], $this->matcher()->matchMany(['Wheel chair']));
    }

    public function test_an_alias_answers_without_asking_the_cleaner(): void
    {
        $id = $this->picture('Freezer');
        DB::table('ai_image_aliases')->insert(['name' => 'Freazer', 'aiimageid' => $id, 'method' => 'item']);

        $m = $this->matcher()->matchMany(['freazer']);

        $this->assertSame(['freazer' => 'freegletusd-'.md5('Freezer')], $m['reuse']);
        $this->assertSame(0, $this->cleanCalls);
    }

    public function test_a_new_picture_is_found_by_its_item_and_by_the_post_wording(): void
    {
        $this->picture('wheelchair');

        $this->matcher()->recordGenerated('PUCHING WELCHIAR', 'wheelchair', 'freegletusd-'.md5('wheelchair'));

        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'wheelchair', 'method' => 'itemkey']);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'PUCHING WELCHIAR', 'method' => 'item']);

        $this->items['Folding wheelchairs'] = 'wheelchairs';
        $this->assertArrayHasKey('Folding wheelchairs', $this->matcher()->matchMany(['Folding wheelchairs'])['reuse']);
    }

    public function test_backfill_keeps_the_most_used_picture_of_an_item_and_retires_the_rest(): void
    {
        $quiet = $this->picture('Grey sofa wanted', 'active', 1);
        $busy = $this->picture('Sofa', 'active', 50);
        $other = $this->picture('Lamp', 'active', 3);
        $post = $this->createTestMessage($this->createTestUser(), $this->createTestGroup());
        DB::table('messages_attachments')->insert([
            'msgid' => $post->id, 'externaluid' => 'freegletusd-'.md5('Grey sofa wanted'),
            'externalmods' => json_encode(['ai' => true]), 'contenttype' => 'image/jpeg',
        ]);
        DB::table('ai_image_aliases')->insert(['name' => 'Gray sofas', 'aiimageid' => $quiet, 'method' => 'item']);
        $this->items = ['Grey sofa wanted' => 'sofa', 'Sofa' => 'sofa', 'Lamp' => 'lamp'];

        $this->assertSame(['seen' => 3, 'kept' => 2, 'retired' => 1], $this->matcher()->backfill(0, 100));

        // The item has one picture, the one on most posts.
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'sofa', 'aiimageid' => $busy, 'method' => 'itemkey']);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'lamp', 'aiimageid' => $other]);
        // The other picture of it is gone; its post, its wording and its aliases use the item's.
        $this->assertDatabaseMissing('ai_images', ['id' => $quiet]);
        $this->assertSame('freegletusd-'.md5('Sofa'), DB::table('messages_attachments')->where('msgid', $post->id)->value('externaluid'));
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'Grey sofa wanted', 'aiimageid' => $busy, 'method' => 'item']);
        $this->assertDatabaseHas('ai_image_aliases', ['name' => 'Gray sofas', 'aiimageid' => $busy]);

        $this->assertSame(['seen' => 0, 'kept' => 0, 'retired' => 0], $this->matcher()->backfill(2, 100));
    }

    public function test_a_dry_run_backfill_counts_but_changes_nothing(): void
    {
        $quiet = $this->picture('Grey sofa wanted', 'active', 1);
        $this->picture('Sofa', 'active', 50);
        $this->items = ['Grey sofa wanted' => 'sofa', 'Sofa' => 'sofa'];

        $this->assertSame(['seen' => 2, 'kept' => 2, 'retired' => 1], $this->matcher()->backfill(0, 100, dryRun: true));

        $this->assertDatabaseHas('ai_images', ['id' => $quiet]);
        $this->assertDatabaseCount('ai_image_aliases', 0);
    }

    public function test_a_dry_run_stores_nothing(): void
    {
        $this->picture('Walking frame');
        $this->items['Aluminium walking frame'] = 'walking frame';

        $m = $this->matcher()->matchMany(['Aluminium walking frame'], dryRun: true);

        $this->assertArrayHasKey('Aluminium walking frame', $m['reuse']);
        $this->assertDatabaseCount('ai_image_aliases', 0);
    }
}

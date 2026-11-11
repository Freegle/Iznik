<?php

namespace Tests\Unit\Commands\AI;

use App\Services\ItemCleanerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillAIImageItemsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('config')->where('key', 'ai.images_item_backfill_offset')->delete();
        DB::table('ai_image_aliases')->delete();
        DB::table('ai_images')->delete();

        // Each name is its own item, lower-cased.
        $this->app->instance(ItemCleanerService::class, new class extends ItemCleanerService
        {
            public function isConfigured(): bool
            {
                return true;
            }

            public function clean(array $names): array
            {
                return array_combine($names, array_map('strtolower', $names));
            }
        });
    }

    public function test_it_works_through_the_pictures_and_picks_up_where_it_stopped(): void
    {
        foreach (['Sofa', 'Bed', 'Lamp'] as $i => $name) {
            DB::table('ai_images')->insert(['name' => $name, 'externaluid' => "freegletusd-$i", 'usage_count' => 10 - $i]);
        }

        $this->artisan('ai:images:backfill-items', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(['bed', 'sofa'], DB::table('ai_image_aliases')->orderBy('name')->pluck('name')->all());

        $this->artisan('ai:images:backfill-items', ['--limit' => 2])->assertSuccessful();
        $this->assertSame(['bed', 'lamp', 'sofa'], DB::table('ai_image_aliases')->orderBy('name')->pluck('name')->all());
        $this->assertSame('3', DB::table('config')->where('key', 'ai.images_item_backfill_offset')->value('value'));

        // Through: nothing more to do.
        $this->artisan('ai:images:backfill-items')->expectsOutputToContain('Looked at 0 pictures, retired 0, 3 kept so far')->assertSuccessful();
    }

    public function test_without_a_key_it_does_nothing(): void
    {
        $this->app->instance(ItemCleanerService::class, new ItemCleanerService);
        config(['freegle.ai_images.gemini_api_key' => '']);

        $this->artisan('ai:images:backfill-items')->expectsOutputToContain('No Gemini key')->assertSuccessful();
    }
}

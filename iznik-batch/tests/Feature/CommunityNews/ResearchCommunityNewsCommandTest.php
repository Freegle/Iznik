<?php

namespace Tests\Feature\CommunityNews;

use App\Models\CommunityNewsArea;
use App\Services\CommunityNews\CommunityNewsAreaService;
use App\Services\CommunityNews\CommunityNewsLimitReached;
use App\Services\CommunityNews\CommunityNewsResearchService;
use Mockery;
use Tests\TestCase;

/**
 * The hourly research run shares a Claude subscription with the AI support helper. It researches a few areas
 * per run, oldest first, and stops at a refusal, so one run after the subscription resets cannot spend the week.
 */
class ResearchCommunityNewsCommandTest extends TestCase
{
    /** @var int[] the area ids researchArea was called with, in order */
    private array $called = [];

    /** anchorgroupid is unique, so each area gets its own. */
    private int $anchor = 900000;

    protected function setUp(): void
    {
        parent::setUp();
        CommunityNewsArea::query()->delete();

        $areas = Mockery::mock(CommunityNewsAreaService::class);
        $areas->shouldReceive('rebuildAreas')->andReturnUsing(fn () => CommunityNewsArea::all());
        $this->app->instance(CommunityNewsAreaService::class, $areas);
    }

    private function area(string $name, ?string $lastResearched): CommunityNewsArea
    {
        return CommunityNewsArea::create([
            'anchorgroupid' => ++$this->anchor, 'name' => $name, 'lat' => 51.5, 'lng' => -0.12, 'groupids' => [], 'groupcount' => 0,
            'lastresearched' => $lastResearched,
        ]);
    }

    /** @param callable(CommunityNewsArea):array $answer */
    private function research(callable $answer): void
    {
        $research = Mockery::mock(CommunityNewsResearchService::class);
        $research->shouldReceive('researchArea')->andReturnUsing(function (CommunityNewsArea $a) use ($answer) {
            $this->called[] = $a->id;

            return $answer($a);
        });
        $this->app->instance(CommunityNewsResearchService::class, $research);
    }

    public function test_a_run_researches_at_most_the_cap_oldest_first(): void
    {
        config(['freegle.communitynews.research_max_per_run' => 2]);
        $recent = $this->area('Recent', now()->subDay()->toDateTimeString());
        $old = $this->area('Old', now()->subDays(10)->toDateTimeString());
        $never = $this->area('Never', null);
        $older = $this->area('Older', now()->subDays(20)->toDateTimeString());
        $this->research(fn () => ['ok' => true, 'items' => 1, 'intro' => 'Hi']);

        $this->artisan('community-news:research')->expectsOutputToContain('stopping: 2 area(s) this run')->assertSuccessful();

        // Never researched comes first, then the oldest; the recent one is not due and is not counted.
        $this->assertSame([$never->id, $older->id], $this->called);
        $this->assertNotContains($old->id, $this->called);
        $this->assertNotContains($recent->id, $this->called);
    }

    public function test_a_refusal_stops_the_run(): void
    {
        config(['freegle.communitynews.research_max_per_run' => 10]);
        $first = $this->area('First', now()->subDays(30)->toDateTimeString());
        $this->area('Second', now()->subDays(20)->toDateTimeString());
        $this->area('Third', now()->subDays(10)->toDateTimeString());
        $this->research(fn (CommunityNewsArea $a) => $a->id === $first->id
            ? ['ok' => true, 'items' => 1, 'intro' => 'Hi']
            : throw new CommunityNewsLimitReached("You've hit your weekly limit"));

        $this->artisan('community-news:research')
            ->expectsOutputToContain("stopping: the model refused, You've hit your weekly limit")
            ->assertSuccessful();

        $this->assertCount(2, $this->called);
    }

    public function test_one_area_by_id_is_not_capped(): void
    {
        config(['freegle.communitynews.research_max_per_run' => 0]);
        $a = $this->area('Only', null);
        $this->research(fn () => ['ok' => true, 'items' => 1, 'intro' => 'Hi']);

        $this->artisan('community-news:research', ['--area' => $a->id])->assertSuccessful();

        $this->assertSame([$a->id], $this->called);
    }
}

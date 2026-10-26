<?php

namespace Tests\Unit\Services;

use App\Models\MessageGroup;
use App\Services\ItemCleanerService;
use App\Services\MessageItemsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MessageItemsServiceTest extends TestCase
{
    /** @var array<string,string> name => item the fake cleaner answers; a name not here is not answered */
    public array $items = [];

    public array $asked = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('messages_cleaned_items')->delete();
        DB::table('messages_spatial')->delete();
    }

    private function service(bool $configured = true): MessageItemsService
    {
        $test = $this;

        return new MessageItemsService(new class($test, $configured) extends ItemCleanerService
        {
            public function __construct(private $test, private bool $configured) {}

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function clean(array $names): array
            {
                $this->test->asked = array_merge($this->test->asked, $names);

                return array_intersect_key($this->test->items, array_flip($names));
            }
        });
    }

    private function livePost(string $subject): int
    {
        $msg = $this->createTestMessage($this->createTestUser(), $this->createTestGroup(), ['subject' => $subject]);
        DB::statement(
            "INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) VALUES (?, ST_GeomFromText('POINT(-0.1 51.5)', 3857), ?, 'Offer', ?)",
            [$msg->id, DB::table('messages_groups')->where('msgid', $msg->id)->value('groupid') ?? 0, now()]
        );

        return $msg->id;
    }

    private function pendingPost(string $subject): int
    {
        $msg = $this->createTestMessage($this->createTestUser(), $this->createTestGroup(), ['subject' => $subject]);
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['collection' => MessageGroup::COLLECTION_PENDING, 'arrival' => now()]);

        return $msg->id;
    }

    public function test_live_and_pending_posts_get_their_item_and_key(): void
    {
        $live = $this->livePost('OFFER: Aluminium walking frame (Town)');
        $pending = $this->pendingPost('WANTED: Knitting needles please (Town)');
        $this->items = ['Aluminium walking frame' => 'walking frame', 'Knitting needles' => 'knitting needles'];

        $this->assertSame(2, $this->service()->process());

        $this->assertDatabaseHas('messages_cleaned_items', ['msgid' => $live, 'item' => 'walking frame', 'itemkey' => 'walking frame']);
        $this->assertDatabaseHas('messages_cleaned_items', ['msgid' => $pending, 'item' => 'knitting needles', 'itemkey' => 'knitting needle']);
    }

    public function test_a_post_with_no_drawable_item_is_kept_without_a_key_and_not_asked_again(): void
    {
        $id = $this->livePost('OFFER: Household bits (Town)');
        $this->items = ['Household bits' => 'none'];

        $this->service()->process();
        $this->assertDatabaseHas('messages_cleaned_items', ['msgid' => $id, 'item' => 'none', 'itemkey' => null]);

        $this->asked = [];
        $this->service()->process();
        $this->assertSame([], $this->asked);
    }

    public function test_a_post_the_cleaner_did_not_answer_is_tried_again(): void
    {
        $id = $this->livePost('OFFER: Sofa (Town)');

        $this->assertSame(0, $this->service()->process());
        $this->assertDatabaseMissing('messages_cleaned_items', ['msgid' => $id]);

        $this->items = ['Sofa' => 'sofa'];
        $this->assertSame(1, $this->service()->process());
    }

    public function test_without_a_key_nothing_happens(): void
    {
        $this->livePost('OFFER: Sofa (Town)');
        $this->items = ['Sofa' => 'sofa'];

        $this->assertSame(0, $this->service(configured: false)->process());
        $this->assertSame([], $this->asked);
    }

    public function test_the_command_runs_the_service(): void
    {
        $this->livePost('OFFER: Sofa (Town)');
        $this->items = ['Sofa' => 'sofa'];
        $this->app->instance(MessageItemsService::class, $this->service());

        $this->artisan('messages:clean-items')->expectsOutputToContain('Gave 1 posts their item')->assertSuccessful();
    }
}

<?php

namespace Tests\Feature\AutoSplit;

use App\Models\MessageGroup;
use App\Services\AutoSplit\AutoSplitService;
use App\Services\AutoSplit\GeminiJson;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoSplitServiceTest extends TestCase
{
    /** What the fake text model answers. */
    private array $text = ['kind' => 'several_items', 'confident' => true, 'items' => [['name' => 'Sofa', 'quantity' => 1], ['name' => 'Chairs', 'quantity' => 2]]];

    /** What the fake vision model answers, one entry per photo in the order they are asked about. */
    private array $photos = [];

    private int $visionCalls = 0;

    /** Run while the vision model is being asked, as a poster's change made meanwhile. */
    private ?\Closure $whileAsking = null;

    private bool $modelDown = false;

    /** The vision model alone is down. Http::fake merges, so this is a switch, not a second fake. */
    private bool $visionDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'freegle.autosplit.mode' => 'on',
            'freegle.autosplit.gemini_api_key' => 'test-key',
            'freegle.autosplit.model' => 'test-model',
            'freegle.autosplit.window_minutes' => 120,
            'freegle.delivery.base_url' => 'http://delivery.test',
            'freegle.tus_uploader' => 'http://uploads.test',
        ]);
        $g = new GeminiJson;
        $g->retryPauseSeconds = 0;
        $this->app->instance(GeminiJson::class, $g);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_starts_with($url, 'http://delivery.test')) {
                return str_contains($url, 'output=json')
                    ? Http::response(['width' => 1000, 'height' => 800])
                    : Http::response('jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']);
            }
            if ($this->modelDown) {
                return Http::response([], 503);
            }
            $isVision = collect($request['contents'][0]['parts'])->contains(fn ($p) => isset($p['inline_data']));
            if ($isVision && $this->visionDown) {
                return Http::response([], 500);
            }
            if ($isVision && $this->whileAsking) {
                ($this->whileAsking)();
                $this->whileAsking = null;
            }
            $answer = $isVision ? ['shows' => $this->photos[$this->visionCalls++]['shows'] ?? []] : $this->text;

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => json_encode($answer)]]]]],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 5],
            ]);
        });
    }

    private function service(): AutoSplitService
    {
        return app(AutoSplitService::class);
    }

    private function newPost(array $attributes = [], string $collection = MessageGroup::COLLECTION_PENDING): int
    {
        $msg = $this->createTestMessage($this->createTestUser(), $this->createTestGroup(), array_merge([
            'subject' => 'OFFER: Sofa and two chairs (Town)',
            'textbody' => 'Leather sofa and two kitchen chairs.',
            'availableinitially' => 1,
            'availablenow' => 1,
        ], $attributes));
        DB::table('messages_groups')->where('msgid', $msg->id)->update(['collection' => $collection]);

        return $msg->id;
    }

    private function photo(int $msgid, string $uid, array $mods = [], int $primary = 0): int
    {
        return DB::table('messages_attachments')->insertGetId([
            'msgid' => $msgid,
            'externaluid' => $uid,
            'externalmods' => json_encode((object) $mods),
            'contenttype' => 'image/jpeg',
            'hash' => 'abcd1234abcd1234',
            'primary' => $primary,
        ]);
    }

    private function geminiCalls(): int
    {
        return count(Http::recorded(fn (Request $r) => str_contains($r->url(), 'generativelanguage')));
    }

    public function test_a_post_of_several_things_becomes_an_item_list_with_its_photos(): void
    {
        $id = $this->newPost();
        $sofaPhoto = $this->photo($id, 'freegletusd-sofa', [], 1);
        $bothPhoto = $this->photo($id, 'freegletusd-both', ['rotate' => 90]);
        $this->photos = [
            ['photo' => 1, 'shows' => [['item' => 1, 'box_2d' => [100, 100, 900, 900]]]],
            ['photo' => 2, 'shows' => [['item' => 1, 'box_2d' => [100, 50, 900, 400]], ['item' => 2, 'box_2d' => [100, 600, 900, 950]]]],
        ];

        $this->assertSame(['split' => 1, 'shadow' => 0, 'notsplit' => 0, 'retry' => 0], $this->service()->process());

        $items = DB::table('messages_bulk_items')->where('msgid', $id)->orderBy('position')->get();
        $this->assertSame(['Sofa', 'Chairs'], $items->pluck('name')->all());
        $this->assertSame([1, 2], $items->pluck('quantity')->map(fn ($q) => (int) $q)->all());

        $row = DB::table('messages_autosplit')->where('msgid', $id)->first();
        $this->assertSame('Split', $row->state);
        $this->assertSame('several_items', $row->kind);
        $this->assertSame(1, (int) $row->originalavailableinitially);
        $this->assertSame('test-model', $row->model);
        $this->assertSame(300, (int) $row->tokensin, "the text call and one call per photo");

        // The pool is the items.
        $msg = DB::table('messages')->where('id', $id)->first();
        $this->assertSame(3, (int) $msg->availableinitially);
        $this->assertSame(3, (int) $msg->availablenow);

        // The sofa photo goes whole to the sofa; the photo of both stays the post's and is cropped.
        $links = DB::table('messages_bulk_item_attachments')->pluck('bulkitemid', 'attachmentid');
        $this->assertSame((int) $items[0]->id, (int) $links[$sofaPhoto]);
        $this->assertArrayNotHasKey($bothPhoto, $links->all());

        $crops = DB::table('messages_attachments')->whereIn('id', json_decode($row->createdattachments))->orderBy('id')->get();
        $this->assertCount(2, $crops);
        foreach ($crops as $crop) {
            $this->assertSame('freegletusd-both', $crop->externaluid);
            $this->assertNull($crop->hash, 'a copy of the hash would look like a repeated photo to the content check');
            $this->assertSame(0, (int) $crop->primary);
            $mods = json_decode($crop->externalmods, true);
            $this->assertSame(90, $mods['rotate'], 'the crop is in the photo as turned');
            $this->assertSame(1000, $mods['fw']);
            $this->assertSame(800, $mods['fh']);
            $this->assertTrue($mods['precrop']);
        }
        $this->assertSame((int) $items[0]->id, (int) $links[$crops[0]->id]);
        $this->assertSame((int) $items[1]->id, (int) $links[$crops[1]->id]);

        // Photos are fetched turned as the poster turned them.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'http://delivery.test?url=http://uploads.test/both&ro=90&output=json'));
    }

    public function test_a_group_photo_that_cannot_be_cut_cleanly_stays_shared(): void
    {
        $id = $this->newPost();
        $photo = $this->photo($id, 'freegletusd-table', [], 1);
        $this->photos = [['photo' => 1, 'shows' => [
            ['item' => 1, 'box_2d' => [200, 200, 800, 800]],
            ['item' => 2, 'box_2d' => [150, 150, 850, 850]],
        ]]];

        $this->service()->process();

        $this->assertSame(2, DB::table('messages_bulk_items')->where('msgid', $id)->count());
        $this->assertSame(0, DB::table('messages_bulk_item_attachments')->where('attachmentid', $photo)->count());
        $this->assertSame(1, DB::table('messages_attachments')->where('msgid', $id)->count(), 'no crops');
        $photos = json_decode(DB::table('messages_autosplit')->where('msgid', $id)->value('photos'), true);
        $this->assertSame('shared', $photos[0]['action']);
    }

    public function test_a_post_of_one_thing_is_left_alone_and_not_asked_about_again(): void
    {
        $this->text = ['kind' => 'set', 'confident' => true, 'items' => [['name' => 'Dining set', 'quantity' => 1]]];
        $id = $this->newPost(['subject' => 'OFFER: Dining table and chairs set (Town)']);
        $this->photo($id, 'freegletusd-set', [], 1);

        $this->assertSame(1, $this->service()->process()['notsplit']);
        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count());
        $this->assertSame('NotSplit', DB::table('messages_autosplit')->where('msgid', $id)->value('state'));
        $this->assertSame(1, $this->geminiCalls(), 'no photo call for a post that is not split');
        $this->assertSame(1, (int) DB::table('messages')->where('id', $id)->value('availableinitially'));

        $this->service()->process();
        $this->assertSame(1, $this->geminiCalls(), 'decided once');
    }

    public function test_a_photo_deleted_while_the_model_worked_is_not_brought_back(): void
    {
        $id = $this->newPost();
        $both = $this->photo($id, 'freegletusd-both', [], 1);
        $this->photos = [['photo' => 1, 'shows' => [
            ['item' => 1, 'box_2d' => [100, 50, 900, 400]],
            ['item' => 2, 'box_2d' => [100, 600, 900, 950]],
        ]]];
        $this->whileAsking = fn () => DB::table('messages_attachments')->where('id', $both)->delete();

        $this->assertSame(1, $this->service()->process()['split']);

        $this->assertSame(0, DB::table('messages_attachments')->where('msgid', $id)->count(), 'no crops of a deleted photo');
        $this->assertSame(0, DB::table('messages_bulk_item_attachments')->count());
    }

    public function test_a_post_taken_while_the_model_worked_is_left_alone(): void
    {
        $id = $this->newPost();
        $this->photo($id, 'freegletusd-a', [], 1);
        $this->photos = [['photo' => 1, 'shows' => []]];
        $this->whileAsking = fn () => DB::table('messages_outcomes')->insert(['msgid' => $id, 'outcome' => 'Taken']);

        $this->service()->process();

        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count());
        $this->assertSame(1, (int) DB::table('messages')->where('id', $id)->value('availableinitially'));
    }

    public function test_a_post_changed_to_a_wanted_while_the_model_worked_is_left_alone(): void
    {
        $id = $this->newPost();
        $this->photo($id, 'freegletusd-a', [], 1);
        $this->photos = [['photo' => 1, 'shows' => []]];
        $this->whileAsking = fn () => DB::table('messages')->where('id', $id)->update(['type' => 'Wanted']);

        $this->assertSame(0, $this->service()->process()['split']);

        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count(), 'a WANTED is never split');
        $this->assertSame(0, DB::table('messages_autosplit')->where('msgid', $id)->where('state', 'Split')->count());
        $this->assertSame(1, (int) DB::table('messages')->where('id', $id)->value('availableinitially'));
    }

    public function test_marking_a_split_posts_items_gone_leaves_a_clearance_alone(): void
    {
        $split = $this->newPost();
        $clearance = $this->newPost();
        DB::table('messages_bulk_items')->insert([
            ['msgid' => $split, 'name' => 'Sofa', 'quantity' => 1, 'available' => 1],
            ['msgid' => $clearance, 'name' => 'Desk', 'quantity' => 1, 'available' => 1],
        ]);
        DB::table('messages_autosplit')->insert(['msgid' => $split, 'state' => 'Split']);

        AutoSplitService::markItemsGone($split);
        AutoSplitService::markItemsGone($clearance);

        $this->assertSame(0, (int) DB::table('messages_bulk_items')->where('msgid', $split)->value('available'));
        $this->assertSame(1, (int) DB::table('messages_bulk_items')->where('msgid', $clearance)->value('available'));
    }

    public function test_shadow_mode_records_the_split_and_leaves_the_post(): void
    {
        config(['freegle.autosplit.mode' => 'shadow']);
        $id = $this->newPost();

        $this->assertSame(1, $this->service()->process()['shadow']);
        $this->assertSame('Shadow', DB::table('messages_autosplit')->where('msgid', $id)->value('state'));
        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count());
    }

    public function test_off_mode_does_nothing(): void
    {
        foreach (['off', 'yes', ''] as $mode) {
            config(['freegle.autosplit.mode' => $mode]);
            $this->newPost();
            $this->assertSame(['split' => 0, 'shadow' => 0, 'notsplit' => 0, 'retry' => 0], $this->service()->process());
        }
        $this->assertSame(0, $this->geminiCalls());
    }

    public function test_no_answer_from_the_model_leaves_the_post_for_the_next_run(): void
    {
        $id = $this->newPost();
        $this->modelDown = true;

        $this->assertSame(1, $this->service()->process()['retry']);
        $this->assertSame(0, DB::table('messages_autosplit')->where('msgid', $id)->count());

        $this->modelDown = false;
        $this->assertSame(1, $this->service()->process()['split']);
    }

    public function test_no_answer_about_the_photos_leaves_the_post_for_the_next_run(): void
    {
        $id = $this->newPost();
        $this->photo($id, 'freegletusd-a', [], 1);
        $this->visionDown = true;

        $this->assertSame(1, $this->service()->process()['retry']);
        $this->assertSame(0, DB::table('messages_autosplit')->where('msgid', $id)->count());
        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count());
    }

    public function test_a_post_with_no_photos_is_split_on_its_words(): void
    {
        $id = $this->newPost();
        $this->assertSame(1, $this->service()->process()['split']);
        $this->assertSame(2, DB::table('messages_bulk_items')->where('msgid', $id)->count());
        $this->assertSame(1, $this->geminiCalls());
    }

    public function test_ai_drawings_and_photos_stored_elsewhere_are_not_sent_to_the_vision_model(): void
    {
        $id = $this->newPost();
        $this->photo($id, 'freegletusd-drawing', ['ai' => true], 1);
        DB::table('messages_attachments')->insert(['msgid' => $id, 'externaluid' => null, 'contenttype' => 'image/jpeg']);

        $this->service()->process();

        $this->assertSame(1, $this->geminiCalls(), 'nothing to send, so no vision call');
        Http::assertNotSent(fn (Request $r) => str_starts_with($r->url(), 'http://delivery.test'));
    }

    public function test_only_new_native_offers_are_considered(): void
    {
        $tn = $this->newPost(['tnpostid' => '123', 'source' => 'Email']);
        $wanted = $this->newPost(['type' => 'Wanted', 'subject' => 'WANTED: Sofa and chairs (Town)']);
        $old = $this->newPost(['arrival' => now()->subHours(3)]);
        $deleted = $this->newPost(['deleted' => now()]);
        $clearance = $this->newPost();
        DB::table('messages_bulk_items')->insert(['msgid' => $clearance, 'name' => 'Desk', 'quantity' => 1]);
        $taken = $this->newPost();
        DB::table('messages_outcomes')->insert(['msgid' => $taken, 'outcome' => 'Taken']);
        $rejected = $this->newPost([], 'Rejected');
        // A draft is on no community yet: the API keeps it in messages_drafts, with no messages_groups row.
        $draft = $this->newPost();
        DB::table('messages_groups')->where('msgid', $draft)->delete();
        DB::table('messages_drafts')->insert(['msgid' => $draft]);
        $removed = $this->newPost();
        DB::table('messages_groups')->where('msgid', $removed)->update(['deleted' => 1]);
        $live = $this->newPost([], MessageGroup::COLLECTION_APPROVED);
        $pending = $this->newPost();

        $ids = array_map(fn ($m) => (int) $m->id, $this->service()->candidates(100));

        $this->assertContains($live, $ids);
        $this->assertContains($pending, $ids);
        foreach ([$tn, $wanted, $old, $deleted, $clearance, $taken, $rejected, $draft, $removed] as $no) {
            $this->assertNotContains($no, $ids);
        }
    }

    public function test_a_clearance_made_meanwhile_is_left_alone(): void
    {
        $id = $this->newPost();
        $msg = $this->service()->candidates(10)[0];
        DB::table('messages_bulk_items')->insert(['msgid' => $id, 'name' => 'Desk', 'quantity' => 1]);

        $this->assertSame('notsplit', $this->service()->processOne($msg, 'on'));
        $this->assertSame(['Desk'], DB::table('messages_bulk_items')->where('msgid', $id)->pluck('name')->all());
        $this->assertSame(0, DB::table('messages_autosplit')->where('msgid', $id)->count());
    }

    public function test_a_post_another_run_has_decided_is_not_split_twice(): void
    {
        $id = $this->newPost();
        $msg = $this->service()->candidates(10)[0];
        DB::table('messages_autosplit')->insert(['msgid' => $id, 'state' => 'NotSplit']);

        $this->assertSame('notsplit', $this->service()->processOne($msg, 'on'));
        $this->assertSame(0, DB::table('messages_bulk_items')->where('msgid', $id)->count());
    }

    public function test_the_command_reports_the_mode_and_what_it_did(): void
    {
        $this->newPost();
        $this->artisan('messages:auto-split')->expectsOutputToContain('Mode on: {"split":1')->assertSuccessful();
    }
}

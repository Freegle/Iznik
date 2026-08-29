<?php

namespace Tests\Feature\Eee;

use App\Services\EeeClassificationService;
use App\Services\EeeComponentService;
use App\Services\EeeSqliteService;
use App\Services\EeeVisionService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Covers what the hourly job is allowed to classify.
 *
 * The selection is a privacy boundary, not an optimisation: everything selected has its
 * photo and text sent to an external API, so the cases pinned here are the ones where a
 * plausible query quietly sends the wrong thing — posts no moderator has passed, deleted
 * posts, posts already spent on.
 */
class EeeClassifyNewCommandTest extends TestCase
{
    /** Message ids handed to the classifier, in order. */
    private array $classified = [];

    private bool $indexEmpty = false;

    /** Message ids the classifier should throw for. */
    private array $poison = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->classified = [];
        $this->poison     = [];
        $this->indexEmpty = false;

        DB::table('messages_eee')->delete();

        $vision = $this->createMock(EeeVisionService::class);
        $vision->method('isConfigured')->willReturn(true);
        $vision->method('getModelName')->willReturn('test-model');
        $vision->method('getPromptVersion')->willReturn('1');
        $this->instance(EeeVisionService::class, $vision);

        $components = $this->createMock(EeeComponentService::class);
        $components->method('needsBuilding')->willReturnCallback(fn() => $this->indexEmpty);
        $this->instance(EeeComponentService::class, $components);

        $sqlite = $this->createMock(EeeSqliteService::class);
        $sqlite->method('startRun')->willReturn(1);
        $this->instance(EeeSqliteService::class, $sqlite);

        $classifier = $this->createMock(EeeClassificationService::class);
        $classifier->method('classifyMessage')->willReturnCallback(function (int $msgid) {
            if (in_array($msgid, $this->poison, true)) {
                throw new \RuntimeException('poison');
            }
            $this->classified[] = $msgid;

            return ['is_eee' => 1, 'cost_usd' => 0.0];
        });
        $this->instance(EeeClassificationService::class, $classifier);
    }

    private function makeOffer(array $groupRow = [], array $messageRow = []): int
    {
        $user  = $this->createTestUser();
        $group = $this->createTestGroup();
        $id    = (int) $this->createTestMessage($user, $group)->id;

        if ($messageRow) {
            DB::table('messages')->where('id', $id)->update($messageRow);
        }
        if ($groupRow) {
            DB::table('messages_groups')->where('msgid', $id)->update($groupRow);
        }

        return $id;
    }

    private function runCommand(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('eee:classify-new', ['--since' => '2000-01-01 00:00:00']);
    }

    public function test_only_approved_undeleted_offers_are_classified(): void
    {
        $approved   = $this->makeOffer();
        $pending    = $this->makeOffer(['collection' => 'Pending']);
        $deleted    = $this->makeOffer([], ['deleted' => now()->toDateTimeString()]);
        $groupGone  = $this->makeOffer(['deleted' => 1]);
        $wanted     = $this->makeOffer([], ['type' => 'Wanted']);

        $this->runCommand()->assertExitCode(0);

        $this->assertContains($approved, $this->classified);
        $this->assertNotContains($pending, $this->classified, 'unapproved content must never be sent out');
        $this->assertNotContains($deleted, $this->classified);
        $this->assertNotContains($groupGone, $this->classified);
        $this->assertNotContains($wanted, $this->classified);
    }

    public function test_already_classified_messages_are_not_spent_on_again(): void
    {
        $done = $this->makeOffer();
        DB::table('messages_eee')->insert([
            'msgid'          => $done,
            'is_eee'         => 1,
            'model'          => 'test-model',
            'prompt_version' => '1',
            'classified_at'  => now()->toDateTimeString(),
        ]);

        // The same message under a different prompt is new work, not a duplicate.
        $otherPrompt = $this->makeOffer();
        DB::table('messages_eee')->insert([
            'msgid'          => $otherPrompt,
            'is_eee'         => 1,
            'model'          => 'test-model',
            'prompt_version' => '0',
            'classified_at'  => now()->toDateTimeString(),
        ]);

        $this->runCommand()->assertExitCode(0);

        $this->assertNotContains($done, $this->classified);
        $this->assertContains($otherPrompt, $this->classified);
    }

    /**
     * A post held at arrival and approved days later joins on its approval, however old
     * its arrival is. Under an arrival-only clock it would be behind the mark for ever.
     */
    public function test_late_approval_is_picked_up_despite_an_old_arrival(): void
    {
        $held = $this->makeOffer(
            ['arrival' => '2000-01-01 00:00:00', 'approvedat' => now()->toDateTimeString()],
            ['arrival' => '2000-01-01 00:00:00'],
        );

        // No --since: the mark defaults to 24 hours ago, well after the arrival.
        $this->artisan('eee:classify-new')->assertExitCode(0);

        $this->assertContains($held, $this->classified);
    }

    public function test_refuses_to_run_with_an_empty_component_index(): void
    {
        $this->indexEmpty = true;
        $this->makeOffer();

        $this->runCommand()->assertExitCode(1);

        $this->assertSame([], $this->classified, 'nothing may be spent while every verdict would be null');
    }

    public function test_one_failing_message_does_not_stall_the_rest(): void
    {
        $bad  = $this->makeOffer();
        $good = $this->makeOffer();

        $this->poison = [$bad];

        $this->runCommand()->assertExitCode(0);

        $this->assertContains($good, $this->classified, 'messages after the failure must still be processed');
        $this->assertNotContains($bad, $this->classified);
    }
}

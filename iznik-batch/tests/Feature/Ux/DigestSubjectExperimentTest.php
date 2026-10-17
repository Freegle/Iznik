<?php

namespace Tests\Feature\Ux;

use App\Mail\Digest\UnifiedDigest;
use App\Models\Group;
use App\Models\Message;
use App\Models\User;
use App\Services\UnifiedDigestService;
use App\Services\Ux\Sources\EmailClickSource;
use App\Services\Ux\UxArm;
use App\Services\Ux\UxExperimentRefused;
use App\Services\Ux\UxExperimentService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * The digest-subject experiment as the engine runs it, over the real switch list entry
 * (config/ux_switches.php) and real rows: the shape of the entry, the create command of the pull
 * request, the start and its refusals, the two arms reaching real mail, who is counted, and how fast
 * a stop or the global switch puts everyone back to control. The mail itself is in
 * tests/Unit/Mail/UnifiedDigestSubjectExperimentTest.php.
 *
 * The time is Tuesday 20 October 2026, 12:30 London time (11:30 UTC): the start the pull request advises.
 */
class DigestSubjectExperimentTest extends TestCase
{
    use FakesUxLoki;

    private const NAME = 'digest-subject';

    /**
     * The flags of the create command in the pull request, as the pull request prints them. The
     * pull request repeats these and the design below: change them together. --pr is the entry's own.
     */
    private const CREATE = [
        '--flow' => 'reply',
        '--name' => self::NAME,
        '--title' => 'Daily digest subject that leads with the items',
        '--hypothesis' => "A phone shows only the start of a subject. The daily digest subject starts with What's New and the number of posts, so the items come second. The line under the subject shows the first three item names in both versions.",
        '--change' => "The items version lists the same item names and says how many more posts the email has. It leaves out What's New and the number of posts. An email with only one post is not in the experiment.",
        '--expected' => 'More members click a link in the digest within a day. No more members click the Unsubscribe link.',
        '--arms' => '{"control":[0,49],"items":[50,99]}',
    ];

    private const DESIGN = '{"channel":"email","type":"UnifiedDigestDaily","source":"email_click","window_hours":24,"intake_hours":24,"max_hours":48,"expected_points":0.5,"copy":{"control":"The subject says What\'s New and the number of posts, then names the first items. Example: What\'s New (12 posts) - Sofa, Lamp, Books, Chair, Table","items":"The subject names the same items, then says how many more posts the email has. Example: Sofa, Lamp, Books, Chair, Table and 7 more. An email with only one post is not in the experiment."}}';

    private UxExperimentService $service;

    /** @var array<int, array{message: Message, postedToGroups: int[]}> */
    private array $posts = [];

    private ?Group $group = null;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 11:30:00');
        UxArm::flush();
        $this->fakeLoki();
        $this->service = app(UxExperimentService::class);
        // Rows from other work in the same database must not count here or hold the slot.
        DB::table('ux_experiment_reads')->delete();
        DB::table('ux_experiments')->delete();
        DB::table('config')->whereIn('key', [UxExperimentService::CONFIG_KEY, UxArm::SWITCH_KEY])->delete();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        UxArm::flush();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** @return array<string, mixed> */
    private function entry(): array
    {
        $entries = array_values(array_filter((array) config('ux_switches'), fn ($e) => is_array($e) && ($e['name'] ?? null) === self::NAME));
        $this->assertCount(1, $entries, 'config/ux_switches.php holds one entry for '.self::NAME);

        return $entries[0];
    }

    private function createDraft(): int
    {
        $this->artisan('ux:experiment', ['action' => 'create', '--pr' => $this->entry()['pr'], '--design' => self::DESIGN] + self::CREATE)
            ->expectsOutputToContain('Created experiment')
            ->assertExitCode(0);

        return (int) DB::table('ux_experiments')->where('name', self::NAME)->value('id');
    }

    private function switchServerArmsOn(): void
    {
        DB::table('config')->upsert([['key' => UxArm::SWITCH_KEY, 'value' => '1']], ['key'], ['value']);
        UxArm::flush();
    }

    private function startTheExperiment(): object
    {
        $id = $this->createDraft();
        $this->switchServerArmsOn();

        return $this->service->start($id);
    }

    private function member(): User
    {
        $user = $this->createTestUser();
        $this->createMembership($user, $this->group());

        return $user;
    }

    private function group(): Group
    {
        return $this->group ??= $this->createTestGroup();
    }

    /** Two posts, so a digest of them is in the experiment. */
    private function twoPosts(): Collection
    {
        if ($this->posts === []) {
            $poster = $this->createTestUser();
            $this->createMembership($poster, $this->group());
            foreach (['OFFER: Sofa (London)', 'OFFER: Lamp (London)'] as $title) {
                $this->posts[] = ['message' => $this->createTestMessage($poster, $this->group(), ['subject' => $title]), 'postedToGroups' => [$this->group()->id]];
            }
        }

        return collect($this->posts);
    }

    private function digestFor(User $user): UnifiedDigest
    {
        return new UnifiedDigest($user, $this->twoPosts(), UnifiedDigestService::MODE_DAILY);
    }

    /** One member in each arm of the running experiment, found by trying new members: at most 30. */
    private function membersInEachArm(): array
    {
        $found = [];
        for ($i = 0; $i < 30 && count($found) < 2; $i++) {
            $user = $this->member();
            $arm = UxArm::for($user->id, self::NAME);
            $this->assertContains($arm, ['control', 'items'], 'the arms cover every bucket');
            $found[$arm] ??= $user;
        }
        $this->assertCount(2, $found, 'a member in each arm within 30 tries');

        return $found;
    }

    // ---------------------------------------------------------------- the entry

    public function test_the_switch_list_entry_has_the_shape_the_code_expects(): void
    {
        $entry = $this->entry();
        $design = json_decode(self::DESIGN, true);

        $keys = array_keys($entry);
        sort($keys);
        $this->assertSame(['arms', 'channel', 'keep_until', 'metric', 'name', 'opened', 'pr', 'type', 'varies'], $keys, 'exactly the keys of the docblock in config/ux_switches.php');
        $this->assertMatchesRegularExpression('#^https://github\.com/Freegle/Iznik/pull/[0-9]+$#', $entry['pr'], 'the number of the pull request is in it');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['opened']);
        $this->assertSame($entry['opened'], Carbon::parse($entry['opened'], 'UTC')->format('Y-m-d'), 'opened is a real day');
        $this->assertNull($entry['keep_until']);
        $this->assertSame('email', $entry['channel']);
        $this->assertSame('subject', $entry['varies']);
        $this->assertSame(['control', 'items'], $entry['arms']);
        $this->assertSame(['source' => 'email_click', 'window_hours' => 24], $entry['metric']);
        $this->assertSame($design['window_hours'], $entry['metric']['window_hours'], 'the design counts over the window the entry says');
        $this->assertSame($design['source'], $entry['metric']['source']);
        $this->assertSame($design['channel'], $entry['channel']);

        // The type is the email type of the mail, which other code matches exactly: rename it and the experiment would leave its slot.
        $this->assertSame($entry['type'], $this->digestFor($this->member())->getEmailType());
        $this->assertSame($design['type'], $entry['type']);
    }

    public function test_the_create_command_of_the_pull_request_makes_the_draft(): void
    {
        $id = $this->createDraft();

        $row = DB::table('ux_experiments')->where('id', $id)->first();
        $this->assertSame('draft', $row->status);
        $this->assertSame('reply', $row->flow);
        $this->assertSame(self::NAME, $row->name);
        $this->assertSame($this->entry()['pr'], $row->pr_url);
        // A JSON column keeps its keys in its own order, so the arms and the design are compared as maps.
        $this->assertEquals(['control' => [0, 49], 'items' => [50, 99]], json_decode($row->arms, true));

        $design = json_decode($row->design, true);
        $this->assertEquals([
            'channel' => 'email', 'type' => 'UnifiedDigestDaily', 'source' => 'email_click', 'window_hours' => 24, 'intake_hours' => 24,
            'max_hours' => 48, 'expected_points' => 0.5, 'fixed_split' => true,
            'copy' => json_decode(self::DESIGN, true)['copy'],
        ], $design);
        $this->assertTrue($design['fixed_split'], 'the split never moves');
        $this->assertArrayNotHasKey('guardrail', $design, 'there is no such design key: the guardrail comes from the source');
        $this->assertArrayNotHasKey('slot', $design, 'the slot is email:UnifiedDigestDaily, from the channel and the type');
        foreach ($design['copy'] as $text) {
            $this->assertLessThanOrEqual(240, mb_strlen($text));
        }
        foreach (array_merge(array_values(self::CREATE), array_values($design['copy'])) as $text) {
            $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $text, 'no long dash in words staff read');
        }
    }

    // ---------------------------------------------------------------- the start

    public function test_start_is_refused_until_server_arms_are_on_then_runs_48_hours_and_serves_25(): void
    {
        $id = $this->createDraft();

        try {
            $this->service->start($id);
            $this->fail('a start with server arms off must be refused');
        } catch (UxExperimentRefused $e) {
            $this->assertSame('Experiments in mail, push and server code are switched off. Set the config key ux_server_arms to 1, then start this one again.', $e->getMessage());
        }

        $this->switchServerArmsOn();
        $started = $this->service->start($id);

        $this->assertSame('running', $started->status);
        $this->assertSame('2026-10-20 11:30:00', $started->started_at);
        $this->assertSame('2026-10-22 11:30:00', $started->ends_at, '48 hours');
        $design = json_decode($started->design, true);
        $this->assertSame(24, $design['intake_hours']);
        $this->assertSame(48, $design['max_hours']);
        $this->assertSame([], $design['touches'], 'a source experiment watches no funnel');

        $published = json_decode((string) DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), true);
        $this->assertEquals(
            [self::NAME => ['arms' => ['control' => [0, 49], 'items' => [50, 99]], 'until' => Carbon::parse('2026-10-21 12:30:00', 'UTC')->getTimestamp()]],
            $published,
            'served until 25 hours after the start, with no salt'
        );
    }

    public function test_a_second_digest_experiment_is_refused(): void
    {
        $this->startTheExperiment();
        $second = $this->service->create([
            'flow' => 'reply', 'name' => 'digest-subject-two', 'title' => 'A second digest test', 'hypothesis' => 'A second change.',
            'change' => 'Something else.', 'expected' => 'More clicks.',
            'design' => ['channel' => 'email', 'type' => 'UnifiedDigestDaily', 'source' => 'email_click', 'window_hours' => 24, 'expected_points' => 0.5],
        ]);

        try {
            $this->service->start($second);
            $this->fail('a second experiment on the daily digest must be refused');
        } catch (UxExperimentRefused $e) {
            $this->assertSame('Another email experiment, digest-subject, is already running for UnifiedDigestDaily. Only one change at a time can run there, so the results can be told apart.', $e->getMessage());
        }
    }

    // ---------------------------------------------------------------- real mail, counted

    public function test_both_arms_reach_real_mail_and_each_member_is_counted_once(): void
    {
        $exp = $this->startTheExperiment();
        Carbon::setTestNow('2026-10-20 11:31:00');
        $members = $this->membersInEachArm();

        $control = $this->digestFor($members['control']);
        $items = $this->digestFor($members['items']);
        $this->assertStringStartsWith("What's New (2 posts)", $control->envelope()->subject);
        $this->assertSame('Sofa, Lamp', $items->envelope()->subject);
        // A second digest for the same member is a second row, and the count takes the first.
        $this->digestFor($members['items']);
        $this->assertSame(3, DB::table('ux_experiment_exposures')->where('experiment', self::NAME)->count());

        // A day and an hour later the first exposures have had their window.
        Carbon::setTestNow('2026-10-21 12:35:00');
        $source = new EmailClickSource;
        $read = fn () => $source->counts($this->service->find($exp->id), Carbon::now('UTC'), null);

        $c = $read();
        $this->assertSame(1, $c['arms']['control']['starts']);
        $this->assertSame(1, $c['arms']['items']['starts']);
        $this->assertEquals(['control' => 1, 'items' => 1], $c['extra']['exposed']);
        $this->assertEquals(['control' => 1, 'items' => 1], $c['extra']['counted']);
        $this->assertSame(0, $c['arms']['items']['completes']);

        // The items member clicks a link in the email.
        DB::table('email_tracking')->where('id', $items->getTracking()->id)->update(['clicked_at' => '2026-10-20 12:00:00']);
        $c = $read();
        $this->assertSame(1, $c['arms']['items']['completes']);
        $this->assertSame(0, $c['arms']['control']['completes']);

        // The control member clicks the unsubscribe link, which also sets the click time: no click, and one for the guardrail.
        DB::table('email_tracking')->where('id', $control->getTracking()->id)->update(['clicked_at' => '2026-10-20 12:00:00', 'unsubscribed_at' => '2026-10-20 12:00:00']);
        $c = $read();
        $this->assertSame(0, $c['arms']['control']['completes']);
        $this->assertSame(1, $c['guards']['unsubscribe']['control']['bad']);
        $this->assertSame(1, $c['guards']['unsubscribe']['control']['base']);
        $this->assertSame(0, $c['guards']['unsubscribe']['items']['bad']);
        $this->assertSame([], $this->lokiQueries, 'nothing was read from the logs');
    }

    // ---------------------------------------------------------------- the ways back to control

    /** @return array<string, array{0: string}> */
    public static function waysBack(): array
    {
        return [
            'a stop' => ['stop'],
            'the global switch' => ['switch'],
        ];
    }

    #[DataProvider('waysBack')]
    public function test_stop_and_the_global_switch_return_everyone_to_control_within_a_minute(string $way): void
    {
        $this->startTheExperiment();
        $members = $this->membersInEachArm();
        $this->assertSame('Sofa, Lamp', $this->digestFor($members['items'])->envelope()->subject, 'running: the items member gets the items subject');

        if ($way === 'stop') {
            $this->artisan('ux:experiment', ['action' => 'stop', 'name' => self::NAME, '--reason' => 'test'])->assertExitCode(0);
            $this->assertSame('{}', DB::table('config')->where('key', UxExperimentService::CONFIG_KEY)->value('value'), 'the entry leaves the key at once');
        } else {
            DB::table('config')->where('key', UxArm::SWITCH_KEY)->update(['value' => '0']);
        }

        // This process still holds the copy of the rows it read a moment ago, for a minute.
        Carbon::setTestNow('2026-10-20 11:30:59');
        $this->assertSame('Sofa, Lamp', $this->digestFor($members['items'])->envelope()->subject, '59 seconds: the copy is still held');

        Carbon::setTestNow('2026-10-20 11:31:01');
        $after = $this->digestFor($members['items']);
        $this->assertStringStartsWith("What's New (2 posts)", $after->envelope()->subject, '61 seconds: control');
        $rows = DB::table('ux_experiment_exposures')->where('experiment', self::NAME)->where('userid', $members['items']->id)->count();
        $this->assertSame(2, $rows, 'the two builds inside the minute wrote a row each, the later one none');
    }
}

<?php

namespace Tests\Feature\TrashNothing;

use App\Console\Commands\TrashNothing\TNParityCheckCommand;
use App\Models\Group;
use App\Models\Membership;
use App\Models\UserEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Illuminate\Console\OutputStyle;
use Tests\TestCase;

/**
 * The tn:parity-check COMMAND, end to end in --local-testing mode.
 *
 * ParityComparer's four-layer logic has its own tests (EmailApiParityTest); what is only
 * reachable through the command is everything around it - loading the fixture CSV, deriving
 * the window from it, running both real ingestion paths under captured trace logs,
 * redirecting Loki away from the production stream, printing the report, and choosing an
 * exit code. That orchestration is what decides whether a real parity run means anything,
 * and it is the part an operator actually invokes.
 *
 * Both fixture files describe the SAME four posts, so a correctly wired run must report
 * PASS. The interesting failure it also has to catch is the opposite of an obvious one: a
 * database without the groups TN addressed drops every post before a messages row exists,
 * which leaves Layers 3-5 empty and would otherwise print PASS having compared nothing.
 */
class TNParityCheckCommandTest extends TestCase
{
    private int $fakeLocationId;

    private const TN_USER_IDS = [99010001, 99010002, 99010003];

    protected function setUp(): void
    {
        parent::setUp();

        // The fixture CSV carries no secret column; EmailReplaySyncer injects the configured
        // one so IncomingMailService::shouldSkipSpamCheck() treats the rows as real TN posts.
        config([
            'freegle.mail.trashnothing_secret' => 'test-secret-12345',
            'freegle.trashnothing.api_key' => 'test-key',
            'freegle.trashnothing.api_base_url' => 'https://example.invalid',
        ]);

        // Both paths resolve a location through the spatial server. Pin every coordinate to
        // one real row so the two paths cannot disagree on locationid for a reason that has
        // nothing to do with parity - and so the id is one that exists, since
        // users.lastlocation is a foreign key.
        $this->fakeLocationId = (int) DB::table('locations')->insertGetId([
            'name' => 'TestLocation_'.uniqid('', true),
            'type' => 'Postcode',
            'lat' => 55.9533,
            'lng' => -3.1883,
        ]);
        Http::fake([
            '*/v1/postcodes/knn*' => Http::response(['results' => [['id' => $this->fakeLocationId, 'distance' => 0]]], 200),
            '*' => Http::response([], 200),
        ]);
    }

    public function test_local_testing_run_reports_parity_between_the_two_paths(): void
    {
        $edinburgh = $this->seedGroup('edinburghfreegle', 55.9533, -3.1883);
        $glasgow = $this->seedGroup('glasgowfreegle', 55.8642, -4.2518);

        foreach (self::TN_USER_IDS as $i => $id) {
            $this->seedTnUser($id, 'parityuser'.$i.'@user.trashnothing.com', $i === 2 ? $glasgow : $edinburgh);
        }

        $this->artisan('tn:parity-check', ['--local-testing' => true])
            ->expectsOutputToContain('Running email path')
            ->expectsOutputToContain('Running API path')
            ->expectsOutputToContain('PASS')
            ->assertExitCode(0);
    }

    /**
     * The check that stops a parity run from passing vacuously. Nothing is seeded, so the
     * email path drops every post as "unknown group" before writing anything, and the layers
     * that compare database state have nothing in them at all.
     */
    public function test_a_database_without_the_groups_fails_rather_than_passing_on_nothing(): void
    {
        $this->artisan('tn:parity-check', ['--local-testing' => true])
            ->expectsOutputToContain('unknown group')
            ->assertExitCode(1);
    }

    /**
     * Loki entries have to go somewhere other than the production stream: a parity run
     * replays real posts through both paths, and those entries would otherwise land in the
     * feed ModTools' dashboards read as if the posts had genuinely arrived twice.
     */
    public function test_loki_output_is_redirected_away_from_the_production_stream(): void
    {
        $group = $this->seedGroup('edinburghfreegle', 55.9533, -3.1883);
        foreach (self::TN_USER_IDS as $i => $id) {
            $this->seedTnUser($id, 'parityloki'.$i.'@user.trashnothing.com', $group);
        }

        $this->artisan('tn:parity-check', ['--local-testing' => true])
            ->expectsOutputToContain('Loki entries redirected to');

        $this->assertNotSame(
            config('freegle.loki.log_path'),
            '/var/log/loki',
            'the run must not leave the production log path configured'
        );
    }

    /**
     * The report is the whole output of a parity run, and a run that found problems and then
     * printed a clean-looking summary would be worse than no run at all. Driven with
     * synthetic layers because making the two real paths disagree means breaking one of them.
     */
    public function test_the_report_names_every_failing_post_and_the_swallowed_groups(): void
    {
        $buffer = $this->printReportWith([
            'emailPostIdCount' => 4,
            'apiCoveredCount' => 2,
            'layer1Missing' => ['tn-missing-1'],
            'layer1Details' => ['tn-missing-1' => 'post_id=tn-missing-1 group=edinburghfreegle'],
            'layer2Extra' => ['tn-extra-1'],
            'overlapCount' => 2,
            'layer3Mismatches' => ['post_id=tn-mismatch-1 subject: OFFER: Sofa vs OFFER: Settee'],
            'layer4Divergences' => ['post_id=tn-diverge-1 different groups'],
            'layer5Compared' => 2,
            'layer5StructureOnly' => 0,
            'layer5Mismatches' => ['post_id=tn-loki-1 subject differs: A vs B'],
            'lokiEntriesSeen' => 4,
            'emailUnknownGroups' => ['goneawayfreegle' => ['tn-gone-1', 'tn-gone-2']],
            'emailUnknownGroupPostCount' => 2,
            'emailIngestedCount' => 2,
            'apiIngestedCount' => 3,
            'layer2ExtraIngested' => ['tn-extra-1'],
        ]);

        foreach (['tn-missing-1', 'tn-extra-1', 'tn-mismatch-1', 'tn-diverge-1', 'tn-loki-1'] as $postId) {
            $this->assertStringContainsString($postId, $buffer, "the report must name {$postId}");
        }

        // The group that swallowed posts has to be named, with a sample of what it swallowed:
        // "2 missing groups" alone leaves the operator nothing to restore.
        $this->assertStringContainsString('goneawayfreegle', $buffer);
        $this->assertStringContainsString('2 post(s)', $buffer);
        $this->assertStringContainsString('post_id=tn-gone-1', $buffer);

        // The gain line is relative to the email baseline, not a raw post count.
        $this->assertStringContainsString('50.0%', $buffer);
    }

    /**
     * A Loki-less run must say so rather than reporting "0 mismatches", which reads as
     * agreement when in fact Layer 5 never ran.
     */
    public function test_the_report_says_when_layer5_never_ran(): void
    {
        $buffer = $this->printReportWith(['lokiEntriesSeen' => 0]);

        $this->assertStringContainsString('NOT CHECKED', $buffer);
    }

    /**
     * A post_id is a prefix of longer ids, so anchoring matters: filtering out post_id=471169
     * must not also drop post_id=4711690's mismatch, which would hide a real failure.
     */
    public function test_a_post_id_does_not_match_a_longer_one(): void
    {
        $mismatches = [
            'post_id=471169 subject: A vs B',
            'post_id=4711690 subject: C vs D',
        ];

        $kept = $this->invokePrivate('dropMismatchesFor', [$mismatches, '471169']);

        $this->assertSame(['post_id=4711690 subject: C vs D'], $kept);
    }

    /**
     * The informational "TN edited the title" entry has to say what changed. It prefers the
     * Layer 5 wording, falls back to Layer 3, and says something useful when neither line is
     * there rather than naming a post_id the reader then has to go and look up.
     */
    public function test_the_subject_diff_description_prefers_layer5_then_layer3(): void
    {
        $layer5 = ['post_id=tn-1 subject differs: "Sofa" vs "Settee"'];
        $layer3 = ['post_id=tn-1 subject: "Sofa" vs "Settee"'];

        $this->assertStringStartsWith(
            'subject differs:',
            $this->invokePrivate('describeSubjectDiff', [['layer5Mismatches' => $layer5, 'layer3Mismatches' => $layer3], 'tn-1'])
        );

        $this->assertStringStartsWith(
            'subject:',
            $this->invokePrivate('describeSubjectDiff', [['layer5Mismatches' => [], 'layer3Mismatches' => $layer3], 'tn-1'])
        );

        $this->assertStringContainsString(
            'TN edited the title',
            $this->invokePrivate('describeSubjectDiff', [['layer5Mismatches' => [], 'layer3Mismatches' => []], 'tn-1'])
        );
    }

    /**
     * The reclassified Layer 1 buckets and the TN-edited-title bucket are informational, but
     * a run that filtered posts out and did not say so reads as a run that never saw them.
     */
    public function test_the_report_shows_what_was_filtered_out(): void
    {
        $buffer = $this->printReportWith([
            'layer1Deleted' => ['tn-deleted-1'],
            'layer1BumpedOutOfWindow' => ['post_id=tn-bumped-1 now dated 2026-08-01'],
            'layer1ResolvedOutcome' => ['post_id=tn-resolved-1 outcome=Taken'],
            'subjectEditedOnTn' => ['post_id=tn-edited-1 subject differs: "A" vs "B"'],
            'apiCrosspostsDiscarded' => ['tn-crosspost-1'],
        ]);

        $this->assertStringContainsString('deleted=1', $buffer);
        $this->assertStringContainsString('bumped_out_of_window=1', $buffer);
        $this->assertStringContainsString('resolved_outcome=1', $buffer);
        $this->assertStringContainsString('title_edited_on_tn=1', $buffer);
        foreach (['tn-deleted-1', 'tn-bumped-1', 'tn-resolved-1', 'tn-edited-1', 'tn-crosspost-1'] as $postId) {
            $this->assertStringContainsString($postId, $buffer);
        }
    }

    /**
     * How a TN-side edit is detected at all: `expiration` is pinned at original-publish + 90
     * days while `date` moves on a repost or edit, so the two drifting apart is the tell. It
     * has to tolerate a little slop - TN's own timestamps are not to the second - without
     * tolerating a repost.
     */
    public function test_an_edit_is_detected_by_expiration_drifting_from_the_post_date(): void
    {
        $published = '2026-05-01T12:00:00+00:00';
        $expiration = new \DateTime('2026-07-30T12:00:00+00:00');   // publish + 90 days

        $post = new class($expiration)
        {
            public function __construct(private \DateTime $expiration) {}

            public function getExpiration(): \DateTime
            {
                return $this->expiration;
            }
        };

        $this->assertFalse(
            $this->invokePrivate('wasEditedSincePublication', [['date' => $published, 'post' => $post]]),
            'an untouched post has expiration exactly 90 days after its date'
        );

        $this->assertTrue(
            $this->invokePrivate('wasEditedSincePublication', [['date' => '2026-06-15T12:00:00+00:00', 'post' => $post]]),
            'a date moved forward while expiration stayed put is a repost or edit'
        );

        // Nothing to compare against is not evidence of an edit.
        $this->assertFalse($this->invokePrivate('wasEditedSincePublication', [['date' => null, 'post' => $post]]));
    }

    /**
     * Runs printReport() against a layers array, defaulting every key the report reads so a
     * test only has to state the part it cares about, and returns what the operator sees.
     */
    private function printReportWith(array $layers): string
    {
        $full = array_merge([
            'emailPostIdCount' => 0,
            'apiCoveredCount' => 0,
            'layer1Missing' => [],
            'layer2Extra' => [],
            'overlapCount' => 0,
            'layer3Mismatches' => [],
            'layer4Divergences' => [],
            'layer5Compared' => 0,
            'layer5StructureOnly' => 0,
            'layer5Mismatches' => [],
            'lokiEntriesSeen' => 1,
            'emailUnknownGroups' => [],
            'emailUnknownGroupPostCount' => 0,
        ], $layers);

        $output = new BufferedOutput();
        $command = new TNParityCheckCommand();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

        $method = new \ReflectionMethod(TNParityCheckCommand::class, 'printReport');
        $method->setAccessible(true);
        $method->invoke($command, $full);

        return $output->fetch();
    }

    private function invokePrivate(string $name, array $args): mixed
    {
        $command = new TNParityCheckCommand();
        $command->setLaravel($this->app);
        $command->setOutput(new OutputStyle(new ArrayInput([]), new BufferedOutput()));

        $method = new \ReflectionMethod(TNParityCheckCommand::class, $name);
        $method->setAccessible(true);

        return $method->invokeArgs($command, $args);
    }

    private function seedGroup(string $nameshort, float $lat, float $lng): Group
    {
        return Group::firstOrCreate(['nameshort' => $nameshort], [
            'namefull' => 'TN Parity Command Test '.$nameshort,
            'type' => Group::TYPE_FREEGLE,
            'region' => 'TestRegion',
            'lat' => $lat,
            'lng' => $lng,
            'onhere' => 1,
            'publish' => 1,
        ]);
    }

    private function seedTnUser(int $id, string $email, Group $group): void
    {
        DB::table('users')->insert([
            'id' => $id,
            'fullname' => 'TN Parity Command User '.$id,
            'systemrole' => 'User',
            'added' => now(),
            'lastlocation' => $this->fakeLocationId,
        ]);

        UserEmail::create([
            'userid' => $id,
            'email' => $email,
            'preferred' => 1,
            'added' => now(),
            'canon' => $email,
        ]);

        // MODERATED pins the routing outcome on posting status alone, so the comparison does
        // not depend on what the content check would later decide about the fixture text.
        Membership::create([
            'userid' => $id,
            'groupid' => $group->id,
            'role' => Membership::ROLE_MEMBER,
            'collection' => Membership::COLLECTION_APPROVED,
            'emailfrequency' => Membership::EMAIL_FREQUENCY_IMMEDIATE,
            'ourPostingStatus' => 'MODERATED',
            'added' => now(),
        ]);
    }
}

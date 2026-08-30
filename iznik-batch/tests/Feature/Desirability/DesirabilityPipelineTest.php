<?php

namespace Tests\Feature\Desirability;

use App\Services\Desirability\DesirabilityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DesirabilityPipelineTest extends TestCase
{
    private string $version = 'desir-test-1';

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.desirability.model_version' => $this->version]);
        // The selection query reads raw messages/messages_groups: clear anything
        // committed outside our transaction so counts are deterministic.
        DB::table('messages_desirability')->delete();
        DB::table('item_desirability')->delete();
    }

    private function makeApprovedOffer(string $subject, ?string $approvedAt = null): int
    {
        $msgid = DB::table('messages')->insertGetId([
            'subject' => $subject,
            'type' => 'Offer',
            'arrival' => now()->subHour(),
            'date' => now()->subHour(),
            'textbody' => 'Test body',
        ]);
        $groupid = DB::table('groups')->insertGetId([
            'nameshort' => 'TestGroup'.$msgid,
            'type' => 'Freegle',
            'polyindex' => DB::raw("ST_GeomFromText('POINT(-0.1 51.5)', 3857)"),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $msgid,
            'groupid' => $groupid,
            'collection' => 'Approved',
            'arrival' => now()->subHour(),
            'approvedat' => $approvedAt ?? now()->subHour(),
            'deleted' => 0,
        ]);

        return $msgid;
    }

    private function importRows(array $rows): void
    {
        $path = tempnam(sys_get_temp_dir(), 'desir');
        file_put_contents($path, implode("\n", array_map('json_encode', $rows))."\n");
        $this->artisan('desirability:import-artifact', ['path' => $path, '--model-version' => $this->version])
            ->assertExitCode(0);
        unlink($path);
    }

    /** 256 little-endian floats, unit-normalised one-hot-ish vector, base64 encoded. */
    private function vec(int $hotIndex): string
    {
        $vals = array_fill(0, DesirabilityService::EMBEDDING_DIM, 0.0);
        $vals[$hotIndex] = 1.0;

        return base64_encode(pack('g*', ...$vals));
    }

    #[Test]
    public function import_replaces_rows_and_validates_embeddings(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high', 'n_posts' => 700],
            ['canonical' => 'sofa', 'lift_replies' => 0.42, 'evidence' => 900, 'bucket' => 'low', 'embedding' => $this->vec(3)],
            ['canonical' => 'bad-embedding', 'lift_replies' => 1.0, 'evidence' => 5, 'bucket' => 'medium', 'embedding' => base64_encode('too short')],
        ]);
        $this->assertSame(2, DB::table('item_desirability')->where('model_version', $this->version)->count());
        $this->assertSame(1, DB::table('item_desirability')->whereNotNull('embedding')->count());

        // Re-import replaces rather than duplicates.
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.3, 'evidence' => 550, 'bucket' => 'high'],
        ]);
        $rows = DB::table('item_desirability')->where('model_version', $this->version)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(2.3, (float) $rows[0]->lift_replies);
    }

    #[Test]
    public function score_new_is_a_quiet_noop_without_an_artifact(): void
    {
        $this->makeApprovedOffer('OFFER: Washing machine (AB1)');
        $this->artisan('desirability:score-new')->assertExitCode(0);
        $this->assertSame(0, DB::table('messages_desirability')->count());
    }

    #[Test]
    public function score_new_scores_exact_matches_and_respects_the_high_water_mark(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        $msgid = $this->makeApprovedOffer('OFFER: Bosch washing machine (Headington OX3)');

        $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
            ->assertExitCode(0);

        $row = DB::table('messages_desirability')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame('exact', $row->source);
        $this->assertSame('high', $row->bucket);
        $this->assertEquals(2.1, (float) $row->score);
        $this->assertSame('washing machine', $row->matched_canonical);

        // Second run: NOT EXISTS means no rework and no duplicate.
        $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
            ->assertExitCode(0);
        $this->assertSame(1, DB::table('messages_desirability')->where('msgid', $msgid)->count());
    }

    #[Test]
    public function unseen_titles_fall_back_to_knn_over_reference_embeddings(): void
    {
        $this->importRows([
            ['canonical' => 'mobility scooter', 'lift_replies' => 5.4, 'evidence' => 300, 'bucket' => 'high', 'embedding' => $this->vec(0)],
            ['canonical' => 'rubble', 'lift_replies' => 0.1, 'evidence' => 400, 'bucket' => 'low', 'embedding' => $this->vec(7)],
        ]);
        $msgid = $this->makeApprovedOffer('OFFER: Shoprider mobility buggy (ZE1)');

        // Sidecar returns a vector nearly identical to the mobility scooter reference.
        $query = array_fill(0, DesirabilityService::EMBEDDING_DIM, 0.0);
        $query[0] = 0.999;
        $query[1] = 0.0447;
        config(['freegle.desirability.sidecar_url' => 'http://fake-sidecar:3200']);
        Http::fake(['fake-sidecar:3200/*' => Http::response(['embeddings' => [$query]], 200)]);

        try {
            $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
                ->assertExitCode(0);
        } finally {
            config(['freegle.desirability.sidecar_url' => '']);
        }

        $row = DB::table('messages_desirability')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame('knn', $row->source);
        $this->assertSame('mobility scooter', $row->matched_canonical);
        $this->assertSame('high', $row->bucket);
        $this->assertGreaterThan(1.6, (float) $row->score);
    }

    #[Test]
    public function unseen_titles_without_a_sidecar_score_default_medium(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        $msgid = $this->makeApprovedOffer('OFFER: Zorbulator flange bracket (ZE1)');

        $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
            ->assertExitCode(0);

        $row = DB::table('messages_desirability')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame('default', $row->source);
        $this->assertSame('medium', $row->bucket);
        $this->assertEquals(1.0, (float) $row->score);
    }

    #[Test]
    public function knn_disagreeing_neighbours_stay_medium(): void
    {
        // Two similar references straddling 1.0, both very close to the query
        // (top cos ~0.975, above the strong-cos gate): score blends, but the
        // side disagreement must keep the bucket medium - no cliff edge from
        // an uncertain inference. (The references must be similar to each
        // other: two orthogonal vectors can never both clear the 0.80 floor.)
        $b = array_fill(0, DesirabilityService::EMBEDDING_DIM, 0.0);
        $b[0] = 0.9;
        $b[1] = sqrt(1 - 0.81);
        $this->importRows([
            ['canonical' => 'thing a', 'lift_replies' => 2.5, 'evidence' => 100, 'bucket' => 'high', 'embedding' => $this->vec(0)],
            ['canonical' => 'thing b', 'lift_replies' => 0.4, 'evidence' => 100, 'bucket' => 'low', 'embedding' => base64_encode(pack('g*', ...$b))],
        ]);
        $msgid = $this->makeApprovedOffer('OFFER: Mystery widget (ZE1)');

        // Query = normalised midpoint of the two references: cos ~0.975 to each.
        $query = array_fill(0, DesirabilityService::EMBEDDING_DIM, 0.0);
        $query[0] = 0.9747;
        $query[1] = 0.2237;
        config(['freegle.desirability.sidecar_url' => 'http://fake-sidecar:3200']);
        Http::fake(['fake-sidecar:3200/*' => Http::response(['embeddings' => [$query]], 200)]);

        try {
            $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
                ->assertExitCode(0);
        } finally {
            config(['freegle.desirability.sidecar_url' => '']);
        }

        $row = DB::table('messages_desirability')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame('knn', $row->source);
        $this->assertSame('medium', $row->bucket);
    }

    #[Test]
    public function an_empty_artifact_file_fails_without_touching_existing_rows(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'desir');
        file_put_contents($path, '');
        $this->artisan('desirability:import-artifact', ['path' => $path, '--model-version' => $this->version])
            ->assertExitCode(1);
        unlink($path);
        // The previous artifact survives an empty upload.
        $this->assertSame(1, DB::table('item_desirability')->where('model_version', $this->version)->count());
    }

    #[Test]
    public function a_file_of_only_malformed_lines_fails_without_touching_existing_rows(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'desir');
        file_put_contents($path, "not json at all\n{\"canonical\":\"x\"}\n");
        $this->artisan('desirability:import-artifact', ['path' => $path, '--model-version' => $this->version])
            ->assertExitCode(1);
        unlink($path);
        $this->assertSame(1, DB::table('item_desirability')->where('model_version', $this->version)->count());
        $this->assertSame('washing machine', DB::table('item_desirability')->where('model_version', $this->version)->value('canonical'));
    }

    #[Test]
    public function a_failing_sidecar_scores_default_not_an_error(): void
    {
        $this->importRows([
            ['canonical' => 'mobility scooter', 'lift_replies' => 5.4, 'evidence' => 300, 'bucket' => 'high', 'embedding' => $this->vec(0)],
        ]);
        $msgid = $this->makeApprovedOffer('OFFER: Zorbulator flange bracket (ZE1)');

        config(['freegle.desirability.sidecar_url' => 'http://fake-sidecar:3200']);
        Http::fake(['fake-sidecar:3200/*' => Http::response('upstream error', 500)]);

        try {
            $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
                ->assertExitCode(0);
        } finally {
            config(['freegle.desirability.sidecar_url' => '']);
        }

        $row = DB::table('messages_desirability')->where('msgid', $msgid)->first();
        $this->assertNotNull($row);
        $this->assertSame('default', $row->source);
        $this->assertSame('medium', $row->bucket);
    }

    #[Test]
    public function null_and_uncanonicalisable_subjects_score_default(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        $svc = app(DesirabilityService::class);
        $got = $svc->scoreSubject(null);
        $this->assertSame('default', $got['source']);
        $this->assertEquals(1.0, $got['score']);
        $this->assertSame('medium', $got['bucket']);
    }

    #[Test]
    public function pending_and_deleted_posts_are_never_scored(): void
    {
        $this->importRows([
            ['canonical' => 'washing machine', 'lift_replies' => 2.1, 'evidence' => 500, 'bucket' => 'high'],
        ]);
        // Pending post.
        $pending = DB::table('messages')->insertGetId([
            'subject' => 'OFFER: Washing machine (AB1)', 'type' => 'Offer',
            'arrival' => now()->subHour(), 'date' => now()->subHour(), 'textbody' => 'x',
        ]);
        $groupid = DB::table('groups')->insertGetId([
            'nameshort' => 'TestGroupP'.$pending, 'type' => 'Freegle',
            'polyindex' => DB::raw("ST_GeomFromText('POINT(-0.1 51.5)', 3857)"),
        ]);
        DB::table('messages_groups')->insert([
            'msgid' => $pending, 'groupid' => $groupid, 'collection' => 'Pending',
            'arrival' => now()->subHour(), 'deleted' => 0,
        ]);
        // Deleted post.
        $deleted = $this->makeApprovedOffer('OFFER: Washing machine (AB2)');
        DB::table('messages')->where('id', $deleted)->update(['deleted' => now()]);

        $this->artisan('desirability:score-new', ['--since' => now()->subDay()->toDateTimeString()])
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('messages_desirability')->whereIn('msgid', [$pending, $deleted])->count());
    }
}

<?php

namespace Tests\Feature\Eee;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Covers resuming, which is what makes a million-row run affordable: an interrupted live run
 * must not re-ask for rows it has, and an interrupted batch run must wait on the job it
 * already paid for rather than submitting a second one.
 */
class EeeClassifyTextsCommandTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.eee.model' => 'gemini', 'freegle.eee.gemini_api_key' => 'test-key']);
        $this->dir = sys_get_temp_dir() . '/eee-texts-' . uniqid();
        mkdir($this->dir);
        file_put_contents("{$this->dir}/in.tsv", "r1\tOFFER: Fridge freezer\tworks\nr2\tOFFER: Sofa\t\nr3\tOFFER: Kettle\t\n");
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    public function test_live_run_writes_each_listing_once_and_skips_what_it_has(): void
    {
        file_put_contents("{$this->dir}/out.tsv", "r1\t1\t2\tB: Cooling appliances\n");

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '[{"n":1,"e":0,"s":null},{"n":2,"e":1,"s":7}]']]]]],
        ])]);

        $this->artisan('eee:classify-texts', ['input' => "{$this->dir}/in.tsv", 'output' => "{$this->dir}/out.tsv"])
            ->assertExitCode(0);

        Http::assertSent(function (Request $request) {
            $items = json_decode($request->data()['contents'][0]['parts'][0]['text'], true);
            return array_column($items, 't') === ['OFFER: Sofa', 'OFFER: Kettle'];
        });
        $this->assertSame(
            "r1\t1\t2\tB: Cooling appliances\nr2\t0\t\t\nr3\t1\t7\tG: Small mixed WEEE\n",
            file_get_contents("{$this->dir}/out.tsv")
        );
    }

    public function test_live_run_fails_when_a_listing_is_never_answered(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => '[{"n":1,"e":1,"s":2}]']]]]],
        ])]);

        $this->artisan('eee:classify-texts', ['input' => "{$this->dir}/in.tsv", 'output' => "{$this->dir}/out.tsv", '--batch' => 3, '--passes' => 2])
            ->assertExitCode(1);
    }

    public function test_google_batch_submits_waits_and_collects(): void
    {
        $results = implode("\n", [
            json_encode(['key' => 'b0', 'response' => [
                'candidates'    => [['content' => ['parts' => [['text' => '[{"n":1,"e":1,"s":2},{"n":2,"e":0},{"n":3,"e":1,"s":7}]']]]]],
                'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 9],
            ]]),
        ]) . "\n";

        Http::fake([
            'generativelanguage.googleapis.com/upload/v1beta/files'     => Http::response([], 200, ['x-goog-upload-url' => 'https://upload.example/session']),
            'upload.example/*'                                          => Http::response(['file' => ['name' => 'files/in1']]),
            'generativelanguage.googleapis.com/v1beta/models/*'         => Http::response(['name' => 'batches/job1']),
            'generativelanguage.googleapis.com/v1beta/batches/job1'     => Http::response(['metadata' => ['state' => 'BATCH_STATE_SUCCEEDED'], 'response' => ['responsesFile' => 'files/res1']]),
            'generativelanguage.googleapis.com/download/v1beta/files/*' => Http::response($results),
        ]);

        $this->artisan('eee:classify-texts', ['input' => "{$this->dir}/in.tsv", 'output' => "{$this->dir}/out.tsv", '--google-batch' => true, '--batch' => 3])
            ->assertExitCode(0);

        Http::assertSent(fn(Request $r) => str_contains($r->url(), ':batchGenerateContent')
            && $r->data()['batch']['input_config']['file_name'] === 'files/in1');
        $this->assertSame(
            "r1\t1\t2\tB: Cooling appliances\nr2\t0\t\t\nr3\t1\t7\tG: Small mixed WEEE\n",
            file_get_contents("{$this->dir}/out.tsv")
        );
        $this->assertFileDoesNotExist("{$this->dir}/out.tsv.job", 'A finished job is forgotten');

        $line = json_decode(explode("\n", file_get_contents("{$this->dir}/out.tsv.requests.jsonl"))[0], true);
        $this->assertSame('b0', $line['key']);
        $this->assertArrayHasKey('system_instruction', $line['request']);
    }

    public function test_google_batch_resumes_the_job_it_already_submitted(): void
    {
        file_put_contents("{$this->dir}/out.tsv.job", 'batches/job1');
        file_put_contents("{$this->dir}/out.tsv.batches.json", json_encode([['r1' => ['subject' => 'Fridge']]]));

        Http::fake([
            'generativelanguage.googleapis.com/v1beta/batches/job1'     => Http::response(['state' => 'BATCH_STATE_SUCCEEDED', 'dest' => ['fileName' => 'files/res1']]),
            'generativelanguage.googleapis.com/download/v1beta/files/*' => Http::response(json_encode([
                'key' => 'b0', 'response' => ['candidates' => [['content' => ['parts' => [['text' => '[{"n":1,"e":1,"s":2}]']]]]]],
            ]) . "\n"),
        ]);

        // Only r1 was in the submitted job; r2 and r3 are left for another run.
        $this->artisan('eee:classify-texts', ['input' => "{$this->dir}/in.tsv", 'output' => "{$this->dir}/out.tsv", '--google-batch' => true])
            ->assertExitCode(0);

        Http::assertNotSent(fn(Request $r) => str_contains($r->url(), 'upload') || str_contains($r->url(), ':batchGenerateContent'));
        $this->assertSame("r1\t1\t2\tB: Cooling appliances\n", file_get_contents("{$this->dir}/out.tsv"));
    }

    public function test_google_batch_that_failed_keeps_the_job_file_and_fails(): void
    {
        file_put_contents("{$this->dir}/out.tsv.job", 'batches/job1');
        file_put_contents("{$this->dir}/out.tsv.batches.json", json_encode([['r1' => ['subject' => 'Fridge']]]));
        Http::fake(['generativelanguage.googleapis.com/v1beta/batches/job1' => Http::response(['state' => 'BATCH_STATE_EXPIRED'])]);

        $this->artisan('eee:classify-texts', ['input' => "{$this->dir}/in.tsv", 'output' => "{$this->dir}/out.tsv", '--google-batch' => true])
            ->assertExitCode(1);

        $this->assertFileExists("{$this->dir}/out.tsv.job");
    }
}

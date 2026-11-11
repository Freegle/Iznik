<?php

namespace Tests\Feature\Paf;

use App\Console\Commands\Paf\CheckLatestCommand;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * paf:check-latest keeps the latest release on AFD's site in the config row the Go API reads.
 */
class CheckLatestCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.paf.extractor_url' => 'http://paf-extractor.test']);
        DB::table('config')->where('key', CheckLatestCommand::CONFIG_KEY)->delete();
    }

    private function stored(): array
    {
        return json_decode(DB::table('config')->where('key', CheckLatestCommand::CONFIG_KEY)->value('value'), true);
    }

    public function test_stores_the_release_the_extractor_found(): void
    {
        Http::fake(['paf-extractor.test/latest' => Http::response(['size' => 440401920, 'published' => '2026-10-03'])]);

        $this->artisan('paf:check-latest')->assertExitCode(0);

        $value = $this->stored();
        $this->assertSame('2026-10-03', $value['published']);
        $this->assertSame(440401920, $value['size']);
        $this->assertArrayHasKey('checked_at', $value);
        $this->assertArrayNotHasKey('error', $value);
    }

    public function test_stores_why_it_could_not_and_replaces_an_earlier_answer(): void
    {
        DB::table('config')->insert(['key' => CheckLatestCommand::CONFIG_KEY, 'value' => '{"published":"2026-09-01"}']);
        Http::fake(['paf-extractor.test/latest' => Http::response(['error' => 'No SetupRM.exe at the top'], 500)]);

        $this->artisan('paf:check-latest')->assertExitCode(1);

        $value = $this->stored();
        $this->assertSame('No SetupRM.exe at the top', $value['error']);
        $this->assertArrayNotHasKey('published', $value);
    }

    public function test_stores_why_when_the_extractor_cannot_be_reached(): void
    {
        Http::fake(fn () => throw new ConnectionException('Connection refused'));

        $this->artisan('paf:check-latest')->assertExitCode(1);

        $value = $this->stored();
        $this->assertSame('Connection refused', $value['error']);
        $this->assertArrayNotHasKey('published', $value);
    }
}

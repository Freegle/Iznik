<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * The idea generator writes only its own table. It reads the tables of the experiment engine and
 * the member surveys, and its one call into the engine is UxExperimentService::create(), when an
 * idea is scheduled.
 *
 * Each file is named here and read as text, comments removed. The test fails unless it read every
 * one of them and none was empty, so a wrong path cannot make it pass by checking nothing.
 * config/freegle.php and routes/console.php are not read: they hold the blocks and schedule lines of
 * every part.
 */
class UxIdeasBoundaryTest extends TestCase
{
    /** Relative to the batch's base path. */
    private const FILES = [
        'app/Services/Ux/UxIdeasService.php',
        'app/Console/Commands/Ux/IdeasRefreshCommand.php',
        'app/Console/Commands/Ux/IdeasSyncCommand.php',
        'app/Console/Commands/Ux/IdeaCommand.php',
        'app/Services/DiscourseClient.php',
    ];

    /** Tables of the experiment engine and the member surveys. */
    private const OTHERS = ['ux_problems', 'ux_prompts', 'ux_prompt_responses', 'ux_experiments', 'ux_experiment_reads'];

    private const WRITE = '/->\s*(insert\w*|update\w*|upsert|delete|truncate|increment\w*|decrement\w*)\s*\(|DB::\s*(insert|update|delete|statement|unprepared)\s*\(/i';

    /** The calls on the experiment engine other than create(). */
    private const ENGINE_CALL = '/UxExperimentService::class\)\s*->\s*(?!create\()\w+\s*\(/';

    private function withoutComments(string $php): string
    {
        $php = preg_replace('#/\*.*?\*/#s', '', $php) ?? $php;

        // A // that is not part of a URL.
        return preg_replace('#(?<!:)//[^\n]*#', '', $php) ?? $php;
    }

    /**
     * The statements of this code that write to a table of another part.
     *
     * @return string[]
     */
    private function writesToOthers(string $php): array
    {
        $bad = [];
        foreach (explode(';', $this->withoutComments($php)) as $statement) {
            foreach (self::OTHERS as $table) {
                if (str_contains($statement, $table) && preg_match(self::WRITE, $statement)) {
                    $bad[] = trim($statement);
                }
            }
        }

        return $bad;
    }

    public function test_the_idea_generator_writes_no_table_that_belongs_to_another_part(): void
    {
        $read = [];
        foreach (self::FILES as $file) {
            $path = base_path($file);
            $this->assertFileExists($path, "$file is not where this test looks for it");
            $text = (string) file_get_contents($path);
            $this->assertNotSame('', trim($text), "$file is empty");
            $read[] = $file;

            $this->assertSame([], $this->writesToOthers($text), "$file writes a table that belongs to another part");
            $this->assertDoesNotMatchRegularExpression(self::ENGINE_CALL, $this->withoutComments($text), "$file calls the experiment engine for more than create()");
        }

        $this->assertCount(5, $read);
    }

    public function test_the_only_call_into_the_experiment_engine_is_create(): void
    {
        $service = $this->withoutComments((string) file_get_contents(base_path('app/Services/Ux/UxIdeasService.php')));

        $this->assertSame(1, preg_match_all('/UxExperimentService::class\)\s*->\s*create\(/', $service), 'scheduleExperiment() makes the one call');
        $this->assertStringNotContainsString('->start(', $service);
        $this->assertStringNotContainsString('->stop(', $service);
        $this->assertStringNotContainsString('->tick(', $service);
    }

    public function test_the_check_sees_a_write_and_lets_a_read_pass(): void
    {
        $writes = [
            "DB::table('ux_problems')->insert(['title' => 'x']);",
            "DB::table('ux_experiments')\n    ->where('id', 1)\n    ->update(['status' => 'stopped']);",
            "DB::table('ux_prompt_responses')->where('id', 1)->delete();",
            "DB::table('ux_prompts')->upsert([], ['id']);",
            "DB::table('ux_experiment_reads')->truncate();",
            "DB::update('update ux_problems set title = 1');",
            "DB::table('ux_problems')->where('id', 1)->increment('mentions');",
        ];
        foreach ($writes as $code) {
            $this->assertNotSame([], $this->writesToOthers($code), $code);
        }

        $reads = [
            "DB::table('ux_problems')->where('kind', 'problem')->orderBy('id')->get();",
            "DB::table('ux_experiments')->useWritePdo()->where('id', 1)->first();",
            "DB::table('ux_ideas')->where('id', 1)->update(['status' => 'open']);",
            "// DB::table('ux_problems')->insert([]);\nDB::table('ux_ideas')->count();",
            "/* DB::table('ux_experiments')->delete(); */ DB::table('ux_ideas')->count();",
        ];
        foreach ($reads as $code) {
            $this->assertSame([], $this->writesToOthers($code), $code);
        }

        $this->assertSame(1, preg_match(self::ENGINE_CALL, 'app(UxExperimentService::class)->start($id);'));
        $this->assertSame(0, preg_match(self::ENGINE_CALL, 'app(UxExperimentService::class)->create([]);'));
    }
}

<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * The member surveys stand alone. Their files name no table or class of the experiment engine or
 * the idea generator, which are separate changes that can merge in any order, so either can be
 * absent without this one failing to load.
 *
 * Each file is named here and read as text. The test fails unless it read every one of them and
 * none was empty, so a wrong path cannot make it pass by checking nothing. config/freegle.php and
 * routes/console.php are not read: they hold the blocks and schedule lines of every pillar.
 */
class UxPromptBoundaryTest extends TestCase
{
    /** Relative to the batch's base path. */
    private const FILES = [
        'app/Services/Ux/UxPromptService.php',
        'app/Services/Ux/UxScrub.php',
        'app/Services/Ux/PlainWords.php',
        'app/Console/Commands/Ux/PromptCommand.php',
        'app/Console/Commands/Ux/PromptsTickCommand.php',
        'database/migrations/2026_10_15_000001_create_ux_survey_tables.php',
    ];

    private const FORBIDDEN = [
        'ux_experiments', 'ux_experiment_reads', 'ux_ideas',
        'UxExperimentService', 'UxIdeasService',
    ];

    /** @return string[] the forbidden names a text contains */
    private function namesIn(string $text): array
    {
        return array_values(array_filter(self::FORBIDDEN, fn ($name) => str_contains($text, $name)));
    }

    public function test_the_surveys_name_nothing_that_belongs_to_another_pillar(): void
    {
        $read = [];
        foreach (self::FILES as $file) {
            $path = base_path($file);
            $this->assertFileExists($path, "$file is not where this test looks for it");
            $text = (string) file_get_contents($path);
            $this->assertNotSame('', trim($text), "$file is empty");
            $read[] = $file;

            $this->assertSame([], $this->namesIn($text), "$file names something that belongs to another pillar");
        }

        $this->assertCount(6, $read);
    }

    public function test_the_check_can_fail(): void
    {
        // A file that did name them would be caught, so the loop above is checking something.
        $this->assertSame(['ux_experiments', 'UxIdeasService'], $this->namesIn('writes to ux_experiments and calls UxIdeasService'));
        $this->assertSame(['ux_ideas'], $this->namesIn("DB::table('ux_ideas')"));
        $this->assertSame([], $this->namesIn("DB::table('ux_problems')"));
    }
}

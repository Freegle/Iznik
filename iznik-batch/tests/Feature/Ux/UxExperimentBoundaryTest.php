<?php

namespace Tests\Feature\Ux;

use Tests\TestCase;

/**
 * The experiment engine stands alone. It names no table, class or helper of the member surveys or
 * the idea generator, which are separate changes that can merge in any order after it.
 *
 * Each file is named here and read as text. The test fails unless it read every one of them and none
 * was empty, so a wrong path cannot make it pass by checking nothing. config/freegle.php and
 * routes/console.php are not read: they hold the blocks and schedule lines of every pillar.
 */
class UxExperimentBoundaryTest extends TestCase
{
    /** Relative to the batch's base path. */
    private const FILES = [
        'app/Services/Ux/BetaBinomial.php',
        'app/Services/Ux/UxRng.php',
        'app/Services/Ux/UxExperimentRefused.php',
        'app/Services/Ux/UxExperimentService.php',
        'app/Services/Ux/UxArm.php',
        'app/Services/Ux/UxMetricSource.php',
        'app/Services/Ux/ExposureSource.php',
        'app/Services/Ux/Sources/EmailClickSource.php',
        'app/Services/Ux/Sources/ActiveAfterSource.php',
        'app/Services/Ux/Sources/ConfirmReplySource.php',
        'app/Models/UxExperimentExposure.php',
        'config/ux_switches.php',
        'app/Console/Commands/Ux/ExperimentCommand.php',
        'app/Console/Commands/Ux/ExperimentsTickCommand.php',
        'database/migrations/2026_10_14_000001_create_ux_experiment_tables.php',
        'database/migrations/2026_10_16_000001_create_ux_experiment_exposures_table.php',
    ];

    private const FORBIDDEN = [
        'ux_ideas', 'ux_prompts', 'ux_prompt_responses', 'ux_problems',
        'UxPromptService', 'UxIdeasService', 'UxScrub',
    ];

    public function test_the_engine_names_nothing_that_belongs_to_another_pillar(): void
    {
        $read = [];
        foreach (self::FILES as $file) {
            $path = base_path($file);
            $this->assertFileExists($path, "$file is not where this test looks for it");
            $text = (string) file_get_contents($path);
            $this->assertNotSame('', trim($text), "$file is empty");
            $read[] = $file;

            foreach (self::FORBIDDEN as $name) {
                $this->assertStringNotContainsString($name, $text, "$file names $name, which belongs to another pillar");
            }
        }

        $this->assertCount(16, $read);
    }
}

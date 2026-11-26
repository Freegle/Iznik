<?php

namespace Tests\Feature\Servers;

use App\Services\Servers\ServerSizingCommentary;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * The AI review at the top of the sizing report. It must use the subscription token and never the
 * metered key, must be handed the figures, and must fail quietly: the report goes out without it.
 */
class ServerSizingCommentaryTest extends TestCase
{
    private const REPLY = "CHANGES\n- Move db-9 down to C-24, saving £22.50 a month.\nWATCH\n- The docker disk is 84% full.\n* db-2 load is close to its processors.\nCAVEATS:\n- Database memory is cache.\n";

    protected function setUp(): void
    {
        parent::setUp();
        config(['freegle.servers.oauth_token' => 'tok-123', 'freegle.servers.model' => 'opus']);
    }

    private function fakeCli(array $body, int $exit = 0): void
    {
        Process::fake(['*' => Process::result(output: json_encode($body), exitCode: $exit)]);
    }

    public function test_the_three_lists_are_read_from_the_reply(): void
    {
        $this->fakeCli(['type' => 'result', 'result' => self::REPLY]);

        $review = (new ServerSizingCommentary)->write(['servers' => [['name' => 'db-9']]]);

        $this->assertSame(['Move db-9 down to C-24, saving £22.50 a month.'], $review['changes']);
        $this->assertSame(['The docker disk is 84% full.', 'db-2 load is close to its processors.'], $review['watch']);
        $this->assertSame(['Database memory is cache.'], $review['caveats']);
    }

    public function test_it_runs_on_the_subscription_token_with_the_figures_and_without_the_metered_key(): void
    {
        $this->fakeCli(['type' => 'result', 'result' => self::REPLY]);

        (new ServerSizingCommentary)->write(['servers' => [['name' => 'db-9']]]);

        Process::assertRan(function ($process) {
            $env = (array) (function () {
                return $this->environment;
            })->call($process);
            $command = (array) $process->command;

            return $command[0] === 'claude'
                && in_array('--output-format', $command, true)
                && $command[array_search('--model', $command, true) + 1] === 'opus'
                && str_contains($command[array_search('-p', $command, true) + 1], '"name": "db-9"')
                && $command[array_search('--append-system-prompt', $command, true) + 1] === ServerSizingCommentary::SYSTEM_PROMPT
                && ($env['CLAUDE_CODE_OAUTH_TOKEN'] ?? null) === 'tok-123'
                && ($env['ANTHROPIC_API_KEY'] ?? null) === false
                && ! empty($env['CLAUDE_CONFIG_DIR']);
        });
    }

    public function test_no_token_means_no_call_and_no_review(): void
    {
        config(['freegle.servers.oauth_token' => '']);
        Process::fake();

        $this->assertNull((new ServerSizingCommentary)->write([]));
        Process::assertNothingRan();
    }

    public function test_a_failed_call_gives_no_review(): void
    {
        $this->fakeCli(['type' => 'result', 'result' => 'boom'], 1);
        $this->assertNull((new ServerSizingCommentary)->write([]));
    }

    public function test_a_refusal_reported_in_the_body_gives_no_review(): void
    {
        $this->fakeCli(['type' => 'result', 'is_error' => true, 'api_error_status' => 429, 'result' => 'limit reached']);
        $this->assertNull((new ServerSizingCommentary)->write([]));
    }

    public function test_output_that_is_not_the_cli_wrapper_gives_no_review(): void
    {
        Process::fake(['*' => Process::result(output: 'not json')]);
        $this->assertNull((new ServerSizingCommentary)->write([]));
    }

    public function test_a_process_that_throws_gives_no_review(): void
    {
        Process::fake(['*' => function () {
            throw new \RuntimeException('no such binary');
        }]);
        $this->assertNull((new ServerSizingCommentary)->write([]));
    }

    public function test_a_reply_in_some_other_shape_is_left_out(): void
    {
        $this->fakeCli(['type' => 'result', 'result' => 'Everything looks fine to me.']);
        $this->assertNull((new ServerSizingCommentary)->write([]));
    }

    public function test_headings_and_items_in_other_common_forms_are_still_read(): void
    {
        $review = (new ServerSizingCommentary)->parse(implode("\n", [
            'Here is the review.',
            '**Changes**',
            '1. Move db-9 down to C-24,',
            '   saving £22.50 a month.',
            '',
            '## Watch:',
            '2) The docker disk.',
            'Caveats -',
            '• Database memory is cache.',
        ]));

        $this->assertSame(['Move db-9 down to C-24, saving £22.50 a month.'], $review['changes']);
        $this->assertSame(['The docker disk.'], $review['watch']);
        $this->assertSame(['Database memory is cache.'], $review['caveats']);
    }

    public function test_it_runs_in_the_empty_config_directory(): void
    {
        $dir = sys_get_temp_dir().'/servers-claude-cwd-'.getmypid();
        config(['freegle.servers.claude_config_dir' => $dir]);
        $this->fakeCli(['type' => 'result', 'result' => self::REPLY]);

        (new ServerSizingCommentary)->write([]);

        Process::assertRan(fn ($process) => $process->path === $dir);
        @rmdir($dir);
    }

    public function test_a_configured_config_directory_is_used(): void
    {
        $dir = sys_get_temp_dir().'/servers-claude-test-'.getmypid();
        config(['freegle.servers.claude_config_dir' => $dir]);
        $this->fakeCli(['type' => 'result', 'result' => self::REPLY]);

        (new ServerSizingCommentary)->write([]);

        $this->assertDirectoryExists($dir);
        Process::assertRan(fn ($process) => ((array) (function () {
            return $this->environment;
        })->call($process))['CLAUDE_CONFIG_DIR'] === $dir);
        @rmdir($dir);
    }
}

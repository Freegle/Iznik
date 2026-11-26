<?php

namespace Tests\Unit\Mail;

use Tests\TestCase;

class HousekeeperResultsMailTest extends TestCase
{
    private function makeViewData(array $overrides = []): array
    {
        return array_merge([
            'task'       => 'facebook-deletion',
            'taskStatus' => 'success',
            'summary'    => '3 users processed',
            'results'    => [],
            'timestamp'  => '24/06/2026 10:00:00',
        ], $overrides);
    }

    public function test_preheader_shows_ok_prefix_and_summary_on_success(): void
    {
        $data = $this->makeViewData([
            'taskStatus' => 'success',
            'summary'    => 'Deleted 7 Facebook accounts',
        ]);

        $html = view('emails.mjml.housekeeper.results', $data)->render();

        $this->assertStringContainsString(
            '<mj-preview>OK: Deleted 7 Facebook accounts</mj-preview>',
            $html,
            'Preheader must start with "OK: " followed by the summary on success'
        );
    }

    public function test_preheader_shows_failed_prefix_and_summary_on_failure(): void
    {
        $data = $this->makeViewData([
            'taskStatus' => 'failure',
            'summary'    => 'Could not connect to Facebook API',
        ]);

        $html = view('emails.mjml.housekeeper.results', $data)->render();

        $this->assertStringContainsString(
            '<mj-preview>FAILED: Could not connect to Facebook API</mj-preview>',
            $html,
            'Preheader must start with "FAILED: " followed by the summary on failure'
        );
    }
}

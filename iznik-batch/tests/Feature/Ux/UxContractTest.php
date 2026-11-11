<?php

namespace Tests\Feature\Ux;

use App\Services\Ux\UxRollupService;
use Carbon\Carbon;
use Tests\Support\FakesUxLoki;
use Tests\TestCase;

/**
 * The rollup reads names the browser writes. This fails when either side renames one:
 * the event names and the envelope fields come from useFlowTelemetry.js itself.
 */
class UxContractTest extends TestCase
{
    use FakesUxLoki;

    /** Kept in step with useFlowTelemetry.js; asserted equal to it wherever the file is readable. */
    private const EVENTS = ['flow_start', 'step_enter', 'step_complete', 'step_back', 'field_revisit', 'validation_failed',
        'choice_switched', 'flow_submit', 'flow_submit_result', 'flow_complete', 'flow_abandon', 'flow_resume', 'help_sought'];

    private const EMITTED = ['flow', 'flow_instance_id', 'step', 'step_seq', 'variant', 'entry', 'first_visit', 'dwell_ms',
        'to_step', 'field', 'change_kind', 'rule', 'attempt', 'from', 'to', 'ok', 'status', 'error_code', 'reason',
        'last_step', 'age_ms', 'start_logged_in', 'started_ms', 'device_class', 'logged_in', 'is_app', 'page_route', 'action_name', 'session_id', 'user_id'];

    private function js(): ?string
    {
        foreach ([base_path('../iznik-nuxt3/composables/useFlowTelemetry.js'), '/var/www/iznik-nuxt3/composables/useFlowTelemetry.js'] as $p) {
            if (is_file($p)) {
                return file_get_contents($p);
            }
        }
        // The batch container only mounts iznik-batch; the pinned lists above stand in for the file.
        return null;
    }

    private function constants(string $js, string $name): array
    {
        preg_match('/export const '.$name.' = \{(.*?)\n\}/s', $js, $m);
        preg_match_all("/:\s*'([^']+)'/", $m[1] ?? '', $v);

        return $v[1];
    }

    public function test_flow_names_and_events_match_the_browser(): void
    {
        $js = $this->js();
        $events = self::EVENTS;
        if ($js !== null) {
            $this->assertEqualsCanonicalizing($this->constants($js, 'FlowName'), UxRollupService::FLOWS);
            $this->assertEqualsCanonicalizing($this->constants($js, 'FlowEvent'), self::EVENTS);
        }

        $this->fakeLoki();
        $this->service()->rollupLokiDay(Carbon::parse('2026-03-05', 'UTC'));
        preg_match_all('/action_name="([a-z_]+)"/', implode("\n", $this->lokiQueries), $m);
        foreach (array_unique($m[1]) as $used) {
            $this->assertContains($used, $events, "rollup reads action_name=\"$used\" which the browser does not emit");
        }
    }

    public function test_every_flow_field_the_rollup_extracts_is_emitted(): void
    {
        $js = $this->js();
        $emitted = self::EMITTED;
        if ($js !== null) {
            // Every field named in a key position in the composable must be in the pinned list.
            foreach (['flow', 'step', 'dwell_ms', 'first_visit', 'last_step', 'ok'] as $must) {
                $this->assertStringContainsString($must.':', $js, "browser no longer emits '$must'");
            }
        }

        $this->fakeLoki();
        $this->service()->rollupLokiDay(Carbon::parse('2026-03-05', 'UTC'));
        foreach ($this->lokiQueries as $q) {
            if (! str_contains($q, 'event_type="flow"')) {
                continue;
            }
            preg_match_all('/\| json ([a-z_, ]+?)(?: \||\s*\[)/', $q, $j);
            foreach ($j[1] as $list) {
                foreach (array_map('trim', explode(',', $list)) as $field) {
                    $this->assertContains($field, $emitted, "rollup extracts '$field' which the browser does not emit");
                }
            }
        }
    }

    private function service(): UxRollupService
    {
        return app(UxRollupService::class);
    }

    public function test_every_flow_field_an_experiment_read_extracts_is_emitted(): void
    {
        $this->fakeLoki();
        \Illuminate\Support\Facades\DB::table('ux_experiments')->where('name', 'contract-probe')->delete();
        $id = \Illuminate\Support\Facades\DB::table('ux_experiments')->insertGetId([
            'flow' => 'give', 'name' => 'contract-probe', 'title' => 't', 'hypothesis' => 'h', 'change_description' => 'c', 'expected' => 'e',
            'arms' => json_encode(['control' => [0, 49], 'b' => [50, 99]]), 'status' => 'running',
            'started_at' => '2026-10-14 10:00:00', 'ends_at' => '2026-10-15 10:00:00', 'created_at' => '2026-10-14 10:00:00', 'updated_at' => '2026-10-14 10:00:00',
        ]);
        try {
            app(\App\Services\Ux\UxExperimentService::class)->read(
                \Illuminate\Support\Facades\DB::table('ux_experiments')->find($id), Carbon::parse('2026-10-14 14:00:00', 'UTC'));
        } finally {
            \Illuminate\Support\Facades\DB::table('ux_experiments')->where('id', $id)->delete();
        }

        $this->assertNotEmpty($this->lokiQueries);
        foreach ($this->lokiQueries as $q) {
            preg_match_all('/\| json ([a-z_, ]+?) \|/', $q, $j);
            $this->assertNotEmpty($j[1], 'no json stage in: '.$q);
            foreach ($j[1] as $list) {
                foreach (array_map('trim', explode(',', $list)) as $field) {
                    $this->assertContains($field, self::EMITTED, "the experiment read extracts '$field' which the browser does not emit");
                }
            }
        }
    }
}

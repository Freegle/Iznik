<?php

namespace Tests\Feature\Monitor;

use App\Monitoring\Checks\HostHealthCheck;
use App\Monitoring\HostCommandRunner;
use App\Monitoring\OutcomeResult;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * HostHealthCheck ports the per-host checks from V1's scripts/cron/status.php
 * (security patches, reboot-required, monit summary) into the outcome
 * monitoring pipeline. These tests drive it with a fake runner returning
 * canned probe output, so no ssh happens under test.
 */
class HostHealthCheckTest extends TestCase
{
    private function runnerReturning(?string $output): HostCommandRunner
    {
        return new class($output) implements HostCommandRunner
        {
            public ?string $lastTarget = null;
            public ?string $lastScript = null;

            public function __construct(private ?string $output)
            {
            }

            public function run(string $target, string $script): ?string
            {
                $this->lastTarget = $target;
                $this->lastScript = $script;

                return $this->output;
            }
        };
    }

    /**
     * Build probe output. Defaults are a completely healthy host.
     */
    private function probeOutput(
        string $reboot = 'no',
        int $security = 0,
        ?array $monitLines = ['freegle-host                     OK                          System'],
        ?array $modes = null,
    ): string {
        $out = "REBOOT:{$reboot}\n";
        $out .= "SECURITY:{$security}\n";

        if ($monitLines === null) {
            $out .= "MONIT_ABSENT\n";
        } else {
            $out .= "MONIT_BEGIN\n";
            $out .= "Monit 5.31.0 uptime: 1d 2h 3m\n";
            $out .= " Service Name                     Status                      Type          \n";
            foreach ($monitLines as $line) {
                $out .= " {$line}\n";
            }
            $out .= "MONIT_END\n";

            if ($modes !== null) {
                // `monit status -B`: one block per service, headed by the type
                // and quoted name, with the mode a few lines in.
                $out .= "MONIT_STATUS_BEGIN\n";
                $out .= "Monit 5.31.0 uptime: 1d 2h 3m\n\n";
                foreach ($modes as $service => $mode) {
                    // A plain string is the mode line as a monit before 5.26
                    // printed it. An array spells out mode and on-reboot the
                    // way monit 5.26+ prints them, where `mode manual` in the
                    // config comes out as `active` plus `on reboot laststate`.
                    [$modeLine, $onReboot] = is_array($mode)
                        ? [$mode['mode'] ?? 'active', $mode['onreboot'] ?? 'start']
                        : [$mode, null];
                    $out .= "Remote Host '{$service}'\n";
                    $out .= "  status                       Not monitored\n";
                    $out .= "  monitoring status            Not monitored\n";
                    $out .= "  monitoring mode              {$modeLine}\n";
                    if ($onReboot !== null) {
                        $out .= "  on reboot                    {$onReboot}\n";
                    }
                    $out .= "  data collected               Tue, 22 Sep 2026 07:55:34\n\n";
                }
                $out .= "MONIT_STATUS_END\n";
            }
        }

        return $out;
    }

    private function evaluate(?string $probeOutput = null, string $target = 'root@host-a'): OutcomeResult
    {
        $check = new HostHealthCheck($target, $this->runnerReturning($probeOutput));

        return $check->evaluate(Carbon::now());
    }

    public function test_slug_is_the_host_without_the_ssh_user(): void
    {
        $check = new HostHealthCheck('root@host-a', $this->runnerReturning(null));
        $this->assertSame('host:host-a', $check->slug());

        $check = new HostHealthCheck('host-b', $this->runnerReturning(null));
        $this->assertSame('host:host-b', $check->slug());
    }

    public function test_a_healthy_host_is_ok(): void
    {
        $result = $this->evaluate($this->probeOutput());

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_reboot_required_is_a_plain_warning_without_packages(): void
    {
        $result = $this->evaluate($this->probeOutput(reboot: 'yes'));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('reboot required', $result->message);
        // No package list: it names nothing a mod acts on, and it made the
        // modal read like a changelog.
        $this->assertStringNotContainsString('(', $result->message);
    }

    public function test_pending_security_updates_are_a_warning_with_the_count(): void
    {
        $result = $this->evaluate($this->probeOutput(security: 3));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('3 security update(s) to apply', $result->message);
    }

    public function test_a_monit_service_not_monitored_is_a_warning(): void
    {
        $result = $this->evaluate($this->probeOutput(monitLines: [
            'freegle-host                     OK                          System',
            'iznik-server-go                  Not monitored               Process',
        ]));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('iznik-server-go', $result->message);
        $this->assertStringContainsString('Not monitored', $result->message);
    }

    public function test_the_probe_asks_monit_for_each_services_mode(): void
    {
        $this->assertStringContainsString('monit status -B', HostHealthCheck::PROBE);
        $this->assertStringContainsString('MONIT_STATUS_BEGIN', HostHealthCheck::PROBE);
    }

    public function test_not_monitored_in_manual_mode_is_held_on_purpose_not_a_warning(): void
    {
        // A retired service whose check is kept in place under `mode manual`,
        // as a monit before 5.26 reports it: somebody switched it off, and the
        // host is healthy.
        $result = $this->evaluate($this->probeOutput(
            monitLines: [
                'freegle-host                     OK                          System',
                'iznik-server-go                  Not monitored               Remote Host',
                'mysqld                           Not monitored               Process',
            ],
            modes: ['iznik-server-go' => 'manual', 'mysqld' => 'manual', 'redis-server' => 'active'],
        ));

        $this->assertTrue($result->isOk(), $result->message);
        $this->assertStringContainsString('holds iznik-server-go, mysqld retired on purpose', $result->message);
    }

    public function test_not_monitored_with_on_reboot_laststate_is_held_as_monit_5_26_reports_it(): void
    {
        // The same `mode manual` config on monit 5.26+ (5.31 on the database
        // nodes): the mode line says active, and only `on reboot laststate`
        // carries the fact that a person parked it.
        $result = $this->evaluate($this->probeOutput(
            monitLines: [
                'freegle-host                     OK                          System',
                'mysqld                           Not monitored               Process',
                'iznik-server-go                  Not monitored               Remote Host',
            ],
            modes: [
                'mysqld' => ['mode' => 'active', 'onreboot' => 'laststate'],
                'iznik-server-go' => ['mode' => 'active', 'onreboot' => 'laststate'],
                'garbd' => ['mode' => 'active', 'onreboot' => 'start'],
            ],
        ));

        $this->assertTrue($result->isOk(), $result->message);
        $this->assertStringContainsString('holds mysqld, iznik-server-go retired on purpose', $result->message);
    }

    public function test_not_monitored_with_on_reboot_start_is_drift_and_a_warning(): void
    {
        // Monit 5.26+ output for a check nobody parked: an apt upgrade changed
        // the binary and the checksum template unmonitored it. That is the
        // safety net gone, and stays a warning.
        $result = $this->evaluate($this->probeOutput(
            monitLines: ['nginx_bin                        Not monitored               File'],
            modes: ['nginx_bin' => ['mode' => 'active', 'onreboot' => 'start']],
        ));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('nginx_bin', $result->message);
    }

    public function test_not_monitored_in_active_mode_is_still_a_warning(): void
    {
        // Active mode means monit should be watching it; "Not monitored" is
        // then a `monit stop` nobody followed up, exactly V1's warning.
        $result = $this->evaluate($this->probeOutput(
            monitLines: ['iznik-server-go                  Not monitored               Remote Host'],
            modes: ['iznik-server-go' => 'active'],
        ));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('iznik-server-go', $result->message);
    }

    public function test_manual_mode_excuses_only_not_monitored(): void
    {
        // A manual-mode service that monit IS watching and finds broken is as
        // broken as any other.
        $result = $this->evaluate($this->probeOutput(
            monitLines: ['iznik-server-go                  Does not exist              Remote Host'],
            modes: ['iznik-server-go' => 'manual'],
        ));

        $this->assertTrue($result->isBreach());
        $this->assertSame('error', $result->severity);
    }

    public function test_transient_monit_states_are_warnings_not_errors(): void
    {
        $result = $this->evaluate($this->probeOutput(monitLines: [
            'php-fpm                          Initializing                Process',
            'mysqld                           Resource limit matched      Process',
        ]));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
    }

    public function test_a_failed_monit_service_is_an_error(): void
    {
        $result = $this->evaluate($this->probeOutput(monitLines: [
            'iznik-server-go                  Does not exist              Process',
        ]));

        $this->assertTrue($result->isBreach());
        $this->assertSame('error', $result->severity);
        $this->assertStringContainsString('iznik-server-go', $result->message);
    }

    public function test_a_dead_monit_daemon_is_an_error(): void
    {
        $output = "REBOOT:no\nSECURITY:0\nMONIT_BEGIN\n"
            . "Status not available -- the monit daemon is not running\nMONIT_END\n";

        $result = $this->evaluate($output);

        $this->assertTrue($result->isBreach());
        $this->assertSame('error', $result->severity);
        $this->assertStringContainsString('monit', strtolower($result->message));
    }

    public function test_a_host_without_monit_is_still_ok(): void
    {
        $result = $this->evaluate($this->probeOutput(monitLines: null));

        $this->assertTrue($result->isOk(), $result->message);
    }

    public function test_an_unreachable_host_is_a_warning(): void
    {
        $result = $this->evaluate(null);

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('unreachable', $result->message);
    }

    public function test_an_error_outranks_coexisting_warnings(): void
    {
        $result = $this->evaluate($this->probeOutput(
            reboot: 'yes',
            security: 2,
            monitLines: ['exim                             Execution failed            Process'],
        ));

        $this->assertTrue($result->isBreach());
        $this->assertSame('error', $result->severity);
        // The warnings still travel in the message so the modal shows the
        // whole host picture, not just the worst finding.
        $this->assertStringContainsString('reboot required', $result->message);
        $this->assertStringContainsString('2 security update(s)', $result->message);
        $this->assertStringContainsString('exim', $result->message);
    }
}

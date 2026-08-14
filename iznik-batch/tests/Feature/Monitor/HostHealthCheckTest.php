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
        string $rebootPkgs = '',
        int $security = 0,
        ?array $monitLines = ['freegle-host                     OK                          System'],
    ): string {
        $out = "REBOOT:{$reboot}\n";
        $out .= "REBOOT_PKGS:{$rebootPkgs}\n";
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

    public function test_reboot_required_is_a_warning_naming_the_packages(): void
    {
        $result = $this->evaluate($this->probeOutput(reboot: 'yes', rebootPkgs: 'linux-base libc6'));

        $this->assertTrue($result->isBreach());
        $this->assertSame('warning', $result->severity);
        $this->assertStringContainsString('reboot required', $result->message);
        $this->assertStringContainsString('linux-base libc6', $result->message);
    }

    public function test_reboot_package_names_lose_their_version_and_dedupe(): void
    {
        $result = $this->evaluate($this->probeOutput(
            reboot: 'yes',
            rebootPkgs: 'linux-image-5.15.0-186-generic linux-base libc6 linux-image-5.15.0-181-generic',
        ));

        $this->assertTrue($result->isBreach());
        $this->assertStringContainsString('(linux-image-generic linux-base libc6)', $result->message);
        $this->assertStringNotContainsString('5.15', $result->message);
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
        $output = "REBOOT:no\nREBOOT_PKGS:\nSECURITY:0\nMONIT_BEGIN\n"
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

<?php

namespace App\Monitoring;

use Symfony\Component\Process\Process;

/**
 * HostCommandRunner over ssh, mirroring how V1's status cron reached each
 * host. BatchMode forbids interactive prompts (a missing/bad key fails fast
 * instead of hanging the scheduler), and the known-hosts checks are disabled
 * because the container's filesystem is ephemeral — the same trade V1 made
 * with StrictHostKeyChecking=no.
 */
class SshHostCommandRunner implements HostCommandRunner
{
    public function __construct(
        private readonly string $keyPath,
        private readonly int $timeoutSeconds = 30,
    ) {
    }

    public function run(string $target, string $script): ?string
    {
        $process = new Process(
            [
                'ssh',
                '-o', 'ConnectTimeout=10',
                '-o', 'BatchMode=yes',
                '-o', 'StrictHostKeyChecking=no',
                '-o', 'UserKnownHostsFile=/dev/null',
                '-o', 'LogLevel=ERROR',
                '-i', $this->keyPath,
                $target,
                $script,
            ],
            timeout: $this->timeoutSeconds,
        );

        try {
            $process->run();
        } catch (\Throwable) {
            // Includes ProcessTimedOutException — a host that can't answer the
            // probe within the timeout is unreachable for our purposes.
            return null;
        }

        // ssh exits 255 when the CONNECTION failed (auth, route, timeout); the
        // probe script's own exit status is irrelevant because its findings
        // travel in stdout. Empty output is also treated as unreachable — the
        // probe always prints its markers when it actually ran.
        if ($process->getExitCode() === 255 || trim($process->getOutput()) === '') {
            return null;
        }

        return $process->getOutput();
    }
}

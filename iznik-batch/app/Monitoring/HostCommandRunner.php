<?php

namespace App\Monitoring;

/**
 * Runs a shell script on a remote host and returns its combined output.
 *
 * Abstracted so HostHealthCheck can be tested with canned output; the real
 * implementation (SshHostCommandRunner) shells out to ssh, exactly as V1's
 * scripts/cron/status.php did.
 */
interface HostCommandRunner
{
    /**
     * @param  string  $target  ssh target, e.g. "root@10.0.0.1"
     * @param  string  $script  shell script to run remotely
     * @return string|null the script's stdout, or null if the host was unreachable
     */
    public function run(string $target, string $script): ?string;
}

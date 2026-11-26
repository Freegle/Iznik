<?php

namespace App\Services\Servers;

use App\Models\ServerSample;
use App\Monitoring\HostCommandRunner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Takes one reading from every host the monitoring already reaches and stores it.
 *
 * The hosts are the ones in FREEGLE_MONITORING_HOSTS and SERVERS_EXTRA_HOSTS, so the estate's
 * layout stays in the environment and a host added there is sampled without a code change. A host that does not
 * answer is skipped and logged; the next run tries it again.
 */
class ServerSampler
{
    public function __construct(
        private readonly HostCommandRunner $runner,
        private readonly ServerProbe $probe,
    ) {}

    /**
     * @return array{sampled: list<string>, failed: list<string>}
     */
    public function sampleAll(): array
    {
        $sampled = [];
        $failed = [];

        foreach ($this->targets() as $target) {
            $output = $this->runner->run($target, ServerProbe::SCRIPT);
            $reading = $output === null ? null : $this->probe->parse($output);

            if ($reading === null) {
                Log::warning('servers:sample got no reading', ['target' => $target]);
                $failed[] = $target;

                continue;
            }

            ServerSample::create($reading + ['sampled_at' => Carbon::now()]);
            $sampled[] = $reading['host'];
        }

        return ['sampled' => $sampled, 'failed' => $failed];
    }

    /**
     * Remove readings older than the retention setting. Returns how many went.
     */
    public function prune(): int
    {
        $days = max(1, (int) config('freegle.servers.sample_retention_days', 400));

        return ServerSample::where('sampled_at', '<', Carbon::now()->subDays($days))->delete();
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        // The monitored hosts, plus any that are sampled for sizing only (SERVERS_EXTRA_HOSTS):
        // a host listed there is not drawn into the patch and monit checks.
        return array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', config('freegle.monitoring.hosts', '').','.config('freegle.servers.extra_hosts', ''))
        ))));
    }
}

<?php

namespace Tests\Feature\Integrations;

use App\Services\ReachVolunteeringService;
use Illuminate\Support\Facades\DB;
use Tests\Support\LagsTheReadNode;
use Tests\TestCase;

/**
 * The Reach feed can list an opportunity twice. The first copy is inserted, and the read node may not
 * have it when the second copy is looked up. On live (19 October 2026) the second copy was inserted
 * again and the sync stopped on "Duplicate entry 'reach-1341238' for key 'volunteering.externalid'".
 */
class ReachVolunteeringReadNodeLagTest extends TestCase
{
    use LagsTheReadNode;

    public function test_a_row_the_read_node_has_not_seen_is_still_found(): void
    {
        $externalId = 'reach-lag-'.random_int(100000, 999999);
        $url = "https://reachvolunteering.org.uk/vol/{$externalId}";

        $this->withLaggingReadNode(['volunteering'], function (string $lag) use ($externalId, $url) {
            $db = DB::connection($lag);
            $id = $db->table('volunteering')->insertGetId([
                'title' => 'Lag role', 'location' => 'Lagtown', 'description' => 'x', 'contacturl' => $url,
                'externalid' => $externalId, 'pending' => 1, 'online' => 0, 'deleted' => 0, 'added' => now(),
            ]);

            $service = new class extends ReachVolunteeringService {
                public function __construct() {}

                public function lookUp(string $url, string $externalId): ?object
                {
                    return $this->existing($url, $externalId);
                }
            };

            DB::setDefaultConnection($lag);
            try {
                // The read node has no rows at all, so only a read from the write node finds it.
                $this->assertNull($db->table('volunteering')->where('externalid', $externalId)->first());
                $byId = $service->lookUp('https://elsewhere.example/none', $externalId);
                $byUrl = $service->lookUp($url, 'reach-none');
            } finally {
                DB::setDefaultConnection('mysql');
                $db->table('volunteering')->where('id', $id)->delete();
            }

            $this->assertSame($id, (int) $byId->id);
            $this->assertSame($id, (int) $byUrl->id);
        });
    }
}

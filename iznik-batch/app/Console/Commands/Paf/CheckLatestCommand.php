<?php

namespace App\Console\Commands\Paf;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Asks the paf-extractor sidecar which Royal Mail address data (PAF) release is at the top of
 * AFD's site, and keeps the answer in the config row paf_latest for the PAF update on SysAdmin Housekeeping:
 * {"published":"2026-10-03","size":440401920,"checked_at":"..."}, or {"error":...} when AFD
 * or the sidecar could not be reached. The Go API cannot reach the sidecar, so it reads this.
 *
 * docs/ops/runbooks/paf-address-data.md.
 */
#[AsCommand(name: 'paf:check-latest')]
class CheckLatestCommand extends Command
{
    public const CONFIG_KEY = 'paf_latest';

    protected $signature = 'paf:check-latest';

    protected $description = 'Record the latest PAF release on the AFD site, for the PAF update on SysAdmin Housekeeping';

    public function handle(): int
    {
        $extractor = rtrim((string) config('freegle.paf.extractor_url'), '/');
        $value = ['checked_at' => now()->toIso8601String()];

        try {
            $response = Http::timeout(90)->get("{$extractor}/latest");
            if ($response->successful() && $response->json('published')) {
                $value += ['published' => $response->json('published'), 'size' => $response->json('size')];
            } else {
                $value['error'] = (string) ($response->json('error') ?? 'The extractor gave no release date');
            }
        } catch (\Throwable $e) {
            $value['error'] = $e->getMessage();
        }

        DB::table('config')->upsert([['key' => self::CONFIG_KEY, 'value' => json_encode($value)]], ['key'], ['value']);
        $this->line(json_encode($value));

        return isset($value['error']) ? self::FAILURE : self::SUCCESS;
    }
}

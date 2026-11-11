<?php

namespace App\Console\Commands\Partnerships;

use App\Services\AuthorityStatsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Brings council sponsorships set up before the Partnerships page existed onto it, so the
 * page shows each council's history of deals and the live ones can be managed there.
 *
 * Those sponsorships are rows in groups_sponsorship, one per community. Rows with the same
 * sponsor name and dates are one deal. Each deal is filed against the council whose name
 * matches the sponsor name, or the one given with --map; a deal whose council cannot be found
 * is reported and skipped. The existing rows are linked rather than copied, so members see no
 * change. Rows already linked to a partnership are skipped, so running it twice is harmless.
 *
 * Imported deals are marked Confirmed - they were shown to members - with the value being
 * the sum of the per-community amounts. Both are worth checking on the page afterwards.
 *
 *   php artisan partnerships:import-sponsorships --dry-run
 *   php artisan partnerships:import-sponsorships --map="Norfolk Recycles=12345"
 */
#[AsCommand(name: 'partnerships:import-sponsorships')]
class ImportSponsorshipsCommand extends Command
{
    protected $signature = 'partnerships:import-sponsorships
                            {--map=* : "Sponsor name=authority id" for a sponsor whose name is not a council name}
                            {--dry-run : Report what would be imported without importing it}';

    protected $description = 'Turn sponsorships set up by hand into partnerships on the Partnerships page';

    // The kinds of authority a sponsorship deal is done with: councils, not wards or constituencies.
    private const COUNCIL_CODES = ['CTY', 'DIS', 'UTA', 'MTD', 'LBO', 'GLA', 'WST'];

    public function handle(AuthorityStatsService $authorityStats): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $map = $this->parseMap();
        if ($map === null) {
            return Command::FAILURE;
        }

        $linked = DB::table('partnerships_groups')->whereNotNull('sponsorshipid')->pluck('sponsorshipid')->all();

        $deals = DB::table('groups_sponsorship')
            ->whereNotIn('id', $linked)
            ->orderBy('startdate')
            ->get()
            ->groupBy(fn ($row) => $row->name . '|' . $row->startdate . '|' . $row->enddate);

        $imported = 0;
        $skipped = 0;

        foreach ($deals as $rows) {
            $first = $rows->first();
            $authorityId = $map[$first->name] ?? $this->findCouncil($first->name);

            if ($authorityId === null) {
                $skipped++;
                $this->warn(sprintf('No council found for "%s" (%s to %s, %d %s). Give one with --map="%s=<authority id>".',
                    $first->name, $first->startdate, $first->enddate, $rows->count(),
                    $rows->count() === 1 ? 'community' : 'communities', $first->name));

                continue;
            }

            $amount = (float) $rows->sum('amount');
            $this->info(sprintf('%s "%s" (%s to %s, £%s) against authority %d, covering %d %s.',
                $dryRun ? 'Would import' : 'Importing',
                $first->name, $first->startdate, $first->enddate, number_format($amount, 2),
                $authorityId, $rows->count(), $rows->count() === 1 ? 'community' : 'communities'));

            if (!$dryRun) {
                $this->import($authorityStats, $authorityId, $rows->all(), $amount);
            }

            $imported++;
        }

        $this->info(sprintf('%s %d %s; %d skipped.',
            $dryRun ? 'Would import' : 'Imported',
            $imported, $imported === 1 ? 'deal' : 'deals', $skipped));

        return Command::SUCCESS;
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function import(AuthorityStatsService $authorityStats, int $authorityId, array $rows, float $amount): void
    {
        $first = $rows[0];

        DB::transaction(function () use ($authorityStats, $authorityId, $rows, $amount, $first) {
            $partnershipId = DB::table('partnerships')->insertGetId([
                'authorityid' => $authorityId,
                'name' => $first->name,
                'tagline' => $first->tagline,
                'description' => $first->description,
                'linkurl' => $first->linkurl,
                'imageurl' => $first->imageurl,
                'startdate' => $first->startdate,
                'enddate' => $first->enddate,
                'amount' => $amount,
                'status' => 'Confirmed',
                'notes' => $first->notes,
                'visible' => $first->visible ? 1 : 0,
            ]);

            if ($first->contactname || $first->contactemail) {
                DB::table('partnerships_contacts')->insert([
                    'partnershipid' => $partnershipId,
                    'name' => $first->contactname ?: null,
                    'email' => $first->contactemail ?: null,
                    'role' => 'Waste',
                ]);
            }

            // A community significantly inside the council boundary becomes a boundary one, so
            // it is weighted by its overlap like any other; one that is not was sponsored anyway.
            $inside = [];
            foreach ($authorityStats->getSignificantGroups($authorityId)['groups'] ?? [] as $group) {
                $inside[$group['id']] = $group['overlap'];
            }

            foreach ($rows as $row) {
                DB::table('partnerships_groups')->insertOrIgnore([
                    'partnershipid' => $partnershipId,
                    'groupid' => $row->groupid,
                    'source' => isset($inside[$row->groupid]) ? 'Boundary' : 'Added',
                    'overlap' => $inside[$row->groupid] ?? null,
                    'sponsorshipid' => $row->id,
                ]);
            }
        });
    }

    /**
     * The council a sponsor name refers to: an exact name match, or the name without its
     * trailing "Council" ("Essex County Council" is the authority "Essex County"). Only
     * council-level authorities count, and an ambiguous name matches nothing.
     */
    private function findCouncil(string $name): ?int
    {
        $candidates = array_unique([$name, preg_replace('/\s+Council$/i', '', $name)]);

        $ids = DB::table('authorities')
            ->whereIn('name', $candidates)
            ->whereIn('area_code', self::COUNCIL_CODES)
            ->pluck('id');

        return $ids->count() === 1 ? (int) $ids->first() : null;
    }

    /**
     * @return array<string, int>|null
     */
    private function parseMap(): ?array
    {
        $map = [];

        foreach ((array) $this->option('map') as $entry) {
            $pos = strrpos((string) $entry, '=');
            $id = $pos === false ? 0 : (int) substr($entry, $pos + 1);

            if ($id <= 0) {
                $this->error("Could not read --map=\"{$entry}\"; it should be \"Sponsor name=authority id\".");

                return null;
            }

            $map[substr($entry, 0, $pos)] = $id;
        }

        return $map;
    }
}

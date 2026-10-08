<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Keeps the communities a partnership covers in line with its council's boundary, and the
 * member site's sponsorship rows in line with those.
 *
 * The Go API does the same when someone saves the deal or re-checks the boundary on the
 * Partnerships page (detectGroups and syncSponsorships in iznik-server-go/partnerships); this
 * is the daily run that means a community set up inside the boundary later is covered without
 * anyone having to open the page. The two must agree:
 *
 *   - Boundary rows follow the boundary: added when a community overlaps it significantly
 *     (AuthorityStatsService::isSignificantOverlap), dropped when one no longer does. A
 *     community that only grazes the edge is not listed, though the stats page still counts it.
 *   - Added rows (put in by hand) and Removed rows (left out by hand) are never changed.
 *   - Every covered community has a groups_sponsorship row, shown only while the deal is
 *     committed and visible.
 */
class PartnershipGroupsService
{
    private const COMMITTED = ['Confirmed', 'Paid', 'Overdue'];

    public function __construct(private readonly AuthorityStatsService $authorityStats) {}

    /**
     * Bring one partnership up to date. Returns the communities newly covered and dropped.
     *
     * @return array{added: array<int, int>, dropped: array<int, int>}
     */
    public function sync(int $partnershipId, bool $dryRun = false): array
    {
        $partnership = DB::table('partnerships')->where('id', $partnershipId)->first();
        if (!$partnership) {
            return ['added' => [], 'dropped' => []];
        }

        $authority = $this->authorityStats->getSignificantGroups((int) $partnership->authorityid);
        $inside = [];
        foreach ($authority['groups'] ?? [] as $group) {
            $inside[$group['id']] = $group['overlap'];
        }

        $rows = DB::table('partnerships_groups')->where('partnershipid', $partnershipId)
            ->get(['groupid', 'source', 'sponsorshipid'])
            ->keyBy('groupid');

        $added = array_values(array_diff(array_keys($inside), $rows->keys()->all()));
        $dropped = $rows->filter(fn ($r) => $r->source === 'Boundary' && !isset($inside[$r->groupid]))
            ->keys()->all();

        if ($dryRun) {
            return ['added' => $added, 'dropped' => $dropped];
        }

        foreach ($dropped as $groupId) {
            $sponsorshipId = $rows[$groupId]->sponsorshipid;
            if ($sponsorshipId) {
                DB::table('groups_sponsorship')->where('id', $sponsorshipId)->delete();
            }
            DB::table('partnerships_groups')
                ->where('partnershipid', $partnershipId)->where('groupid', $groupId)->delete();
        }

        foreach ($rows as $groupId => $row) {
            if (!in_array($groupId, $dropped, true)) {
                DB::table('partnerships_groups')
                    ->where('partnershipid', $partnershipId)->where('groupid', $groupId)
                    ->update(['overlap' => $inside[$groupId] ?? null]);
            }
        }

        foreach ($added as $groupId) {
            DB::table('partnerships_groups')->insert([
                'partnershipid' => $partnershipId,
                'groupid' => $groupId,
                'source' => 'Boundary',
                'overlap' => $inside[$groupId],
            ]);
        }

        $this->syncSponsorships($partnership);

        return ['added' => $added, 'dropped' => $dropped];
    }

    /**
     * Write a groups_sponsorship row for every covered community that lacks one, and bring the
     * existing ones up to date.
     */
    private function syncSponsorships(object $partnership): void
    {
        // groups_sponsorship has room for one contact: the first from the waste team, who
        // would want to hear about the sponsorship, else the first of anyone.
        $contact = DB::table('partnerships_contacts')
            ->where('partnershipid', $partnership->id)
            ->orderBy('id')
            ->get(['name', 'email', 'role'])
            ->sortBy(fn ($c) => $c->role === 'Waste' ? 0 : 1)
            ->first();

        $fields = [
            'name' => $partnership->name,
            'linkurl' => $partnership->linkurl,
            'startdate' => $partnership->startdate,
            'enddate' => $partnership->enddate,
            'contactname' => $contact->name ?? '',
            'contactemail' => $contact->email ?? '',
            'amount' => (int) $partnership->amount,
            'notes' => $partnership->notes,
            'imageurl' => $partnership->imageurl,
            'visible' => $partnership->visible && in_array($partnership->status, self::COMMITTED, true) ? 1 : 0,
            'tagline' => $partnership->tagline,
            'description' => $partnership->description,
        ];

        $links = DB::table('partnerships_groups')
            ->where('partnershipid', $partnership->id)
            ->where('source', '!=', 'Removed')
            ->get(['groupid', 'sponsorshipid']);

        foreach ($links as $link) {
            if ($link->sponsorshipid) {
                DB::table('groups_sponsorship')->where('id', $link->sponsorshipid)->update($fields);

                continue;
            }

            $sponsorshipId = DB::table('groups_sponsorship')->insertGetId(['groupid' => $link->groupid] + $fields);
            DB::table('partnerships_groups')
                ->where('partnershipid', $partnership->id)->where('groupid', $link->groupid)
                ->update(['sponsorshipid' => $sponsorshipId]);
        }
    }
}

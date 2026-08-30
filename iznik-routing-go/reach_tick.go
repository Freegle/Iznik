package main

// POST /v1/reach-tick — everything one ripple tick needs, from the post's stored labels.
//
// Expansion walks each post up a schedule of growing drive-time budgets. Until now every
// one of those steps went to /v1/catchment, which starts by running a fresh search over
// the whole edge list to find out what the post reaches. That search is why the call is
// gated: it is the expensive thing the eight compute slots exist to ration. So a backlog
// of overdue posts competed for those slots with the interactive consumers, and when it
// saturated them the whole expansion stalled (2026-08-29/30).
//
// The search is redundant. A post's labels are computed ONCE when it is created and
// stored, and they already encode arrival times, so "what does this post reach at budget
// t" is a question the stored blob can answer on its own: ReachedNodes expands a labeling
// into the same reached-node map the full search produces, from region-table lookups. The
// comment on that function puts it at a 1-2ms label query plus lookups against a 0.3-2s
// Dijkstra, and everything downstream of an isochrone runs unchanged on top of it.
//
// So this endpoint answers a tick from the stored labels, and needs no compute slot. It
// returns the same three things /v1/catchment does for expansion, derived from one
// expansion of the blob:
//
//   - the coarse catchment outline and its sandwich bounds (see catchment_coarse.go for
//     why coarse is the right resolution for what expansion asks of them)
//   - the reachable group ids at this budget, by the same rule the targeting already
//     uses: a group counts when an active member who lives inside it has a road node in
//     the reached set (freeglerReachableGroupIDs)
//
// Same rule, same primitives, same answer - the only change is where the reached-node set
// came from. That is what makes it checkable: TestReachTickMatchesTheLiveSearch pins the
// two against each other on the fixture.

import (
	"encoding/base64"
	"fmt"

	"github.com/gofiber/fiber/v2"
)

// reachTickReq is the body of POST /v1/reach-tick.
type reachTickReq struct {
	// Labels is the post's stored label blob, base64, exactly as /v1/reach-labels
	// returned it and the caller stored it.
	Labels string `json:"labels"`

	// T is this tick's drive-time budget in seconds. Clamped to the budget the labels
	// were computed for - a tick can never ask for more reach than was stored.
	T *float64 `json:"t"`

	// Mode names the travel mode for snapping members to road nodes. Empty means drive,
	// which is what rippling uses - note parseMode's own default is walk, so this is
	// resolved explicitly rather than handed to it blank.
	Mode string `json:"mode"`

	// Groups asks for the reachable group ids. Off by default because it needs the
	// groups database and the active-member query, which a caller that only wants the
	// geometry should not pay for.
	Groups bool `json:"groups"`
}

// A stored blob materialises exactly. EncodeLabels leaves out the origin region's interior
// arrivals as too big, but keeps the seeds they can be rebuilt from, and ReachedNodes does
// that rebuild - so a tick served from a stored blob is the same reach a full road-network
// search finds, place for place and second for second (TestStoredBlobMaterialisesExactly).

// tickBudget is the budget a tick is actually served at: the one asked for, capped at the
// budget the labels were computed for.
//
// The cap is load-bearing, not tidiness. ReachedNodes does NOT clamp itself - hand it a
// limit beyond the stored one and the region tables keep yielding arrivals that the
// original query never relaxed, which on the Bristol fixture turns 15,648 reached nodes
// into 61,254. That larger set is not a longer drive, it is an unvalidated one. A tick can
// only ever ask for reach that was actually computed and stored.
func tickBudget(lbl *ReachLabels, want *float64) float32 {
	if want == nil {
		return lbl.T
	}
	if w := float32(*want); w < lbl.T {
		return w
	}

	return lbl.T
}

// tickMode resolves the request's travel mode, defaulting to drive. parseMode defaults to
// WALK, which would be wrong here: rippling is a drive-time model throughout, and snapping
// members to walking nodes would quietly answer a different question.
func tickMode(s string) Mode {
	if s == "" {
		return Drive
	}

	return parseMode(s)
}

// handleReachTick serves POST /v1/reach-tick. Ungated: no graph sweep, so it does not
// consume a compute slot.
func handleReachTick() fiber.Handler {
	return func(c *fiber.Ctx) error {
		if reachLive == nil {
			return fiber.NewError(fiber.StatusServiceUnavailable, "reach engine not configured (REACH_DIR)")
		}

		var req reachTickReq
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "bad body")
		}
		blob, err := base64.StdEncoding.DecodeString(req.Labels)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "labels not base64")
		}

		// Any loaded build may own this blob: after a partition rebuild the labels stored
		// against the previous build keep answering until each post's new ones land, so a
		// map refresh is a rolling migration rather than a site-wide outage. The reach is
		// only meaningful on the build that decoded it.
		lbl, e, err := decodeLabelsAnyBuild(blob)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("labels: %v", err))
		}

		if req.T != nil && *req.T < 0 {
			return fiber.NewError(fiber.StatusBadRequest, "t must be >= 0")
		}
		effT := tickBudget(lbl, req.T)

		reached := e.ReachedNodes(lbl, effT)
		poly, bounds, _ := CoarseCatchment(e.G, reached)

		resp := fiber.Map{"catchment": poly, "coarse": true}
		if bounds.Outer != nil {
			resp["catchment_outer"] = bounds.Outer
		}
		if bounds.Inner != nil {
			resp["catchment_inner"] = bounds.Inner
		}

		if req.Groups {
			// Always present and non-null when asked for, so a caller can tell "computed
			// and found none" from "could not compute" - the same contract
			// /v1/reachable-groups states, and one the targeting gate depends on: an
			// absent set means fall back to the polygon, an empty one means nobody.
			ids := make([]int64, 0)
			if len(reached) > 0 {
				if db := ensureGroupsDB(); db != nil {
					minLat, maxLat, minLng, maxLng := reachedBBox(e.G, reached)
					if members, mErr := queryActiveMembersInBox(db, minLat, maxLat, minLng, maxLng); mErr == nil {
						ids = groupIDsWithinSeconds(snapMembers(e.G, reached, members, tickMode(req.Mode)), effT)
					}
				}
			}
			resp["reachable_group_ids"] = ids
		}

		return c.JSON(resp)
	}
}

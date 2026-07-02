package main

import (
	"database/sql"
	"log"
	"math"
	"strconv"
	"sync"
	"time"

	"github.com/gofiber/fiber/v2"
)

// groupActivesResponse is the payload for GET /v1/group-actives.
type groupActivesResponse struct {
	GroupID int64 `json:"groupid"`
	Actives int   `json:"actives"`
	NStar   int   `json:"nstar"`
}

// nStarFloor/nStarCeiling bound the Stage-A audience target N* — the reach the Rippling
// Explorer's "Proposed audience-based reach" option targets in place of the fixed 4000-member
// default. nStarFloor is F_band (density-banded in the full design; fixed at 1000 for now,
// density banding not yet built). nStarCeiling is a safety anomaly-guard, not a derived value.
const (
	nStarFloor   = 1000.0
	nStarCeiling = 4000.0
)

// clip constrains x to [lo, hi]. Pure and unit-testable without a DB or graph.
func clip(x, lo, hi float64) float64 {
	if x < lo {
		return lo
	}
	if x > hi {
		return hi
	}
	return x
}

// computeNStar turns a raw active-member count into the audience target N*:
// clip(max(1.0*actives, 1000), 1000, 4000).
func computeNStar(actives int) int {
	v := math.Max(1.0*float64(actives), nStarFloor)
	return int(clip(v, nStarFloor, nStarCeiling))
}

// --- in-process ~1h cache, keyed by groupid ---------------------------------

type activesCacheEntry struct {
	resp      groupActivesResponse
	expiresAt time.Time
}

const activesCacheTTL = time.Hour

var (
	activesCacheMu sync.RWMutex
	activesCache   = make(map[int64]activesCacheEntry)
)

func activesCacheGet(groupID int64) (groupActivesResponse, bool) {
	activesCacheMu.RLock()
	defer activesCacheMu.RUnlock()
	e, ok := activesCache[groupID]
	if !ok || time.Now().After(e.expiresAt) {
		return groupActivesResponse{}, false
	}
	return e.resp, true
}

func activesCacheSet(groupID int64, resp groupActivesResponse) {
	activesCacheMu.Lock()
	defer activesCacheMu.Unlock()
	activesCache[groupID] = activesCacheEntry{resp: resp, expiresAt: time.Now().Add(activesCacheTTL)}
}

// queryActiveMembers counts approved members of groupID who have used the site in the last 90
// days. Reuses the shared groupsDB pool (see groups.go).
func queryActiveMembers(db *sql.DB, groupID int64) (int, error) {
	var count int
	err := db.QueryRow(
		`SELECT COUNT(*) FROM memberships m
		 INNER JOIN users u ON u.id = m.userid
		 WHERE m.groupid = ? AND m.collection = 'Approved'
		   AND u.lastaccess >= NOW() - INTERVAL 90 DAY`,
		groupID,
	).Scan(&count)
	return count, err
}

// handleGroupActives handles GET /v1/group-actives?groupid=N, returning
// {groupid, actives, nstar} — the 90-day-active member count for a group and the Stage-A
// audience target derived from it. Cached in-process per groupid for ~1h. Returns 503 when no
// MySQL is configured/reachable (matches digest_simulator.go's precedent for this package).
func handleGroupActives() fiber.Handler {
	return func(c *fiber.Ctx) error {
		gid, err := strconv.ParseInt(c.Query("groupid"), 10, 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "groupid required")
		}

		if cached, ok := activesCacheGet(gid); ok {
			return c.JSON(cached)
		}

		db := ensureGroupsDB()
		if db == nil {
			return c.Status(fiber.StatusServiceUnavailable).
				JSON(fiber.Map{"error": "database not available"})
		}

		actives, err := queryActiveMembers(db, gid)
		if err != nil {
			log.Printf("group-actives query (groupid=%d): %v", gid, err)
			return fiber.NewError(fiber.StatusInternalServerError, err.Error())
		}

		resp := groupActivesResponse{
			GroupID: gid,
			Actives: actives,
			NStar:   computeNStar(actives),
		}
		activesCacheSet(gid, resp)
		return c.JSON(resp)
	}
}

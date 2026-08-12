package rippling

import (
	"encoding/json"
	"sort"
	"time"

	"gorm.io/gorm"
)

// HazardHoursConfigKey is the `config` row iznik-batch publishes the hazard schedule
// into (Ripple\ExpandCommand::publishReplyDelay). Tick k of a post's reach becomes
// live at arrival + hazard_hours[k-1], which is what turns "the tick that would cover
// you" into a time we can show a member.
const HazardHoursConfigKey = "ripple.hazard_hours"

// DefaultHazardHours matches config('freegle.ripple.hazard_hours') in iznik-batch, and
// is the fallback when the row has not been published yet.
func DefaultHazardHours() []int {
	return []int{1, 3, 6, 12, 24, 48, 72, 120, 168}
}

// LoadHazardHours reads the published schedule, falling back to the documented default
// on any failure. A short or empty schedule is treated as unusable rather than trusted:
// it would silently shorten every estimate we give.
func LoadHazardHours(db *gorm.DB) []int {
	var row struct {
		Value string `gorm:"column:value"`
	}

	result := db.Table("config").Select("value").Where("`key` = ?", HazardHoursConfigKey).Limit(1).Scan(&row)
	if result.Error != nil || row.Value == "" {
		return DefaultHazardHours()
	}

	var hours []int
	if err := json.Unmarshal([]byte(row.Value), &hours); err != nil || len(hours) == 0 {
		return DefaultHazardHours()
	}

	return hours
}

// ScheduleTick is one entry of rippling_reach.schedule. The stored rows carry
// cumulative_users and reachable_group_ids too; this reads only what the estimate
// needs. Note that live schedules hold NO polygons - they are the slim form - so the
// only way to place a member on one is by their drive time from the origin.
type ScheduleTick struct {
	Tick     int     `json:"tick"`
	DriveMin float64 `json:"drive_min"`
}

// ParseSchedule decodes rippling_reach.schedule, ordered by tick. Returns nil when the
// column is empty or unusable, which callers treat as "no estimate available".
func ParseSchedule(raw string) []ScheduleTick {
	if raw == "" {
		return nil
	}

	var ticks []ScheduleTick
	if err := json.Unmarshal([]byte(raw), &ticks); err != nil || len(ticks) == 0 {
		return nil
	}

	sort.Slice(ticks, func(i, j int) bool { return ticks[i].Tick < ticks[j].Tick })

	return ticks
}

// Coverage is when a post's reach is expected to reach a given member.
type Coverage struct {
	// At is when it happens: either the tick that covers them going live, or the
	// reach finishing and releasing their held reply anyway.
	At time.Time
	// Covered distinguishes the two. True means a tick's drive-time budget actually
	// grows far enough to include them. False means it never does, and At is instead
	// when the reach stops expanding - which is a real answer, not a failure, because
	// that is the point their reply is passed on regardless (releaseAll 'maxed').
	Covered bool
}

// CoverageAt works out when a post's reach reaches a member whose road time from the
// post's origin is driveMin.
//
// Tick k is live from arrival + hazardHours[k-1], except tick 1 which is live from
// arrival itself: the reach is initialised at tick 1 immediately, and
// ReachService::tickForElapsedHours clamps anything earlier to it. Verified against
// live rows, where reaches that finish at tick k do so exactly hazardHours[k] hours
// after arrival, i.e. at the moment they would have advanced again.
//
// reachable=false means the routing search did not get to them inside the budget it
// was given, so no tick can cover them and the answer is the reach's own finish.
//
// This is the schedule's timetable, so it is an UPPER BOUND on the not-covered case
// rather than a prediction: ExpandService also marks a reach done early when the post
// gathers enough distinct repliers (reply_saturation_stop) or is taken, and neither is
// knowable in advance. Callers should word it as "by", not "at".
func CoverageAt(ticks []ScheduleTick, hazardHours []int, arrival time.Time, driveMin float64, reachable bool) (Coverage, bool) {
	if len(ticks) == 0 || len(hazardHours) == 0 {
		return Coverage{}, false
	}

	// Tick k is live at hazardHours[k-1] (0-indexed), because tickForElapsedHours
	// promotes to tick k once elapsed >= hazardHours[k-1]. Tick 1 is the exception:
	// it is the clamped initial value, so it is live from arrival rather than from
	// hazardHours[0].
	liveAt := func(tick int) (time.Time, bool) {
		if tick <= 1 {
			return arrival, true
		}
		if tick-1 >= len(hazardHours) {
			return time.Time{}, false
		}

		return arrival.Add(time.Duration(hazardHours[tick-1]) * time.Hour), true
	}

	if reachable {
		for _, t := range ticks {
			if t.DriveMin >= driveMin {
				at, ok := liveAt(t.Tick)
				if !ok {
					return Coverage{}, false
				}

				return Coverage{At: at, Covered: true}, true
			}
		}
	}

	// Beyond the widest budget this post will ever have. They wait for the reach to
	// stop expanding, which is the final tick going live.
	at, ok := liveAt(ticks[len(ticks)-1].Tick)
	if !ok {
		return Coverage{}, false
	}

	return Coverage{At: at, Covered: false}, true
}

// Package localreach decides who sees a community event or a volunteering opportunity.
//
// With no communities, each item is shown around a place: its centre point, which is the
// poster's location unless they named a postcode, and a reach, which is a drive time the
// poster sets on a slider. The reach defaults to the density cap at the centre - the same
// budget a post starting there would get - so an item posted once is seen as widely as a
// post would be, without being posted to several communities.
//
// The drive time is turned into a crow-flies radius by routing when the item is saved, and
// the lists and digests filter on that radius. Routing is not asked again on every read.
package localreach

import (
	"github.com/freegle/iznik-server-go/density"
	"github.com/freegle/iznik-server-go/town"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// MinMinutes and MaxMinutes bound the slider. They match the browse slider
// (BROWSE_MINUTES_MIN, BROWSE_MINUTES_MAX): the top is the ripple ceiling, because that is
// how far a post can reach from anywhere.
const (
	MinMinutes = 5
	MaxMinutes = 45
)

// FallbackMiles is the radius used for an item whose reach could not be routed when it was
// saved, and for items imported without one. About what a 30-minute drive reaches.
// Mirrored in iznik-batch config('freegle.local_reach.fallback_miles').
const FallbackMiles = 15.0

// Reach is what gets stored on the item.
type Reach struct {
	Lat     float64
	Lng     float64
	Minutes int
	// Miles is nil when routing could not answer; readers then use FallbackMiles.
	Miles *float64
}

// Resolve works out the centre and reach for an item being created or changed by myid.
// lat/lng are the place the poster named, if any; minutes is the slider position, if any.
// ok is false when there is no centre at all: nothing named and no known location for the
// poster.
func Resolve(myid uint64, lat, lng *float64, minutes *int) (Reach, bool) {
	var r Reach

	if lat != nil && lng != nil && (*lat != 0 || *lng != 0) {
		r.Lat, r.Lng = *lat, *lng
	} else {
		loc := user.GetLatLng(myid)
		if loc.Lat == 0 && loc.Lng == 0 {
			return r, false
		}
		r.Lat, r.Lng = float64(loc.Lat), float64(loc.Lng)
	}

	if minutes != nil {
		r.Minutes = Clamp(*minutes)
	} else {
		r.Minutes = Clamp(int(density.CapFor(r.Lat, r.Lng).MaxMinutes))
	}

	r.Miles = town.ReachRadius(r.Lat, r.Lng, float64(r.Minutes))

	return r, true
}

// Clamp keeps a slider position inside the range the slider offers.
func Clamp(minutes int) int {
	if minutes < MinMinutes {
		return MinMinutes
	}
	if minutes > MaxMinutes {
		return MaxMinutes
	}
	return minutes
}

// Columns is the stored form of a reach, for an insert or update.
func (r Reach) Columns() map[string]interface{} {
	cols := map[string]interface{}{
		"lat":          r.Lat,
		"lng":          r.Lng,
		"reachminutes": r.Minutes,
		"reachmiles":   nil,
	}
	if r.Miles != nil {
		cols["reachmiles"] = *r.Miles
	}
	return cols
}

// distanceSQL is the great-circle distance in miles from (?, ?) to table.lat/lng. Its
// arguments are lat, lng, lat.
func distanceSQL(table string) string {
	return "3959 * ACOS(GREATEST(-1.0, LEAST(1.0, " +
		"COS(RADIANS(?)) * COS(RADIANS(" + table + ".lat)) * COS(RADIANS(" + table + ".lng) - RADIANS(?)) " +
		"+ SIN(RADIANS(?)) * SIN(RADIANS(" + table + ".lat)))))"
}

// Within is a WHERE fragment that keeps the items whose reach covers a viewer at (lat, lng).
// An item with no centre is shown to nobody but its owner, which the caller handles.
func Within(table string, lat, lng float64) (string, []interface{}) {
	return table + ".lat IS NOT NULL AND " + distanceSQL(table) +
			" <= COALESCE(" + table + ".reachmiles, ?)",
		[]interface{}{lat, lng, lat, FallbackMiles}
}

// Nearest orders nearest centre first, items with no centre last. It is a clause.OrderBy
// because GORM silently drops a bare gorm.Expr passed to Order.
func Nearest(table string, lat, lng float64) clause.OrderBy {
	return clause.OrderBy{Expression: gorm.Expr(
		"("+table+".lat IS NULL) ASC, "+distanceSQL(table)+" ASC", lat, lng, lat)}
}

// Update changes the centre or reach of a stored item. Anything not given keeps its stored
// value, and an item that has never had a centre takes its owner's location, so a moderator
// editing someone else's item never moves it to where the moderator lives.
func Update(db *gorm.DB, table string, id uint64, lat, lng *float64, minutes *int) error {
	var stored struct {
		Userid       uint64
		Lat          *float64
		Lng          *float64
		Reachminutes *int
	}
	if err := db.Table(table).Select("userid, lat, lng, reachminutes").Where("id = ?", id).
		Scan(&stored).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to read reach")
	}

	if lat == nil || lng == nil {
		lat, lng = stored.Lat, stored.Lng
	}
	if minutes == nil {
		minutes = stored.Reachminutes
	}

	reach, ok := Resolve(stored.Userid, lat, lng, minutes)
	if !ok {
		return fiber.NewError(fiber.StatusBadRequest, "We need to know where it is - please add a postcode")
	}

	return db.Table(table).Where("id = ?", id).Updates(reach.Columns()).Error
}

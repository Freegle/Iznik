package newsfeed

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	geo "github.com/kellydunn/golang-geo"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	xurls "mvdan.cc/xurls/v2"
)

func (Newsfeed) TableName() string {
	return "newsfeed"
}

type NewsImage struct {
	ID           uint64          `json:"id"`
	Path         string          `json:"path"`
	PathThumb    string          `json:"paththumb"`
	Externaluid  string          `json:"externaluid"`
	Ouruid       string          `json:"ouruid"`
	Externalmods json.RawMessage `json:"externalmods"`
}

type NewsLove struct {
	Newsfeedid uint64    `json:"newsfeedid"`
	Userid     uint64    `json:"userid"`
	Timestamp  time.Time `json:"timestamp"`
}

type NewsfeedSummary struct {
	ID                  uint64     `json:"id" gorm:"primary_key"`
	Userid              uint64     `json:"userid"`
	Hidden              *time.Time `json:"hidden"`
	Hiddenby            uint64     `json:"hiddenby"`
	Eventpending        bool       `json:"-"`
	Volunteeringpending bool       `json:"-"`
	Storypending        bool       `json:"-"`
}

func (NewsfeedPreview) TableName() string {
	return "link_previews"
}

type NewsfeedPreview struct {
	ID          uint64 `json:"id" gorm:"primary_key"`
	Url         string `json:"url"`
	Title       string `json:"title"`
	Description string `json:"description"`
	Image       string `json:"image"`
}

type Newsfeed struct {
	ID             uint64            `json:"id" gorm:"primary_key"`
	Threadhead     uint64            `json:"threadhead"`
	Timestamp      time.Time         `json:"timestamp"`
	Added          time.Time         `json:"added"`
	Type           string            `json:"type"`
	Userid         uint64            `json:"userid"`
	Displayname    string            `json:"displayname"`
	Profile        user.UserProfile  `json:"profile" gorm:"-"`
	Info           user.UserInfo     `json:"userinfo" gorm:"-"`
	Showmod        bool              `json:"showmod"`
	Location       string            `json:"location"`
	Imageid        uint64            `json:"imageid"`
	Imagearchived  bool              `json:"-"`
	Imageuid       string            `json:"-"`
	Imagemods      json.RawMessage   `json:"-"`
	Image          *NewsImage        `json:"image" gorm:"-"`
	Msgid          uint64            `json:"msgid"`
	Msgtype        string            `json:"msgtype,omitempty" gorm:"-"`
	Replyto        uint64            `json:"replyto"`
	Groupid        uint64            `json:"groupid"`
	Eventid        uint64            `json:"eventid"`
	Volunteeringid uint64            `json:"volunteeringid"`
	Storyid        uint64            `json:"storyid"`
	Message        string            `json:"message"`
	Html           string            `json:"html"`
	Pinned         bool              `json:"pinned"`
	Hidden         *time.Time        `json:"hidden"`
	Hiddenby       uint64            `json:"hiddenby"`
	Deleted        *time.Time        `json:"deleted"`
	Loves          int64             `json:"loves"`
	Loved          bool              `json:"loved"`
	Replies        []Newsfeed        `json:"replies" gorm:"-"`
	Lovelist       []NewsLove        `json:"lovelist" gorm:"-"`
	Previews       []NewsfeedPreview `json:"previews" gorm:"-"`
}

func GetNearbyDistance(uid uint64) (float64, utils.LatLng, float64, float64, float64, float64) {
	const nearbyLimit = 10
	// Over-fetch from the spatial index so that, after dropping alerts, stale
	// posts, the user's OWN posts and co-located duplicates, we still have at
	// least nearbyLimit candidates. 100x reaches past the "wall" a long-time
	// poster builds at their own coordinates: a member with years of posts
	// geocoded to their home postcode can fill the first hundreds of KNN
	// results with points ~0m away, which at 10x starved the walk before it
	// ever saw another member's post (observed live: 100/100 results within
	// 0.19km, radius clamped to the 1km floor, feed of 2 items).
	// nearbyLimit*overFetch = 1000 is the spatial server's result limit.
	const overFetch = 100
	// maxNearbyKm bounds every radius computed below - the largest radius the
	// pre-#459 doubling-box algorithm ever searched (1km doubling to 128km)
	// before giving up. getFeed() treats a 0 return as "no geographic
	// filtering at all", so every path here MUST end up at a positive,
	// capped value rather than an unbounded or zero one - Discourse #9937
	// (a 169-mile-away post topping a "Nearby" feed because the radius
	// silently became "no restriction").
	const maxNearbyKm = 128.0
	// minNearbyKm floors the same result from the other end. Both
	// data-driven branches below can legitimately compute a distance of
	// exactly 0 - a newsfeed post at the user's own coordinates (their own
	// post, or someone else's right on top of them) has KNN distance 0 in
	// decimal degrees - which hits the exact same "no restriction" path as
	// an unbounded radius, per the comment above. That's #9937 again.
	const minNearbyKm = 1.0

	latlng := user.GetLatLng(uid)
	if latlng.Lat == 0 && latlng.Lng == 0 {
		// We don't know where this user is at all, so there's no reference
		// point to size a "nearby" radius from - the caller must fall back
		// to an unfiltered feed.
		return 0, latlng, 0, 0, 0, 0
	}

	// Default to the capped ceiling; every branch below either leaves this
	// alone or overwrites it with a data-driven value that gets clamped
	// between minNearbyKm and maxNearbyKm before we return.
	distKm := maxNearbyKm

	results, err := spatial.KNN("newsfeed", float64(latlng.Lng), float64(latlng.Lat), nearbyLimit*overFetch, "")
	if err == nil && len(results) >= nearbyLimit {
		// The spatial "newsfeed" index has no type/timestamp/user columns, so it
		// can't exclude alerts, stale posts or the user's own posts — applying
		// that here restores the pre-spatial query's behaviour (otherwise those
		// posts shrink the computed radius).
		ids := make([]int64, len(results))
		for i, r := range results {
			ids[i] = r.ID
		}
		allowed := RecentNonAlertNewsfeedIDs(ids, uid)

		// Walk the KNN results in distance order; the nearbyLimit-th allowed
		// DISTINCT distance sets the radius (decimal degrees → km, 1 degree ≈
		// 111 km). Distinct because many posts share one coordinate (a postcode
		// centroid, a housebound poster): counting them individually measures
		// one location's chattiness, not how far away the community is.
		count := 0
		distDeg := 0.0
		lastCounted := -1.0
		for _, r := range results {
			if _, ok := allowed[r.ID]; !ok {
				continue
			}
			if r.Distance <= lastCounted {
				continue
			}
			count++
			lastCounted = r.Distance
			if count == nearbyLimit {
				distDeg = r.Distance
				break
			}
		}

		if count == nearbyLimit {
			// Enough recent posts by other people nearby - size the radius from
			// their true density.
			distKm = distDeg * 111.0
		} else {
			// Too few distinct recent posts by others inside the fetch window.
			// Size from the window's full reach: we know the index's activity
			// (of any age) covers at least this far, and the furthest raw entry
			// cannot be wall-dominated the way a near entry can. The old
			// fallback used the nearbyLimit-th RAW entry, which against a
			// co-located wall was ~0m and clamped the radius to the floor.
			distKm = results[len(results)-1].Distance * 111.0
		}
	}

	if distKm > maxNearbyKm {
		distKm = maxNearbyKm
	} else if distKm < minNearbyKm {
		distKm = minNearbyKm
	}

	p := geo.NewPoint(float64(latlng.Lat), float64(latlng.Lng))
	ne := p.PointAtDistanceAndBearing(distKm, 45)
	sw := p.PointAtDistanceAndBearing(distKm, 225)

	return distKm, latlng, ne.Lat(), ne.Lng(), sw.Lat(), sw.Lng()
}

// RecentNonAlertNewsfeedIDs returns the subset of the given newsfeed ids that
// are not ALERT-type, were posted within the feed window (31 days), and were
// not authored by excludeUserid (pass 0 to keep everyone): a member's own
// posts sit at their own coordinates and say nothing about how far away the
// surrounding community's activity is. The
// spatial "newsfeed" index omits the type and timestamp columns, so the
// nearby-distance calculation applies these filters here (matching the old
// MySQL query) rather than in the shared index.
func RecentNonAlertNewsfeedIDs(ids []int64, excludeUserid uint64) map[int64]struct{} {
	allowed := make(map[int64]struct{}, len(ids))
	if len(ids) == 0 {
		return allowed
	}

	since := time.Now().AddDate(0, 0, -31).Format("2006-01-02")

	// ids was a
	// hand-built comma-joined literal-int list; GORM's native "IN (?)"
	// slice-bind is the direct replacement, giving exactly one rendered
	// form, declared in ormharness/shapes.json and proven by
	// TestTier3Shapes_d80ab5badcb6 (iznik-server-go/test).
	var found []int64
	database.DBConn.Table("newsfeed").
		Select("id").
		Where("id IN (?) AND type != ? AND `timestamp` >= ? AND userid != ?", ids, utils.NEWSFEED_TYPE_ALERT, since, excludeUserid).
		Scan(&found)

	for _, id := range found {
		allowed[id] = struct{}{}
	}
	return allowed
}

func Feed(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var distance uint64
	var err error
	gotDistance := false

	if c.Query("distance") != "" && c.Query("distance") != "nearby" {
		if c.Query("distance") == "anywhere" {
			distance = 0
			gotDistance = true
		} else {
			distance, err = strconv.ParseUint(c.Query("distance"), 10, 32)

			if err == nil {
				gotDistance = true
			}
		}
	}

	ret := getFeed(myid, gotDistance, distance)
	if len(ret) == 0 {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	} else {
		return c.JSON(ret)
	}
}

func getFeed(myid uint64, gotDistance bool, distance uint64) []NewsfeedSummary {
	db := database.DBConn

	var gotLatLng bool

	gotLatLng = false

	// We want the whole feed.
	//
	// Get:
	// - the distance we want to show.
	// - the current user to check mod status
	// - the feed
	var nelat, nelng, swlat, swlng float64
	var userLat, userLng float64

	// The "everywhere" feed is unfiltered as a whole, but unpinned alerts
	// (Community News) must still stay in their geography - see below.
	var alertNelat, alertNelng, alertSwlat, alertSwlng float64
	var gotAlertBox bool

	var wg sync.WaitGroup

	wg.Add(1)
	go func() {
		defer wg.Done()

		if !gotDistance {
			// We need to calculate a reasonable feed distance to show.
			var reasonable float64
			var latlng utils.LatLng
			reasonable, latlng, nelat, nelng, swlat, swlng = GetNearbyDistance(myid)

			if reasonable > 0 {
				gotLatLng = true
				userLat = float64(latlng.Lat)
				userLng = float64(latlng.Lng)
			}
		} else if distance > 0 {
			// We've been given a distance.
			latlng := user.GetLatLng(myid)

			if latlng.Lat != 0 && latlng.Lng != 0 {
				userLat = float64(latlng.Lat)
				userLng = float64(latlng.Lng)
				// Get a bounding box for the distance.
				p := geo.NewPoint(userLat, userLng)
				ne := p.PointAtDistanceAndBearing(float64(distance/1000), 45)
				nelat = ne.Lat()
				nelng = ne.Lng()
				sw := p.PointAtDistanceAndBearing(float64(distance/1000), 225)
				swlat = sw.Lat()
				swlng = sw.Lng()
				gotLatLng = true
			}
		} else {
			// Explicit "anywhere" - which is also the DEFAULT for anyone who has
			// never chosen a feed distance. The feed body is unfiltered, but we
			// still need the user's location for the alert box below.
			latlng := user.GetLatLng(myid)
			userLat = float64(latlng.Lat)
			userLng = float64(latlng.Lng)
		}

		// Unpinned alerts (Community News) stay in their geography whatever the
		// distance toggle says. Their box is a fixed NEWSFEED_ALERT_RADIUS_KM
		// around the member - the scale their news area is clustered at - NOT the
		// feed's density-derived radius, which can collapse to its 1km floor and
		// starve them of news (see the constant's comment). With no known
		// location only pinned alerts are served.
		if userLat != 0 || userLng != 0 {
			p := geo.NewPoint(userLat, userLng)
			ne := p.PointAtDistanceAndBearing(utils.NEWSFEED_ALERT_RADIUS_KM, 45)
			sw := p.PointAtDistanceAndBearing(utils.NEWSFEED_ALERT_RADIUS_KM, 225)
			gotAlertBox = true
			alertNelat = ne.Lat()
			alertNelng = ne.Lng()
			alertSwlat = sw.Lat()
			alertSwlng = sw.Lng()
		}
	}()

	var me user.User

	wg.Add(1)
	go func() {
		defer wg.Done()
		// get user
		db := database.DBConn
		db.First(&me, myid)
	}()

	wg.Wait()

	var newsfeed []NewsfeedSummary

	// Get the top-level threads, i.e. replyto IS NULL.  Put a limit on the number of threads we get - we're
	// unlikely to scroll that far.
	//
	// We use a UNION to pick up the alerts, even though it makes the SQL longer, because it allows efficient
	// use of the spatial index.
	//
	// Use a backstop timestamp so we can index better.
	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE_CHITCHAT).Format("2006-01-02")

	if gotLatLng {
		// Four-way UNION:
		// 1. Regular posts (non-event, non-alert types) in the user's geographic area, capped at 100.
		// 2. Event/volunteering posts in the user's area, capped at NEWSFEED_EVENTS_PER_FEED so a
		//    flood of these cannot push regular posts out of the feed (Discourse #9624).
		// 3. Alerts in the user's ALERT box (fixed NEWSFEED_ALERT_RADIUS_KM), capped at
		//    NEWSFEED_ALERTS_PER_FEED. Community News drip-posts as type Alert, so this is
		//    the same flood guard as #2.
		// 4. PINNED alerts (any location), capped at 5. Only pinned alerts - central Freegle
		//    announcements - are allowed to escape the geographic filter.
		db.Raw(
			fmt.Sprintf(
				"(SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (position) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE MBRContains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), position) AND "+
					"newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type NOT IN (?, ?, ?) "+
					"ORDER BY timestamp DESC "+
					"LIMIT 100 "+
					") UNION ("+
					"SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (position) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE MBRContains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), position) AND "+
					"newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type IN (?, ?) "+
					"ORDER BY ST_Distance(position, ST_SRID(POINT(?, ?), ?)) ASC, timestamp DESC "+
					"LIMIT %d "+
					") UNION ("+
					"SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (position) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE MBRContains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), position) AND "+
					"newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type = ? "+
					"ORDER BY pinned DESC, timestamp DESC "+
					"LIMIT %d "+
					") UNION ("+
					"SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (position) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.type = ? AND "+
					// Only PINNED alerts escape the geographic filter. Unpinned ones -
					// Community News - are served by the in-area arm above.
					"newsfeed.pinned = 1 AND "+
					"newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"ORDER BY pinned DESC, timestamp DESC "+
					"LIMIT 5) "+
					"ORDER BY pinned DESC, timestamp DESC LIMIT 100;",
				utils.NEWSFEED_EVENTS_PER_FEED, utils.NEWSFEED_ALERTS_PER_FEED),
			// UNION 1: regular posts in geographic area
			utils.NEWSFEED_MODSTATUS_SUPPRESSED,
			utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
			myid,
			swlng, swlat,
			swlng, nelat,
			nelng, nelat,
			nelng, swlat,
			swlng, swlat,
			utils.SRID,
			start,
			utils.NEWSFEED_TYPE_COMMUNITY_EVENT, utils.NEWSFEED_TYPE_VOLUNTEER_OPPORTUNITY, utils.NEWSFEED_TYPE_ALERT,
			// UNION 2: event/volunteering posts in geographic area (flood-capped, proximity-sorted)
			utils.NEWSFEED_MODSTATUS_SUPPRESSED,
			utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
			myid,
			swlng, swlat,
			swlng, nelat,
			nelng, nelat,
			nelng, swlat,
			swlng, swlat,
			utils.SRID,
			start,
			utils.NEWSFEED_TYPE_COMMUNITY_EVENT, utils.NEWSFEED_TYPE_VOLUNTEER_OPPORTUNITY,
			userLng, userLat, utils.SRID,
			// UNION 3: alerts in the ALERT box (flood-capped) - Community News posts here.
			// The alert box, not the feed box: the feed's density-derived radius can be
			// far smaller than the ~20-mile scale news areas are clustered at.
			utils.NEWSFEED_MODSTATUS_SUPPRESSED,
			utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
			myid,
			alertSwlng, alertSwlat,
			alertSwlng, alertNelat,
			alertNelng, alertNelat,
			alertNelng, alertSwlat,
			alertSwlng, alertSwlat,
			utils.SRID,
			start,
			utils.NEWSFEED_TYPE_ALERT,
			// UNION 4: pinned alerts only (any location)
			utils.NEWSFEED_MODSTATUS_SUPPRESSED,
			utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
			myid,
			start,
			utils.NEWSFEED_TYPE_ALERT,
		).Scan(&newsfeed)
	} else {
		// Three-way UNION for the "everywhere" path:
		// 1. Regular posts (non-event, non-alert types), capped at 100.
		// 2. Event/volunteering posts, capped at NEWSFEED_EVENTS_PER_FEED.
		// 3. Alerts, capped at NEWSFEED_ALERTS_PER_FEED. Pinned alerts (central Freegle
		//    announcements) come from anywhere; unpinned ones - Community News - only from
		//    inside the user's own alert box, so "everywhere" (the default feed setting)
		//    doesn't serve somebody in Cornwall news about Yorkshire. With no known
		//    location only pinned alerts are served.
		alertGeo := "AND newsfeed.pinned = 1 "
		var alertGeoArgs []interface{}

		if gotAlertBox {
			alertGeo = "AND (newsfeed.pinned = 1 OR MBRContains(ST_SRID(POLYGON(LINESTRING(POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?), POINT(?, ?))), ?), position)) "
			alertGeoArgs = []interface{}{
				alertSwlng, alertSwlat,
				alertSwlng, alertNelat,
				alertNelng, alertNelat,
				alertNelng, alertSwlat,
				alertSwlng, alertSwlat,
				utils.SRID,
			}
		}

		db.Raw(
			fmt.Sprintf(
				"(SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, "+
					"pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (timestamp) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type NOT IN (?, ?, ?) "+
					"ORDER BY pinned DESC, newsfeed.timestamp DESC LIMIT 100 "+
					") UNION ("+
					"SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, "+
					"pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (timestamp) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type IN (?, ?) "+
					"ORDER BY newsfeed.timestamp DESC LIMIT %d "+
					") UNION ("+
					"SELECT newsfeed.id, newsfeed.userid, (CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, hiddenby, "+
					"pinned, newsfeed.timestamp, "+
					"(CASE WHEN communityevents.id IS NOT NULL AND communityevents.pending THEN 1 ELSE 0 END) AS eventpending,"+
					"(CASE WHEN volunteering.id IS NOT NULL AND volunteering.pending THEN 1 ELSE 0 END) AS volunteeringpending, "+
					"(CASE WHEN users_stories.id IS NOT NULL AND (users_stories.public = 0 OR users_stories.reviewed = 0) THEN 1 ELSE 0 END) AS storypending "+
					"FROM newsfeed FORCE INDEX (timestamp) "+
					"LEFT JOIN users ON users.id = newsfeed.userid "+
					"LEFT JOIN spam_users ON spam_users.userid = newsfeed.userid AND collection IN (?, ?) "+
					"LEFT JOIN newsfeed_unfollow ON newsfeed.id = newsfeed_unfollow.newsfeedid AND newsfeed_unfollow.userid = ? "+
					"LEFT JOIN communityevents ON newsfeed.eventid = communityevents.id "+
					"LEFT JOIN volunteering ON newsfeed.volunteeringid = volunteering.id "+
					"LEFT JOIN users_stories ON newsfeed.storyid = users_stories.id "+
					"WHERE newsfeed.timestamp >= ? AND replyto IS NULL AND newsfeed.deleted IS NULL AND reviewrequired = 0 "+
					"AND users.deleted IS NULL "+
					"AND spam_users.id IS NULL "+
					"AND newsfeed.type = ? "+
					"%s"+
					"ORDER BY pinned DESC, newsfeed.timestamp DESC LIMIT %d "+
					") ORDER BY pinned DESC, timestamp DESC LIMIT 100;",
				utils.NEWSFEED_EVENTS_PER_FEED, alertGeo, utils.NEWSFEED_ALERTS_PER_FEED),
			append([]interface{}{
				// UNION 1: regular posts
				utils.NEWSFEED_MODSTATUS_SUPPRESSED,
				utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
				myid,
				start,
				utils.NEWSFEED_TYPE_COMMUNITY_EVENT, utils.NEWSFEED_TYPE_VOLUNTEER_OPPORTUNITY, utils.NEWSFEED_TYPE_ALERT,
				// UNION 2: event/volunteering posts (flood-capped)
				utils.NEWSFEED_MODSTATUS_SUPPRESSED,
				utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
				myid,
				start,
				utils.NEWSFEED_TYPE_COMMUNITY_EVENT, utils.NEWSFEED_TYPE_VOLUNTEER_OPPORTUNITY,
				// UNION 3: alerts (flood-capped) - Community News posts here
				utils.NEWSFEED_MODSTATUS_SUPPRESSED,
				utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER,
				myid,
				start,
				utils.NEWSFEED_TYPE_ALERT,
			}, alertGeoArgs...)...).Scan(&newsfeed)
	}

	amAMod := me.Systemrole != utils.SYSTEMROLE_USER

	var ret []NewsfeedSummary

	for i := 0; i < len(newsfeed); i++ {
		if newsfeed[i].Hidden != nil {
			if newsfeed[i].Userid == myid || amAMod {
				// Don't use hidden entries unless they are ours.  This means that to a spammer or suppressed user
				// it looks like their posts are there but nobody else sees them.
				ret = append(ret, newsfeed[i])
			}
		} else {
			// Don't return volunteering/events/stories if they are still pending.
			if !newsfeed[i].Eventpending && !newsfeed[i].Volunteeringpending && !newsfeed[i].Storypending {
				ret = append(ret, newsfeed[i])
			}
		}
	}

	return ret
}

func Single(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	lovelist, _ := strconv.ParseBool(c.Query("lovelist", "false"))

	if err == nil {
		// Get a single thread.
		var wg sync.WaitGroup
		var newsfeed Newsfeed
		var replies = []Newsfeed{}

		wg.Add(1)
		go func() {
			defer wg.Done()
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()
			newsfeed, _ = fetchSingle(id, myid, lovelist)
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()

			amAMod := false
			if myid > 0 {
				var me user.User
				db := database.DBConn
				db.First(&me, myid)
				amAMod = me.Systemrole != utils.SYSTEMROLE_USER
			}

			replies = fetchReplies(id, myid, id, amAMod)
		}()

		wg.Wait()

		if newsfeed.ID > 0 {
			newsfeed.Replies = replies

			if newsfeed.Replyto > 0 {
				// We need to find the thread head.
				parentid := newsfeed.Replyto
				for parentid > 0 {
					newsfeed.Threadhead = parentid
					parent, _ := fetchSingle(parentid, myid, lovelist)
					parentid = parent.Replyto
				}
			}

			newsfeed.Previews = getPreviews(newsfeed.Message)

			return c.JSON(newsfeed)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "Newsfeed item not found")
}

func getPreviews(text string) []NewsfeedPreview {
	db := database.DBConn

	previews := []NewsfeedPreview{}

	rxRelaxed := xurls.Relaxed()
	urls := rxRelaxed.FindAllString(text, -1)

	if len(urls) > 0 {
		var wg2 sync.WaitGroup
		var mu sync.Mutex

		for _, url := range urls {
			wg2.Add(1)

			go func(url string) {
				defer wg2.Done()

				// Replace http:// with https://
				url = strings.ReplaceAll(url, "http://", "https://")

				// Prepend https:// to the url if not present.
				if !strings.HasPrefix(strings.ToLower(url), "https://") {
					url = "https://" + url
				}

				// Exclude email addresses which contain @
				if !strings.Contains(url, "@") {
					// Get the title of the URL.  Don't use First() as logs error.
					var preview NewsfeedPreview
					preview.ID = 0
					db.Where("url LIKE ?", url).Limit(1).Find(&preview)

					if preview.ID > 0 {
						mu.Lock()
						defer mu.Unlock()
						previews = append(previews, preview)
					}
				}
			}(url)
		}

		wg2.Wait()
	}

	return previews
}

func fetchSingle(id uint64, myid uint64, lovelist bool) (Newsfeed, bool) {
	db := database.DBConn

	var newsfeed Newsfeed
	var loves int64
	var loved bool

	loverlist := []NewsLove{}

	newsfeed.Replies = []Newsfeed{}
	newsfeed.Lovelist = []NewsLove{}

	var wg sync.WaitGroup

	wg.Add(1)
	go func() {
		defer wg.Done()

		db.Table("newsfeed").
			Select("newsfeed.*, newsfeed_images.archived AS imagearchived, newsfeed_images.externaluid AS imageuid, newsfeed_images.externalmods AS imagemods, "+
				"(CASE WHEN users.newsfeedmodstatus = ? THEN NOW() ELSE newsfeed.hidden END) AS hidden, "+
				"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname, "+
				"CASE WHEN systemrole IN (?, ?, ?) THEN CASE WHEN JSON_EXTRACT(users.settings, '$.showmod') IS NULL THEN 1 ELSE JSON_EXTRACT(users.settings, '$.showmod') END ELSE 0 END AS showmod",
				utils.NEWSFEED_MODSTATUS_SUPPRESSED, utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN).
			Joins("LEFT JOIN users ON users.id = newsfeed.userid").
			Joins("LEFT JOIN newsfeed_images ON newsfeed.imageid = newsfeed_images.id").
			Where("newsfeed.id = ?", id).
			Scan(&newsfeed)

		if newsfeed.Imageid > 0 {
			if newsfeed.Imageuid != "" {
				newsfeed.Image = &NewsImage{
					ID:           newsfeed.Imageid,
					Ouruid:       newsfeed.Imageuid,
					Externalmods: newsfeed.Imagemods,
					Path:         misc.GetImageDeliveryUrl(newsfeed.Imageuid, string(newsfeed.Imagemods)),
					PathThumb:    misc.GetImageDeliveryUrl(newsfeed.Imageuid, string(newsfeed.Imagemods)),
				}
			} else if newsfeed.Imagearchived {
				newsfeed.Image = &NewsImage{
					ID:        newsfeed.Imageid,
					Path:      "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/fimg_" + strconv.FormatUint(newsfeed.Imageid, 10) + ".jpg",
					PathThumb: "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/tfimg_" + strconv.FormatUint(newsfeed.Imageid, 10) + ".jpg",
				}
			} else {
				newsfeed.Image = &NewsImage{
					ID:        newsfeed.Imageid,
					Path:      "https://" + os.Getenv("IMAGE_DOMAIN") + "/fimg_" + strconv.FormatUint(newsfeed.Imageid, 10) + ".jpg",
					PathThumb: "https://" + os.Getenv("IMAGE_DOMAIN") + "/tfimg_" + strconv.FormatUint(newsfeed.Imageid, 10) + ".jpg",
				}
			}
		}

		// A convert-to-post notice needs to say WHAT was posted for the member
		// - "a WANTED" reads very differently from "an OFFER" - and the client
		// can't look the message up itself: it's usually still pending, which
		// only mods and the author can fetch.
		if newsfeed.Type == "ConvertedToPost" && newsfeed.Msgid > 0 {
			db.Table("messages").Select("type").Where("id = ?", newsfeed.Msgid).Scan(&newsfeed.Msgtype)
		}

		var wg2 sync.WaitGroup

		wg2.Add(2)

		var info user.UserInfo
		var profileRecord user.UserProfileRecord

		wg2.Add(1)
		go func() {
			defer wg2.Done()
			info = user.GetUserInfo(newsfeed.Userid, myid)
		}()

		go func() {
			defer wg2.Done()
			profileRecord = user.GetProfileRecord(newsfeed.Userid)
		}()

		previews := []NewsfeedPreview{}

		go func() {
			defer wg2.Done()
			previews = getPreviews(newsfeed.Message)
		}()

		wg2.Wait()

		newsfeed.Info = info
		newsfeed.Previews = previews

		if profileRecord.Useprofile {
			user.ProfileSetPath(profileRecord.Profileid, profileRecord.Url, profileRecord.Externaluid, profileRecord.Externalmods, profileRecord.Archived, &newsfeed.Profile)
		}
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()

		// Get count of loves.
		db.Table("newsfeed_likes").Where("newsfeedid = ?", id).Count(&loves)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()

		// Get any loves by us
		// loved is bool, not int64, so
		// this keeps Row().Scan (database/sql converts a numeric COUNT(*) to bool
		// via a nonzero check) rather than GORM's Count, which requires *int64.
		db.Table("newsfeed_likes").Select("COUNT(*)").Where("newsfeedid = ? AND userid = ?", id, myid).Row().Scan(&loved)
	}()

	if lovelist {
		wg.Add(1)
		go func() {
			defer wg.Done()

			db.Table("newsfeed_likes").Where("newsfeedid = ?", id).Scan(&loverlist)
		}()
	}

	wg.Wait()

	if newsfeed.ID > 0 {
		// We return the hidden flag.  This would allow someone whose posts had been hidden to spot that in the API
		// call, but it saves some extra DB ops to determine that we are a mod. So we hide that from them in the client.
		newsfeed.Loved = loved
		newsfeed.Loves = loves
		newsfeed.Lovelist = loverlist
		// trim message for all types except Noticeboard (which stores JSON in message).
		if newsfeed.Type != "Noticeboard" {
			newsfeed.Message = strings.TrimSpace(newsfeed.Message)
		}

		newsfeed.Displayname = strings.TrimSpace(newsfeed.Displayname)
		newsfeed.Displayname = utils.TidyName(newsfeed.Displayname)

		// Use area name for privacy instead of postcode. Look up from user's location.
		var areaname string
		db.Table("users").
			Select("COALESCE(l2.name, '')").
			Joins("LEFT JOIN locations l1 ON users.lastlocation = l1.id").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("users.id = ?", newsfeed.Userid).
			Scan(&areaname)
		if areaname != "" {
			newsfeed.Location = areaname
		} else if len(newsfeed.Location) > 2 {
			// Fallback to truncated postcode if no area name found.
			newsfeed.Location = strings.TrimSpace(newsfeed.Location[:len(newsfeed.Location)-2])
		}

		if newsfeed.Replyto == 0 {
			newsfeed.Threadhead = newsfeed.ID
		}

		return newsfeed, false
	} else {
		return newsfeed, true
	}
}

func fetchReplies(id uint64, myid uint64, threadhead uint64, amAMod bool) []Newsfeed {
	db := database.DBConn

	var replies = []Newsfeed{}

	type ReplyId struct {
		ID uint64 `json:"id"`
	}

	var replyids []ReplyId
	var mu sync.Mutex

	db.Table("newsfeed").Select("id").Where("replyto = ? AND deleted IS NULL", id).Order("timestamp ASC").Scan(&replyids)

	var wg sync.WaitGroup

	// We have to fetch the replies first otherwise we don't have a slot into which
	// to put the replies to the replies.
	for i := 0; i < len(replyids); i++ {
		wg.Add(1)
		go func(replyid uint64) {
			defer wg.Done()
			reply, err := fetchSingle(replyid, myid, false)

			if !err {
				reply.Threadhead = threadhead

				mu.Lock()
				defer mu.Unlock()
				replies = append(replies, reply)
			}
		}(replyids[i].ID)
	}

	wg.Wait()

	var wg2 sync.WaitGroup

	// Fetch any replies to the replies (which in turn will fetch replies to those).
	for i := 0; i < len(replyids); i++ {
		wg2.Add(1)
		go func(replyid uint64) {
			defer wg2.Done()

			repliestoreplies := fetchReplies(replyid, myid, threadhead, amAMod)
			mu.Lock()
			defer mu.Unlock()

			// Add these replies to the entry in replies with the correct ID.
			for j := 0; j < len(replies); j++ {
				if replies[j].ID == replyid {
					replies[j].Replies = repliestoreplies
				}
			}
		}(replyids[i].ID)
	}

	wg2.Wait()

	// Sort replies by ascending timestamp.
	sort.Slice(replies, func(i, j int) bool {
		return replies[i].Timestamp.Before(replies[j].Timestamp)
	})

	// Remove any hidden replies unless they are ours or we're a mod.
	var newReplies = []Newsfeed{}

	for i := 0; i < len(replies); i++ {
		if replies[i].Hidden == nil || replies[i].Userid == myid || amAMod {
			newReplies = append(newReplies, replies[i])
		}
	}

	return newReplies
}

func Count(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var distance uint64 = 1609
	var err error
	gotDistance := true

	if c.Query("distance") != "" && c.Query("distance") != "nearby" {
		if c.Query("distance") == "anywhere" {
			distance = 0
			gotDistance = true
		} else {
			distance, err = strconv.ParseUint(c.Query("distance"), 10, 32)

			if err != nil {
				gotDistance = true
			}
		}
	}

	// Get what we've already seen, and our current feed.
	var wg sync.WaitGroup
	var ret []NewsfeedSummary
	var seen uint64

	wg.Add(1)

	go func() {
		defer wg.Done()
		ret = getFeed(myid, gotDistance, distance)
	}()

	db := database.DBConn
	wg.Add(1)

	go func() {
		defer wg.Done()
		db.Table("newsfeed_users").Select("newsfeedid").Where("userid = ?", myid).Row().Scan(&seen)
	}()

	wg.Wait()

	// Count the ids in the feed that are greater than seen.
	var count uint64 = 0

	for i := 0; i < len(ret); i++ {
		if ret[i].ID > seen && ret[i].Hidden == nil {
			count++
		}
	}

	return c.JSON(fiber.Map{
		"count": count,
	})
}

type PostRequest struct {
	ID      uint64 `json:"id"`
	Action  string `json:"action"`
	Message string `json:"message"`
	Reason  string `json:"reason"`
	Replyto uint64 `json:"replyto"`
	Imageid uint64 `json:"imageid"`
	// Msgid carries the OFFER/WANTED just created for the poster by the
	// ChitChat convert-to-post flow, so the note on the thread can point at it.
	Msgid uint64 `json:"msgid"`
}

// canModifyPost checks if a user can edit/delete a newsfeed post.
// Allowed: post owner, admin/support, or any group moderator.
func canModifyPost(myid uint64, nfID uint64) bool {
	db := database.DBConn

	var ownerID uint64
	db.Table("newsfeed").Select("userid").Where("id = ?", nfID).Scan(&ownerID)

	if ownerID == myid {
		return true
	}

	if auth.IsAdminOrSupport(myid) {
		return true
	}

	var modCount int64
	db.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).Count(&modCount)

	return modCount > 0
}

// canHidePost checks if a user can hide/unhide a newsfeed post.
// Requires: isAdminOrSupport() OR member of "ChitChat Moderation" team.
// This is stricter than canModifyPost - not all moderators can hide posts.
func canHidePost(myid uint64) bool {
	// ChitChat Moderation team, or support/admin. Shared with the message
	// package, which gates posting on a member's behalf on the same audience.
	return auth.IsChitChatMod(myid)
}

func Post(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PostRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	switch req.Action {
	case "Love":
		if req.ID > 0 {
			db.Table("newsfeed_likes").Clauses(clause.Insert{Modifier: "IGNORE"}).
				Create(map[string]interface{}{"newsfeedid": req.ID, "userid": myid})

			// Send notification to the post/comment author.
			type PostOwner struct {
				Userid  uint64  `json:"userid"`
				Replyto *uint64 `json:"replyto"`
			}
			var owner PostOwner
			db.Table("newsfeed").Select("userid, replyto").Where("id = ?", req.ID).Scan(&owner)
			if owner.Userid > 0 && owner.Userid != myid {
				notifType := "LovedPost"
				if owner.Replyto != nil && *owner.Replyto > 0 {
					notifType = "LovedComment"
				}
				db.Table("users_notifications").Create(map[string]interface{}{
					"fromuser":   myid,
					"touser":     owner.Userid,
					"type":       notifType,
					"newsfeedid": req.ID,
				})
			}
		}
	case "Unlove":
		if req.ID > 0 {
			db.Table("newsfeed_likes").Where("newsfeedid = ? AND userid = ?", req.ID, myid).Delete(nil)
		}
	case "Seen":
		if req.ID > 0 {
			// Only update if no existing record or the new ID is higher than the current one.
			// Otherwise we'd mark an earlier item as seen, causing duplicate digest emails.
			var currentSeenID uint64
			db.Table("newsfeed_users").Select("newsfeedid").Where("userid = ?", myid).Scan(&currentSeenID)

			if currentSeenID == 0 || req.ID > currentSeenID {
				db.Table("newsfeed_users").Clauses(clause.Insert{Modifier: "REPLACE"}).
					Create(map[string]interface{}{"userid": myid, "newsfeedid": req.ID})
			}
			db.Table("users_notifications").Where("touser = ? AND newsfeedid = ?", myid, req.ID).
				Update("seen", gorm.Expr("1"))
		}
	case "Follow":
		if req.ID > 0 {
			db.Table("newsfeed_unfollow").Where("userid = ? AND newsfeedid = ?", myid, req.ID).Delete(nil)
		}
	case "Unfollow":
		if req.ID > 0 {
			db.Table("newsfeed_unfollow").Clauses(clause.Insert{Modifier: "REPLACE"}).
				Create(map[string]interface{}{"userid": myid, "newsfeedid": req.ID})
			db.Table("users_notifications").
				Where("touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?))", myid, req.ID, req.ID).
				Delete(nil)
		}
	case "Report":
		if req.ID > 0 {
			db.Table("newsfeed").Where("id = ?", req.ID).Update("reviewrequired", gorm.Expr("1"))
			// ORM migration site 958d1d242008 (wave 3), through the portable
			// upsert wrapper. The conflict target is the composite
			// (userid, newsfeedid) unique key: PostgreSQL requires it to be
			// named explicitly, and naming it here keeps the one call site
			// correct on both engines.
			db.Table("newsfeed_reports").Clauses(clause.OnConflict{
				Columns:   []clause.Column{{Name: "userid"}, {Name: "newsfeedid"}},
				DoUpdates: clause.Assignments(map[string]interface{}{"reason": req.Reason}),
			}).Create(map[string]interface{}{
				"userid":     myid,
				"newsfeedid": req.ID,
				"reason":     req.Reason,
			})

			// Queue email to ChitChat support.
			type ReporterInfo struct {
				Fullname string
				Email    string
			}
			var reporter ReporterInfo
			db.Table("users u").
				Select("u.fullname, ue.email").
				Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
				Where("u.id = ?", myid).
				Order("ue.preferred DESC, ue.id ASC").
				Limit(1).
				Scan(&reporter)

			if err := queue.QueueTask(queue.TaskEmailChitchatReport, map[string]interface{}{
				"user_id":     myid,
				"user_name":   reporter.Fullname,
				"user_email":  reporter.Email,
				"newsfeed_id": req.ID,
				"reason":      req.Reason,
			}); err != nil {
				stdlog.Printf("Failed to queue chitchat report email for newsfeed %d: %v", req.ID, err)
			}
		}
	case "Hide":
		if req.ID > 0 && canHidePost(myid) {
			db.Table("newsfeed").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"hidden": gorm.Expr("NOW()"), "hiddenby": myid})
			db.Table("logs").Create(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"type":      log.LOG_TYPE_CHITCHAT,
				"subtype":   log.LOG_SUBTYPE_HIDDEN,
				"byuser":    myid,
				"text":      gorm.Expr("'Newsfeed entry hidden'"),
			})
		} else if req.ID > 0 {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}
	case "Unhide":
		if req.ID > 0 && canHidePost(myid) {
			db.Table("newsfeed").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"hidden": gorm.Expr("NULL"), "hiddenby": gorm.Expr("NULL")})
			db.Table("logs").Create(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"type":      log.LOG_TYPE_CHITCHAT,
				"subtype":   log.LOG_SUBTYPE_UNHIDDEN,
				"byuser":    myid,
				"text":      gorm.Expr("'Newsfeed entry unhidden'"),
			})
		} else if req.ID > 0 {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}
	case "ConvertedToPost":
		// Records on the thread that a ChitChat moderator has posted this as a
		// real OFFER/WANTED for the member, so they can see what happened and
		// go and find it. The post itself is created through the normal
		// PUT /message + JoinAndPost route with ?onbehalfof=, which is where
		// the permission to post as them is enforced; this only adds the note.
		if req.ID == 0 || req.Msgid == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "id and msgid are required")
		}

		if !canHidePost(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Permission denied")
		}

		createRefer(db, myid, req.ID, "ConvertedToPost", req.Msgid)

		// If the member put a photo on their ChitChat post, carry it onto the
		// OFFER/WANTED - a photo is often the most useful part. Modern images
		// live in the external store (externaluid); rows without one are
		// legacy blobs which a fresh ChitChat post can't have.
		// database.InsertSelect keeps
		// this a single atomic statement - splitting it into a read then a
		// write would open a race between the existence check and the copy.
		database.InsertSelect(db, "messages_attachments",
			"(msgid, contenttype, archived, hash, externaluid, externalmods, identification, `primary`) "+
				"SELECT ?, ni.contenttype, ni.archived, ni.hash, ni.externaluid, ni.externalmods, ni.identification, 1 "+
				"FROM newsfeed n INNER JOIN newsfeed_images ni ON ni.id = n.imageid "+
				"WHERE n.id = ? AND ni.externaluid IS NOT NULL "+
				"AND NOT EXISTS (SELECT 1 FROM messages_attachments ma WHERE ma.msgid = ?)",
			req.Msgid, req.ID, req.Msgid)

		// The real post now exists, so the ChitChat copy is redundant: hide it
		// exactly as the Hide action does, so it stops collecting replies. The
		// member still sees their own hidden post, with the notice on it.
		db.Table("newsfeed").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"hidden": gorm.Expr("NOW()"), "hiddenby": myid})

		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"),
			"type":      log.LOG_TYPE_CHITCHAT,
			"subtype":   log.LOG_SUBTYPE_CREATED,
			"byuser":    myid,
			"text":      fmt.Sprintf("ChitChat post %d posted as message %d for the member", req.ID, req.Msgid),
		})
	case "ReferToWanted":
		if req.ID > 0 {
			createRefer(db, myid, req.ID, "ReferToWanted", 0)
		}
	case "ReferToOffer":
		if req.ID > 0 {
			createRefer(db, myid, req.ID, "ReferToOffer", 0)
		}
	case "ReferToTaken":
		if req.ID > 0 {
			createRefer(db, myid, req.ID, "ReferToTaken", 0)
		}
	case "ReferToReceived":
		if req.ID > 0 {
			createRefer(db, myid, req.ID, "ReferToReceived", 0)
		}
	case "AttachToThread":
		// Mod-only: attach a newsfeed item to a different thread
		if req.ID > 0 && req.Replyto > 0 {
			var modCount int64
			db.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).Count(&modCount)
			if modCount > 0 {
				db.Table("newsfeed").Where("id = ?", req.ID).Update("replyto", req.Replyto)
				db.Table("logs").Create(map[string]interface{}{
					"timestamp": gorm.Expr("NOW()"),
					"type":      log.LOG_TYPE_CHITCHAT,
					"subtype":   log.LOG_SUBTYPE_ATTACHED_TO_THREAD,
					"byuser":    myid,
					"text":      gorm.Expr("'Newsfeed entry attached to thread'"),
				})
			} else {
				return fiber.NewError(fiber.StatusForbidden, "Permission denied")
			}
		}
	case "ConvertToStory":
		if req.ID > 0 {
			// Mod-only action
			var modCount int64
			db.Table("memberships").Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Count(&modCount)
			if modCount == 0 {
				return fiber.NewError(fiber.StatusForbidden, "Permission denied")
			}

			// Get the newsfeed entry
			var nf struct {
				Userid  uint64
				Message string
			}
			db.Table("newsfeed").Select("userid, message").Where("id = ?", req.ID).Scan(&nf)

			if nf.Userid == 0 {
				return fiber.NewError(fiber.StatusNotFound, "Newsfeed entry not found")
			}

			// Create a story from this newsfeed entry. Table()+map Create reads
			// the new id back from the same sql.Result the INSERT returned,
			// under the map key "@id" - see test/orm_insertid_test.go. Still
			// the write connection, so still immune to the read/write split's
			// Discourse-9832-class staleness.
			row := map[string]interface{}{
				"userid":       nf.Userid,
				"headline":     gorm.Expr("''"),
				"story":        nf.Message,
				"date":         gorm.Expr("NOW()"),
				"fromnewsfeed": gorm.Expr("1"),
			}
			if err := db.Table("users_stories").Create(row).Error; err != nil {
				return fiber.NewError(fiber.StatusInternalServerError, "Failed to create story")
			}
			storyIDInt, _ := row["@id"].(int64)
			storyID := uint64(storyIDInt)

			return c.JSON(fiber.Map{"id": storyID})
		}
	case "":
		// No action = create new post or reply. Require a message.
		if req.Message == "" {
			return fiber.NewError(fiber.StatusBadRequest, "message is required")
		}
		return createPost(c, db, myid, req)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
	}

	return c.JSON(fiber.Map{"success": true})
}

// createPost creates a new newsfeed post or reply.
func createPost(c *fiber.Ctx, db *gorm.DB, myid uint64, req PostRequest) error {
	// Check if user is a spammer
	var spammerCount int64
	db.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", myid, utils.SPAM_COLLECTION_PENDING_ADD, utils.SPAM_COLLECTION_SPAMMER).Count(&spammerCount)
	if spammerCount > 0 {
		// Silently succeed - don't reveal spammer status.
		return c.JSON(fiber.Map{"id": 0})
	}

	// Check suppression status
	var newsfeedmodstatus string
	db.Table("users").Select("COALESCE(newsfeedmodstatus, '')").Where("id = ?", myid).Scan(&newsfeedmodstatus)
	hidden := newsfeedmodstatus == utils.NEWSFEED_MODSTATUS_SUPPRESSED

	// Get user's lat/lng for geographic positioning
	latlng := user.GetLatLng(myid)
	lat := float64(latlng.Lat)
	lng := float64(latlng.Lng)

	if lat == 0 && lng == 0 {
		// No location - can't create non-alert posts without location
		return c.JSON(fiber.Map{"id": 0})
	}

	// Duplicate prevention: check last post by user
	type LastPost struct {
		ID      uint64  `json:"id"`
		Replyto *uint64 `json:"replyto"`
		Type    string  `json:"type"`
		Message string  `json:"message"`
	}
	var last LastPost
	db.Table("newsfeed").Select("id, replyto, type, message").Where("userid = ?", myid).Order("id DESC").Limit(1).Scan(&last)

	var lastReplyto uint64
	if last.Replyto != nil {
		lastReplyto = *last.Replyto
	}
	if last.ID > 0 && lastReplyto == req.Replyto && last.Type == "Message" && last.Message == req.Message {
		// Duplicate - return existing ID
		return c.JSON(fiber.Map{"id": last.ID})
	}

	// Get user's display location - use area name (e.g. "Kirkcaldy") for privacy instead of postcode.
	var location *string
	db.Table("users").
		Select("l2.name").
		Joins("LEFT JOIN locations l1 ON users.lastlocation = l1.id").
		Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
		Where("users.id = ?", myid).
		Scan(&location)

	// Insert the newsfeed entry
	hiddenSQL := "NULL"
	if hidden {
		hiddenSQL = "NOW()"
	}

	var imageid interface{}
	if req.Imageid > 0 {
		imageid = req.Imageid
	}
	var replyto interface{}
	if req.Replyto > 0 {
		replyto = req.Replyto
	}

	// Same zero-precision-change
	// conversion as createRefer (10bcbd6a6404) above: the WKT text is built
	// exactly as before via fmt.Sprintf("POINT(%f %f)", ...), then bound as a
	// genuine ST_GeomFromText argument rather than spliced into the SQL text.
	// hiddenSQL is a fixed two-way literal ("NULL" or "NOW()"), never a bound
	// value, so gorm.Expr(hiddenSQL) with no args is exact.
	row := map[string]interface{}{
		"type":     gorm.Expr("'Message'"),
		"userid":   myid,
		"imageid":  imageid,
		"replyto":  replyto,
		"message":  req.Message,
		"position": gorm.Expr("ST_GeomFromText(?, ?)", fmt.Sprintf("POINT(%f %f)", lng, lat), utils.SRID),
		"hidden":   gorm.Expr(hiddenSQL),
		"location": location,
	}
	if err := db.Table("newsfeed").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create newsfeed post")
	}

	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	// If this is a reply and not hidden, bump the thread
	if id > 0 && req.Replyto > 0 && !hidden {
		bumpThread(db, req.Replyto)
		notifyThreadContributors(db, myid, id, req.Replyto)

		// Mark own notifications for this thread as seen
		db.Table("users_notifications").
			Where("touser = ? AND (newsfeedid = ? OR newsfeedid IN (SELECT id FROM newsfeed WHERE replyto = ?))", myid, req.Replyto, req.Replyto).
			Update("seen", gorm.Expr("1"))
	}

	return c.JSON(fiber.Map{"id": id})
}

// bumpThread updates timestamps up the reply chain to bring the thread to the top of the feed.
func bumpThread(db *gorm.DB, replyto uint64) {
	bump := replyto
	for bump > 0 {
		db.Table("newsfeed").Where("id = ?", bump).Update("timestamp", gorm.Expr("NOW()"))
		var parent *uint64
		db.Table("newsfeed").Select("replyto").Where("id = ?", bump).Scan(&parent)
		if parent != nil && *parent > 0 {
			bump = *parent
		} else {
			bump = 0
		}
	}
}

// notifyThreadContributors notifies users who have recently contributed to a thread.
// Only notifies users who commented in the last 7 days.
func notifyThreadContributors(db *gorm.DB, posterUserid uint64, newPostID uint64, replyto uint64) {
	recent := time.Now().AddDate(0, 0, -7)

	// Collect all post IDs in the thread and contributors
	type PostInfo struct {
		ID        uint64    `json:"id"`
		Userid    uint64    `json:"userid"`
		Timestamp time.Time `json:"timestamp"`
	}

	contributed := make(map[uint64]bool)
	ids := []uint64{replyto}
	processed := make(map[uint64]bool)

	for {
		oldLen := len(ids)
		var newIDs []uint64

		for _, pid := range ids {
			if processed[pid] {
				continue
			}
			processed[pid] = true

			var posts []PostInfo
			db.Table("newsfeed").Select("id, userid, timestamp").Where("replyto = ? OR id = ?", pid, pid).Scan(&posts)

			for _, p := range posts {
				if p.Timestamp.After(recent) && p.Userid != posterUserid {
					contributed[p.Userid] = true
				}
				newIDs = append(newIDs, p.ID)
			}
		}

		ids = append(ids, newIDs...)
		// Deduplicate
		seen := make(map[uint64]bool)
		unique := make([]uint64, 0)
		for _, id := range ids {
			if !seen[id] {
				seen[id] = true
				unique = append(unique, id)
			}
		}
		ids = unique

		if len(ids) == oldLen {
			break
		}
	}

	// Notify contributors — point to the new post (the reply) so the notification
	// shows its message rather than the original thread-head's message.
	for uid := range contributed {
		db.Table("users_notifications").Create(map[string]interface{}{
			"fromuser":   posterUserid,
			"touser":     uid,
			"type":       gorm.Expr("'CommentOnYourPost'"),
			"newsfeedid": newPostID,
		})
	}
}

// createRefer creates a refer-type reply to a newsfeed post and notifies the original poster.
// msgid is only set for ConvertedToPost notices, where it names the OFFER/WANTED the notice
// is about so the thread can say which kind of post was made; pass 0 for the ReferTo family.
func createRefer(db *gorm.DB, myid uint64, nfID uint64, referType string, msgid uint64) {
	// Get user's location
	latlng := user.GetLatLng(myid)
	lat := float64(latlng.Lat)
	lng := float64(latlng.Lng)

	// The WKT text is built exactly as
	// before (fmt.Sprintf("POINT(%f %f)", ...), unchanged) - only WHERE it goes
	// changes: it is now a genuine bind argument to ST_GeomFromText via
	// gorm.Expr, not spliced into the SQL text. This is a zero-precision-change
	// conversion, deliberately: the review flagged a "%f-truncation vs full
	// float64 precision" decision to make here, and binding the already-
	// formatted WKT string sidesteps it entirely rather than answering it.
	row := map[string]interface{}{
		"type":     referType,
		"userid":   myid,
		"replyto":  nfID,
		"msgid":    gorm.Expr("NULLIF(?, 0)", msgid),
		"position": gorm.Expr("ST_GeomFromText(?, ?)", fmt.Sprintf("POINT(%f %f)", lng, lat), utils.SRID),
	}
	if err := db.Table("newsfeed").Create(row).Error; err != nil {
		return
	}

	newIDInt, _ := row["@id"].(int64)
	newID := uint64(newIDInt)

	// Notify the original poster
	if newID > 0 {
		var originalUserid uint64
		db.Table("newsfeed").Select("userid").Where("id = ?", nfID).Scan(&originalUserid)
		if originalUserid > 0 && originalUserid != myid {
			db.Table("users_notifications").Create(map[string]interface{}{
				"fromuser":   myid,
				"touser":     originalUserid,
				"type":       gorm.Expr("'CommentOnYourPost'"),
				"newsfeedid": nfID,
			})
		}
	}
}

type PatchRequest struct {
	ID      uint64 `json:"id"`
	Message string `json:"message"`
}

func Edit(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn
	var ownerID uint64
	db.Table("newsfeed").Select("userid").Where("id = ?", req.ID).Scan(&ownerID)
	if ownerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Newsfeed post not found")
	}

	if ownerID != myid && !canModifyPost(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to edit this post")
	}

	db.Table("newsfeed").Where("id = ?", req.ID).Update("message", req.Message)

	return c.JSON(fiber.Map{"success": true})
}

func Delete(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid ID")
	}

	db := database.DBConn
	var ownerID uint64
	db.Table("newsfeed").Select("userid").Where("id = ?", id).Scan(&ownerID)
	if ownerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Newsfeed post not found")
	}

	if ownerID != myid && !canModifyPost(myid, id) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized to delete this post")
	}

	// Soft delete
	db.Table("newsfeed").Where("id = ?", id).
		Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "deletedby": myid})
	db.Table("users_notifications").Where("newsfeedid = ?", id).Delete(nil)

	return c.JSON(fiber.Map{"success": true})
}

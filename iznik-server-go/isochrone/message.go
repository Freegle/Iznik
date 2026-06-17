package isochrone

import (
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

type IsochronesUsers struct {
	ID          uint64 `json:"id" gorm:"primary_key"`
	Userid      uint64 `json:"userid"`
	Isochroneid uint64 `json:"isochroneid"`
	Polygon     string `json:"polygon" gorm:"column:polygon"`
}

func Messages(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	var isochrones []IsochronesUsers
	res := []message.MessageSummary{}

	latlng := user.GetLatLng(myid)

	// Fetch isochrones including polygon WKT for spatial server queries.
	db.Raw(
		"SELECT isochrones_users.id, isochrones_users.userid, isochrones_users.isochroneid, "+
			"ST_AsText(isochrones.polygon) AS polygon "+
			"FROM isochrones_users "+
			"JOIN isochrones ON isochrones.id = isochrones_users.isochroneid "+
			"WHERE isochrones_users.userid = ?",
		myid,
	).Scan(&isochrones)

	if len(isochrones) > 0 {
		var mu sync.Mutex
		var wg sync.WaitGroup

		for _, iso := range isochrones {
			wg.Add(1)

			go func(iso IsochronesUsers) {
				defer wg.Done()

				msgs := []message.MessageSummary{}
				start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

				// Use spatial server to find message IDs within the isochrone polygon.
				msgIDs, err := spatial.Within("messages", iso.Polygon)
				if err == nil && len(msgIDs) > 0 {
					placeholders := make([]string, len(msgIDs))
					// SQL bind order: userid, type (for messages_likes JOIN), then msgid list, then lng/lat (for postvisibility).
					args := make([]any, len(msgIDs)+4)
					args[0] = myid
					args[1] = utils.MESSAGE_LIKES_VIEW
					for i, id := range msgIDs {
						placeholders[i] = "?"
						args[i+2] = id
					}
					args[len(msgIDs)+2] = latlng.Lng
					args[len(msgIDs)+3] = latlng.Lat

					db.Raw(fmt.Sprintf(
						"SELECT ST_Y(ms.point) AS lat, ST_X(ms.point) AS lng, "+
							"ms.msgid AS id, ms.successful, ms.promised, ms.groupid, "+
							"ms.msgtype AS type, ms.arrival, "+
							"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
							"FROM messages_spatial ms "+
							"INNER JOIN `groups` g ON g.id = ms.groupid "+
							"LEFT JOIN messages_likes ml ON ml.msgid = ms.msgid AND ml.userid = ? AND ml.type = ? "+
							"WHERE ms.msgid IN (%s) "+
							"AND (g.postvisibility IS NULL OR ST_Contains(g.postvisibility, ST_SRID(POINT(?,?), %d))) = 1",
						strings.Join(placeholders, ","), utils.SRID,
					), args...).Scan(&msgs)
				}

				// Also include user's own messages within the isochrone that may not yet be in messages_spatial.
				var ownMsgs []message.MessageSummary
				db.Raw(
					"SELECT m.lat, m.lng, m.id, "+
						"(CASE WHEN mo.outcome IN (?, ?) THEN 1 ELSE 0 END) AS successful, "+
						"(CASE WHEN mp.id IS NOT NULL THEN 1 ELSE 0 END) AS promised, "+
						"mg.groupid, m.type, mg.arrival, "+
						"CASE WHEN ml.msgid IS NULL THEN 1 ELSE 0 END AS unseen "+
						"FROM messages m "+
						"INNER JOIN messages_groups mg ON mg.msgid = m.id "+
						"INNER JOIN `groups` g ON g.id = mg.groupid "+
						"INNER JOIN isochrones iso ON ST_Contains(iso.polygon, ST_SRID(POINT(m.lng, m.lat), ?)) "+
						"LEFT JOIN messages_outcomes mo ON mo.msgid = m.id "+
						"LEFT JOIN messages_promises mp ON mp.msgid = m.id "+
						"LEFT JOIN messages_likes ml ON ml.msgid = m.id AND ml.userid = ? AND ml.type = ? "+
						"WHERE m.fromuser = ? AND mg.arrival >= ? AND iso.id = ? "+
						"AND (g.postvisibility IS NULL OR ST_Contains(g.postvisibility, ST_SRID(POINT(?,?), ?))) = 1 "+
						"AND mo.id IS NULL",
					utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED,
					utils.SRID, myid, utils.MESSAGE_LIKES_VIEW, myid, start, iso.Isochroneid,
					latlng.Lng, latlng.Lat, utils.SRID,
				).Scan(&ownMsgs)

				mu.Lock()
				defer mu.Unlock()
				res = append(res, msgs...)
				res = append(res, ownMsgs...)
			}(iso)
		}

		wg.Wait()

		// Q2a (§6): hide posts whose rippling reach exists but hasn't reached the viewer
		// yet. Inert until the reach engine populates messages_reach.
		res = FilterReachBlocked(db, res, float64(latlng.Lat), float64(latlng.Lng))

		for ix, r := range res {
			res[ix].Lat, res[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
		}
	}

	return c.JSON(res)
}

// FilterReachBlocked removes messages whose rippling reach exists but does not yet cover
// the viewer's location (§6 — a post stays hidden until the ripple reaches you). It is
// inert until the reach engine populates messages_reach: a missing table or no matching
// rows leaves msgs unchanged, so non-rippling posts and the pre-engine period are
// unaffected.
func FilterReachBlocked(db *gorm.DB, msgs []message.MessageSummary, lat, lng float64) []message.MessageSummary {
	if len(msgs) == 0 || (lat == 0 && lng == 0) {
		return msgs
	}

	ids := make([]uint64, 0, len(msgs))
	for _, m := range msgs {
		ids = append(ids, m.ID)
	}

	var rows []struct {
		Msgid uint64 `gorm:"column:msgid"`
	}
	if err := db.Raw(
		"SELECT msgid FROM messages_reach WHERE msgid IN (?) "+
			"AND ST_Contains(polygon, ST_SRID(POINT(?, ?), ?)) = 0",
		ids, lng, lat, utils.SRID,
	).Scan(&rows).Error; err != nil {
		return msgs // messages_reach absent (pre-engine) — no filtering
	}
	if len(rows) == 0 {
		return msgs
	}

	blocked := make(map[uint64]bool, len(rows))
	for _, r := range rows {
		blocked[r.Msgid] = true
	}

	out := make([]message.MessageSummary, 0, len(msgs))
	for _, m := range msgs {
		if !blocked[m.ID] {
			out = append(out, m)
		}
	}
	return out
}

func Count(c *fiber.Ctx) error {
	db := database.DBConn
	myid := user.WhoAmI(c)

	var count uint64 = 0

	browseView := c.Query("browseView", "nearby")

	if browseView == "mygroups" {
		db.Raw("SELECT COUNT(DISTINCT(messages_spatial.msgid)) FROM memberships "+
			"INNER JOIN messages_spatial ON messages_spatial.groupid = memberships.groupid "+
			"LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ? "+
			"WHERE memberships.userid = ? AND messages_spatial.successful = 0 AND messages_likes.msgid IS NULL", myid, utils.MESSAGE_LIKES_VIEW, myid).Scan(&count)
	} else {
		count = isochroneCount(myid)
	}

	return c.JSON(fiber.Map{
		"count": count,
	})
}

func isochroneCount(myid uint64) uint64 {
	db := database.DBConn

	var isochrones []IsochronesUsers
	res := uint64(0)

	latlng := user.GetLatLng(myid)

	db.Where("userid = ?", myid).Find(&isochrones)

	if len(isochrones) > 0 {
		var mu sync.Mutex

		var wg sync.WaitGroup

		for _, isochrone := range isochrones {
			wg.Add(1)

			go func(isochrone IsochronesUsers) {
				defer wg.Done()

				thiscount := uint64(0)

				db.Raw("SELECT COUNT(DISTINCT(messages_spatial.msgid)) "+
					"FROM messages_spatial "+
					"INNER JOIN isochrones ON ST_Contains(isochrones.polygon, ST_SRID(point, ?)) "+
					"INNER JOIN `groups` ON groups.id = messages_spatial.groupid "+
					"LEFT JOIN messages_likes ON messages_likes.msgid = messages_spatial.msgid AND messages_likes.userid = ? AND messages_likes.type = ? "+
					"WHERE isochrones.id = ? AND messages_spatial.successful = 0 "+
					"AND (CASE WHEN postvisibility IS NULL OR ST_Contains(postvisibility, ST_SRID(POINT(?, ?),?)) THEN 1 ELSE 0 END) = 1 "+
					"AND messages_likes.msgid IS NULL;", utils.SRID, myid, utils.MESSAGE_LIKES_VIEW, isochrone.Isochroneid, latlng.Lng, latlng.Lat, utils.SRID).Scan(&thiscount)

				mu.Lock()
				defer mu.Unlock()
				res += thiscount
			}(isochrone)
		}

		wg.Wait()
	}

	return res
}

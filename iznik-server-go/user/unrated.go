package user

import (
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// How far back a handover counts as recent enough to ask about, and how many people are returned.
const UNRATED_DAYS = 14
const UNRATED_MAX = 5

// Unrated is a freegler the member handed an item over with lately and has not rated. One entry per
// person, for the most recent handover with them.
type Unrated struct {
	Userid    uint64    `json:"userid"`
	Msgid     uint64    `json:"msgid"`
	Subject   string    `json:"subject"`
	Item      string    `json:"item"`
	Gave      bool      `json:"gave"`
	Timestamp time.Time `json:"timestamp"`
}

type unratedRow struct {
	Userid    uint64
	Msgid     uint64
	Subject   string
	Item      string
	Type      string
	Poster    bool
	Timestamp time.Time
}

// GetUnrated lists the people the logged-in member handed an item over with in the last UNRATED_DAYS
// days (a messages_by row, in either direction) and has not rated. Any ratings row from the member to
// that person counts as rated, including one whose rating was later removed: a member who took a rating
// back is not asked again.
func GetUnrated(c *fiber.Ctx) error {
	myid := WhoAmI(c)

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn
	since := time.Now().AddDate(0, 0, -UNRATED_DAYS)

	var rows []unratedRow

	// The first branch is the member's own posts and who took them (messages.fromuser is indexed, then
	// messages_by by msgid); the second is the posts the member took (messages_by by userid, then the
	// message by primary key). Both are bounded by the handover time.
	// keep-raw: a UNION ALL of two branches used as a derived table in FROM, with a correlated subquery in the select list; a GORM chain cannot render the union as a FROM source.
	db.Raw(`SELECT t.userid, t.msgid, m.subject, m.type, t.poster, t.timestamp, `+
		`COALESCE((SELECT i.name FROM messages_items mi INNER JOIN items i ON i.id = mi.itemid WHERE mi.msgid = m.id ORDER BY mi.itemid LIMIT 1), '') AS item `+
		`FROM (`+
		`SELECT mb.userid AS userid, mb.msgid AS msgid, 1 AS poster, mb.timestamp AS timestamp `+
		`FROM messages mp INNER JOIN messages_by mb ON mb.msgid = mp.id `+
		`WHERE mp.fromuser = ? AND mb.timestamp >= ? AND mb.userid IS NOT NULL AND mb.userid != ? `+
		`UNION ALL `+
		`SELECT mt.fromuser, mb.msgid, 0, mb.timestamp `+
		`FROM messages_by mb INNER JOIN messages mt ON mt.id = mb.msgid `+
		`WHERE mb.userid = ? AND mb.timestamp >= ? AND mt.fromuser IS NOT NULL AND mt.fromuser != ?`+
		`) t `+
		`INNER JOIN messages m ON m.id = t.msgid `+
		`INNER JOIN users u ON u.id = t.userid AND u.deleted IS NULL `+
		`LEFT JOIN ratings r ON r.rater = ? AND r.ratee = t.userid `+
		`WHERE r.id IS NULL `+
		`ORDER BY t.timestamp DESC `+
		`LIMIT 50`,
		myid, since, myid, myid, since, myid, myid).Scan(&rows)

	ret := make([]Unrated, 0)
	seen := map[uint64]bool{}

	for _, r := range rows {
		if seen[r.Userid] {
			continue
		}

		seen[r.Userid] = true

		// On an Offer the poster gave; on a Wanted the poster received.
		gave := (r.Type == "Offer") == r.Poster

		ret = append(ret, Unrated{
			Userid:    r.Userid,
			Msgid:     r.Msgid,
			Subject:   r.Subject,
			Item:      r.Item,
			Gave:      gave,
			Timestamp: r.Timestamp,
		})

		if len(ret) >= UNRATED_MAX {
			break
		}
	}

	return c.JSON(ret)
}

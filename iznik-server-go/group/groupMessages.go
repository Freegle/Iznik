package group

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"strconv"
	"time"
)

func GetGroupMessages(c *fiber.Ctx) error {
	var ret []uint64
	myid := user.WhoAmI(c)

	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)

	db := database.DBConn

	now := time.Now()
	then := now.AddDate(0, 0, -31)

	// We want to return messages which have no outcome or are successful (which will be shown by the client as
	// freegled) but not withdrawn messages.  We also add in our own posts that are still pending, so a member
	// can't tell their post is awaiting moderation - but NOT our own rejected posts: a rejected post has been
	// removed and must not leak back into the poster's browse feed.
	db.Raw("SELECT messages_groups.msgid FROM messages_groups "+
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid "+
		"INNER JOIN messages ON messages.id = messages_groups.msgid "+
		"INNER JOIN users ON users.id = messages.fromuser "+
		"WHERE groupid = ? AND messages_groups.arrival >= ? AND (collection = ? OR (messages.fromuser = ? AND collection != ?)) AND messages_groups.deleted = 0 AND users.deleted IS NULL AND (messages_outcomes.id IS NULL OR messages_outcomes.outcome IN (?, ?)) "+
		"ORDER BY messages_groups.arrival DESC", id, then.Format(time.RFC3339), utils.COLLECTION_APPROVED, myid, utils.COLLECTION_REJECTED, utils.OUTCOME_TAKEN, utils.OUTCOME_RECEIVED).Pluck("msgid", &ret)

	if ret == nil {
		ret = make([]uint64, 0)
	}

	return c.JSON(ret)
}

// GroupMessageSummary is just enough of a post to render a crawlable link to it.
type GroupMessageSummary struct {
	ID      uint64    `json:"id"`
	Subject string    `json:"subject"`
	Arrival time.Time `json:"arrival"`
}

// The community page renders this list server-side, so keep it to a page's worth.
const groupMessageSummaryLimit = 200

// GetGroupMessageSummaries returns id + subject for a community's live posts.
//
// This backs the server-rendered list of posts on /explore/<group>. That page's
// interactive content is all inside <client-only>, so the HTML a crawler receives
// used to contain no links to posts at all - which meant Google could only find a
// post by running our JavaScript, on a delayed second pass, and not for every
// community every time. A post could therefore surface under some communities and
// not the one it was actually posted to.
//
// Unlike GetGroupMessages this is anonymous: it never includes the caller's own
// pending posts, because it is rendered into a page that gets cached and served to
// everyone.
func GetGroupMessageSummaries(c *fiber.Ctx) error {
	id, _ := strconv.ParseUint(c.Params("id"), 10, 64)

	db := database.DBConn

	ret := []GroupMessageSummary{}

	now := time.Now()
	then := now.AddDate(0, 0, -31)

	db.Raw("SELECT messages.id, messages.subject, messages_groups.arrival FROM messages_groups "+
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages_groups.msgid "+
		"INNER JOIN messages ON messages.id = messages_groups.msgid "+
		"INNER JOIN users ON users.id = messages.fromuser "+
		"WHERE groupid = ? AND messages_groups.arrival >= ? AND collection = ? AND messages_groups.deleted = 0 "+
		"AND users.deleted IS NULL AND messages.deleted IS NULL AND messages_outcomes.id IS NULL "+
		"AND messages.type IN (?, ?) "+
		"ORDER BY messages_groups.arrival DESC LIMIT ?",
		id, then.Format(time.RFC3339), utils.COLLECTION_APPROVED,
		utils.OFFER, utils.WANTED, groupMessageSummaryLimit,
	).Scan(&ret)

	if ret == nil {
		ret = make([]GroupMessageSummary, 0)
	}

	return c.JSON(ret)
}

package changes

import (
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

type MessageChange struct {
	ID        uint64 `json:"id"`
	Timestamp string `json:"timestamp"`
	Type      string `json:"type"`
}

// UserChange is one user a partner should re-read - or, when Type is
// UserChangeDeleted, stop holding altogether.
type UserChange struct {
	ID          uint64  `json:"id"`
	LastUpdated *string `json:"lastupdated" gorm:"column:lastupdated"`
	Type        string  `json:"type"`
}

// MaxSinceLookback bounds how far back a partner may ask for changes.
//
// The queries below have no LIMIT: every matching row is materialised into a
// Go slice and then into a JSON body. On 2026-08-17 a single call with a
// `since` of 1947 made the UNION examine 17,870,331 rows over 130s, and the
// OOM killer took both apiv2 and monit with it on that node. 90 days holds the
// worst case to roughly 700k message rows - a few hundred MB, which the box
// absorbs without noticing.
const MaxSinceLookback = 90 * 24 * time.Hour

const (
	// UserChangeModified - the user's profile has changed; re-read it.
	UserChangeModified = "Modified"

	// UserChangeDeleted - the user has been forgotten or purged. Delete your
	// copy: the id is all you get, because everything else about them is gone.
	UserChangeDeleted = "Deleted"
)

type Rating struct {
	ID         uint64  `json:"id"`
	Rater      uint64  `json:"rater"`
	Ratee      uint64  `json:"ratee"`
	Rating     string  `json:"rating"`
	Timestamp  string  `json:"timestamp"`
	Visible    int     `json:"visible"`
	TnRatingID *uint64 `json:"tn_rating_id"`
	Comment    *string `json:"comment" gorm:"column:text"`
	Reason     *string `json:"reason" gorm:"column:reason"`
}

// ChangesData contains the three collections of changes, plus the window they
// were actually taken from - which is not necessarily the one that was asked
// for, since `since` is clamped to MaxSinceLookback.
type ChangesData struct {
	Since    string          `json:"since"`
	Messages []MessageChange `json:"messages"`
	Users    []UserChange    `json:"users"`
	Ratings  []Rating        `json:"ratings"`
}

// ChangesResponse is the top-level response for the changes endpoint.
type ChangesResponse struct {
	Ret     int         `json:"ret" example:"0"`
	Status  string      `json:"status" example:"Success"`
	Changes ChangesData `json:"changes"`
}

// GetChanges returns message changes, user changes, and optionally ratings since a given time.
// Requires partner key authentication via the partner query parameter.
// @Summary Get changes since a timestamp
// @Description Returns message changes (deleted, edited, promised, reneged, outcomes, approved/reposted), user changes (type Modified for a profile to re-read, type Deleted for a user who has been forgotten or purged and should be removed), and ratings since a given time. Requires partner key authentication. A since more than 90 days old is clamped to 90 days; changes.since reports the window actually used.
// @Tags changes
// @Produce json
// @Param since query string false "ISO8601 or MySQL datetime timestamp (defaults to 1 hour ago, clamped to at most 90 days ago)" example("2026-03-04T12:00:00Z")
// @Param partner query string true "Partner API key"
// @Success 200 {object} ChangesResponse
// @Failure 400 {object} fiber.Error "Invalid since parameter"
// @Failure 403 {object} fiber.Error "Invalid or missing partner key"
// @Router /api/changes [get]
func GetChanges(c *fiber.Ctx) error {
	// Partner authentication is required.
	partner := c.Query("partner", "")
	if partner == "" {
		return fiber.NewError(fiber.StatusForbidden, "Partner key required")
	}

	db := database.DBConn

	var partnerID uint64
	db.Table("partners_keys").Select("id").Where("`key` = ?", partner).Scan(&partnerID)

	if partnerID == 0 {
		return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
	}

	since, err := resolveSince(c.Query("since", ""), time.Now())
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid since parameter")
	}

	mysqlTime := since.Format("2006-01-02 15:04:05")

	// Fetch message changes, user changes, and ratings in parallel.
	var messages []MessageChange
	var users []UserChange
	var deletions []UserChange
	var ratings []Rating
	var wg sync.WaitGroup

	wg.Add(4)

	go func() {
		defer wg.Done()
		// Top-level
		// UNION, nothing wrapping it: BuildClauses={"SELECT"} suppresses the
		// FROM GORM would otherwise inject once .Table() is called, so the
		// whole "SELECT ... UNION SELECT ..." text can be given to .Select()
		// as one fragment - the first SELECT keyword comes from the clause
		// itself (see amp.go's bare-EXISTS conversions for the same
		// mechanism), every subsequent one is literal text in the fragment.
		tx := db.Table("messages").Select(
			"id, deleted AS timestamp, 'Deleted' AS `type` FROM messages WHERE deleted > ? "+
				"UNION SELECT msgid AS id, timestamp, outcome AS `type` FROM messages_outcomes WHERE timestamp > ? "+
				"UNION SELECT messages_edits.msgid AS id, timestamp, 'Edited' AS `type` FROM messages_edits "+
				"INNER JOIN messages_groups ON messages_groups.msgid = messages_edits.msgid AND collection = ? WHERE timestamp > ? "+
				"UNION SELECT msgid AS id, promisedat AS timestamp, 'Promised' AS `type` FROM messages_promises WHERE promisedat > ? "+
				"UNION SELECT msgid AS id, timestamp, 'Reneged' AS `type` FROM messages_reneged WHERE timestamp > ? "+
				// FORCE INDEX: left alone the optimiser takes the `collection`
				// index and filters 4.5M Approved rows by arrival, which is the
				// whole cost of this endpoint - 70.9s against 8.6s forced, on a
				// 90-day window, measured on prod 2026-08-18. `arrival` is
				// (arrival, groupid, msgtype), so the range scan uses its
				// leading column. If that index is ever renamed or dropped this
				// becomes MySQL 1176 rather than a silent slowdown; the
				// integration tests run the same statement and would catch it.
				"UNION SELECT msgid AS id, arrival AS timestamp, 'ApprovedOrReposted' AS `type` FROM messages_groups FORCE INDEX (arrival) "+
				"WHERE messages_groups.arrival > ? AND messages_groups.collection = ?",
			mysqlTime, mysqlTime, utils.COLLECTION_APPROVED, mysqlTime, mysqlTime, mysqlTime, mysqlTime, utils.COLLECTION_APPROVED)
		tx.Statement.BuildClauses = []string{"SELECT"}
		tx.Scan(&messages)
	}()

	go func() {
		defer wg.Done()
		db.Table("users").Select("id, lastupdated, ? AS `type`", UserChangeModified).
			Where("lastupdated IS NOT NULL AND lastupdated >= ?", mysqlTime).Scan(&users)
	}()

	go func() {
		defer wg.Done()
		// Users we have destroyed. Read from a tombstone table rather than from
		// users, because the point at which a partner most needs to know is the
		// point at which the row itself has gone.
		db.Table("users_deletions").Select("userid AS id, timestamp AS lastupdated, ? AS `type`", UserChangeDeleted).
			Where("timestamp >= ?", mysqlTime).Scan(&deletions)
	}()

	go func() {
		defer wg.Done()
		db.Table("ratings").
			Select("id, rater, ratee, rating, timestamp, visible, tn_rating_id, text, reason").
			Where("timestamp >= ? AND visible = 1", mysqlTime).
			Scan(&ratings)
	}()

	wg.Wait()

	// Deletions go last so that a partner applying the list in order finishes on
	// the deletion, rather than resurrecting someone whose profile also changed
	// within the window.
	users = append(users, deletions...)

	// Format timestamps to ISO8601.
	for i := range messages {
		messages[i].Timestamp = formatISO(messages[i].Timestamp)
	}
	for i := range users {
		if users[i].LastUpdated != nil {
			formatted := formatISO(*users[i].LastUpdated)
			users[i].LastUpdated = &formatted
		}
	}
	for i := range ratings {
		ratings[i].Timestamp = formatISO(ratings[i].Timestamp)
	}

	// Ensure empty arrays rather than null in JSON.
	if messages == nil {
		messages = make([]MessageChange, 0)
	}
	if users == nil {
		users = make([]UserChange, 0)
	}
	if ratings == nil {
		ratings = make([]Rating, 0)
	}

	return c.JSON(fiber.Map{
		"ret":    0,
		"status": "Success",
		"changes": fiber.Map{
			"since":    since.Format(time.RFC3339),
			"messages": messages,
			"users":    users,
			"ratings":  ratings,
		},
	})
}

// resolveSince turns the partner-supplied since parameter into the timestamp we
// actually query from: an hour ago when it is absent, and never further back
// than MaxSinceLookback however far back they ask. A partner who asks for more
// silently gets less than they wanted, which risks them treating the result as
// complete - so the window we settle on is echoed back in the response.
func resolveSince(sinceStr string, now time.Time) (time.Time, error) {
	since := now.Add(-1 * time.Hour)

	if sinceStr != "" {
		parsed, err := time.Parse(time.RFC3339, sinceStr)
		if err != nil {
			// Try MySQL-style datetime format.
			parsed, err = time.Parse("2006-01-02 15:04:05", sinceStr)
			if err != nil {
				return time.Time{}, err
			}
		}
		since = parsed
	}

	if earliest := now.Add(-MaxSinceLookback); since.Before(earliest) {
		since = earliest
	}

	return since, nil
}

// formatISO converts a MySQL datetime string to ISO8601 format.
func formatISO(mysqlTime string) string {
	t, err := time.Parse("2006-01-02 15:04:05", mysqlTime)
	if err != nil {
		return mysqlTime
	}
	return t.Format(time.RFC3339)
}

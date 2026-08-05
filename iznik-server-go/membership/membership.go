package membership

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// logMembershipAction inserts a mod log entry for membership actions.
func logMembershipAction(logType string, subtype string, groupid uint64, userid uint64, byuser uint64, text string) {
	var textPtr *string
	if text != "" {
		textPtr = &text
	}
	log.Log(log.LogEntry{
		Type:    logType,
		Subtype: subtype,
		Groupid: &groupid,
		User:    &userid,
		Byuser:  &byuser,
		Text:    textPtr,
	})
}

// getRoleForGroup returns the caller's role in the given group ("Owner", "Moderator", "Member"),
// or "" if not an approved member. Admin/Support users are treated as Owner.
func getRoleForGroup(myid uint64, groupid uint64) string {
	if auth.IsAdminOrSupport(myid) {
		return utils.ROLE_OWNER
	}
	db := database.DBConn
	var role string
	db.Table("memberships").Select("role").Where("userid = ? AND groupid = ? AND collection = ?",
		myid, groupid, utils.COLLECTION_APPROVED).Scan(&role)
	return role
}

// isModOfGroup checks if the caller is a Moderator or Owner of the given group,
// or has Admin/Support system role.
func isModOfGroup(myid uint64, groupid uint64) bool {
	db := database.DBConn
	if db == nil {
		return false
	}

	if auth.IsAdminOrSupport(myid) {
		return true
	}

	if groupid == 0 {
		return false
	}

	var role string
	result := db.Table("memberships").Select("role").Where("userid = ? AND groupid = ? AND collection = ?",
		myid, groupid, utils.COLLECTION_APPROVED).Scan(&role)
	if result.Error != nil {
		stdlog.Printf("Failed to check mod role for user %d group %d: %v", myid, groupid, result.Error)
		return false
	}
	return role == utils.ROLE_MODERATOR || role == utils.ROLE_OWNER
}

// PostMembershipsRequest is the body for POST /memberships (moderator actions).
type PostMembershipsRequest struct {
	Userid    uint64  `json:"userid"`
	Groupid   uint64  `json:"groupid"`
	Action    string  `json:"action"`
	Subject   *string `json:"subject"`
	Body      *string `json:"body"`
	Stdmsgid  *uint64 `json:"stdmsgid"`
	Ban       *bool   `json:"ban"`
	Happiness *string `json:"happiness"`
}

// PostMemberships handles POST /memberships - moderator actions on memberships.
// Actions: Hold, Release, Approve, Leave Approved Member, Reject,
// Delete Approved Member, Ban, Unban, ReviewHold, ReviewRelease, ReviewIgnore, HappinessReviewed.
//
// @Summary Update membership actions
// @Tags membership
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/memberships [post]
func PostMemberships(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PostMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	if req.Userid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "userid is required")
	}

	if req.Action == "" {
		return fiber.NewError(fiber.StatusBadRequest, "action is required")
	}

	// Permission check: caller must be mod/owner of group or admin/support.
	if !isModOfGroup(myid, req.Groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
	}

	db := database.DBConn

	// A hold on a membership is an exclusive claim, and was advisory only: ModTools
	// shows "Held by X" but nothing stopped another mod acting from a stale screen.
	// Refuse the actions that decide the membership, and refuse taking the hold off
	// someone. Release/ReviewRelease are deliberately absent - they are the escape
	// hatch when the holder is away.
	switch req.Action {
	case "Approve", "Reject", "Delete Approved Member", "Ban", "Hold", "ReviewHold", "ReviewIgnore":
		var holder uint64
		db.Table("memberships").Select("COALESCE(heldby, 0)").Where("userid = ? AND groupid = ?",
			req.Userid, req.Groupid).Scan(&holder)
		if holder != 0 && holder != myid {
			var holderName string
			db.Table("users").Select("fullname").Where("id = ?", holder).Scan(&holderName)
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{
				"ret":        1,
				"status":     "Held by another moderator",
				"heldby":     holder,
				"heldbyname": holderName,
			})
		}
	}

	switch req.Action {
	case "Hold":
		if result := db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Update("heldby", myid); result.Error != nil {
			stdlog.Printf("Failed to hold membership user %d group %d: %v", req.Userid, req.Groupid, result.Error)
		}
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_HOLD, req.Groupid, req.Userid, myid, "")
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Release":
		db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Update("heldby", gorm.Expr("NULL"))
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_RELEASE, req.Groupid, req.Userid, myid, "")
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Leave Member", "Leave Approved Member":
		// send modmail to the member without changing membership status.
		// PHP memberships.php line 291-294: just calls $u->mail().
		subject := ""
		if req.Subject != nil {
			subject = *req.Subject
		}
		body := ""
		if req.Body != nil {
			body = *req.Body
		}
		stdmsgid := uint64(0)
		if req.Stdmsgid != nil {
			stdmsgid = *req.Stdmsgid
		}
		// normaliseColumnOrder handles
		// the map-Create column reorder (data, task_type) against a JSON_OBJECT
		// value; see ormharness/normalise_test.go TestNormaliseColumnOrder_InsertWithNestedFunctionArgs.
		db.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_mod_stdmsg",
			"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				req.Userid, req.Groupid, myid, subject, body, stdmsgid, "Leave Approved Member"),
		})
		// V1 parity: Leave Approved Member only calls $u->mail(), no log entry.
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Approve":
		// Map keys "collection" and
		// "heldby" already sort alphabetically in that order, so Updates(map)
		// emits the same SET order as the golden without needing an approved diff.
		if result := db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Updates(map[string]interface{}{"collection": utils.COLLECTION_APPROVED, "heldby": gorm.Expr("NULL")}); result.Error != nil {
			stdlog.Printf("Failed to approve membership user %d group %d: %v", req.Userid, req.Groupid, result.Error)
		}

		// Queue welcome/approval email if stdmsg content provided.
		subject := ""
		if req.Subject != nil {
			subject = *req.Subject
		}
		body := ""
		if req.Body != nil {
			body = *req.Body
		}
		if subject != "" || body != "" {
			db.Table("background_tasks").Create(map[string]interface{}{
				"task_type": "email_mod_stdmsg",
				"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
					req.Userid, req.Groupid, myid, subject, body, 0, "Approve Member"),
			})
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Reject", "Delete Approved Member":
		if result := db.Table("memberships").Where("userid = ? AND groupid = ? AND collection IN (?, ?)",
			req.Userid, req.Groupid, utils.COLLECTION_PENDING, utils.COLLECTION_APPROVED).Delete(nil); result.Error != nil {
			stdlog.Printf("Failed to reject membership user %d group %d: %v", req.Userid, req.Groupid, result.Error)
		}

		// Queue rejection notification if stdmsg content provided.
		subject := ""
		if req.Subject != nil {
			subject = *req.Subject
		}
		body := ""
		if req.Body != nil {
			body = *req.Body
		}
		stdmsgid := uint64(0)
		if req.Stdmsgid != nil {
			stdmsgid = *req.Stdmsgid
		}
		if subject != "" || body != "" {
			db.Table("background_tasks").Create(map[string]interface{}{
				"task_type": "email_mod_stdmsg",
				"data": gorm.Expr("JSON_OBJECT('userid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
					req.Userid, req.Groupid, myid, subject, body, stdmsgid, req.Action),
			})
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Ban":
		// V1 parity: removeMembership($ban=true) deletes the memberships row entirely, then
		// writes to users_banned. There is no memberships.collection='Banned' row in V1.
		// Converted together with its
		// identical twin in DeleteMemberships (d60d7b2e0f2a): a half-converted
		// pair renumbers the survivor's site ID, so gate (h) refuses the split state.
		if result := db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Delete(nil); result.Error != nil {
			stdlog.Printf("Failed to delete membership for ban user %d group %d: %v", req.Userid, req.Groupid, result.Error)
		}
		// Converted together with its
		// identical twin in DeleteMemberships (d788d299a578): a half-converted
		// pair renumbers the survivor's site ID, so gate (h) refuses the split
		// state.
		db.Table("users_banned").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "byuser"}, Value: clause.Column{Table: "excluded", Name: "byuser"}},
				{Column: clause.Column{Name: "date"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"userid": req.Userid, "groupid": req.Groupid, "byuser": myid,
		})
		// V1 parity: removeMembership($ban=true) logs type=Group/subtype=Left/text="via ban"
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_LEFT, req.Groupid, req.Userid, myid, "via ban")
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "Unban":
		// V1 parity: unban() deletes from users_banned only — there is no memberships row to delete.
		db.Table("users_banned").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).Delete(nil)
		// V1 parity: unban() does not log.
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "ReviewHold":
		// ReviewHold is used in the chat review context - sets heldby on the membership.
		db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Update("heldby", myid)
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "ReviewRelease":
		// ReviewRelease clears the heldby on the membership (chat review context).
		db.Table("memberships").Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Update("heldby", gorm.Expr("NULL"))
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "ReviewIgnore":
		// Per-group: mods on adjacent communities make independent decisions (Discourse 9618 #8).
		// Closing the review is terminal for THIS group, so drop our own hold with it -
		// otherwise the row keeps a heldby that nothing clears, and the member shows as
		// held again the next time they are flagged. Only this group's row is touched,
		// so a hold on an adjacent community is left alone.
		//
		// Updates(map) emits the SET
		// list alphabetically, which is not the golden's order; the harness
		// normalises column order on both sides, moving each column with its
		// value, so the pairing is still proved.
		db.Table("memberships").
			Where("userid = ? AND groupid = ?", req.Userid, req.Groupid).
			Updates(map[string]interface{}{
				"reviewedat":        gorm.Expr("NOW()"),
				"reviewrequestedat": gorm.Expr("NULL"),
				"heldby":            gorm.Expr("NULL"),
			})
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	case "HappinessReviewed":
		if req.Happiness == nil {
			return fiber.NewError(fiber.StatusBadRequest, "happiness is required for HappinessReviewed")
		}
		happinessID, err := strconv.ParseUint(*req.Happiness, 10, 64)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "happiness must be a valid ID")
		}
		db.Table("messages_outcomes").Where("id = ?", happinessID).Update("reviewed", gorm.Expr("1"))
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})

	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action: "+req.Action)
	}
}

// GetMembershipsMember is the response struct for individual members in GetMemberships.
type GetMembershipsMember struct {
	ID                  uint64                  `json:"id"`
	Userid              uint64                  `json:"userid"`
	Groupid             uint64                  `json:"groupid"`
	Role                string                  `json:"role"`
	Collection          string                  `json:"collection"`
	Added               *string                 `json:"added"`
	Heldby              *uint64                 `json:"heldby"`
	Fullname            *string                 `json:"fullname"`
	Firstname           *string                 `json:"firstname"`
	Lastname            *string                 `json:"lastname"`
	Displayname         string                  `json:"displayname" gorm:"-"`
	SettingsRaw         *string                 `json:"-" gorm:"column:settings"`
	Settings            *map[string]interface{} `json:"settings,omitempty" gorm:"-"`
	Emailfrequency      *int                    `json:"emailfrequency"`
	OurPostingStatus    *string                 `json:"ourpostingstatus" gorm:"column:ourPostingStatus"`
	Eventsallowed       *int                    `json:"eventsallowed"`
	Volunteeringallowed *int                    `json:"volunteeringallowed"`
	Bandate             *string                 `json:"bandate"`
	Bannedby            *uint64                 `json:"bannedby"`
	Reviewrequestedat   *string                 `json:"reviewrequestedat"`
	Reviewedat          *string                 `json:"reviewedat"`
	Reviewreason        *string                 `json:"reviewreason"`
	Engagement          *string                 `json:"engagement"`
	Lastmodmail         *string                 `json:"lastmodmail,omitempty"`
	Bouncing            bool                    `json:"bouncing" gorm:"column:bouncing"`
}

// GetMemberships handles GET /memberships - list group members (moderator use).
// Query params: groupid (required for most collections), collection (default "Approved"), limit (default 100), search (optional).
// Special collection "Happiness" queries messages_outcomes instead of memberships.
//
// @Summary Get memberships for modtools
// @Tags membership
// @Produce json
// @Param groupid query integer false "Group ID"
// @Param collection query string false "Collection"
// @Param limit query integer false "Max to return"
// @Param context query integer false "Pagination cursor"
// @Success 200 {object} map[string]interface{}
// @Router /api/memberships [get]
func GetMemberships(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	groupid := uint64(c.QueryInt("groupid", 0))
	collection := c.Query("collection", "Approved")
	limit := c.QueryInt("limit", 100)

	if collection == "Happiness" {
		return getHappinessMembers(c, myid, groupid, limit)
	}

	if collection == "Spam" {
		// Member review: members flagged for review across all mod groups.
		return getSpamMembers(c, myid, groupid, limit)
	}

	if collection == "Related" {
		return getRelatedMembers(c, myid, groupid, limit)
	}

	search := c.Query("search", "")
	filter := c.QueryInt("filter", 0)
	contextID := uint64(c.QueryInt("context", 0))

	if groupid == 0 {
		if search == "" && filter == 0 {
			// No group, no search, no filter — return empty list.
			return c.JSON([]GetMembershipsMember{})
		}
		// filter or search with no specific group — fan out to all of mod's groups.
		// Fall through.
	} else if !isModOfGroup(myid, groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
	}

	db := database.DBConn

	// Handle Banned filter separately — V1 stores bans in users_banned only (no memberships row).
	// V1's getBanned() queries users_banned directly and synthesises 'Banned' as the collection.
	// Cursor-based pagination uses b.userid (returned as id) so callers can page through all bans.
	if filter == 5 {
		// contextID>0
		// is the only toggle - 2 possible rendered forms, both declared in
		// ormharness/shapes.json and proven by TestTier3Shapes_2c3b155f346b
		// (iznik-server-go/test).
		var members []GetMembershipsMember
		bannedTx := db.Table("users_banned b").
			Select("b.userid, b.groupid, 'Member' AS role, 'Banned' AS collection, "+
				"b.date AS added, b.date AS bandate, b.byuser AS bannedby, "+
				"u.fullname, u.firstname, u.lastname, u.engagement, "+
				"b.userid AS id, NULL AS heldby, NULL AS settings, "+
				"0 AS emailfrequency, 'DEFAULT' AS ourPostingStatus, 0 AS eventsallowed, 0 AS volunteeringallowed, "+
				"NULL AS reviewrequestedat, NULL AS reviewedat, NULL AS reviewreason").
			Joins("JOIN users u ON u.id = b.userid").
			Where("b.groupid = ?", groupid)
		if contextID > 0 {
			bannedTx = bannedTx.Where("b.userid < ?", contextID)
		}
		bannedTx.Order("b.userid DESC").Limit(limit).Scan(&members)
		if members == nil {
			members = make([]GetMembershipsMember, 0)
		}
		enrichMembers(members)

		var nextContext interface{}
		if len(members) == limit {
			nextContext = members[len(members)-1].ID
		}
		return c.JSON(fiber.Map{"members": members, "context": nextContext})
	}

	// Handle "Received mod mails" filter — returns members who have been sent mod mails,
	// ordered by join date (m.added DESC) to match the default listing order.
	if filter == 6 {
		var members []GetMembershipsMember
		// Join logs instead of users_modmails: users_modmails is pruned at 30 days by the
		// users:update-modmails cron, so an INNER JOIN on it silently drops members whose
		// only modmails are older than ~30 days.  The logs table is not pruned and is the
		// authoritative source; criteria match the modmailsonly filter in logs/logs.go.
		db.Table("memberships m").
			Select("m.id, m.userid, m.groupid, m.role, m.collection, m.added, m.heldby, "+
				"u.fullname, u.firstname, u.lastname, m.settings, "+
				"m.emailfrequency, m.ourPostingStatus, m.eventsallowed, m.volunteeringallowed, "+
				"b.date AS bandate, b.byuser AS bannedby, "+
				"m.reviewrequestedat, m.reviewedat, m.reviewreason, u.engagement, "+
				"MAX(l.timestamp) AS lastmodmail").
			Joins("JOIN users u ON u.id = m.userid").
			Joins("LEFT JOIN users_banned b ON b.userid = m.userid AND b.groupid = m.groupid").
			Joins("INNER JOIN logs l ON l.user = m.userid AND l.groupid = m.groupid "+
				"AND ((l.type = 'Message' AND l.subtype IN ('Rejected', 'Deleted', 'Replied')) "+
				"OR (l.type = 'User' AND l.subtype IN ('Mailed', 'Rejected', 'Deleted'))) "+
				"AND l.byuser != l.user").
			Where("m.groupid = ? AND m.collection = ?", groupid, collection).
			Group("m.userid").
			Order("m.added DESC").
			Limit(limit).
			Scan(&members)
		if members == nil {
			members = make([]GetMembershipsMember, 0)
		}
		enrichMembers(members)
		return c.JSON(members)
	}

	var members []GetMembershipsMember

	selectCols := "m.id, m.userid, m.groupid, m.role, m.collection, m.added, m.heldby, " +
		"u.fullname, u.firstname, u.lastname, m.settings, " +
		"m.emailfrequency, m.ourPostingStatus, m.eventsallowed, m.volunteeringallowed, " +
		"b.date AS bandate, b.byuser AS bannedby, " +
		"m.reviewrequestedat, m.reviewedat, m.reviewreason, u.engagement, u.bouncing"

	// filterWhereSQL returns the filter-specific WHERE fragment (with
	// comments/notes, moderation team, bouncing, or none) - one of 4 fixed
	// shapes - as a string with no args of its own (the moderation-team
	// role names are baked into the literal text, same as the original raw
	// SQL did).
	filterWhereSQL := func() string {
		switch filter {
		case 1: // With comments/notes — use EXISTS to avoid row multiplication from multi-note members
			return " AND EXISTS (SELECT 1 FROM users_comments uc WHERE uc.userid = m.userid AND uc.groupid = m.groupid)"
		case 2: // Moderation team
			return " AND m.role IN ('" + utils.ROLE_OWNER + "', '" + utils.ROLE_MODERATOR + "')"
		case 3: // Bouncing
			return " AND u.bouncing = 1"
		}
		return ""
	}

	// groupFilterSQL returns the group-scope WHERE fragment - a specific
	// group, or every group the caller moderates when groupid==0 - the
	// other fixed toggle, plus its own arg (if any).
	groupFilterSQL := func() (string, []interface{}) {
		if groupid == 0 {
			return "m.groupid IN (SELECT groupid FROM memberships WHERE userid = ? AND role IN ('" +
				utils.ROLE_MODERATOR + "', '" + utils.ROLE_OWNER + "') AND collection = '" + utils.COLLECTION_APPROVED + "')", []interface{}{myid}
		}
		return "m.groupid = ?", []interface{}{groupid}
	}

	baseTx := func() *gorm.DB {
		return db.Table("memberships m").
			Select(selectCols).
			Joins("JOIN users u ON u.id = m.userid").
			Joins("LEFT JOIN users_banned b ON b.userid = m.userid AND b.groupid = m.groupid")
	}

	// The WHERE for each branch below is built as a single string and
	// passed to ONE Where() call: GORM's clause.Where wraps any fragment
	// containing "AND"/"OR" in an extra paren pair once there is more than
	// one Where expression to combine (clause/where.go buildExprs), which
	// would diverge from the golden.
	if search != "" {
		groupWhere, groupArgs := groupFilterSQL()

		// If search is a pure number, match on userid directly (fast indexed lookup).
		// Otherwise do LIKE search on name/email.
		searchID, numErr := strconv.ParseUint(search, 10, 64)
		if numErr == nil && searchID > 0 {
			// groupid==0
			// and the filter (0-3) give 2x4 = 8 possible rendered forms, all
			// declared in ormharness/shapes.json and proven by
			// TestTier3Shapes_836dc8807739 (iznik-server-go/test).
			whereSQL := groupWhere + " AND m.collection = ?" + filterWhereSQL() + " AND m.userid = ?"
			whereArgs := append(append([]interface{}{}, groupArgs...), collection, searchID)
			baseTx().Where(whereSQL, whereArgs...).
				Order("m.added DESC").Limit(limit).Scan(&members)
		} else {
			searchPattern := "%" + search + "%"
			// Match firstname/lastname as well as fullname: some members (e.g. LoveJunk
			// users, created with fullname=NULL) have their name only in firstname/lastname,
			// so a fullname-only LIKE silently excludes them from name search even though
			// enrichMembers builds their displayname from those columns. (Discourse 9518/371)
			//
			// Same
			// groupid==0 x filter toggles as 836dc8807739 above - 8 possible
			// rendered forms, all declared in ormharness/shapes.json and proven
			// by TestTier3Shapes_5f742c0fcf1f (iznik-server-go/test).
			whereSQL := groupWhere + " AND m.collection = ?" + filterWhereSQL() +
				" AND (u.fullname LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? OR ue.email LIKE ?)"
			whereArgs := append(append([]interface{}{}, groupArgs...), collection,
				searchPattern, searchPattern, searchPattern, searchPattern)
			baseTx().Joins("LEFT JOIN users_emails ue ON ue.userid = m.userid").
				Where(whereSQL, whereArgs...).
				Group("m.id").Order("m.added DESC").Limit(limit).Scan(&members)
		}
	} else {
		// Cursor-based pagination: m.id is the cursor (auto-increment correlates with join date).
		// ORDER BY m.id DESC for deterministic per-page slicing consistent with the cursor.
		//
		// groupid==0,
		// the filter (0-3), and contextID>0 give 2x4x2 = 16 possible rendered
		// forms, all declared in ormharness/shapes.json and proven by
		// TestTier3Shapes_bbc55cf96110 (iznik-server-go/test).
		groupWhere, groupArgs := groupFilterSQL()
		whereSQL := groupWhere + " AND m.collection = ?" + filterWhereSQL()
		whereArgs := append(append([]interface{}{}, groupArgs...), collection)
		if contextID > 0 {
			whereSQL += " AND m.id < ?"
			whereArgs = append(whereArgs, contextID)
		}
		result := baseTx().Where(whereSQL, whereArgs...).Order("m.id DESC").Limit(limit).Scan(&members)
		if result.Error != nil {
			stdlog.Printf("Failed to query memberships group %d collection %s: %v", groupid, collection, result.Error)
		}
	}

	if members == nil {
		members = make([]GetMembershipsMember, 0)
	}

	enrichMembers(members)

	// Return pagination context = last member's ID when a full page was returned (cursor for next page).
	var nextContext interface{}
	if len(members) == limit {
		nextContext = members[len(members)-1].ID
	}

	// When a filter is active, include the total matching count so the UI can display it.
	//
	// groupid==0 and
	// the filter (0-3, though this block only ever runs for filter>0) give
	// 2x4 = 8 possible rendered forms, all declared in ormharness/shapes.json
	// and proven by TestTier3Shapes_5f6ca1b9022f (iznik-server-go/test).
	if filter > 0 {
		var filterCount int64

		groupWhere, groupArgs := groupFilterSQL()
		whereSQL := groupWhere + " AND m.collection = ?" + filterWhereSQL()
		whereArgs := append(append([]interface{}{}, groupArgs...), collection)
		db.Table("memberships m").
			Select("COUNT(DISTINCT m.userid)").
			Joins("JOIN users u ON u.id = m.userid").
			Where(whereSQL, whereArgs...).Scan(&filterCount)

		return c.JSON(fiber.Map{
			"members":     members,
			"filtercount": filterCount,
			"context":     nextContext,
		})
	}

	return c.JSON(fiber.Map{
		"members": members,
		"context": nextContext,
	})
}

// enrichMembers computes displayname from name fields, resolves posting status, and parses settings JSON.
func enrichMembers(members []GetMembershipsMember) {
	for i := range members {
		m := &members[i]

		// Compute displayname from fullname/firstname/lastname.
		if m.Fullname != nil && *m.Fullname != "" {
			m.Displayname = *m.Fullname
		} else {
			parts := []string{}
			if m.Firstname != nil && *m.Firstname != "" {
				parts = append(parts, *m.Firstname)
			}
			if m.Lastname != nil && *m.Lastname != "" {
				parts = append(parts, *m.Lastname)
			}
			m.Displayname = strings.Join(parts, " ")
		}

		// NULL ourPostingStatus defaults to MODERATED.
		// DEFAULT stays as DEFAULT — it's an explicit status (Group.php line 967).
		if m.OurPostingStatus == nil || *m.OurPostingStatus == "" {
			moderated := utils.POSTING_STATUS_MODERATED
			m.OurPostingStatus = &moderated
		}

		// Parse settings JSON.
		if m.SettingsRaw != nil && *m.SettingsRaw != "" {
			var settings map[string]interface{}
			if json.Unmarshal([]byte(*m.SettingsRaw), &settings) == nil {
				m.Settings = &settings
			}
		}
	}
}

// getSpamMembers returns members flagged for review (reviewrequestedat IS NOT NULL and
// the flag is more recent than the last review, or never reviewed).
// Only returns memberships on groups the viewer moderates.
// Used by the Member Review page.
func getSpamMembers(c *fiber.Ctx, myid uint64, groupid uint64, limit int) error {
	db := database.DBConn

	// Get all groups this user moderates.
	var modGroupIDs []uint64

	if groupid > 0 {
		if !isModOfGroup(myid, groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		modGroupIDs = []uint64{groupid}
	} else {
		modGroupIDs = user.GetActiveModGroupIDs(myid)
	}

	if len(modGroupIDs) == 0 {
		return c.JSON(make([]GetMembershipsMember, 0))
	}

	// Return flagged memberships on groups the mod moderates.
	//
	// Show members where reviewrequestedat is set AND either never reviewed or the flag
	// is more recent than the last review. This matches the frontend needsReview logic
	// exactly, preventing "no buttons" where the backend returns a member but the
	// frontend considers them already reviewed.
	//
	// GORM's native
	// "IN ?" slice-bind already normalizes the group-id list regardless of
	// length, so this has exactly one rendered form, declared in
	// ormharness/shapes.json and proven by TestTier3Shapes_fdd14a1656c7
	// (iznik-server-go/test).
	var members []GetMembershipsMember
	result := db.Table("memberships m").
		Select("m.id, m.userid, m.groupid, m.role, m.collection, m.added, m.heldby, "+
			"u.fullname, u.firstname, u.lastname, m.settings, "+
			"m.emailfrequency, m.ourPostingStatus, m.eventsallowed, m.volunteeringallowed, "+
			"b.date AS bandate, b.byuser AS bannedby, "+
			"m.reviewrequestedat, m.reviewedat, m.reviewreason, u.engagement, u.bouncing").
		Joins("JOIN users u ON u.id = m.userid").
		Joins("LEFT JOIN users_banned b ON b.userid = m.userid AND b.groupid = m.groupid").
		Where("m.groupid IN ? AND m.reviewrequestedat IS NOT NULL AND (m.reviewedat IS NULL OR m.reviewrequestedat > m.reviewedat)", modGroupIDs).
		Order("m.userid DESC").Limit(limit).Scan(&members)
	if result.Error != nil {
		stdlog.Printf("Failed to query spam members for user %d: %v", myid, result.Error)
	}

	if members == nil {
		members = make([]GetMembershipsMember, 0)
	}

	enrichMembers(members)

	return c.JSON(members)
}

// getRelatedMembers returns pairs of users who appear to be related (same person / same household)
// based on the users_related table. Returns IDs only — frontend fetches user details from stores.
// User.php listRelated() filtering logic (deleted, no logins → auto-notified).
func getRelatedMembers(c *fiber.Ctx, myid uint64, groupid uint64, limit int) error {
	db := database.DBConn

	var modGroupIDs []uint64
	if groupid > 0 {
		if !isModOfGroup(myid, groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		modGroupIDs = []uint64{groupid}
	} else {
		modGroupIDs = user.GetActiveModGroupIDs(myid)
	}

	if len(modGroupIDs) == 0 {
		return c.JSON(make([]fiber.Map, 0))
	}

	// Query related pairs where at least one user is in a modded group.
	// user1 < user2, notified = 0.
	type relatedRow struct {
		ID    uint64 `gorm:"column:id"`
		User1 uint64 `gorm:"column:user1"`
		User2 uint64 `gorm:"column:user2"`
	}

	var rows []relatedRow
	// Derived-table trick: GORM's
	// Table() passes its name argument through verbatim (no quoting) once it
	// contains a space, so a parenthesized UNION subquery can be given as the
	// "table name" with its own bind args in Table()'s variadic args.
	db.Table("(SELECT users_related.id, user1, user2 FROM users_related "+
		"INNER JOIN memberships ON users_related.user1 = memberships.userid "+
		"INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL AND u1.systemrole = 'User' "+
		"INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL "+
		"WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ? "+
		"UNION "+
		"SELECT users_related.id, user1, user2 FROM users_related "+
		"INNER JOIN memberships ON users_related.user2 = memberships.userid "+
		"INNER JOIN users u1 ON users_related.user1 = u1.id AND u1.deleted IS NULL "+
		"INNER JOIN users u2 ON users_related.user2 = u2.id AND u2.deleted IS NULL AND u2.systemrole = 'User' "+
		"WHERE user1 < user2 AND notified = 0 AND memberships.groupid IN ?) t",
		modGroupIDs, modGroupIDs).
		Select("DISTINCT id, user1, user2").
		Order("id DESC").
		Limit(limit).
		Scan(&rows)

	if len(rows) == 0 {
		return c.JSON(make([]fiber.Map, 0))
	}

	// filter out pairs where either user has no logins (can't log in).
	// The SQL JOINs already filter deleted users and non-User systemroles.
	// Check logins in bulk.
	uidSet := make(map[uint64]bool)
	for _, r := range rows {
		uidSet[r.User1] = true
		uidSet[r.User2] = true
	}
	uidList := make([]uint64, 0, len(uidSet))
	for uid := range uidSet {
		uidList = append(uidList, uid)
	}

	type loginCount struct {
		Userid uint64 `gorm:"column:userid"`
		Count  int    `gorm:"column:count"`
	}
	var loginCounts []loginCount
	db.Table("users_logins").Select("userid, COUNT(*) as count").Where("userid IN ?", uidList).
		Group("userid").Scan(&loginCounts)
	hasLogins := make(map[uint64]bool)
	for _, lc := range loginCounts {
		if lc.Count > 0 {
			hasLogins[lc.Userid] = true
		}
	}

	result := make([]fiber.Map, 0, len(rows))
	for _, r := range rows {
		if !hasLogins[r.User1] || !hasLogins[r.User2] {
			// Auto-mark as notified since these are not actionable.
			db.Table("users_related").Where("id = ?", r.ID).Update("notified", gorm.Expr("1"))
			continue
		}

		result = append(result, fiber.Map{
			"id":    r.ID,
			"user1": r.User1,
			"user2": r.User2,
		})
	}

	return c.JSON(result)
}

// HappinessMember is the response struct for happiness/feedback items.
type HappinessMember struct {
	ID        uint64        `json:"id"`
	Timestamp string        `json:"timestamp"`
	Outcome   *string       `json:"outcome"`
	Happiness *string       `json:"happiness"`
	Comments  *string       `json:"comments"`
	Reviewed  int           `json:"reviewed"`
	Fromuser  uint64        `json:"fromuser"`
	Groupid   uint64        `json:"groupid"`
	User      HappinessUser `json:"user"`
	Message   HappinessMsg  `json:"message"`
}

// HappinessUser is the user info embedded in happiness results.
type HappinessUser struct {
	ID          uint64  `json:"id"`
	Displayname string  `json:"displayname"`
	Email       *string `json:"email"`
}

// HappinessMsg is the message info embedded in happiness results.
type HappinessMsg struct {
	ID      uint64  `json:"id"`
	Subject *string `json:"subject"`
}

// Rating is the response struct for user ratings in the feedback page.
type Rating struct {
	ID               uint64  `json:"id"`
	Rater            uint64  `json:"rater"`
	Ratee            uint64  `json:"ratee"`
	Rating           *string `json:"rating"`
	Reason           *string `json:"reason"`
	Text             *string `json:"text"`
	Visible          bool    `json:"visible"`
	Timestamp        string  `json:"timestamp"`
	Reviewrequired   int     `json:"reviewrequired"`
	Groupid          uint64  `json:"groupid"`
	Raterdisplayname string  `json:"raterdisplayname"`
	Rateedisplayname string  `json:"rateedisplayname"`
}

// ratingRow is the raw DB row for ratings.
type ratingRow struct {
	ID               uint64  `gorm:"column:id"`
	Rater            uint64  `gorm:"column:rater"`
	Ratee            uint64  `gorm:"column:ratee"`
	Rating           *string `gorm:"column:rating"`
	Reason           *string `gorm:"column:reason"`
	Text             *string `gorm:"column:text"`
	Visible          bool    `gorm:"column:visible"`
	Timestamp        string  `gorm:"column:timestamp"`
	Reviewrequired   int     `gorm:"column:reviewrequired"`
	Groupid          uint64  `gorm:"column:groupid"`
	Raterdisplayname string  `gorm:"column:raterdisplayname"`
	Rateedisplayname string  `gorm:"column:rateedisplayname"`
}

// HappinessResponse wraps happiness members and ratings.
type HappinessResponse struct {
	Members []HappinessMember `json:"members"`
	Ratings []Rating          `json:"ratings"`
}

// happinessRow is the raw DB row before assembly.
type happinessRow struct {
	ID        uint64  `gorm:"column:id"`
	Timestamp string  `gorm:"column:timestamp"`
	Msgid     uint64  `gorm:"column:msgid"`
	Outcome   *string `gorm:"column:outcome"`
	Happiness *string `gorm:"column:happiness"`
	Comments  *string `gorm:"column:comments"`
	Reviewed  int     `gorm:"column:reviewed"`
	Fromuser  uint64  `gorm:"column:fromuser"`
	Groupid   uint64  `gorm:"column:groupid"`
	Subject   *string `gorm:"column:subject"`
}

// Auto-generated outcome comments to filter out (not real feedback).
var happinessFilterComments = []string{
	"Sorry, this is no longer available.",
	"Thanks, this has now been taken.",
	"Thanks, I'm no longer looking for this.",
	"Sorry, this has now been taken.",
	"Thanks for the interest, but this has now been taken.",
	"Thanks, these have now been taken.",
	"Thanks, this has now been received.",
	"Withdrawn on user unsubscribe",
	"Auto-Expired",
}

// getHappinessMembers handles the Happiness collection - queries messages_outcomes.
func getHappinessMembers(c *fiber.Ctx, myid uint64, groupid uint64, limit int) error {
	db := database.DBConn

	// Determine which group IDs to query.
	var groupIDs []uint64
	if groupid > 0 {
		if !isModOfGroup(myid, groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		groupIDs = []uint64{groupid}
	} else {
		// No group specified - get all groups where caller is a mod.
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			return c.JSON([]HappinessMember{})
		}
	}

	filter := c.Query("filter", "")

	// Only show recent outcomes (last 31 days).
	start := time.Now().AddDate(0, 0, -31).Format("2006-01-02")

	// filter
	// (none/Happy/Unhappy/Fine) gives 4 possible rendered forms, all declared
	// in ormharness/shapes.json and proven by TestTier3Shapes_3119115f3abe
	// (iznik-server-go/test).
	//
	// rippled_in = 0: only Feedback for posts that ORIGINATED on the
	// group, not copies that rippled in from elsewhere (the badge count
	// queries in groupWork.go / session.go apply the same filter, and it
	// mirrors the Edit badge). Discourse 9808/633.
	// WHERE built as a single string for ONE Where() call: GORM's
	// clause.Where wraps any fragment containing "AND"/"OR" in an extra
	// paren pair once there is more than one Where expression to combine
	// (clause/where.go buildExprs), which would diverge from the golden.
	happinessWhereSQL := "mo.timestamp > ? AND mo.comments IS NOT NULL AND mo.comments NOT IN (?)"
	happinessWhereArgs := []interface{}{start, happinessFilterComments}

	switch filter {
	case "Happy":
		happinessWhereSQL += " AND mo.happiness = 'Happy'"
	case "Unhappy":
		happinessWhereSQL += " AND mo.happiness = 'Unhappy'"
	case "Fine":
		happinessWhereSQL += " AND (mo.happiness IS NULL OR mo.happiness = 'Fine')"
	}

	happinessWhereSQL += " AND mg.arrival > ?"
	happinessWhereArgs = append(happinessWhereArgs, start)

	var rows []happinessRow
	db.Table("messages_outcomes mo").
		Select("mo.id, mo.timestamp, mo.msgid, mo.outcome, mo.happiness, mo.comments, mo.reviewed, "+
			"m.fromuser, mg.groupid, m.subject").
		Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid AND mg.groupid IN (?) AND mg.rippled_in = 0", groupIDs).
		Joins("INNER JOIN messages m ON m.id = mo.msgid").
		Where(happinessWhereSQL, happinessWhereArgs...).
		Order("mo.reviewed ASC, mo.timestamp DESC, mo.id DESC").
		Limit(limit).Scan(&rows)

	if rows == nil {
		ratings := getVisibleRatings(db, groupIDs)
		return c.JSON(HappinessResponse{Members: []HappinessMember{}, Ratings: ratings})
	}

	// Collect unique user IDs for batch lookup.
	userIDSet := make(map[uint64]bool)
	for _, r := range rows {
		userIDSet[r.Fromuser] = true
	}

	// Fetch user display names.
	type userInfo struct {
		ID       uint64  `gorm:"column:id"`
		Fullname *string `gorm:"column:fullname"`
	}
	userIDs := make([]uint64, 0, len(userIDSet))
	for uid := range userIDSet {
		userIDs = append(userIDs, uid)
	}
	var users []userInfo
	if len(userIDs) > 0 {
		db.Table("users").Select("id, fullname").Where("id IN ?", userIDs).Scan(&users)
	}
	userMap := make(map[uint64]*userInfo)
	for i := range users {
		userMap[users[i].ID] = &users[i]
	}

	// Fetch preferred emails for each user.
	type emailInfo struct {
		Userid    uint64 `gorm:"column:userid"`
		Email     string `gorm:"column:email"`
		Preferred int    `gorm:"column:preferred"`
	}
	var emails []emailInfo
	if len(userIDs) > 0 {
		db.Table("users_emails").Select("userid, email, preferred").Where("userid IN ?", userIDs).
			Order("preferred DESC").Scan(&emails)
	}
	emailMap := make(map[uint64]string)
	for _, e := range emails {
		if _, ok := emailMap[e.Userid]; !ok || e.Preferred == 1 {
			emailMap[e.Userid] = e.Email
		}
	}

	// Assemble results, deduplicating by msgid.
	seenMsgids := make(map[uint64]bool)
	results := make([]HappinessMember, 0, len(rows))
	for _, r := range rows {
		if seenMsgids[r.Msgid] {
			continue
		}
		seenMsgids[r.Msgid] = true

		displayname := ""
		if ui, ok := userMap[r.Fromuser]; ok && ui.Fullname != nil {
			displayname = *ui.Fullname
		}
		var email *string
		if e, ok := emailMap[r.Fromuser]; ok {
			email = &e
		}

		results = append(results, HappinessMember{
			ID:        r.ID,
			Timestamp: r.Timestamp,
			Outcome:   r.Outcome,
			Happiness: r.Happiness,
			Comments:  r.Comments,
			Reviewed:  r.Reviewed,
			Fromuser:  r.Fromuser,
			Groupid:   r.Groupid,
			User: HappinessUser{
				ID:          r.Fromuser,
				Displayname: displayname,
				Email:       email,
			},
			Message: HappinessMsg{
				ID:      r.Msgid,
				Subject: r.Subject,
			},
		})
	}

	// Fetch visible ratings for the moderator's groups.
	ratings := getVisibleRatings(db, groupIDs)

	return c.JSON(HappinessResponse{Members: results, Ratings: ratings})
}

// getVisibleRatings returns ratings visible to the moderator for the given groups.
// Both rater and ratee must be members of the same group.
func getVisibleRatings(db *gorm.DB, groupIDs []uint64) []Rating {
	if len(groupIDs) == 0 {
		return []Rating{}
	}

	since := time.Now().AddDate(0, 0, -7).Format("2006-01-02")

	// Both group-id
	// lists are GORM's native "IN (?)" slice-bind, which the harness's
	// collapseInLists normalizes regardless of length, so this has exactly one
	// rendered form, declared in ormharness/shapes.json and proven by
	// TestTier3Shapes_1a000d04649b (iznik-server-go/test).
	var rows []ratingRow
	db.Table("ratings").
		Select("ratings.id, ratings.rater, ratings.ratee, ratings.rating, ratings.reason, "+
			"ratings.text, ratings.visible, ratings.timestamp, ratings.reviewrequired, "+
			"m1.groupid, "+
			"CASE WHEN u1.fullname IS NOT NULL THEN u1.fullname ELSE CONCAT(u1.firstname, ' ', u1.lastname) END AS raterdisplayname, "+
			"CASE WHEN u2.fullname IS NOT NULL THEN u2.fullname ELSE CONCAT(u2.firstname, ' ', u2.lastname) END AS rateedisplayname").
		Joins("INNER JOIN memberships m1 ON m1.userid = ratings.rater").
		Joins("INNER JOIN memberships m2 ON m2.userid = ratings.ratee").
		Joins("INNER JOIN users u1 ON ratings.rater = u1.id").
		Joins("INNER JOIN users u2 ON ratings.ratee = u2.id").
		Where("ratings.timestamp >= ?", since).
		Where("m1.groupid IN (?)", groupIDs).
		Where("m2.groupid IN (?)", groupIDs).
		Where("m1.groupid = m2.groupid").
		Where("ratings.rating IS NOT NULL").
		Group("ratings.id").
		Order("ratings.timestamp DESC").
		Scan(&rows)

	if rows == nil {
		return []Rating{}
	}

	ratings := make([]Rating, len(rows))
	for i, r := range rows {
		ratings[i] = Rating{
			ID:               r.ID,
			Rater:            r.Rater,
			Ratee:            r.Ratee,
			Rating:           r.Rating,
			Reason:           r.Reason,
			Text:             r.Text,
			Visible:          r.Visible,
			Timestamp:        r.Timestamp,
			Reviewrequired:   r.Reviewrequired,
			Groupid:          r.Groupid,
			Raterdisplayname: r.Raterdisplayname,
			Rateedisplayname: r.Rateedisplayname,
		}
	}

	return ratings
}

// PutMembershipsRequest is the body for PUT /memberships (join group).
type PutMembershipsRequest struct {
	Userid  uint64 `json:"userid"`
	Groupid uint64 `json:"groupid"`
	Manual  *bool  `json:"manual"`
}

// PutMemberships handles PUT /memberships - user joins a group.
// Supports three auth modes:
//   - Partner key: partner query param with tnuserid/email for TN integration
//   - Mod-add: JWT auth where userid != myid and caller is mod of the group
//   - Self-join: JWT auth where userid == myid (or omitted)
//
// @Summary Subscribe user to group
// @Tags memberships
// @Accept json
// @Produce json
// @Param partner query string false "Partner API key"
// @Param tnuserid query integer false "Trash Nothing user ID (partner auth)"
// @Param email query string false "User email (partner auth)"
// @Param body body PutMembershipsRequest true "Request body"
// @Success 200 {object} map[string]interface{}
// @Failure 400 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /api/memberships [put]
func PutMemberships(c *fiber.Ctx) error {
	db := database.DBConn

	// Partner auth path: if partner query param is present, use partner key authentication.
	partnerKey := c.Query("partner")
	if partnerKey != "" {
		return putMembershipsPartner(c, db, partnerKey)
	}

	// JWT auth path.
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PutMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	userid := req.Userid
	if userid == 0 {
		userid = myid
	}

	// Non-self joins require moderator permissions on the target group.
	if userid != myid {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
	}

	return addMemberToGroup(c, db, userid, req.Groupid, myid, req.Manual)
}

// putMembershipsPartner handles the partner auth path for PUT /memberships.
func putMembershipsPartner(c *fiber.Ctx, db *gorm.DB, partnerKey string) error {
	_, _, domain, err := user.ValidatePartnerKey(db, partnerKey)
	if err != nil {
		return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
	}

	groupidStr := c.Query("groupid", "0")
	groupid, _ := strconv.ParseUint(groupidStr, 10, 64)
	if groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	email := c.Query("email", "")
	tnuseridStr := c.Query("tnuserid", "0")
	tnuserid, _ := strconv.ParseUint(tnuseridStr, 10, 64)

	// Validate email domain matches partner domain.
	if email != "" && domain != "" {
		parts := strings.SplitN(email, "@", 2)
		if len(parts) == 2 && !strings.EqualFold(parts[1], domain) {
			return fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
		}
	}

	// Check the group exists.
	var groupExists int64
	db.Table("groups").Where("id = ?", groupid).Count(&groupExists)
	if groupExists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}

	// Find or create the user.
	userid := user.FindByTNIdOrEmail(db, tnuserid, email)
	if userid == 0 {
		userid, err = user.CreatePartnerUser(db, tnuserid, email)
		if err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
		}
	}

	// Check if banned. V1 parity: User::addMembership returns FALSE for
	// isBanned(), and the legacy partner handler reported that as ret=4
	// "Failed - likely ban" - a genuine failure. Reporting a fake "Success"
	// here instead (Discourse #9961) leaves no membership, memberships_history,
	// or log row anywhere, so a banned member's join attempt vanishes with
	// nothing for a moderator to find while the partner is told it worked.
	var bannedCount int64
	db.Table("users_banned").Where("userid = ? AND groupid = ?",
		userid, groupid).Count(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "Failed - banned")
	}

	// Check if already a member.
	var existingRole string
	db.Table("memberships").Select("role").Where("userid = ? AND groupid = ?",
		userid, groupid).Scan(&existingRole)
	if existingRole != "" {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": userid, "addedto": utils.COLLECTION_APPROVED})
	}

	// Insert membership. ORM migration site 759766c83c01 (wave 2). Golden column
	// order (userid, groupid, role, collection) is not alphabetical, but
	// normaliseColumnOrder sorts both sides' columns together with their values
	// before comparing (ormharness/normalise_test.go TestNormaliseColumnOrder_Insert),
	// so the map-Create reorder is harmless. Identical twin: addMemberToGroup (27aa0e237120).
	db.Table("memberships").Create(map[string]interface{}{
		"userid":     userid,
		"groupid":    groupid,
		"role":       utils.ROLE_MEMBER,
		"collection": utils.COLLECTION_APPROVED,
	})

	// Record in memberships_history with processingrequired=1 so the
	// Laravel batch (memberships:process) sends the group welcome email,
	// runs spam checks, and applies review flags. Without this row the
	// cron has nothing to do and welcomes are silently dropped.
	// processingrequired is a literal
	// 1 in the golden, not a bind, so it goes through gorm.Expr. Identical twin:
	// addMemberToGroup (2f0c55ec88d6).
	db.Table("memberships_history").Create(map[string]interface{}{
		"userid":             userid,
		"groupid":            groupid,
		"collection":         utils.COLLECTION_APPROVED,
		"processingrequired": gorm.Expr("1"),
	})

	logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_JOINED, groupid, userid, userid, "via partner")

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": userid, "addedto": utils.COLLECTION_APPROVED})
}

// addMemberToGroup is the shared logic for adding a user to a group (JWT auth paths).
// manual mirrors V1 User::addMembership: true→"Manual", false→"Auto", nil→"".
func addMemberToGroup(c *fiber.Ctx, db *gorm.DB, userid uint64, groupid uint64, byuser uint64, manual *bool) error {
	// Check the group exists.
	var groupExists int64
	db.Table("groups").Where("id = ?", groupid).Count(&groupExists)
	if groupExists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}

	// Check if already a member.
	var existingRole string
	db.Table("memberships").Select("role").Where("userid = ? AND groupid = ?",
		userid, groupid).Scan(&existingRole)
	if existingRole != "" {
		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "addedto": "Approved"})
	}

	// Check if banned. V1 parity: User::addMembership returns FALSE for isBanned(),
	// a genuine failure. Reporting a fake "Success" here (Discourse #9961) leaves no
	// membership, memberships_history, or log row anywhere, so a banned member's join
	// attempt vanishes with nothing for a moderator to find while the caller (a partner
	// like TrashNothing, or a moderator using the Add button) is told it worked. Return
	// a real failure so the join doesn't silently disappear.
	var bannedCount int64
	db.Table("users_banned").Where("userid = ? AND groupid = ?",
		userid, groupid).Count(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "Failed - banned")
	}

	// Insert membership as approved member. ORM migration site 27aa0e237120
	// (wave 2). Identical twin of putMembershipsPartner's insert (759766c83c01);
	// see the comment there on why the map-Create column reorder is safe.
	result := db.Table("memberships").Create(map[string]interface{}{
		"userid":     userid,
		"groupid":    groupid,
		"role":       utils.ROLE_MEMBER,
		"collection": utils.COLLECTION_APPROVED,
	})

	if result.RowsAffected > 0 {
		// Record in memberships_history with processingrequired=1 so the
		// Laravel batch (memberships:process) sends the group welcome email,
		// runs spam checks, and applies review flags. Without this row the
		// cron has nothing to do and welcomes are silently dropped.
		// Identical twin of
		// putMembershipsPartner's insert (32d907621f09).
		db.Table("memberships_history").Create(map[string]interface{}{
			"userid":             userid,
			"groupid":            groupid,
			"collection":         utils.COLLECTION_APPROVED,
			"processingrequired": gorm.Expr("1"),
		})

		// V1 parity (User.php:944-957): log text records how the user joined.
		// manual=true→"Manual" (clicked Join button), false→"Auto" (auto-joined
		// to reply/post), nil→"" (method not specified).
		joinText := ""
		if manual != nil {
			if *manual {
				joinText = "Manual"
			} else {
				joinText = "Auto"
			}
		}
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_JOINED, groupid, userid, byuser, joinText)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "addedto": utils.COLLECTION_APPROVED})
}

// DeleteMembershipsRequest is for DELETE /memberships (leave group or ban member).
type DeleteMembershipsRequest struct {
	Userid  uint64 `json:"userid"`
	Groupid uint64 `json:"groupid"`
	Ban     *bool  `json:"ban"`
}

// DeleteMemberships handles DELETE /memberships - user leaves a group.
// Supports partner auth (partner query param) or JWT auth.
// Frontend $delv2 sends JSON body (BaseAPI.js line 166 JSON-stringifies config.params for non-GET/POST).
//
// @Summary Unsubscribe user from group
// @Tags memberships
// @Accept json
// @Produce json
// @Param partner query string false "Partner API key"
// @Param tnuserid query integer false "Trash Nothing user ID (partner auth)"
// @Param email query string false "User email (partner auth)"
// @Param body body DeleteMembershipsRequest true "Request body"
// @Success 200 {object} map[string]interface{}
// @Failure 400 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /api/memberships [delete]
func DeleteMemberships(c *fiber.Ctx) error {
	db := database.DBConn

	// Partner auth path.
	partnerKey := c.Query("partner")
	if partnerKey != "" {
		return deleteMembershipsPartner(c, db, partnerKey)
	}

	// JWT auth path.
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req DeleteMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	userid := req.Userid
	if userid == 0 {
		userid = myid
	}

	// Handle ban. V1 parity: delete memberships row entirely, insert into users_banned.
	if req.Ban != nil && *req.Ban {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		// Converted together with its
		// identical twin in PostMemberships's Ban action (98ee705a8a74).
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).Delete(nil)
		// Twin of dfc985e8ea67 above.
		db.Table("users_banned").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "byuser"}, Value: clause.Column{Table: "excluded", Name: "byuser"}},
				{Column: clause.Column{Name: "date"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"userid": userid, "groupid": req.Groupid, "byuser": myid,
		})
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_LEFT, req.Groupid, userid, myid, "via ban")
		// A ban removes this membership; if it was the user's last Owner/Moderator
		// role, demote a now-stale Moderator systemrole (V1 updateSystemRole parity).
		user.SyncSystemRole(db, userid)
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	// Self-leave is always allowed. Non-self removals require mod/owner of the group.
	if userid != myid {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Not a moderator of this group")
		}
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_DELETED, req.Groupid, userid, myid, "")
	}

	// Remove the membership.
	// Converted together with its
	// identical twin in deleteMembershipsPartner (0fe2da6629e8).
	result := db.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?",
		userid, req.Groupid, utils.COLLECTION_APPROVED).Delete(nil)

	if result.RowsAffected > 0 {
		// V1 parity: User::removeMembership() always logs Group/Left when the
		// DELETE affects rows (User.php:1085-1095). Both self-leave and
		// mod-removes-other need this — otherwise the moderator audit views
		// (which query logs for type=Group/subtype=Left) lose every voluntary
		// leave and every non-ban moderator removal.
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_LEFT, req.Groupid, userid, myid, "")
		// If that leave/removal dropped the user's last Owner/Moderator role,
		// demote a now-stale Moderator systemrole to User (V1 parity).
		user.SyncSystemRole(db, userid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// deleteMembershipsPartner handles the partner auth path for DELETE /memberships.
func deleteMembershipsPartner(c *fiber.Ctx, db *gorm.DB, partnerKey string) error {
	_, _, domain, err := user.ValidatePartnerKey(db, partnerKey)
	if err != nil {
		return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
	}

	groupidStr := c.Query("groupid", "0")
	groupid, _ := strconv.ParseUint(groupidStr, 10, 64)
	if groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	email := c.Query("email", "")
	tnuseridStr := c.Query("tnuserid", "0")
	tnuserid, _ := strconv.ParseUint(tnuseridStr, 10, 64)

	// Validate email domain matches partner domain.
	if email != "" && domain != "" {
		parts := strings.SplitN(email, "@", 2)
		if len(parts) == 2 && !strings.EqualFold(parts[1], domain) {
			return fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
		}
	}

	// Find the user.
	userid := user.FindByTNIdOrEmail(db, tnuserid, email)
	if userid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "User not found")
	}

	// Remove the membership.
	// Converted together with its
	// identical twin in DeleteMemberships (535641088fb3).
	result := db.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?",
		userid, groupid, utils.COLLECTION_APPROVED).Delete(nil)

	if result.RowsAffected > 0 {
		// V1 parity: User::removeMembership() always logs Group/Left when the
		// DELETE affects rows. byuser = the leaving user (no session in the
		// partner path).
		logMembershipAction(log.LOG_TYPE_GROUP, log.LOG_SUBTYPE_LEFT, groupid, userid, userid, "via partner")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "fduserid": userid})
}

// PatchMembershipsRequest is for PATCH /memberships (update settings).
type PatchMembershipsRequest struct {
	Userid              uint64           `json:"userid"`
	ID                  uint64           `json:"id"`
	Groupid             uint64           `json:"groupid"`
	Role                *string          `json:"role"`
	Settings            *json.RawMessage `json:"settings"`
	Configid            *uint64          `json:"configid"`
	Emailfrequency      *utils.FlexInt   `json:"emailfrequency"`
	Eventsallowed       *utils.FlexInt   `json:"eventsallowed"`
	Volunteeringallowed *utils.FlexInt   `json:"volunteeringallowed"`
	OurPostingStatus    *string          `json:"ourPostingStatus"`
}

// PatchMemberships handles PATCH /memberships - update membership settings.
// Users can update their own settings. Moderators can update ourPostingStatus
// and emailfrequency for members of groups they moderate (stdmsg side effects).
func PatchMemberships(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchMembershipsRequest
	if err := c.BodyParser(&req); err != nil {
		stdlog.Printf("[PatchMemberships] BodyParser error for user %d: %v body=%q", myid, err, string(c.Body()))
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Groupid == 0 {
		stdlog.Printf("[PatchMemberships] Missing groupid for user %d: parsed=%+v body=%q", myid, req, string(c.Body()))
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	userid := req.Userid
	if userid == 0 {
		userid = req.ID
	}
	if userid == 0 {
		userid = myid
	}

	// Users can update their own settings. Moderators can update settings for
	// members of groups they moderate (e.g. stdmsg newmodstatus/newdelstatus).
	if userid != myid {
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Cannot modify another user's settings")
		}
	}

	db := database.DBConn

	// Verify the membership exists.
	var membershipExists int64
	db.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?",
		userid, req.Groupid, utils.COLLECTION_APPROVED).Count(&membershipExists)
	if membershipExists == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Not a member of this group")
	}

	// Update whichever settings were provided.
	if req.Emailfrequency != nil {
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("emailfrequency", int(*req.Emailfrequency))
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_OUR_EMAIL_FREQUENCY, req.Groupid, userid, myid,
			fmt.Sprintf("emailfrequency=%d", int(*req.Emailfrequency)))
	}

	if req.Eventsallowed != nil {
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("eventsallowed", int(*req.Eventsallowed))
	}

	if req.Volunteeringallowed != nil {
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("volunteeringallowed", int(*req.Volunteeringallowed))
	}

	if req.Settings != nil {
		// Strip configid from the settings blob — it is a top-level column, not a settings field.
		// If the caller is a mod and no top-level configid was supplied, promote the embedded value
		// so the column update below will run. Non-mods have it silently discarded.
		var settingsMap map[string]json.RawMessage
		if err := json.Unmarshal(*req.Settings, &settingsMap); err == nil {
			if raw, ok := settingsMap["configid"]; ok {
				if req.Configid == nil && isModOfGroup(myid, req.Groupid) {
					var cid uint64
					if json.Unmarshal(raw, &cid) == nil && cid > 0 {
						req.Configid = &cid
					}
				}
				delete(settingsMap, "configid")
				if cleaned, err := json.Marshal(settingsMap); err == nil {
					stripped := json.RawMessage(cleaned)
					req.Settings = &stripped
				}
			}
		}
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("settings", string(*req.Settings))
	}

	if req.Configid != nil {
		// Update the mod config used for this membership.
		// Must be mod/owner of the group to change the config.
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Only moderators can change the config")
		}
		// Verify the config exists.
		var configID uint64
		db.Table("mod_configs").Select("id").Where("id = ?", *req.Configid).Scan(&configID)
		if configID == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Config not found")
		}
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("configid", *req.Configid)
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_CONFIG_CHANGE, req.Groupid, userid, myid,
			fmt.Sprintf("configid=%d", *req.Configid))
	}

	if req.OurPostingStatus != nil {
		// ourPostingStatus is mod-only — users must not change their own moderation status.
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Only moderators can change posting status")
		}
		db.Table("memberships").Where("userid = ? AND groupid = ?", userid, req.Groupid).
			Update("ourPostingStatus", *req.OurPostingStatus)
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_OUR_POSTING_STATUS, req.Groupid, userid, myid,
			*req.OurPostingStatus)
	}

	if req.Role != nil {
		targetRole := *req.Role
		if targetRole != utils.ROLE_MEMBER && targetRole != utils.ROLE_MODERATOR && targetRole != utils.ROLE_OWNER {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid role: must be Member, Moderator, or Owner")
		}

		// Must be a mod/owner of the group (or admin/support) to change anyone's role.
		if !isModOfGroup(myid, req.Groupid) {
			return fiber.NewError(fiber.StatusForbidden, "Only moderators can change roles")
		}

		// Only owners (or admin/support) can promote to Moderator or Owner.
		// Moderators can only demote to Member.
		callerRole := getRoleForGroup(myid, req.Groupid)
		if targetRole == utils.ROLE_MODERATOR || targetRole == utils.ROLE_OWNER {
			if callerRole != utils.ROLE_OWNER {
				return fiber.NewError(fiber.StatusForbidden, "Only owners can promote to moderator or owner")
			}
		}

		db.Table("memberships").Where("userid = ? AND groupid = ? AND collection = ?", userid, req.Groupid, utils.COLLECTION_APPROVED).
			Update("role", targetRole)
		logMembershipAction(log.LOG_TYPE_USER, log.LOG_SUBTYPE_ROLE_CHANGE, req.Groupid, userid, myid, targetRole)

		// V1 parity (User::updateSystemRole, legacy V1 PHP implementation):
		// changes to memberships.role must propagate to users.systemrole so the
		// global Moderator flag stays in sync. The frontend reads users.systemrole
		// to render the crown next to a user (ModLogUser.vue, byline avatars,
		// Discourse SSO). Without this, a per-group Moderator shows the crown on
		// the members page but the plain User icon in the group logs — the
		// Discourse #9481 post 545 "Trainee not showing as a Mod in the group
		// logs" report, root cause confirmed against prod (e.g. uid 41231435
		// DixieKay promoted 2026-05-27, memberships.role='Moderator' but
		// users.systemrole still 'User').
		if targetRole == utils.ROLE_MODERATOR || targetRole == utils.ROLE_OWNER {
			// Promote: only flip 'User' to 'Moderator'. V1 used the same
			// guard (UPDATE … WHERE systemrole = 'User') so Support / Admin
			// users are never silently demoted to Moderator.
			db.Table("users").Where("id = ? AND systemrole = ?", userid, utils.SYSTEMROLE_USER).
				Update("systemrole", utils.SYSTEMROLE_MODERATOR)
		} else {
			// Demote: V1 only reverts systemrole to 'User' if the user no
			// longer holds Moderator / Owner on ANY other approved group.
			// Otherwise they're still a mod elsewhere and stay Moderator.
			var remaining int64
			db.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?",
				userid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).
				Count(&remaining)
			if remaining == 0 {
				db.Table("users").Where("id = ? AND systemrole = ?", userid, utils.SYSTEMROLE_MODERATOR).
					Update("systemrole", utils.SYSTEMROLE_USER)
			}
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

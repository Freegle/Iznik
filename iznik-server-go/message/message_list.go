package message

import (
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// PaginationContext is the opaque cursor echoed between pages of the ModTools
// message list (Date + last id), serialised into the `context` query param.
type PaginationContext struct {
	Date int64  `json:"Date"`
	ID   uint64 `json:"id"`
}

// MessageGroupInfo describes one group entry for a message in the list response.
type MessageGroupInfo struct {
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	Arrival    time.Time `json:"arrival"`
	Heldby     *uint64   `json:"heldby,omitempty"`
	// RippledIn marks a row created by the rippling engine; the mod queue uses it to show
	// the rippled-in banner authoritatively (see MessageGroup.RippledIn). (9808/303)
	RippledIn uint8 `json:"rippled_in"`
	// ModMessagingAllowed: see MessageGroup.ModMessagingAllowed. Carried here so the mod
	// queue can work out, without a second fetch per post, which posts it must not offer
	// Edit / Blank Reply / standard messages for.
	ModMessagingAllowed bool `json:"mod_messaging_allowed"`
}

// ListMessageItem is a single item in the message list response.
type ListMessageItem struct {
	ID                 uint64              `json:"id"`
	Subject            string              `json:"subject"`
	Type               string              `json:"type"`
	Fromuser           uint64              `json:"fromuser"`
	Arrival            time.Time           `json:"arrival"`
	Lat                float64             `json:"lat"`
	Lng                float64             `json:"lng"`
	Availablenow       uint                `json:"availablenow"`
	Availableinitially uint                `json:"availableinitially"`
	Tnpostid           *string             `json:"tnpostid"`
	Expiresat          *time.Time          `json:"expiresat,omitempty"`
	Groups             []MessageGroupInfo  `json:"groups"`
	Attachments        []MessageAttachment `json:"attachments,omitempty"`
	Replycount         int                 `json:"replycount"`
	// ModMessagingAllowed: see Message.ModMessagingAllowed. Reduced from Groups by
	// modMessagingAllowed() so the queue does not have to re-derive it per card.
	ModMessagingAllowed bool `json:"mod_messaging_allowed"`
}

// ListMessagesResponse is the envelope returned by GET /messages and GET /modtools/messages.
type ListMessagesResponse struct {
	Messages []ListMessageItem  `json:"messages"`
	Context  *PaginationContext `json:"context,omitempty"`
}

// buildMTUnionAllMsgIDQuery assembles a UNION ALL query over groupIDs and
// returns the full SQL + args ready for db.Raw().  Using `WHERE mg.groupid IN
// (list)` forces MySQL into a temporary-table + filesort (the composite
// messages_groups(groupid, collection, deleted, arrival) index is ordered
// per-groupid, not globally), so a moderator of several large groups hits
// ~30s query times.  UNION ALL per groupid lets each branch use the index
// with a backward scan and LIMIT early-termination; the outer sort only
// sees N * numGroups rows.
//
// branchSQL must:
//   - Contain exactly one `%GID%` placeholder, substituted per branch
//   - Project `mg.msgid, mg.arrival` (plus anything else needed by its own
//     ORDER BY) so the outer ORDER BY / LIMIT can work on `arrival`
//   - End with `ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?`; the trailing
//     `?` is bound per branch to `limit`
//
// branchArgs are the `?` args for one branch in declaration order, excluding
// the trailing LIMIT; they are replicated per branch.
func buildMTUnionAllMsgIDQuery(branchSQL string, branchArgs []interface{}, groupIDs []uint64, limit int) (string, []interface{}) {
	var sb strings.Builder
	// The outer GROUP BY deduplicates messages that appear in multiple queried
	// groups (e.g. a cross-posted Pending message).  MAX(arrival) picks the
	// most-recent arrival across all branches for ordering.
	//
	// MAX_EXECUTION_TIME caps the whole statement at 20s. The member-name search
	// fallback (leading-wildcard fullname LIKE joined per group) can run 90s+ for
	// a moderator of many groups when no groupid scopes it (Discourse 9518/366) —
	// long enough to exceed the proxy read timeout, so the client never gets a
	// response and the spinner hangs forever. The cap guarantees the query returns
	// (empty, surfaced as "Nothing found") instead of hanging. Well-scoped queries
	// run in well under a second, so the cap never bites them.
	sb.WriteString("SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM (SELECT msgid, MAX(arrival) AS arrival FROM (")

	args := make([]interface{}, 0, (len(branchArgs)+1)*len(groupIDs)+1)
	for i, gid := range groupIDs {
		if i > 0 {
			sb.WriteString(" UNION ALL ")
		}
		branch := strings.Replace(branchSQL, "%GID%", strconv.FormatUint(gid, 10), 1)
		sb.WriteString("(")
		sb.WriteString(branch)
		sb.WriteString(")")
		args = append(args, branchArgs...)
		args = append(args, limit)
	}

	sb.WriteString(") raw GROUP BY msgid) t ORDER BY arrival DESC, msgid DESC LIMIT ?")
	args = append(args, limit)

	return sb.String(), args
}

// ListMessagesMT handles GET /modtools/messages — returns message IDs only
// (the client fetches full details individually via GET /message/:id).
//
// @Summary List messages for modtools
// @Tags message
// @Produce json
// @Param groupid query integer false "Group ID"
// @Param collection query string false "Collection (Approved, Pending, Edits)"
// @Param limit query integer false "Max messages to return"
// @Param context query integer false "Pagination cursor"
// @Success 200 {object} map[string]interface{}
// @Router /api/modtools/messages [get]
func ListMessagesMT(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	collection := c.Query("collection", utils.COLLECTION_APPROVED)
	groupidStr := c.Query("groupid", "0")
	groupid, _ := strconv.ParseUint(groupidStr, 10, 64)
	limit, _ := strconv.Atoi(c.Query("limit", "20"))
	if limit <= 0 {
		limit = 20
	}
	if limit > 100 {
		limit = 100
	}

	validCollections := map[string]bool{
		"Approved": true, "Pending": true, "Rejected": true, "Spam": true, "Edit": true,
	}
	if !validCollections[collection] {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid collection")
	}

	var groupIDs []uint64
	if groupid == 0 {
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			return c.JSON(fiber.Map{"messages": []uint64{}})
		}
	} else {
		if collection != utils.COLLECTION_APPROVED {
			if !user.IsModOfGroup(myid, groupid) {
				return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
			}
		}
		groupIDs = []uint64{groupid}
	}

	var ctx *PaginationContext
	contextStr := c.Query("context", "")
	if contextStr != "" {
		ctx = &PaginationContext{}
		if err := json.Unmarshal([]byte(contextStr), ctx); err != nil {
			ctx = nil
		}
	}

	subaction := c.Query("subaction", "")
	search := c.Query("search", "")
	fromuserStr := c.Query("fromuser", "0")
	fromuser, _ := strconv.ParseUint(fromuserStr, 10, 64)

	var msgIDs []uint64

	// Hide Pending messages not yet processed by the content-check batch job.
	// The 30-minute fallback ensures mods always see messages if the batch job is down,
	// and also makes existing rows (contentcheck_checked_at IS NULL) visible without a
	// backfill — their arrival times are already in the past.
	// When the Pending view also shows Spam-collection messages, scope the filter to
	// Pending rows only (Spam messages have already been reviewed and need no content check).
	contentcheckFilter := ""
	if collection == utils.COLLECTION_PENDING {
		contentcheckFilter = " AND (mg.collection != 'Pending' OR mg.contentcheck_checked_at IS NOT NULL OR mg.arrival < NOW() - INTERVAL 30 MINUTE)"
	}

	// 9808/638: when a mod asks for their OWN-group posts only (?originonly=true), exclude
	// posts that rippled INTO the group (rippled_in = 1) - they otherwise dominate the
	// Approved list and make finding a group's own members' posts hard. Folded into the
	// per-branch filter that every query variant below already appends, using the same
	// messages_groups.rippled_in marker the Edit branch above hardcodes (9808/633). Constant
	// clause, so no bound parameter is added.
	if c.Query("originonly") == "true" {
		contentcheckFilter += " AND mg.rippled_in = 0"
	}

	// A listing query that fails must never be reported as an empty queue. The
	// all-communities form fans out over every group the moderator covers and
	// is capped at MAX_EXECUTION_TIME(20000) (see buildMTUnionAllMsgIDQuery),
	// so a slow replica aborts it. Swallowing that returned a perfectly normal
	// "messages": [] to ModTools, which cannot tell it apart from a genuinely
	// empty queue: the moderator gets an empty page while the work count in
	// the menu insists there is work, and the infinite loader stops for good
	// (Discourse 10037).
	var listErr error

	if collection == "Edit" {
		// Edit review uses messages_edits table, not messages_groups collection.
		// Restrict to ORIGIN messages_groups rows (rippled_in = 0). A post rippled INTO a
		// group gets an Approved row there (rippled_in = 1); without this filter an edit on a
		// rippled-in post surfaces in every receiving group's Edit queue (and to active mods
		// there via the all-groups path), but an edit belongs to the post's origin group(s)
		// only. Same bug class as the IP-abuse fix (WHERE rippled_in=0).
		listErr = db.Table("messages_edits me").
			Select("DISTINCT me.msgid").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid AND mg.deleted = 0 AND mg.rippled_in = 0").
			Where("mg.groupid IN (?) AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)",
				groupIDs).
			Order("me.timestamp DESC").
			Limit(limit).
			Pluck("msgid", &msgIDs).Error
	} else if subaction == "searchall" && search != "" {
		// If the search term is numeric, also match on message ID.
		searchID, numErr := strconv.ParseUint(search, 10, 64)
		if numErr == nil && searchID > 0 {
			branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
				"INNER JOIN messages m ON m.id = mg.msgid " +
				"INNER JOIN users u ON u.id = m.fromuser " +
				"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
				"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL AND m.id = ? " +
				contentcheckFilter +
				" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
			sql, args := buildMTUnionAllMsgIDQuery(branchSQL, []interface{}{collection, searchID}, groupIDs, limit)
			listErr = db.Raw(sql, args...).Pluck("msgid", &msgIDs).Error
		}
		if len(msgIDs) == 0 {
			searchTerm := "%" + search + "%"
			branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
				"INNER JOIN messages m ON m.id = mg.msgid " +
				"INNER JOIN users u ON u.id = m.fromuser " +
				"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
				"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL AND m.subject LIKE ? " +
				contentcheckFilter +
				" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
			sql, args := buildMTUnionAllMsgIDQuery(branchSQL, []interface{}{collection, searchTerm}, groupIDs, limit)
			listErr = db.Raw(sql, args...).Pluck("msgid", &msgIDs).Error
		}
	} else if subaction == "searchmemb" && search != "" {
		// If search is a numeric user ID, do a fast direct lookup first.
		searchUID, numErr := strconv.ParseUint(search, 10, 64)
		if numErr == nil && searchUID > 0 {
			branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
				"INNER JOIN messages m ON m.id = mg.msgid " +
				"INNER JOIN users u ON u.id = m.fromuser " +
				"WHERE mg.groupid = %GID% " +
				"AND mg.collection = ? " +
				"AND mg.deleted = 0 " +
				"AND m.deleted IS NULL AND u.deleted IS NULL " +
				"AND m.fromuser = ? " +
				contentcheckFilter +
				" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
			sql, args := buildMTUnionAllMsgIDQuery(branchSQL, []interface{}{collection, searchUID}, groupIDs, limit)
			listErr = db.Raw(sql, args...).Pluck("msgid", &msgIDs).Error
		}
		if len(msgIDs) == 0 {
			// Fall back to name/email LIKE search.  The LEFT JOIN to
			// users_emails can produce duplicate msgids for users with
			// multiple emails; SELECT DISTINCT inside each UNION branch
			// collapses them before the outer LIMIT is applied.
			searchTerm := "%" + search + "%"
			branchSQL := "SELECT DISTINCT mg.msgid, mg.arrival FROM messages_groups mg " +
				"INNER JOIN messages m ON m.id = mg.msgid " +
				"INNER JOIN users u ON u.id = m.fromuser " +
				"LEFT JOIN users_emails ue ON ue.userid = u.id " +
				"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
				"AND m.deleted IS NULL AND u.deleted IS NULL " +
				// Same fullname/firstname/lastname/concat matching as the non-union branch
				// above - see the comment there (Discourse 9518/379).
				"AND (u.fullname LIKE ? OR u.firstname LIKE ? OR u.lastname LIKE ? " +
				"OR CONCAT_WS(' ', u.firstname, u.lastname) LIKE ? OR ue.email LIKE ?) " +
				contentcheckFilter +
				" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
			sql, args := buildMTUnionAllMsgIDQuery(branchSQL, []interface{}{collection, searchTerm, searchTerm, searchTerm, searchTerm, searchTerm}, groupIDs, limit)
			listErr = db.Raw(sql, args...).Pluck("msgid", &msgIDs).Error
		}
	} else {
		// When listing the Pending review queue, also include Spam-collection messages.
		// The badge work-count (session.workCount.spam) already counts these, so
		// excluding them from the list creates a spurious "+1 over visible" badge
		// (Discourse #9654 / V1 parity).
		collectionFilter := "mg.collection = ?"
		branchArgs := []interface{}{collection}
		if collection == utils.COLLECTION_PENDING {
			// Include Spam-collection messages in the Pending review queue, but
			// only recent ones. Spam older than 30 days is stale — it should age
			// out of the queue rather than accumulate indefinitely. Pending rows
			// are never aged out here.
			collectionFilter = "(mg.collection = ? OR (mg.collection = ? AND mg.arrival >= (NOW() - INTERVAL 30 DAY)))"
			branchArgs = []interface{}{utils.COLLECTION_PENDING, utils.COLLECTION_SPAM}
		}

		branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
			"INNER JOIN messages m ON m.id = mg.msgid " +
			"INNER JOIN users u ON u.id = m.fromuser " +
			"WHERE mg.groupid = %GID% AND " + collectionFilter + " AND mg.deleted = 0 " +
			"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL " +
			contentcheckFilter + " "

		if fromuser > 0 {
			branchSQL += "AND m.fromuser = ? "
			branchArgs = append(branchArgs, fromuser)
		}
		if ctx != nil && ctx.Date > 0 {
			ctxTime := time.Unix(ctx.Date, 0).UTC().Format("2006-01-02 15:04:05")
			branchSQL += "AND (mg.arrival < ? OR (mg.arrival = ? AND mg.msgid < ?)) "
			branchArgs = append(branchArgs, ctxTime, ctxTime, ctx.ID)
		}
		branchSQL += "ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"

		sql, args := buildMTUnionAllMsgIDQuery(branchSQL, branchArgs, groupIDs, limit)
		listErr = db.Raw(sql, args...).Pluck("msgid", &msgIDs).Error
	}

	if listErr != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to list messages")
	}

	if len(msgIDs) == 0 {
		return c.JSON(fiber.Map{"messages": []uint64{}})
	}

	// Build pagination context from last ID.
	var respCtx *PaginationContext
	if len(msgIDs) == limit {
		// The list orders msgids by MAX(arrival) across the queried groups (see
		// buildMTUnionAllMsgIDQuery), so the cursor must be that same MAX — not an
		// arbitrary group's arrival via LIMIT 1. Otherwise, for a cross-posted
		// message the next page's arrival boundary lands at the wrong time and can
		// drop messages that sort between the two values.
		var lastArrival time.Time
		db.Table("messages_groups").Select("MAX(arrival)").
			Where("msgid = ? AND groupid IN ? AND deleted = 0", msgIDs[len(msgIDs)-1], groupIDs).
			Scan(&lastArrival)
		if !lastArrival.IsZero() {
			respCtx = &PaginationContext{
				Date: lastArrival.Unix(),
				ID:   msgIDs[len(msgIDs)-1],
			}
		}
	}

	return c.JSON(fiber.Map{
		"messages": msgIDs,
		"context":  respCtx,
	})
}

// GetMessagesWithHistory handles GET /message/:ids - fetches one or more messages.
// Message history is now returned via the user endpoint (GET /user/fetchmt?modtools=true).
func GetMessagesWithHistory(c *fiber.Ctx) error {
	ids := strings.Split(c.Params("ids"), ",")
	myid := user.WhoAmI(c)
	isPartner := false
	if key := c.Query("partner"); key != "" {
		if _, _, _, err := user.ValidatePartnerKey(database.DBConn, key); err == nil {
			isPartner = true
		}
	}

	if len(ids) >= 20 {
		return fiber.NewError(fiber.StatusBadRequest, "Steady on")
	}

	messages := GetMessagesByIds(myid, ids, isPartner)
	addRoadMetrics(myid, messages)

	if len(ids) == 1 {
		if len(messages) == 1 {
			return c.JSON(messages[0])
		}
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	return c.JSON(messages)
}

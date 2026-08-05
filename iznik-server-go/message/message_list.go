package message

import (
	"encoding/json"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
)

// --- Message List types and handler ---

type MessageGroupInfo struct {
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	Arrival    time.Time `json:"arrival"`
	Heldby     *uint64   `json:"heldby,omitempty"`
	// RippledIn marks a row created by the rippling engine; the mod queue uses it to show
	// the rippled-in banner authoritatively (see MessageGroup.RippledIn). (9808/303)
	RippledIn uint8 `json:"rippled_in"`
}

type PaginationContext struct {
	Date int64  `json:"Date"`
	ID   uint64 `json:"id"`
}

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
}

type ListMessagesResponse struct {
	Messages []ListMessageItem  `json:"messages"`
	Context  *PaginationContext `json:"context,omitempty"`
}

// ListMessages handles GET /messages - list messages with moderation queue support.
func ListMessages(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	db := database.DBConn

	// Parse parameters.
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
	subaction := c.Query("subaction", "")
	search := c.Query("search", "")
	fromuserStr := c.Query("fromuser", "0")
	fromuser, _ := strconv.ParseUint(fromuserStr, 10, 64)

	// Validate collection.
	validCollections := map[string]bool{
		"Approved": true,
		"Pending":  true,
		"Rejected": true,
		"Spam":     true,
	}
	if !validCollections[collection] {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid collection")
	}

	// Determine which groups to query.  When groupid=0 for non-Approved
	// collections, fetch from all the user's moderated groups.
	var groupIDs []uint64

	if myid == 0 && collection != utils.COLLECTION_APPROVED {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if groupid == 0 {
		if myid == 0 {
			return c.JSON(ListMessagesResponse{Messages: []ListMessageItem{}})
		}
		// Fetch from all groups this user moderates.  Return empty
		// list (not an error) if they don't moderate any groups.
		groupIDs = user.GetActiveModGroupIDs(myid)
		if len(groupIDs) == 0 {
			return c.JSON(ListMessagesResponse{Messages: []ListMessageItem{}})
		}
	} else {
		if collection != utils.COLLECTION_APPROVED {
			if !user.IsModOfGroup(myid, groupid) {
				return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
			}
		}
		groupIDs = []uint64{groupid}
	}

	// Parse pagination context.
	var ctx *PaginationContext
	contextStr := c.Query("context", "")
	if contextStr != "" {
		ctx = &PaginationContext{}
		if err := json.Unmarshal([]byte(contextStr), ctx); err != nil {
			ctx = nil
		}
	}

	var msgIDs []uint64

	// Handle search modes.
	if subaction == "searchall" && search != "" {
		// If the search term is numeric, also match on message ID.
		searchID, numErr := strconv.ParseUint(search, 10, 64)
		if numErr == nil && searchID > 0 {
			db.Table("messages_groups mg").
				Select("DISTINCT mg.msgid").
				Joins("INNER JOIN messages m ON m.id = mg.msgid").
				Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL AND m.id = ?",
					groupIDs, collection, searchID).
				Order("mg.arrival DESC").
				Limit(limit).
				Pluck("msgid", &msgIDs)
		}
		if len(msgIDs) == 0 {
			searchTerm := "%" + search + "%"
			db.Table("messages_groups mg").
				Select("DISTINCT mg.msgid").
				Joins("INNER JOIN messages m ON m.id = mg.msgid").
				Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL AND m.subject LIKE ?",
					groupIDs, collection, searchTerm).
				Order("mg.arrival DESC").
				Limit(limit).
				Pluck("msgid", &msgIDs)
		}
	} else if subaction == "searchmemb" && search != "" {
		// If search is a numeric user ID, do a fast direct lookup first.
		searchUID, numErr := strconv.ParseUint(search, 10, 64)
		if numErr == nil && searchUID > 0 {
			db.Table("messages_groups mg").
				Select("DISTINCT mg.msgid").
				Joins("INNER JOIN messages m ON m.id = mg.msgid").
				Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser = ?",
					groupIDs, collection, searchUID).
				Order("mg.arrival DESC").
				Limit(limit).
				Pluck("msgid", &msgIDs)
		}
		if len(msgIDs) == 0 {
			searchTerm := "%" + search + "%"
			db.Table("messages_groups mg").
				Select("DISTINCT mg.msgid").
				Joins("INNER JOIN messages m ON m.id = mg.msgid").
				Joins("INNER JOIN users u ON u.id = m.fromuser").
				Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
				Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND (u.fullname LIKE ? OR ue.email LIKE ?)",
					groupIDs, collection, searchTerm, searchTerm).
				Order("mg.arrival DESC").
				Limit(limit).
				Pluck("msgid", &msgIDs)
		}
	} else {
		// Standard listing with optional pagination and fromuser filter.
		//
		// fromuser>0
		// and ctx pagination give 2x2 = 4 possible rendered forms, all declared
		// in ormharness/shapes.json and proven by TestTier3Shapes_bfe25b4914e8
		// (iznik-server-go/test).
		// WHERE built as a single string for ONE Where() call: GORM's
		// clause.Where wraps any fragment containing "AND"/"OR" in an extra
		// paren pair once there is more than one Where expression to combine
		// (clause/where.go buildExprs), which would diverge from the golden.
		whereSQL := "mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL"
		whereArgs := []interface{}{groupIDs, collection}

		if fromuser > 0 {
			whereSQL += " AND m.fromuser = ?"
			whereArgs = append(whereArgs, fromuser)
		}

		if ctx != nil && ctx.Date > 0 {
			ctxTime := time.Unix(ctx.Date, 0).UTC().Format("2006-01-02 15:04:05")
			whereSQL += " AND (mg.arrival < ? OR (mg.arrival = ? AND mg.msgid < ?))"
			whereArgs = append(whereArgs, ctxTime, ctxTime, ctx.ID)
		}

		db.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where(whereSQL, whereArgs...).
			Order("mg.arrival DESC, mg.msgid DESC").Limit(limit).Pluck("msgid", &msgIDs)
	}

	if len(msgIDs) == 0 {
		return c.JSON(ListMessagesResponse{
			Messages: []ListMessageItem{},
		})
	}

	// Fetch message details in parallel.  Use a semaphore to cap the number
	// of concurrent message goroutines (each spawns 4 inner DB queries).
	messages := make([]ListMessageItem, len(msgIDs))
	var mu sync.Mutex
	var wgOuter sync.WaitGroup
	sem := make(chan struct{}, 10) // At most 10 messages × 4 queries = 40 DB connections.

	archiveDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	imageDomain := os.Getenv("IMAGE_DOMAIN")

	wgOuter.Add(len(msgIDs))

	for idx, msgID := range msgIDs {
		go func(idx int, msgID uint64) {
			sem <- struct{}{}        // Acquire semaphore slot.
			defer func() { <-sem }() // Release on exit.
			defer wgOuter.Done()

			var msg ListMessageItem
			var groups []MessageGroupInfo
			var attachments []MessageAttachment
			var replycount int64

			var wg sync.WaitGroup

			wg.Add(4)

			go func() {
				defer wg.Done()
				db.Table("messages m").
					Select("m.id, m.subject, m.type, m.fromuser, m.arrival, m.lat, m.lng, m.availablenow, m.availableinitially, m.tnpostid").
					Where("m.id = ?", msgID).Scan(&msg)
			}()

			go func() {
				defer wg.Done()
				db.Table("messages_groups").Select("groupid, collection, arrival, heldby, rippled_in").
					Where("msgid = ? AND deleted = 0", msgID).Scan(&groups)
			}()

			go func() {
				defer wg.Done()
				// Fetch first image only for thumbnail.
				db.Table("messages_attachments").Select("id, msgid, archived, externaluid, externalmods").
					Where("msgid = ?", msgID).Order("`primary` DESC, id ASC").Limit(1).Scan(&attachments)
			}()

			go func() {
				defer wg.Done()
				db.Table("chat_messages").
					Where("refmsgid = ? AND type = ? AND reviewrequired = 0 AND reviewrejected = 0", msgID, utils.MESSAGE_INTERESTED).
					Count(&replycount)
			}()

			wg.Wait()

			msg.Groups = groups
			msg.Replycount = int(replycount)

			// Compute expiresat from group settings.
			if len(groups) > 0 {
				mgs := make([]MessageGroup, len(groups))
				for i, g := range groups {
					mgs[i] = MessageGroup{Groupid: g.Groupid, Arrival: g.Arrival}
				}
				msg.Expiresat = computeExpiresat(db, msg.Type, mgs)
			}

			// Process attachment paths.
			for i, a := range attachments {
				if a.Externaluid != "" {
					attachments[i].Ouruid = a.Externaluid
					attachments[i].Externalmods = a.Externalmods
					attachments[i].Path = misc.GetImageDeliveryUrl(a.Externaluid, string(a.Externalmods))
					attachments[i].Paththumb = misc.GetImageDeliveryUrl(a.Externaluid, string(a.Externalmods))
				} else if a.Archived > 0 {
					attachments[i].Path = "https://" + archiveDomain + "/img_" + strconv.FormatUint(a.ID, 10) + ".jpg"
					attachments[i].Paththumb = "https://" + archiveDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
				} else {
					attachments[i].Path = "https://" + imageDomain + "/img_" + strconv.FormatUint(a.ID, 10) + ".jpg"
					attachments[i].Paththumb = "https://" + imageDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
				}
			}
			msg.Attachments = attachments

			// Blur location for privacy.
			msg.Lat, msg.Lng = utils.Blur(msg.Lat, msg.Lng, utils.BLUR_USER)

			mu.Lock()
			messages[idx] = msg
			mu.Unlock()
		}(idx, msgID)
	}

	wgOuter.Wait()

	// Filter out zero-ID entries (shouldn't happen, but be defensive).
	var filtered []ListMessageItem
	for _, m := range messages {
		if m.ID > 0 {
			filtered = append(filtered, m)
		}
	}

	// Build pagination context from the last message.
	var respCtx *PaginationContext
	if len(filtered) > 0 && len(filtered) == limit {
		last := filtered[len(filtered)-1]
		respCtx = &PaginationContext{
			Date: last.Arrival.Unix(),
			ID:   last.ID,
		}
	}

	return c.JSON(ListMessagesResponse{
		Messages: filtered,
		Context:  respCtx,
	})
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

	if collection == "Edit" {
		// Edit review uses messages_edits table, not messages_groups collection.
		// Restrict to ORIGIN messages_groups rows (rippled_in = 0). A post rippled INTO a
		// group gets an Approved row there (rippled_in = 1); without this filter an edit on a
		// rippled-in post surfaces in every receiving group's Edit queue (and to active mods
		// there via the all-groups path), but an edit belongs to the post's origin group(s)
		// only. Same bug class as the IP-abuse fix (WHERE rippled_in=0).
		db.Table("messages_edits me").
			Select("DISTINCT me.msgid").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid AND mg.deleted = 0 AND mg.rippled_in = 0").
			Where("mg.groupid IN (?) AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)",
				groupIDs).
			Order("me.timestamp DESC").
			Limit(limit).
			Pluck("msgid", &msgIDs)
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
			db.Raw(sql, args...).Pluck("msgid", &msgIDs)
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
			db.Raw(sql, args...).Pluck("msgid", &msgIDs)
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
			db.Raw(sql, args...).Pluck("msgid", &msgIDs)
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
				"AND (u.fullname LIKE ? OR ue.email LIKE ?) " +
				contentcheckFilter +
				" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
			sql, args := buildMTUnionAllMsgIDQuery(branchSQL, []interface{}{collection, searchTerm, searchTerm}, groupIDs, limit)
			db.Raw(sql, args...).Pluck("msgid", &msgIDs)
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
		db.Raw(sql, args...).Pluck("msgid", &msgIDs)
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

	if len(ids) == 1 {
		if len(messages) == 1 {
			return c.JSON(messages[0])
		}
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	return c.JSON(messages)
}

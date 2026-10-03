package logs

import (
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/gofiber/fiber/v2"
)

// buildGetLogsQuery is a pure SQL-builder: no database needed - see
// message/message.go's buildApplyPatchMessageCoreUpdate for the established
// convention this follows. Extracted from GetLogs (a pure
// behaviour-preserving refactor - the actual SQL and db.Raw call are
// unchanged) so its independently-optional WHERE factors can be proven via
// the retired ormharness's AssertGoldenWhereFieldwise
// (logs_wherefieldwise_tier9_test.go, removed in d22ba1d6c)
// rather than left as an unproven "352 reachable combinations" policy
// decision - see keep-raw site 6cf1b5aded22. isAdmin is the caller's
// already-resolved permission check (site 5dc370f37ed3, unchanged, still in
// GetLogs) - this function only assembles the query from whatever the
// caller resolved.
func buildGetLogsQuery(logtype string, userid uint64, logsubtype string, dateStr string, search string, modmailsonly bool, limit int, contextID uint64, isAdmin bool) (string, []interface{}) {
	// Build query based on logtype.
	var types []string
	var subtypes []string

	switch logtype {
	case "messages":
		types = []string{log.LOG_TYPE_MESSAGE}
		if logsubtype != "" {
			subtypes = []string{logsubtype}
		} else {
			subtypes = []string{log.LOG_SUBTYPE_RECEIVED, log.LOG_SUBTYPE_APPROVED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED, log.LOG_SUBTYPE_AUTO_REPOSTED, log.LOG_SUBTYPE_AUTO_APPROVED, log.LOG_SUBTYPE_OUTCOME, log.LOG_SUBTYPE_HOLD, log.LOG_SUBTYPE_RELEASE}
		}
	case "memberships":
		types = []string{log.LOG_TYPE_USER}
		if logsubtype != "" {
			subtypes = []string{logsubtype}
		} else {
			subtypes = []string{log.LOG_SUBTYPE_JOINED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_APPROVED, log.LOG_SUBTYPE_APPLIED, log.LOG_SUBTYPE_AUTO_APPROVED, log.LOG_SUBTYPE_LEFT}
		}
	case "user":
		// User-specific logs: all actions affecting this user
		// (returns all types except Created/Merged/YahooConfirmed).
		types = nil
		subtypes = nil
	default:
		// General logs - just filter by user.
		types = nil
		subtypes = nil
	}

	// Build WHERE clauses.
	where := []string{"1=1"}
	args := []interface{}{}

	// exclude uninteresting log subtypes for user-specific logs.
	if logtype == "user" {
		where = append(where, "NOT (logs.type = 'User' AND logs.subtype IN ('Created', 'Merged'))")
	}

	if len(types) > 0 {
		where = append(where, "logs.type IN (?)")
		args = append(args, types)
	}

	if len(subtypes) > 0 {
		where = append(where, "logs.subtype IN (?)")
		args = append(args, subtypes)
	}

	// Apply modmailsonly filter if requested.
	// V1 filters to: Message (Rejected, Deleted, Replied) and User (Mailed, Rejected, Deleted).
	if modmailsonly {
		where = append(where, "((logs.type = ? AND logs.subtype IN (?, ?, ?)) OR (logs.type = ? AND logs.subtype IN (?, ?, ?)))")
		args = append(args, log.LOG_TYPE_MESSAGE, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED, log.LOG_SUBTYPE_REPLIED,
			log.LOG_TYPE_USER, log.LOG_SUBTYPE_MAILED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED)
	}

	if dateStr != "" {
		days, _ := strconv.Atoi(dateStr)
		if days >= 0 {
			mysqlTime := time.Now().AddDate(0, 0, -days).Format("2006-01-02")
			where = append(where, "logs.timestamp >= ?")
			args = append(args, mysqlTime)
		}
	}

	if contextID > 0 {
		where = append(where, "logs.id < ?")
		args = append(args, contextID)
	}

	if userid > 0 {
		where = append(where, "(logs.user = ? OR logs.byuser = ?)")
		args = append(args, userid, userid)
	}

	// Build the query. Always join messages to reconstruct historical subject via messages_edits.
	// Use COALESCE with IFNULL to handle NULL subjects properly: if the subject was edited after
	// this log event, return the subject as it was before that edit (oldsubject); otherwise return
	// the current messages.subject. Use IFNULL as fallback for very old pre-migration rows.
	query := "SELECT logs.*, IFNULL(COALESCE(" +
		"(SELECT me.oldsubject FROM messages_edits me " +
		"WHERE logs.msgid IS NOT NULL AND me.msgid = logs.msgid AND me.timestamp > logs.timestamp " +
		"AND me.oldsubject IS NOT NULL ORDER BY me.timestamp ASC LIMIT 1), " +
		"messages.subject), '') AS msgsubject " +
		"FROM logs LEFT JOIN messages ON messages.id = logs.msgid "

	if search != "" {
		query += "LEFT JOIN users ON users.id = logs.user "

		searchLike := "%" + search + "%"
		where = append(where, "(users.firstname LIKE ? OR users.lastname LIKE ? OR users.fullname LIKE ? "+
			"OR CONCAT(users.firstname, ' ', users.lastname) LIKE ? OR messages.subject LIKE ?)")
		args = append(args, searchLike, searchLike, searchLike, searchLike, searchLike)
	}

	query += "WHERE " + strings.Join(where, " AND ") +
		" ORDER BY logs.id DESC LIMIT ?"
	args = append(args, limit)

	return query, args
}

// GetLogs handles GET /logs for moderator log viewing.
//
// @Summary Get logs
// @Description Returns moderator logs filtered by type, search, with pagination
// @Tags logs
// @Produce json
// @Param logtype query string false "Log type: messages, memberships, user"
// @Param userid query integer false "User ID"
// @Param logsubtype query string false "Log subtype filter"
// @Param date query integer false "Days ago"
// @Param search query string false "Search term"
// @Param limit query integer false "Result limit (default 20)"
// @Param context query string false "Pagination context (last log ID)"
// @Success 200 {object} map[string]interface{}
// @Router /api/logs [get]
func GetLogs(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 || !auth.IsModerator(myid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Not moderator"})
	}

	db := database.DBConn

	logtype := c.Query("logtype", "")
	userid, _ := strconv.ParseUint(c.Query("userid", "0"), 10, 64)
	logsubtype := c.Query("logsubtype", "")
	dateStr := c.Query("date", "")
	search := c.Query("search", "")
	modmailsonly := c.Query("modmailsonly", "") == "true"
	limit, _ := strconv.Atoi(c.Query("limit", "20"))
	contextID, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	if limit <= 0 || limit > 100 {
		limit = 20
	}

	isAdmin := auth.IsAdminOrSupport(myid)

	// ORM migration site 6cf1b5aded22 (where-fieldwise coverage, not
	// exhaustive shapes) - see buildGetLogsQuery above for the query
	// assembly itself, factored out for fieldwise proof.
	query, args := buildGetLogsQuery(logtype, userid, logsubtype, dateStr, search, modmailsonly, limit, contextID, isAdmin)

	type LogRow struct {
		ID         uint64  `json:"id"`
		Timestamp  string  `json:"timestamp"`
		Type       string  `json:"type"`
		Subtype    *string `json:"subtype"`
		User       *uint64 `json:"user"`
		Byuser     *uint64 `json:"byuser"`
		Msgid      *uint64 `json:"msgid"`
		Configid   *uint64 `json:"configid"`
		Stdmsgid   *uint64 `json:"stdmsgid"`
		Text       *string `json:"text"`
		Msgsubject *string `json:"msgsubject"`
	}

	var rows []LogRow
	db.Raw(query, args...).Scan(&rows)

	// Enrich with user and message data.
	result := make([]map[string]interface{}, len(rows))
	for i, r := range rows {
		entry := map[string]interface{}{
			"id":        r.ID,
			"timestamp": r.Timestamp,
			"type":      r.Type,
			"subtype":   r.Subtype,
			"text":      r.Text,
		}

		// V2 pattern: return IDs only — frontend fetches details from stores.
		if r.User != nil && *r.User > 0 {
			entry["userid"] = *r.User
		}

		if r.Byuser != nil && *r.Byuser > 0 {
			entry["byuserid"] = *r.Byuser
		}

		if r.Msgid != nil && *r.Msgid > 0 {
			entry["msgid"] = *r.Msgid
		}

		if r.Stdmsgid != nil && *r.Stdmsgid > 0 {
			entry["stdmsgid"] = *r.Stdmsgid
		}

		if r.Configid != nil && *r.Configid > 0 {
			entry["configid"] = *r.Configid
		}

		if r.Msgsubject != nil && *r.Msgsubject != "" {
			entry["msgsubject"] = *r.Msgsubject
		}

		// Outcome subtype has long text like "Taken: thanks everyone".
		// Trim to just the first word (e.g. "Taken").
		if r.Subtype != nil && *r.Subtype == log.LOG_SUBTYPE_OUTCOME && r.Text != nil && *r.Text != "" {
			firstWord := strings.SplitN(*r.Text, " ", 2)[0]
			entry["text"] = &firstWord
		}

		result[i] = entry
	}

	// Build context for pagination. Return null when no more results to prevent infinite loop.
	var ctx interface{}
	if len(rows) > 0 {
		ctx = map[string]interface{}{
			"id": rows[len(rows)-1].ID,
		}
	}

	return c.JSON(fiber.Map{
		"ret":     0,
		"status":  "Success",
		"logs":    result,
		"context": ctx,
	})
}

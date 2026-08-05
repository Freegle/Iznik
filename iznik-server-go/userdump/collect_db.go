package userdump

import (
	"regexp"
	"strings"
	"time"

	"gorm.io/gorm"
)

// dbSpec is one extraction: SELECT * FROM <table> WHERE <where>, optionally
// capped. The table is created in the dump from whatever columns come back.
type dbSpec struct {
	table string
	where string
	args  []interface{}
	limit int
}

var secretColRe = regexp.MustCompile(`(?i)(password|passwd|secret|token|credential|apikey|api_key|privatekey)`)

// redactColFor returns a predicate naming columns whose values must be withheld
// even from support: secrets by name, plus sessions.series (the other half of a
// session token).
func redactColFor(table string) func(col string) bool {
	return func(col string) bool {
		if secretColRe.MatchString(col) {
			return true
		}
		if strings.EqualFold(table, "sessions") && strings.EqualFold(col, "series") {
			return true
		}
		return false
	}
}

// existingTables returns the lowercased set of tables in the current database,
// so specs for tables absent from this schema are skipped quietly.
//
// ORM migration site f0543b22c8e8 (userdump keep-raw review, revisited). The
// prior reason ("consistent with the sqlite.go entries") was wrong: this
// queries the Percona cluster, not the SQLite export file - gdb is the same
// *gorm.DB every other converted site uses, not a separate connection.
func existingTables(gdb *gorm.DB) map[string]bool {
	out := map[string]bool{}
	var names []string
	gdb.Table("information_schema.tables").Select("table_name").Where("table_schema = DATABASE()").Scan(&names)
	for _, t := range names {
		out[strings.ToLower(t)] = true
	}
	return out
}

// scanIDs returns a single numeric column as a slice of args for IN clauses.
// query is caller-supplied SQL text (a fixed literal at each of its three
// call sites in userdump.go's buildPlan, but the string itself lives at the
// call site, not here) - this remains a keep-raw site (e7d7cae307a7): the
// SQL this call actually executes belongs to its callers, the same way
// database/insert.go's ExecInsertGetID and database/retry.go's wrappers are
// keep-raw as generic executors rather than converted or inventoried per
// caller. Only the connection handle changes here (sqlDB *sql.DB ->
// gdb *gorm.DB, sqlDB.Query -> gdb.Raw(...).Rows(), same *sql.Rows-returning
// contract), needed so this and existingTables/runDBSpec/gatherEmails share
// one handle type through buildDump/buildPlan again.
func scanIDs(gdb *gorm.DB, query string, args ...interface{}) []interface{} {
	rows, err := gdb.Raw(query, args...).Rows()
	if err != nil {
		return nil
	}
	defer rows.Close()
	var ids []interface{}
	for rows.Next() {
		var id int64
		if rows.Scan(&id) == nil {
			ids = append(ids, id)
		}
	}
	return ids
}

func inClause(ids []interface{}) (string, []interface{}) {
	if len(ids) == 0 {
		return "", nil
	}
	return "(" + strings.TrimSuffix(strings.Repeat("?,", len(ids)), ",") + ")", ids
}

// runDBSpec executes one spec and copies the rows into the dump.
//
// ORM migration site 1722b492f85b (userdump keep-raw review, revisited).
// spec.table and spec.where are never user input - they come from the fixed
// literal list buildDBSpecs assembles (61 hardcoded table/where pairs) - so
// .Table(spec.table) and .Where(spec.where, spec.args...) are the same
// runtime-variable-but-caller-controlled shape as any other converted site
// that takes its table name from a Go string variable. GORM auto-quotes
// spec.table as a constructed identifier, so the manual backtick-wrapping
// the raw SQL needed is no longer necessary. The previous "signature change
// is a redesign" framing undersold this: gdb replaces sqlDB uniformly across
// existingTables/scanIDs/runDBSpec/gatherEmails and their two callers
// (buildDump, buildPlan), all four already only ever needed "a handle to
// query the Percona cluster" - there was no second pattern to split across.
func runDBSpec(b *Builder, gdb *gorm.DB, spec dbSpec) (int, error) {
	tx := gdb.Table(spec.table).Where(spec.where, spec.args...)
	if spec.limit > 0 {
		tx = tx.Order("1 DESC").Limit(spec.limit)
	}
	rows, err := tx.Rows()
	if err != nil {
		return 0, err
	}
	defer rows.Close()
	return b.CopyMySQLRows(spec.table, rows, redactColFor(spec.table))
}

// buildDBSpecs returns the full set of user-linked extractions — a superset of
// the V1 GDPR export. chatIDs/msgIDs/trackIDs are anchor id sets gathered up
// front so child tables can be pulled by IN clause (and both sides of chats).
func buildDBSpecs(userID int64, chatIDs, recentChatIDs, msgIDs, trackIDs []interface{}, since time.Time) []dbSpec {
	u := []interface{}{userID}
	uu := []interface{}{userID, userID}
	var specs []dbSpec
	add := func(table, where string, args []interface{}, limit int) {
		specs = append(specs, dbSpec{table: table, where: where, args: args, limit: limit})
	}

	// Identity / account.
	add("users", "id = ?", u, 0)
	add("users_emails", "userid = ?", u, 0)
	add("users_logins", "userid = ?", u, 0)
	add("users_images", "userid = ?", u, 0)
	add("users_aboutme", "userid = ?", u, 0)
	add("users_phones", "userid = ?", u, 0)
	add("users_addresses", "userid = ?", u, 0)
	add("users_push_notifications", "userid = ?", u, 0)
	add("users_donations", "userid = ?", u, 0)
	add("users_donations_asks", "userid = ?", u, 0)
	add("giftaid", "userid = ?", u, 0)
	add("users_invitations", "userid = ?", u, 0)
	add("users_builddates", "userid = ?", u, 0)
	add("sessions", "userid = ?", u, 1000)
	add("users_exports", "userid = ?", u, 0)
	add("users_thanks", "userid = ?", u, 0)
	add("merges", "user1 = ? OR user2 = ? OR offeredby = ?", []interface{}{userID, userID, userID}, 0)

	// Memberships.
	add("memberships", "userid = ?", u, 0)
	add("memberships_history", "userid = ?", u, 0)

	// Posts.
	add("messages", "fromuser = ?", u, 0)
	add("messages_drafts", "userid = ?", u, 0)

	if in, args := inClause(msgIDs); in != "" {
		withU := func(a []interface{}) []interface{} { return append([]interface{}{userID}, a...) }
		add("messages_groups", "msgid IN "+in, args, 0)
		add("messages_attachments", "msgid IN "+in, args, 0)
		add("messages_items", "msgid IN "+in, args, 0)
		add("messages_deadlines", "msgid IN "+in, args, 0)
		add("messages_history", "fromuser = ? OR msgid IN "+in, withU(args), 0)
		add("messages_outcomes", "userid = ? OR msgid IN "+in, withU(args), 0)
		add("messages_promises", "userid = ? OR msgid IN "+in, withU(args), 0)
		add("messages_reneged", "userid = ? OR msgid IN "+in, withU(args), 0)
		add("messages_likes", "userid = ? OR msgid IN "+in, withU(args), 0)
		add("messages_by", "userid = ? OR msgid IN "+in, withU(args), 50000)
		add("messages_edits", "byuser = ? OR msgid IN "+in, withU(args), 0)
	} else {
		add("messages_history", "fromuser = ?", u, 0)
		add("messages_outcomes", "userid = ?", u, 0)
		add("messages_promises", "userid = ?", u, 0)
		add("messages_reneged", "userid = ?", u, 0)
		add("messages_likes", "userid = ?", u, 0)
		add("messages_by", "userid = ?", u, 50000)
		add("messages_edits", "byuser = ?", u, 0)
	}

	// Chats — both sides of every room the user is in.
	//
	// Room membership is NOT windowed: which conversations someone is in is
	// cheap to collect and support needs the whole picture. The message BODIES
	// are, because they are not cheap. A moderator sits in the roster of every
	// Mod2Mod and User2Mod chat on their groups - one real admin is in 18,664
	// rooms - and "chat_messages WHERE chatid IN (18,664 ids)" with no date
	// bound cannot finish: a bare COUNT of it against production ran for over
	// two minutes, so the dump always blew the caller's timeout and that member
	// could never be investigated at all. Only 332 of those rooms had any
	// activity in the default 90-day window, so anchoring the messages on the
	// rooms active within the window - the window the dump already documents
	// via ?since= - collapses the query while losing nothing anyone asked for.
	// The (chatid, date) index serves exactly this shape.
	if in, args := inClause(chatIDs); in != "" {
		add("chat_rooms", "id IN "+in, args, 0)
		add("chat_roster", "chatid IN "+in, args, 0)
		add("chat_messages_held", "chatid IN "+in+" OR userid = ?", append(append([]interface{}{}, args...), userID), 0)
	} else {
		add("chat_messages_held", "userid = ?", u, 0)
	}
	if in, args := inClause(recentChatIDs); in != "" {
		add("chat_messages", "chatid IN "+in+" AND date >= ?", append(append([]interface{}{}, args...), since), 0)
	}
	add("users_chatlists", "userid = ?", u, 0)
	add("users_expected", "expecter = ? OR expectee = ?", uu, 0)
	add("users_nudges", "fromuser = ? OR touser = ?", uu, 0)

	// Moderation.
	add("users_comments", "userid = ? OR byuserid = ?", uu, 0)
	add("users_banned", "userid = ? OR byuser = ?", uu, 0)
	add("spam_users", "userid = ? OR byuserid = ?", uu, 0)
	add("spam_whitelist_links", "userid = ?", u, 0)
	add("microactions", "userid = ?", u, 0)
	add("modnotifs", "userid = ?", u, 0)
	add("users_modmails", "userid = ?", u, 0)
	add("users_related", "user1 = ? OR user2 = ?", uu, 0)

	// Logs.
	add("logs", "user = ? OR byuser = ?", uu, 20000)
	add("logs_emails", "userid = ?", u, 20000)
	add("logs_events", "userid = ?", u, 20000)
	add("audits", "user_id = ?", u, 20000)

	// Email tracking.
	add("email_tracking", "userid = ?", u, 0)
	if in, args := inClause(trackIDs); in != "" {
		add("email_tracking_clicks", "email_tracking_id IN "+in, args, 0)
		add("email_tracking_images", "email_tracking_id IN "+in, args, 0)
	}
	add("users_digests", "userid = ?", u, 0)
	add("alerts_tracking", "userid = ?", u, 0)
	add("engage", "userid = ?", u, 0)

	// Newsfeed / stories / community.
	add("newsfeed", "userid = ?", u, 0)
	add("newsfeed_likes", "userid = ?", u, 0)
	add("newsfeed_reports", "userid = ?", u, 0)
	add("newsfeed_unfollow", "userid = ?", u, 0)
	add("users_stories", "userid = ?", u, 0)
	add("users_stories_likes", "userid = ?", u, 0)
	add("users_stories_requested", "userid = ?", u, 0)
	add("communityevents", "userid = ?", u, 0)
	add("volunteering", "userid = ?", u, 0)
	add("polls_users", "userid = ?", u, 0)

	// Location / activity / preferences / misc.
	add("search_history", "userid = ?", u, 0)
	add("users_approxlocs", "userid = ?", u, 0)
	add("users_active", "userid = ?", u, 0)
	add("users_replytime", "userid = ?", u, 0)
	add("users_nearby", "userid = ?", u, 5000)
	add("users_notifications", "fromuser = ? OR touser = ?", uu, 0)
	add("locations_excluded", "userid = ?", u, 0)
	add("isochrones_users", "userid = ?", u, 0)

	return specs
}

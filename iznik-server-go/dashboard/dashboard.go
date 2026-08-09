package dashboard

import (
	"context"
	json2 "encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"sort"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// GetDashboard handles GET /dashboard with component-based or legacy response.
//
// @Summary Get dashboard data
// @Description Returns dashboard components for moderator/user dashboards
// @Tags dashboard
// @Produce json
// @Param components query string false "Comma-separated component names"
// @Param group query integer false "Group ID"
// @Param systemwide query boolean false "System-wide data"
// @Param allgroups query boolean false "All moderator groups"
// @Param start query string false "Start date (default: 30 days ago)"
// @Param end query string false "End date (default: today)"
// @Success 200 {object} map[string]interface{}
// @Router /api/dashboard [get]
func GetDashboard(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	db := database.DBConn

	// Heatmap: return location data for recent successful messages.
	if c.Query("heatmap") == "true" || c.Query("heatmap") == "1" {
		type HeatmapPoint struct {
			Lat   float64 `json:"lat"`
			Lng   float64 `json:"lng"`
			Count int     `json:"count"`
		}

		// Aggregate successful posts into ~1km cells (2 dp ≈ 1.1km) and return a count
		// per cell. The client weights the heatmap by `count` (log-scaled), so every
		// point MUST carry one - returning raw points with no count left the client's
		// weighting NaN and the map blank. Rounding also blurs exact locations (the page
		// states locations are approximate for privacy) and shrinks the payload.
		var points []HeatmapPoint
		db.Table("messages_spatial").
			Select("ROUND(ST_Y(point), 2) AS lat, ROUND(ST_X(point), 2) AS lng, COUNT(*) AS count").
			Where("arrival > DATE_SUB(NOW(), INTERVAL 31 DAY) AND successful = 1").
			Group("ROUND(ST_Y(point), 2), ROUND(ST_X(point), 2)").
			Scan(&points)

		if points == nil {
			points = make([]HeatmapPoint, 0)
		}

		return c.JSON(fiber.Map{
			"ret":     0,
			"status":  "Success",
			"heatmap": points,
		})
	}

	// Parse date range.
	// The end date is bumped by one day so that SQL "<= endQ" includes the
	// entire final day.
	startStr := c.Query("start", "30 days ago")
	endStr := c.Query("end", "today")
	startDate := parseRelativeDate(startStr)
	endDate := parseRelativeDate(endStr).AddDate(0, 0, 1)
	startQ := startDate.Format("2006-01-02")
	endQ := endDate.Format("2006-01-02")

	// Determine group scope.
	groupID := c.QueryInt("group", 0)
	systemwide := c.Query("systemwide") == "true" || c.Query("systemwide") == "1"
	allgroups := c.Query("allgroups") == "true" || c.Query("allgroups") == "1"

	groupIDs := resolveGroupIDs(myid, uint64(groupID), systemwide, allgroups)

	// Check if user is a moderator (for mod-only components).
	isMod := false
	if myid > 0 && len(groupIDs) > 0 {
		var modCount int64
		db.Table("memberships").Where("userid = ? AND role IN (?, ?) AND groupid IN ?",
			myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, groupIDs).Count(&modCount)
		isMod = modCount > 0
	}

	// Component-based (new style).
	// Accept both "components=X,Y" and "components[]=X&components[]=Y" query styles.
	components := c.Query("components", "")
	if components == "" {
		args := c.Context().QueryArgs()
		vals := args.PeekMulti("components[]")
		if len(vals) > 0 {
			parts := make([]string, len(vals))
			for i, v := range vals {
				parts[i] = string(v)
			}
			components = strings.Join(parts, ",")
		}
	}
	if components != "" {
		result := make(map[string]interface{})
		for _, comp := range strings.Split(components, ",") {
			comp = strings.TrimSpace(comp)
			result[comp] = getComponent(comp, groupIDs, startQ, endQ, systemwide, isMod)
		}
		return c.JSON(fiber.Map{
			"ret":        0,
			"status":     "Success",
			"components": result,
			"start":      startStr,
			"end":        endStr,
		})
	}

	// Legacy style - return basic dashboard.
	dashboard := make(map[string]interface{})
	dashboard["newmembers"] = 0
	dashboard["newmessages"] = 0

	if len(groupIDs) > 0 {
		var msgCount int64
		db.Table("messages").
			Select("COUNT(DISTINCT messages.id)").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Where("messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?)", startQ, endQ, groupIDs).
			Scan(&msgCount)
		dashboard["newmessages"] = msgCount

		var memCount int64
		// Converted together with its
		// identical sibling in getRecentCounts below: leaving one of two
		// textually identical statements raw is the configuration that
		// renumbers the survivor's site ID (ratchet gate h).
		db.Table("memberships").Where("groupid IN ? AND added >= ? AND added <= ?",
			groupIDs, startQ, endQ).Count(&memCount)
		dashboard["newmembers"] = memCount
	}

	return c.JSON(fiber.Map{
		"ret":       0,
		"status":    "Success",
		"dashboard": dashboard,
		"start":     startStr,
		"end":       endStr,
	})
}

func getComponent(comp string, groupIDs []uint64, startQ, endQ string, systemwide, isMod bool) interface{} {
	switch comp {
	case "RecentCounts":
		return getRecentCounts(groupIDs, startQ, endQ)
	case "PopularPosts":
		return getPopularPosts(groupIDs, startQ, endQ, systemwide)
	case "UsersPosting":
		if !isMod {
			return nil
		}
		return getUsersPosting(groupIDs, startQ, endQ)
	case "UsersReplying":
		if !isMod {
			return nil
		}
		return getUsersReplying(groupIDs, startQ, endQ)
	case "ModeratorsActive":
		if !isMod {
			return nil
		}
		return getModeratorsActive(groupIDs)
	case "MessageBreakdown":
		return getMessageBreakdown(groupIDs, startQ, endQ)
	case "Activity", "Replies", "ApprovedMessageCount",
		"Weight", "Outcomes", "ActiveUsers", "ApprovedMemberCount":
		// ApprovedMemberCount (community member counts) is public — group size is
		// not sensitive and the Authority stats page shows it to everyone. Only
		// ActiveUsers remains moderator-only.
		modOnly := comp == "ActiveUsers"
		if modOnly && !isMod {
			return nil
		}
		return getStatsTimeSeries(comp, groupIDs, startQ, endQ)
	case "Donations":
		return getDonations(groupIDs, startQ, endQ, systemwide)
	case "Happiness":
		if !isMod {
			return nil
		}
		return getHappiness(groupIDs, startQ, endQ, systemwide)
	case "DiscourseTopics":
		if !isMod {
			return nil
		}
		return getDiscourseTopics()
	}
	return nil
}

func getRecentCounts(groupIDs []uint64, startQ, endQ string) map[string]int64 {
	db := database.DBConn
	result := map[string]int64{"newmembers": 0, "newmessages": 0}
	if len(groupIDs) == 0 {
		return result
	}

	var newmessages, newmembers int64
	db.Table("messages").
		Select("COUNT(DISTINCT messages.id)").
		Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
		Where("messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?) AND messages.arrival >= ? AND messages.arrival <= ?",
			startQ, endQ, groupIDs, startQ, endQ).
		Scan(&newmessages)

	// Identical sibling of
	// 770ce1ca6e09 above in GetDashboard; converted together (ratchet gate h).
	db.Table("memberships").Where("groupid IN ? AND added >= ? AND added <= ?",
		groupIDs, startQ, endQ).Count(&newmembers)

	result["newmessages"] = newmessages
	result["newmembers"] = newmembers

	return result
}

func getPopularPosts(groupIDs []uint64, startQ, endQ string, systemwide bool) []map[string]interface{} {
	db := database.DBConn
	if len(groupIDs) == 0 {
		return []map[string]interface{}{}
	}

	type PostRow struct {
		Views   int
		ID      uint64
		Subject string
	}

	var posts []PostRow

	if systemwide {
		// For systemwide queries, skip the messages_groups join entirely since
		// all groups are included. Use a correlated subquery on messages_likes
		// instead of a JOIN to avoid scanning the 73M+ row messages_likes table.
		// Cap at 90 days max to keep query time under ~5s.
		start, err1 := time.Parse("2006-01-02", startQ)
		end, err2 := time.Parse("2006-01-02", endQ)
		capStart := startQ
		if err1 == nil && err2 == nil {
			maxWindow := end.AddDate(0, 0, -90)
			if start.Before(maxWindow) {
				capStart = maxWindow.Format("2006-01-02")
			}
		}

		db.Table("messages m").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = m.id AND type = ?) AS views, m.id, m.subject", utils.MESSAGE_LIKES_VIEW).
			Where("m.arrival >= ? AND m.arrival <= ? AND m.deleted IS NULL", capStart, endQ).
			Order("views DESC").
			Limit(5).
			Scan(&posts)
	} else {
		// For specific groups, use correlated subquery with messages_groups filter.
		// Uses existing groupid index on messages_groups.
		//
		// rippled_in = 0 restricts to each post's ORIGIN group row. Rippling-out adds
		// an Approved messages_groups row (rippled_in = 1) per group a post reaches, so
		// without this filter a post rippled into several of a moderator's groups would
		// appear once per group (duplicates under allgroups) and posts merely rippled
		// INTO a group would pollute that group's own popular list. GROUP BY mg.msgid
		// additionally collapses genuine multi-group (crossposted) origin rows so each
		// post is listed once. Same native-only pattern as the stats/IP-abuse/edit-queue
		// fixes (fa60c39b0, 4b6d7b3c3).
		db.Table("messages_groups mg").
			Select("(SELECT COUNT(*) FROM messages_likes WHERE msgid = mg.msgid AND type = ?) AS views, mg.msgid AS id, MIN(m.subject) AS subject", utils.MESSAGE_LIKES_VIEW).
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.arrival >= ? AND mg.arrival <= ? AND mg.groupid IN (?) AND mg.collection = ? AND mg.rippled_in = 0",
				startQ, endQ, groupIDs, utils.COLLECTION_APPROVED).
			Group("mg.msgid").
			Order("views DESC").
			Limit(5).
			Scan(&posts)
	}

	userSite := os.Getenv("USER_SITE")
	if userSite == "" {
		userSite = "www.ilovefreegle.org"
	}

	result := make([]map[string]interface{}, len(posts))
	for i, p := range posts {
		// Get reply count.
		var replies int
		db.Table("chat_messages").Select("COUNT(*)").Where("refmsgid = ?", p.ID).Scan(&replies)

		result[i] = map[string]interface{}{
			"views":   p.Views,
			"id":      p.ID,
			"subject": p.Subject,
			"replies": replies,
			"url":     fmt.Sprintf("https://%s/message/%d", userSite, p.ID),
		}
	}
	return result
}

func getUsersPosting(groupIDs []uint64, startQ, endQ string) []map[string]interface{} {
	db := database.DBConn
	if len(groupIDs) == 0 {
		return []map[string]interface{}{}
	}

	type UserCount struct {
		Count    int
		Fromuser uint64
	}

	var users []UserCount
	db.Table("messages").
		Select("COUNT(*) AS count, messages.fromuser").
		Where("id IN (SELECT msgid FROM messages_groups WHERE messages_groups.arrival >= ? AND messages_groups.arrival <= ? AND groupid IN (?)) AND messages.arrival >= ? AND messages.arrival <= ?",
			startQ, endQ, groupIDs, startQ, endQ).
		Group("messages.fromuser").
		Order("count DESC").
		Limit(5).
		Scan(&users)

	result := make([]map[string]interface{}, len(users))
	for i, u := range users {
		var displayname string
		db.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").Where("id = ?", u.Fromuser).Scan(&displayname)
		result[i] = map[string]interface{}{
			"id":          u.Fromuser,
			"displayname": displayname,
			"posts":       u.Count,
		}
	}
	return result
}

// usersReplyingWindowDays bounds each messages_groups arrival-range scan in getUsersReplying to at
// most a week of rows, regardless of how wide the dashboard's overall date range is (Admins can
// pick "systemwide" across ~442 groups, or a custom range back to 2015) - keeps every individual
// statement a cheap seek on the existing `arrival` index instead of one scan across a large
// fraction of the 9.4M-row table.
const usersReplyingWindowDays = 7

// usersReplyingBatch bounds each chat_messages IN (...) lookup in getUsersReplying so the
// statement stays a bounded set of keyed lookups on the existing refmsgid index, rather than
// growing with the number of messages the date range/group scope matched.
const usersReplyingBatch = 1500

// usersReplyingDeadline bounds the whole chunked walk. The fiber request context can't be used
// for this (fasthttp only cancels it on server shutdown), so without an explicit deadline an
// abandoned systemwide/wide-range request would keep stepping through every remaining window.
const usersReplyingDeadline = 60 * time.Second

func getUsersReplying(groupIDs []uint64, startQ, endQ string) []map[string]interface{} {
	if len(groupIDs) == 0 {
		return []map[string]interface{}{}
	}
	ctx, cancel := context.WithTimeout(context.Background(), usersReplyingDeadline)
	defer cancel()
	db := database.DBConn.WithContext(ctx)

	// Step 1: which messages got replies in scope, and how many of the selected groups each one
	// matched (a message crossposted to k of them counts k times below, matching the old single
	// JOIN's behaviour of producing one row per (chat_message, messages_groups) match).
	multiplicity := repliedMessageMultiplicity(db, groupIDs, startQ, endQ)
	if len(multiplicity) == 0 {
		return []map[string]interface{}{}
	}

	// Step 2: batch-fetch reply counts per (message, user) for those messages, then fold them
	// into per-user totals weighted by multiplicity.
	rows := repliesForMessages(db, multiplicity)
	totals := mergeReplyCounts(rows, multiplicity)
	users := topUserCounts(totals, 5)

	result := make([]map[string]interface{}, len(users))
	for i, u := range users {
		var displayname string
		db.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").Where("id = ?", u.Userid).Scan(&displayname)
		result[i] = map[string]interface{}{
			"id":          u.Userid,
			"displayname": displayname,
			"replies":     u.Count,
		}
	}
	return result
}

// repliedMessageMultiplicity walks [startQ, endQ] in usersReplyingWindowDays-day sub-windows, each
// running a bounded arrival-indexed scan of messages_groups, and returns how many times each msgid
// matched the group scope. startQ/endQ follow GetDashboard's convention: endQ is already the
// desired end date plus one day, so "arrival <= endQ" on the final window reproduces the original
// query's inclusive-through-the-last-day semantics exactly; interior windows use a strict "<"
// upper bound (equal to the next window's lower bound) so no arrival can be double-counted across
// windows.
func repliedMessageMultiplicity(db *gorm.DB, groupIDs []uint64, startQ, endQ string) map[uint64]int {
	multiplicity := make(map[uint64]int)

	start, err := time.Parse("2006-01-02", startQ)
	if err != nil {
		return multiplicity
	}
	end, err := time.Parse("2006-01-02", endQ)
	if err != nil {
		return multiplicity
	}

	for winStart := start; winStart.Before(end); winStart = winStart.AddDate(0, 0, usersReplyingWindowDays) {
		winEnd := winStart.AddDate(0, 0, usersReplyingWindowDays)
		last := !winEnd.Before(end)
		if last {
			winEnd = end
		}

		var msgids []uint64
		var err error
		if last {
			err = db.Raw("SELECT msgid FROM messages_groups WHERE arrival >= ? AND arrival <= ? AND groupid IN (?)",
				winStart.Format("2006-01-02"), winEnd.Format("2006-01-02"), groupIDs).Scan(&msgids).Error
		} else {
			err = db.Raw("SELECT msgid FROM messages_groups WHERE arrival >= ? AND arrival < ? AND groupid IN (?)",
				winStart.Format("2006-01-02"), winEnd.Format("2006-01-02"), groupIDs).Scan(&msgids).Error
		}
		if err != nil {
			// Fail the whole component (empty top-5, like the replaced single
			// statement did on error) rather than silently missing a window's
			// worth of messages from the counts.
			return map[uint64]int{}
		}

		for _, id := range msgids {
			multiplicity[id]++
		}
	}

	return multiplicity
}

// refUserCount is one row of "how many replies did this user leave on this message", as batched
// out of chat_messages by repliesForMessages.
type refUserCount struct {
	Refmsgid uint64
	Userid   uint64
	Count    int
}

// repliesForMessages batches the msgids in multiplicity into usersReplyingBatch-sized IN (...)
// lookups against chat_messages (existing refmsgid index), so no single statement grows with the
// number of messages the date range/group scope matched.
func repliesForMessages(db *gorm.DB, multiplicity map[uint64]int) []refUserCount {
	msgids := make([]uint64, 0, len(multiplicity))
	for id := range multiplicity {
		msgids = append(msgids, id)
	}

	var rows []refUserCount
	for _, batch := range chunkUint64s(msgids, usersReplyingBatch) {
		var batchRows []refUserCount
		if err := db.Raw("SELECT refmsgid, userid, COUNT(*) AS count FROM chat_messages "+
			"WHERE refmsgid IN (?) AND type = ? GROUP BY refmsgid, userid",
			batch, utils.CHAT_MESSAGE_INTERESTED).Scan(&batchRows).Error; err != nil {
			// All-or-nothing, matching the replaced single statement's
			// fail-empty behaviour.
			return nil
		}
		rows = append(rows, batchRows...)
	}

	return rows
}

// chunkUint64s splits ids into consecutive batches of at most size, preserving order. Pure and
// DB-free so it can be unit tested directly; size <= 0 is treated as "everything in one batch"
// rather than looping forever.
func chunkUint64s(ids []uint64, size int) [][]uint64 {
	if len(ids) == 0 {
		return nil
	}
	if size <= 0 {
		size = len(ids)
	}

	batches := make([][]uint64, 0, (len(ids)+size-1)/size)
	for start := 0; start < len(ids); start += size {
		end := start + size
		if end > len(ids) {
			end = len(ids)
		}
		batches = append(batches, ids[start:end])
	}
	return batches
}

// mergeReplyCounts folds per-(msgid,userid) reply counts into per-user totals, weighting each row
// by that msgid's multiplicity. This PRESERVES the original single-statement query's behaviour: a
// message crossposted to k of the selected groups had its INNER JOIN row - and so its replies -
// counted k times, so this multiplies each (msgid,userid) count by k before summing rather than
// deduplicating msgids.
func mergeReplyCounts(rows []refUserCount, multiplicity map[uint64]int) map[uint64]int {
	totals := make(map[uint64]int)
	for _, r := range rows {
		k := multiplicity[r.Refmsgid]
		if k == 0 {
			continue
		}
		totals[r.Userid] += r.Count * k
	}
	return totals
}

// userCount is one entry of the sorted-and-limited output of topUserCounts.
type userCount struct {
	Userid uint64
	Count  int
}

// topUserCounts sorts totals desc by count (userid asc as a deterministic tie-break - the replaced
// "ORDER BY count DESC" had no secondary key either, so ties were never guaranteed any particular
// order) and returns at most limit entries, replicating the original query's
// "ORDER BY count DESC LIMIT 5" in application code.
func topUserCounts(totals map[uint64]int, limit int) []userCount {
	users := make([]userCount, 0, len(totals))
	for userid, count := range totals {
		users = append(users, userCount{Userid: userid, Count: count})
	}
	sort.Slice(users, func(i, j int) bool {
		if users[i].Count != users[j].Count {
			return users[i].Count > users[j].Count
		}
		return users[i].Userid < users[j].Userid
	})
	if len(users) > limit {
		users = users[:limit]
	}
	return users
}

func getModeratorsActive(groupIDs []uint64) []map[string]interface{} {
	db := database.DBConn
	if len(groupIDs) == 0 {
		return []map[string]interface{}{}
	}

	type ModRow struct {
		Userid     uint64
		Lastactive *string
	}

	// MAX(arrival) rather than ORDER BY approvedat DESC LIMIT 1: the covering
	// index is lastapproved (approvedby, groupid, arrival), so MAX over its
	// suffix resolves each membership's subquery as a single index seek.
	// Sorting by approvedat - which is NOT in any index - forced a read and
	// filesort of the mod's entire approval history PER MEMBERSHIP ROW: a
	// support dashboard spanning ~450 groups (~1,200 rows) ran 8-12 MINUTES,
	// and reloads stacked 19+ copies, pinning db3's CPU (recurring monit
	// alerts, worst captured 725s). For "when was this mod last active",
	// the arrival of the last message they approved is the same signal.
	//
	// The 30s ceiling is the backstop: if this ever regresses, the query dies
	// instead of stacking - a missing widget beats a downed write node.
	ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer cancel()

	var mods []ModRow
	db.WithContext(ctx).Table("memberships").
		Select("userid, (SELECT MAX(messages_groups.arrival) FROM messages_groups WHERE messages_groups.approvedby = memberships.userid AND messages_groups.groupid = memberships.groupid) AS lastactive").
		Where("groupid IN (?) AND role IN (?, ?)", groupIDs, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Having("lastactive IS NOT NULL").
		Scan(&mods)

	result := make([]map[string]interface{}, 0, len(mods))
	for _, m := range mods {
		var displayname string
		db.Table("users").Select("COALESCE(fullname, firstname, lastname, 'Unknown')").Where("id = ?", m.Userid).Scan(&displayname)
		entry := map[string]interface{}{
			"id":          m.Userid,
			"displayname": displayname,
		}
		if m.Lastactive != nil {
			entry["lastactive"] = *m.Lastactive
		}
		result = append(result, entry)
	}
	return result
}

// getMessageBreakdown returns {Offer: count, Wanted: count} summary from the stats table.
// The breakdown column contains JSON like {"Offer":10,"Wanted":5} per group/date row.
// We parse each and sum the Offer/Wanted totals.
func getMessageBreakdown(groupIDs []uint64, startQ, endQ string) map[string]int64 {
	db := database.DBConn
	if len(groupIDs) == 0 {
		return map[string]int64{}
	}

	type BreakdownRow struct {
		Breakdown *string
	}

	var rows []BreakdownRow
	db.Table("stats").Select("breakdown").
		Where("type = 'MessageBreakdown' AND groupid IN ? AND date >= ? AND date <= ?",
			groupIDs, startQ, endQ).Scan(&rows)

	result := map[string]int64{"Offer": 0, "Wanted": 0}
	for _, r := range rows {
		if r.Breakdown == nil || *r.Breakdown == "" || *r.Breakdown == "[]" {
			continue
		}
		var bd map[string]int64
		if err := json2.Unmarshal([]byte(*r.Breakdown), &bd); err == nil {
			for k, v := range bd {
				result[k] += v
			}
		}
	}
	return result
}

// getStatsTimeSeries reads from the pre-computed stats table.
func getStatsTimeSeries(component string, groupIDs []uint64, startQ, endQ string) []map[string]interface{} {
	db := database.DBConn
	if len(groupIDs) == 0 {
		return []map[string]interface{}{}
	}

	// Map component names to stats table types.
	statsType := component
	switch component {
	case "Activity":
		statsType = "Activity"
	case "Replies":
		statsType = "Replies"
	case "ApprovedMessageCount":
		statsType = "ApprovedMessageCount"
	case "Weight":
		statsType = "Weight"
	case "Outcomes":
		statsType = "Outcomes"
	case "ActiveUsers":
		statsType = "ActiveUsers"
	case "ApprovedMemberCount":
		statsType = "ApprovedMemberCount"
	}

	type StatsRow struct {
		Date  string
		Count *int64
	}

	var rows []StatsRow
	db.Table("stats").Select("date, SUM(count) AS count").
		Where("type = ? AND groupid IN ? AND date >= ? AND date <= ?", statsType, groupIDs, startQ, endQ).
		Group("date").Order("date ASC").Scan(&rows)

	result := make([]map[string]interface{}, len(rows))
	for i, r := range rows {
		entry := map[string]interface{}{
			"date": r.Date,
		}
		if r.Count != nil {
			entry["count"] = *r.Count
		} else {
			entry["count"] = 0
		}
		result[i] = entry
	}
	return result
}

func getDonations(groupIDs []uint64, startQ, endQ string, systemwide bool) []map[string]interface{} {
	db := database.DBConn

	type DonRow struct {
		Count float64
		Date  string
	}

	var rows []DonRow
	if systemwide {
		db.Table("users_donations").
			Select("SUM(GrossAmount) AS count, DATE(timestamp) AS date").
			Where("timestamp >= ? AND timestamp <= ?", startQ, endQ).
			Group("date").
			Order("date ASC").
			Scan(&rows)
	} else if len(groupIDs) > 0 {
		db.Table("users_donations").
			Select("SUM(GrossAmount) AS count, DATE(timestamp) AS date").
			Where("userid IN (SELECT DISTINCT userid FROM memberships WHERE groupid IN (?)) AND timestamp >= ? AND timestamp <= ?",
				groupIDs, startQ, endQ).
			Group("date").
			Order("date ASC").
			Scan(&rows)
	}

	result := make([]map[string]interface{}, len(rows))
	for i, r := range rows {
		result[i] = map[string]interface{}{
			"count": r.Count,
			"date":  r.Date,
		}
	}
	return result
}

func getHappiness(groupIDs []uint64, startQ, endQ string, systemwide bool) []map[string]interface{} {
	db := database.DBConn

	type HappyRow struct {
		Count     int
		Happiness string
	}

	var rows []HappyRow
	if systemwide {
		db.Table("messages_outcomes").
			Select("COUNT(*) AS count, happiness").
			Where("timestamp >= ? AND timestamp <= ? AND happiness IS NOT NULL", startQ, endQ).
			Group("happiness").
			Order("count DESC").
			Scan(&rows)
	} else if len(groupIDs) > 0 {
		db.Table("messages_outcomes").
			Select("COUNT(*) AS count, happiness").
			Joins("INNER JOIN messages ON messages.id = messages_outcomes.msgid").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages_outcomes.msgid").
			Where("timestamp >= ? AND timestamp <= ? AND messages_groups.groupid IN (?) AND happiness IS NOT NULL", startQ, endQ, groupIDs).
			Group("happiness").
			Order("count DESC").
			Scan(&rows)
	}

	result := make([]map[string]interface{}, len(rows))
	for i, r := range rows {
		result[i] = map[string]interface{}{
			"count":     r.Count,
			"happiness": r.Happiness,
		}
	}
	return result
}

func getDiscourseTopics() interface{} {
	apiURL := os.Getenv("DISCOURSE_API")
	apiKey := os.Getenv("DISCOURSE_APIKEY")

	if apiURL == "" || apiKey == "" {
		return nil
	}

	client := &http.Client{Timeout: 5 * time.Second}
	req, err := http.NewRequest("GET", apiURL+"/posts.json", nil)
	if err != nil {
		return nil
	}

	req.Header.Set("Api-Key", apiKey)
	req.Header.Set("Api-Username", "system")
	req.Header.Set("Accept-language", "en")

	resp, err := client.Do(req)
	if err != nil {
		return nil
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil
	}

	// Return the raw JSON string directly to the caller.
	return string(body)
}

// resolveGroupIDs determines which groups to query based on parameters.
func resolveGroupIDs(myid uint64, groupID uint64, systemwide, allgroups bool) []uint64 {
	var groupIDs []uint64

	if groupID > 0 {
		groupIDs = []uint64{groupID}
	} else if systemwide {
		database.DBConn.Table("groups").Select("id").Where("publish = 1 AND onhere = 1").Scan(&groupIDs)
	} else if allgroups && myid > 0 {
		database.DBConn.Table("memberships").Select("groupid").
			Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
			Scan(&groupIDs)
	}
	return groupIDs
}

func parseRelativeDate(s string) time.Time {
	switch s {
	case "today":
		return time.Now()
	case "30 days ago":
		return time.Now().AddDate(0, 0, -30)
	case "7 days ago":
		return time.Now().AddDate(0, 0, -7)
	case "90 days ago":
		return time.Now().AddDate(0, 0, -90)
	case "1 year ago":
		return time.Now().AddDate(-1, 0, 0)
	default:
		// Try parsing as a date.
		t, err := time.Parse("2006-01-02", s)
		if err != nil {
			t, err = time.Parse(time.RFC3339, s)
			if err != nil {
				return time.Now().AddDate(0, 0, -30)
			}
		}
		return t
	}
}

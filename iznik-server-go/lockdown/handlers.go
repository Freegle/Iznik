package lockdown

// Handlers for the lockdown switch's own five endpoints (plan section 11.2,
// plans/active/2026-09-27-lockdown-switch.md): GET /lockdown, GET /modtools/lockdown,
// GET /modtools/lockdown/stats, GET /modtools/lockdown/history, PATCH /lockdown.
//
// Every PATCH action writes a new row to the append-only lockdowns table (the plan is
// explicit: "Each writes a new row. Returns the new state") and calls Invalidate() so the
// change is visible immediately. That includes markspam and releaseclass, which change no
// field the lockdowns table itself tracks - their row is a carried-forward copy of the
// current state, written purely so changedby/created record who touched the incident and
// when; their response adds "holds"/"users" or "holds" counts alongside the usual state view.

import (
	"encoding/json"
	"fmt"
	"sort"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// surfaceKeys are the eight surfaces the surfaces JSON column always carries, matching the
// plan's literal shape (section 11.1): chat, posts, chitchat, events, email, push, export,
// mods. chat_mode is a ninth key in the same JSON object, a string rather than a bool.
var surfaceKeys = []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"}

func isSurfaceKey(k string) bool {
	for _, sk := range surfaceKeys {
		if sk == k {
			return true
		}
	}
	return false
}

// noticeText carries the exact wording from the plan (section 11.1), identical in Go and
// the batch: whichever side renders the notice, members see the same words.
var noticeText = map[string]string{
	"delay": "Freegle is running slowly today. Messages and posts may take longer than usual " +
		"to reach people.",
	"security": "We're dealing with a spam attack. Messages may be delayed. If you received a " +
		"message about vouchers or payments, please don't click the link.",
	"normal": "Things are back to normal.",
}

var validNotices = map[string]bool{"delay": true, "security": true, "normal": true}

// GetLockdown is the public, unauthenticated endpoint. It never returns active or surfaces:
// telling an anonymous caller whether a lockdown is active, or which surfaces are held, would
// let an attacker time their actions around the moment they are about to be throttled.
//
// The notice itself is member-facing only while it is still relevant (finding 7): while the
// incident is active, whatever notice is set shows unconditionally, same as before. Once
// closed, delay/security notices are cleared by patchClose (they would otherwise tell members
// about a security incident that ended), and a "normal" notice - the deliberate "things are
// back to normal" reassurance - shows for 24 hours after the row that set it and then stops,
// rather than lingering on the public page forever.
//
// @Summary Get the public lockdown notice
// @Description Returns the current member-facing notice, if any. Never reveals whether a
// @Description lockdown is active or which surfaces are held.
// @Tags lockdown
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /lockdown [get]
func GetLockdown(c *fiber.Ctx) error {
	s := Current()

	notice := s.Notice
	if !s.Active {
		if notice != "normal" || !within24Hours(rowCreatedAt(s.ID)) {
			notice = ""
		}
	}

	text, ok := noticeText[notice]
	if notice == "" || !ok {
		return c.JSON(fiber.Map{"notice": nil})
	}
	return c.JSON(fiber.Map{
		"notice": fiber.Map{"key": notice, "text": text},
	})
}

// within24Hours reports whether t is set and less than 24 hours in the past.
func within24Hours(t *time.Time) bool {
	return t != nil && time.Since(*t) < 24*time.Hour
}

// GetModtoolsLockdown is visible to any moderator; phrases are added only for Support/Admin.
//
// @Summary Get the lockdown state for moderators
// @Description Active, incidentid, surfaces, reason, notice and who started it. Phrases only
// @Description for Support/Admin.
// @Tags lockdown
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/lockdown [get]
func GetModtoolsLockdown(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	if !auth.IsAdminOrSupport(myid) && !auth.IsModOfAnyGroup(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Moderator role required")
	}
	s := Current()
	return c.JSON(modtoolsView(s, auth.IsAdminOrSupport(myid)))
}

// modtoolsView is the shared shape returned by GetModtoolsLockdown and every PATCH action
// (Support/Admin only reach PATCH, so includePhrases is always true there).
func modtoolsView(s State, includePhrases bool) fiber.Map {
	view := fiber.Map{
		"active":        s.Active,
		"incidentid":    s.IncidentID,
		"surfaces":      surfacesMap(s),
		"reason":        s.Reason,
		"notice":        s.Notice,
		"startedat":     s.StartedAt,
		"startedby":     s.StartedBy,
		"startedbyname": userFullname(s.StartedBy),
	}
	if includePhrases {
		phrases := s.Phrases
		if phrases == nil {
			phrases = []string{}
		}
		view["phrases"] = phrases
	}
	return view
}

func surfacesMap(s State) map[string]interface{} {
	out := make(map[string]interface{}, len(surfaceKeys)+1)
	for _, k := range surfaceKeys {
		out[k] = s.Surfaces[k]
	}
	out["chat_mode"] = s.ChatMode
	return out
}

func userFullname(userid uint64) string {
	if userid == 0 {
		return ""
	}
	var name string
	database.DBConn.Table("users").Select("fullname").Where("id = ?", userid).Limit(1).Scan(&name)
	return name
}

// GetModtoolsLockdownHistory returns the last 50 rows of the append-only lockdowns table,
// newest first - the full audit trail, including rows markspam/releaseclass wrote purely to
// record who touched the incident and when.
//
// @Summary Get lockdown history
// @Description Last 50 rows of the lockdowns table, newest first. Support/Admin only.
// @Tags lockdown
// @Produce json
// @Security BearerAuth
// @Success 200 {array} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/lockdown/history [get]
func GetModtoolsLockdownHistory(c *fiber.Ctx) error {
	var raw []struct {
		ID         uint64     `gorm:"column:id"`
		Incidentid *uint64    `gorm:"column:incidentid"`
		Active     int        `gorm:"column:active"`
		Reason     *string    `gorm:"column:reason"`
		Notice     *string    `gorm:"column:notice"`
		Changedby  *uint64    `gorm:"column:changedby"`
		Created    time.Time  `gorm:"column:created"`
		Startedby  *uint64    `gorm:"column:startedby"`
		Startedat  *time.Time `gorm:"column:startedat"`
		Endedby    *uint64    `gorm:"column:endedby"`
		Endedat    *time.Time `gorm:"column:endedat"`
		Endnote    *string    `gorm:"column:endnote"`
	}
	database.DBConn.Table("lockdowns").
		Select("id, incidentid, active, reason, notice, changedby, created, startedby, startedat, endedby, endedat, endnote").
		Order("id DESC").Limit(50).Scan(&raw)

	rows := make([]fiber.Map, 0, len(raw))
	for _, r := range raw {
		rows = append(rows, fiber.Map{
			"id":          r.ID,
			"incidentid":  derefUint(r.Incidentid),
			"active":      r.Active == 1,
			"reason":      derefString(r.Reason),
			"notice":      derefString(r.Notice),
			"changedby":   derefUint(r.Changedby),
			"created":     r.Created,
			"startedby":   derefUint(r.Startedby),
			"startedat":   r.Startedat,
			"endedby":     derefUint(r.Endedby),
			"endedbyname": userFullname(derefUint(r.Endedby)),
			"endedat":     r.Endedat,
			"endnote":     derefString(r.Endnote),
		})
	}
	return c.JSON(rows)
}

// GetModtoolsLockdownStats is the Support/Admin dashboard for a lockdown in progress: what is
// held, what has been triaged, samples to review, and what has been counted.
//
// @Summary Get lockdown stats
// @Description Held/triage/sample breakdown for the current incident. Support/Admin only.
// @Tags lockdown
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/lockdown/stats [get]
func GetModtoolsLockdownStats(c *fiber.Ctx) error {
	s := Current()
	minutesAgo := 0
	if s.StartedAt != nil {
		minutesAgo = int(time.Since(*s.StartedAt).Minutes())
	}
	return c.JSON(fiber.Map{
		"pressedat":       s.StartedAt,
		"pressedby":       s.StartedBy,
		"pressedbyname":   userFullname(s.StartedBy),
		"minutesago":      minutesAgo,
		"rowid":           s.ID,
		"changedat":       rowCreatedAt(s.ID),
		"held":            heldByKind(s.IncidentID),
		"triage":          triageByKindRisk(s.IncidentID),
		"samples":         samplesByRisk(s.IncidentID),
		"clusters":        lockdownClusters(s.IncidentID),
		"accountscreated": accountsCreatedSince(s.StartedAt),
		"counters":        countersBreakdown(s.IncidentID),
		"outcomes":        outcomesBreakdown(s.IncidentID),
		"acks":            acksStatus(s.ID),
		"leaked":          leakedSince(s.IncidentID, s.StartedAt),
		"waiting":         waitingCounts(s.IncidentID),
		"api":             fiber.Map{"delayseconds": int(TTL.Seconds())},
	})
}

// rowCreatedAt is the current row's own "created" timestamp (section 11.6's "changedat":
// when the state last changed, which is not startedat - that stays fixed at the original
// press). A single indexed lookup by primary key, cheap enough for a five-second poll. Nil
// before anything has ever been pressed.
func rowCreatedAt(rowID uint64) *time.Time {
	if rowID == 0 {
		return nil
	}
	var created time.Time
	database.DBConn.Table("lockdowns").Select("created").Where("id = ?", rowID).Limit(1).Scan(&created)
	if created.IsZero() {
		return nil
	}
	return &created
}

// heldByKind is per-kind counts of holds not yet resolved (outcome IS NULL): how many, how
// many distinct senders, the oldest one and how many arrived in the last ten minutes.
func heldByKind(incidentID uint64) []fiber.Map {
	var rows []struct {
		Kind          string     `gorm:"column:kind"`
		Count         int64      `gorm:"column:count"`
		Distinctusers int64      `gorm:"column:distinctusers"`
		Oldest        *time.Time `gorm:"column:oldest"`
		Newlast10min  int64      `gorm:"column:newlast10min"`
	}
	database.DBConn.Table("lockdown_holds").
		Select("kind, COUNT(*) AS count, COUNT(DISTINCT userid) AS distinctusers, "+
			"MIN(created) AS oldest, "+
			"SUM(CASE WHEN created >= NOW() - INTERVAL 10 MINUTE THEN 1 ELSE 0 END) AS newlast10min").
		Where("lockdownid = ? AND outcome IS NULL", incidentID).
		Group("kind").Scan(&rows)

	out := make([]fiber.Map, 0, len(rows))
	for _, r := range rows {
		out = append(out, fiber.Map{
			"kind":          r.Kind,
			"count":         r.Count,
			"distinctusers": r.Distinctusers,
			"oldest":        r.Oldest,
			"newlast10min":  r.Newlast10min,
		})
	}
	return out
}

// triageByKindRisk is a plain count of every hold in the incident, grouped by kind and risk
// (untriaged holds - risk not yet assigned - grouped together), regardless of outcome.
func triageByKindRisk(incidentID uint64) []fiber.Map {
	var rows []struct {
		Kind  string `gorm:"column:kind"`
		Risk  string `gorm:"column:risk"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_holds").
		Select("kind, COALESCE(risk, 'untriaged') AS risk, COUNT(*) AS count").
		Where("lockdownid = ?", incidentID).
		Group("kind, COALESCE(risk, 'untriaged')").Scan(&rows)

	out := make([]fiber.Map, 0, len(rows))
	for _, r := range rows {
		out = append(out, fiber.Map{"kind": r.Kind, "risk": r.Risk, "count": r.Count})
	}
	return out
}

// samplesByRisk returns up to three most recent samples each for spam and risky holds, never
// for low - a moderator reviewing samples should never be shown to have wasted time on the
// class that was going to be released anyway.
func samplesByRisk(incidentID uint64) map[string]interface{} {
	out := map[string]interface{}{}
	for _, risk := range []string{"spam", "risky"} {
		var holds []struct {
			Kind   string `gorm:"column:kind"`
			Refid  uint64 `gorm:"column:refid"`
			Userid uint64 `gorm:"column:userid"`
		}
		database.DBConn.Table("lockdown_holds").
			Select("kind, refid, userid").
			Where("lockdownid = ? AND risk = ? AND outcome IS NULL", incidentID, risk).
			Order("id DESC").Limit(3).Scan(&holds)

		refs := make([]holdRef, 0, len(holds))
		for _, h := range holds {
			refs = append(refs, holdRef{Kind: h.Kind, Refid: h.Refid})
		}
		texts := batchSampleTexts(refs)

		samples := make([]fiber.Map, 0, len(holds))
		for _, h := range holds {
			samples = append(samples, fiber.Map{
				"kind":   h.Kind,
				"refid":  h.Refid,
				"userid": h.Userid,
				"text":   texts[h.Kind][h.Refid],
			})
		}
		out[risk] = samples
	}
	return out
}

// holdRef is the (kind, refid) key batchSampleTexts groups by.
type holdRef struct {
	Kind  string
	Refid uint64
}

// batchSampleTexts fetches the text a list of holds refers to - chat messages by their own
// text, posts by subject (the body is not held content, the whole post is), ChitChat by its
// message text - truncated to 200 characters, in one query per distinct kind rather than one
// query per hold (finding 9: lockdownClusters can look at up to 200 holds in a single stats
// poll, and samplesByRisk adds up to six more; a poll whose cost does not grow with the size
// of the wave). A (kind, refid) with nothing found is simply absent from the result, which
// callers read the same as "" via the map's zero value.
func batchSampleTexts(refs []holdRef) map[string]map[uint64]string {
	byKind := map[string][]uint64{}
	for _, r := range refs {
		byKind[r.Kind] = append(byKind[r.Kind], r.Refid)
	}

	out := make(map[string]map[uint64]string, len(byKind))
	db := database.DBConn
	for kind, refids := range byKind {
		var rows []struct {
			ID   uint64 `gorm:"column:id"`
			Text string `gorm:"column:text"`
		}
		switch kind {
		case "chat":
			db.Table("chat_messages").Select("id, message AS text").Where("id IN ?", refids).Scan(&rows)
		case "post":
			db.Table("messages").Select("id, subject AS text").Where("id IN ?", refids).Scan(&rows)
		case "chitchat":
			db.Table("newsfeed").Select("id, message AS text").Where("id IN ?", refids).Scan(&rows)
		default:
			continue
		}

		texts := make(map[uint64]string, len(rows))
		for _, r := range rows {
			texts[r.ID] = utils.TruncateStringUtil(r.Text, 200)
		}
		out[kind] = texts
	}
	return out
}

// lockdownClusters folds the first line of up to 200 most recent pending holds' sample text,
// groups identical lines and returns the top five by count - a fast way for Support to see
// whether one templated message is behind most of the wave.
func lockdownClusters(incidentID uint64) []fiber.Map {
	var holds []struct {
		Kind  string `gorm:"column:kind"`
		Refid uint64 `gorm:"column:refid"`
	}
	database.DBConn.Table("lockdown_holds").
		Select("kind, refid").
		Where("lockdownid = ? AND outcome IS NULL", incidentID).
		Order("id DESC").Limit(200).Scan(&holds)

	refs := make([]holdRef, 0, len(holds))
	for _, h := range holds {
		refs = append(refs, holdRef{Kind: h.Kind, Refid: h.Refid})
	}
	texts := batchSampleTexts(refs)

	counts := map[string]int{}
	for _, h := range holds {
		line := firstLine(texts[h.Kind][h.Refid])
		if line == "" {
			continue
		}
		counts[line]++
	}

	type clusterCount struct {
		Line  string
		Count int
	}
	clusters := make([]clusterCount, 0, len(counts))
	for line, count := range counts {
		clusters = append(clusters, clusterCount{Line: line, Count: count})
	}
	sort.Slice(clusters, func(i, j int) bool { return clusters[i].Count > clusters[j].Count })
	if len(clusters) > 5 {
		clusters = clusters[:5]
	}

	out := make([]fiber.Map, 0, len(clusters))
	for _, cl := range clusters {
		out = append(out, fiber.Map{"text": cl.Line, "count": cl.Count})
	}
	return out
}

func firstLine(s string) string {
	if idx := strings.IndexAny(s, "\r\n"); idx >= 0 {
		return s[:idx]
	}
	return s
}

func accountsCreatedSince(startedAt *time.Time) int64 {
	if startedAt == nil {
		return 0
	}
	var count int64
	database.DBConn.Table("users").Where("added >= ?", *startedAt).Count(&count)
	return count
}

// countersBreakdown splits lockdown_counters' flat (lockdownid, kind, count) rows into the
// shape the dashboard wants: email broken down by type, push/export as plain numbers,
// refused_member as a plain number, and refused/approved as arrays naming the moderator.
func countersBreakdown(incidentID uint64) fiber.Map {
	var rows []struct {
		Kind  string `gorm:"column:kind"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_counters").
		Select("kind, count").
		Where("lockdownid = ?", incidentID).
		Scan(&rows)

	email := map[string]interface{}{}
	var push, export, refusedMember int64
	refused := make([]fiber.Map, 0)
	approved := make([]fiber.Map, 0)

	for _, r := range rows {
		switch {
		case r.Kind == "push":
			push = r.Count
		case r.Kind == "export":
			export = r.Count
		case r.Kind == "refused_member":
			refusedMember = r.Count
		case strings.HasPrefix(r.Kind, "email:"):
			email[strings.TrimPrefix(r.Kind, "email:")] = r.Count
		case strings.HasPrefix(r.Kind, "refused:"):
			uid, _ := strconv.ParseUint(strings.TrimPrefix(r.Kind, "refused:"), 10, 64)
			refused = append(refused, fiber.Map{"userid": uid, "name": userFullname(uid), "count": r.Count})
		case strings.HasPrefix(r.Kind, "approved:"):
			uid, _ := strconv.ParseUint(strings.TrimPrefix(r.Kind, "approved:"), 10, 64)
			approved = append(approved, fiber.Map{"userid": uid, "name": userFullname(uid), "count": r.Count})
		}
	}

	return fiber.Map{
		"email":          email,
		"push":           push,
		"export":         export,
		"refused_member": refusedMember,
		"refused":        refused,
		"approved":       approved,
	}
}

func outcomesBreakdown(incidentID uint64) fiber.Map {
	var rows []struct {
		Outcome string `gorm:"column:outcome"`
		Count   int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_holds").
		Select("COALESCE(outcome, 'pending') AS outcome, COUNT(*) AS count").
		Where("lockdownid = ?", incidentID).
		Group("COALESCE(outcome, 'pending')").Scan(&rows)

	out := fiber.Map{}
	for _, r := range rows {
		out[r.Outcome] = r.Count
	}
	return out
}

// ackLoops are the batch loops that call LockdownService::ack() (plan 11.6), in the order
// the plan lists them. Named once here so a loop that has never ticked over at all since
// the press - no lockdown_acks row yet - still appears, listed as not caught up, rather
// than being silently absent from the page.
var ackLoops = []string{
	"chat-process", "content-check", "auto-approve", "mail-spool",
	"mail-loops", "background-tasks", "push", "triage",
}

// acksStatus reads lockdown_acks - at most one row per loop, upserted in place (see the
// migration's UNIQUE KEY on `loop`), so this is a single unfiltered select over at most
// eight rows - and reports, per loop, the lockdowns row id it last acted on, when, how many
// seconds that took, and whether it has caught up with the current row. currentRowID is the
// newest lockdowns row's own id (State.ID): a loop is caught up once its own lockdownrowid
// is at least that.
//
// seconds is measured against the CREATED time of the row the loop actually acted on, not
// the current row - a loop that is still behind reports how long it took to notice the
// change it did see, not a number that keeps growing simply because a newer row has since
// arrived.
func acksStatus(currentRowID uint64) []fiber.Map {
	var rows []struct {
		Loop          string    `gorm:"column:loop"`
		Lockdownrowid uint64    `gorm:"column:lockdownrowid"`
		Seenat        time.Time `gorm:"column:seenat"`
	}
	database.DBConn.Table("lockdown_acks").Select("`loop`, lockdownrowid, seenat").Scan(&rows)

	type ackSeen struct {
		Lockdownrowid uint64
		Seenat        time.Time
	}
	byLoop := make(map[string]ackSeen, len(rows))
	rowIDs := make([]uint64, 0, len(rows))
	for _, r := range rows {
		byLoop[r.Loop] = ackSeen{r.Lockdownrowid, r.Seenat}
		rowIDs = append(rowIDs, r.Lockdownrowid)
	}

	// One extra indexed-by-primary-key query covering every distinct row an ack refers to
	// (at most eight), so each ack can be timed against the row it actually acted on.
	createdByID := map[uint64]time.Time{}
	if len(rowIDs) > 0 {
		var createdRows []struct {
			ID      uint64    `gorm:"column:id"`
			Created time.Time `gorm:"column:created"`
		}
		database.DBConn.Table("lockdowns").Select("id, created").Where("id IN ?", rowIDs).Scan(&createdRows)
		for _, cr := range createdRows {
			createdByID[cr.ID] = cr.Created
		}
	}

	out := make([]fiber.Map, 0, len(ackLoops))
	for _, loop := range ackLoops {
		ack, seen := byLoop[loop]
		if !seen {
			out = append(out, fiber.Map{
				"loop":          loop,
				"lockdownrowid": nil,
				"seenat":        nil,
				"seconds":       nil,
				"caughtup":      false,
			})
			continue
		}
		var seconds interface{}
		if created, ok := createdByID[ack.Lockdownrowid]; ok {
			seconds = int(ack.Seenat.Sub(created).Seconds())
		}
		out = append(out, fiber.Map{
			"loop":          loop,
			"lockdownrowid": ack.Lockdownrowid,
			"seenat":        ack.Seenat,
			"seconds":       seconds,
			"caughtup":      ack.Lockdownrowid >= currentRowID,
		})
	}
	return out
}

// leakedSince is what still went out after the incident's own startedat despite the hold
// (plan 11.6/11.7): mail that reached the relay (lockdown_counters "leaked:email:<type>"
// rows, written by the batch's send path) folded together with every other "leaked:*"
// counter, plus User2User chat messages the chat processor let through. Chat is computed
// live rather than counted, because nothing on that path increments a lockdown counter for
// a message it let straight through - see leakedChatCount.
//
// Deliberately a flat map, any prefix stripped: the page sums every value for one "sent
// since the press: N" figure and does not need to know what an individual key means.
func leakedSince(incidentID uint64, startedAt *time.Time) fiber.Map {
	out := fiber.Map{}

	var rows []struct {
		Kind  string `gorm:"column:kind"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_counters").
		Select("kind, count").
		Where("lockdownid = ? AND kind LIKE 'leaked:%'", incidentID).
		Scan(&rows)
	for _, r := range rows {
		out[strings.TrimPrefix(r.Kind, "leaked:")] = r.Count
	}

	if startedAt != nil {
		out["chat"] = leakedChatCount(*startedAt)
	}
	return out
}

// leakedChatCount counts User2User chat messages the processor marked processingsuccessful
// after startedat, sent by someone who is neither Support/Admin nor a moderator of any group
// (a mod sending through the held surface is the deliberate exception in plan 11.3, not a
// leak), and which never went through the hold pipeline at all. A message with its own
// lockdown_holds row (kind='chat', refid=cm.id) was seen and held by this incident - reviewed
// and then released via markspam/releaseclass/liftall, or still waiting - so it is exactly the
// pipeline working, not a leak; without excluding it, every hold this incident itself released
// counted as "sent since the press" (finding 8), which over-counts as the incident works
// through its own backlog. One query, every column it filters on indexed: chat_messages.date
// leads the (date, seenbyall) composite index, chat_rooms.chattype and users.systemrole are
// both indexed on their own, lockdown_holds has a unique key on (kind, refid), and memberships
// has no covering index for this shape but is small enough per user that the correlated
// subquery costs nothing next to the outer scan - cheap enough for a five-second poll.
//
// date is chat_messages' only timestamp column - there is no separate "processed at" column
// - so it is the best available marker of when the message went out.
func leakedChatCount(startedAt time.Time) int64 {
	var count int64
	database.DBConn.Table("chat_messages AS cm").
		Joins("INNER JOIN chat_rooms cr ON cr.id = cm.chatid").
		Joins("INNER JOIN users u ON u.id = cm.userid").
		Where("cr.chattype = ? AND cm.processingsuccessful = 1 AND cm.date > ? AND u.systemrole NOT IN (?, ?)",
			utils.CHAT_TYPE_USER2USER, startedAt, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN).
		Where("NOT EXISTS (SELECT 1 FROM memberships m WHERE m.userid = cm.userid AND m.role IN (?, ?))",
			utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Where("NOT EXISTS (SELECT 1 FROM lockdown_holds lh WHERE lh.kind = 'chat' AND lh.refid = cm.id)").
		Count(&count)
	return count
}

// waitingCounts is mail not yet sent, for three different reasons (plan 11.7/11.8), none of
// them a leak, so all three are kept apart from leakedSince:
//
//   - "queued" is mail already spooled but held back from sending, counted by the batch per
//     mail type as "spooled_held:<type>".
//   - "deferred" is mail whose generation was skipped altogether while held (the loop ran,
//     it just chose not to build the mail this pass), counted by the batch per loop that
//     skipped it as "deferred:<loop>" - there is no per-type breakdown for something that
//     was never generated to have a type.
//   - "removed" is a spool file the batch deleted outright rather than holding, counted per
//     mail type as "filtered:email:<type>" (plan 11.8: content the filter rejected, so it was
//     never going to be sent once released either).
//
// All three nested under "email" because every counter this reads is a mail counter.
func waitingCounts(incidentID uint64) fiber.Map {
	var rows []struct {
		Kind  string `gorm:"column:kind"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_counters").
		Select("kind, count").
		Where("lockdownid = ? AND (kind LIKE 'spooled_held:%' OR kind LIKE 'deferred:%' OR kind LIKE 'filtered:email:%')", incidentID).
		Scan(&rows)

	queued := map[string]interface{}{}
	deferred := map[string]interface{}{}
	removed := map[string]interface{}{}
	for _, r := range rows {
		switch {
		case strings.HasPrefix(r.Kind, "spooled_held:"):
			queued[strings.TrimPrefix(r.Kind, "spooled_held:")] = r.Count
		case strings.HasPrefix(r.Kind, "filtered:email:"):
			removed[strings.TrimPrefix(r.Kind, "filtered:email:")] = r.Count
		case strings.HasPrefix(r.Kind, "deferred:"):
			deferred[strings.TrimPrefix(r.Kind, "deferred:")] = r.Count
		}
	}
	return fiber.Map{"email": fiber.Map{"deferred": deferred, "queued": queued, "removed": removed}}
}

// patchLockdownRequest covers every PATCH /lockdown action's body in one struct; each action
// handler reads only the fields it needs.
type patchLockdownRequest struct {
	Action   string          `json:"action"`
	Reason   string          `json:"reason"`
	Notice   *string         `json:"notice"`
	Surfaces map[string]bool `json:"surfaces"`
	ChatMode *string         `json:"chat_mode"`
	Phrases  []string        `json:"phrases"`
	Kind     string          `json:"kind"`
	Risk     string          `json:"risk"`
	Decision string          `json:"decision"`
	Endnote  string          `json:"endnote"`
}

// PatchLockdown is the single Support/Admin write endpoint (RequireSupportOrAdminMiddleware
// on the route); it dispatches on the action field to one of the eight sub-handlers below.
//
// @Summary Change the lockdown state
// @Description press, surfaces, notice, phrases, markspam, releaseclass, liftall or close.
// @Description Support/Admin only. Each writes a new row and returns the new state.
// @Tags lockdown
// @Accept json
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Failure 400 {object} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Failure 409 {object} map[string]interface{}
// @Router /lockdown [patch]
// patchResult carries what a patch sub-handler produced back out to PatchLockdown: the id of
// the row it wrote, plus any extra fields (holds/users counts and the like) the response needs
// beyond the usual state view.
type patchResult struct {
	rowID uint64
	extra fiber.Map
}

// PatchLockdown is the single Support/Admin write endpoint. Finding 6: every action must decide
// against the newest row, not the process's five-second Current() cache, or two Support agents
// (or one double-click) acting within that window can both believe they are the ones pressing,
// or merge surfaces onto a state that a concurrent write has already moved on from. So the whole
// dispatch runs inside one transaction, reading the latest row FOR UPDATE first - which also
// blocks a second PATCH until the first has committed and invalidated the cache.
func PatchLockdown(c *fiber.Ctx) error {
	myid := auth.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req patchLockdownRequest
	if strings.Contains(c.Get("Content-Type"), "application/json") {
		if err := c.BodyParser(&req); err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
		}
	}

	var result patchResult
	txErr := database.DBConn.Transaction(func(tx *gorm.DB) error {
		fresh, err := loadStateForUpdate(tx)
		if err != nil {
			return err
		}

		var res patchResult
		switch req.Action {
		case "press":
			res, err = patchPress(tx, myid, fresh, req)
		case "surfaces":
			res, err = patchSurfaces(tx, myid, fresh, req)
		case "notice":
			res, err = patchNotice(tx, myid, fresh, req)
		case "phrases":
			res, err = patchPhrases(tx, myid, fresh, req)
		case "markspam":
			res, err = patchMarkspam(tx, myid, fresh)
		case "releaseclass":
			res, err = patchReleaseclass(tx, myid, fresh, req)
		case "liftall":
			res, err = patchLiftall(tx, myid, fresh, req)
		case "close":
			res, err = patchClose(tx, myid, fresh, req)
		default:
			err = fiber.NewError(fiber.StatusBadRequest, "Unknown action")
		}
		if err != nil {
			return err
		}
		result = res
		return nil
	})
	if txErr != nil {
		if fe, ok := txErr.(*fiber.Error); ok {
			return fe
		}
		return fiber.NewError(fiber.StatusInternalServerError, txErr.Error())
	}

	Invalidate()
	response := patchResponse(result.rowID, Current())
	for k, v := range result.extra {
		response[k] = v
	}
	return c.JSON(response)
}

// writeStateRow inserts the next row of the append-only lockdowns table. It runs on the same
// transaction PatchLockdown reads the fresh row from and locks it (finding 6) - the caller
// invalidates the cache once the transaction has committed, not here. A fresh press (IncidentID
// still zero, Active true) needs a second write once the row's own id is known, so the incident
// is keyed on its own pressing row - the same two-step pattern lockdown_test.go's
// setActiveLockdownRow uses.
func writeStateRow(tx *gorm.DB, myid uint64, next State) (uint64, error) {
	surfacesJSON, err := json.Marshal(surfacesStorageMap(next))
	if err != nil {
		return 0, err
	}

	row := map[string]interface{}{
		"incidentid": next.IncidentID,
		"active":     boolToInt(next.Active),
		"surfaces":   string(surfacesJSON),
		"changedby":  myid,
	}
	if next.Reason != "" {
		row["reason"] = next.Reason
	}
	if next.Notice != "" {
		row["notice"] = next.Notice
	}
	if len(next.Phrases) > 0 {
		phrasesJSON, err := json.Marshal(next.Phrases)
		if err != nil {
			return 0, err
		}
		row["phrases"] = string(phrasesJSON)
	}
	if next.StartedBy != 0 {
		row["startedby"] = next.StartedBy
	}
	if next.StartedAt != nil {
		row["startedat"] = *next.StartedAt
	}
	if next.EndedBy != 0 {
		row["endedby"] = next.EndedBy
	}
	if next.EndedAt != nil {
		row["endedat"] = *next.EndedAt
	}
	if next.EndNote != "" {
		row["endnote"] = next.EndNote
	}

	if result := tx.Table("lockdowns").Create(row); result.Error != nil {
		return 0, result.Error
	}
	idInt, _ := row["@id"].(int64)
	id := uint64(idInt)

	if next.IncidentID == 0 && next.Active {
		if err := tx.Table("lockdowns").Where("id = ?", id).
			Update("incidentid", id).Error; err != nil {
			return 0, err
		}
	}

	return id, nil
}

func surfacesStorageMap(s State) map[string]interface{} {
	out := make(map[string]interface{}, len(surfaceKeys)+1)
	for _, k := range surfaceKeys {
		out[k] = s.Surfaces[k]
	}
	out["chat_mode"] = s.ChatMode
	return out
}

func patchResponse(rowID uint64, s State) fiber.Map {
	view := modtoolsView(s, true) // only Support/Admin ever reach PATCH
	view["id"] = rowID
	return view
}

func patchPress(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "A lockdown incident is already active.")
	}
	if strings.TrimSpace(req.Reason) == "" {
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "reason is required")
	}
	notice := ""
	if req.Notice != nil {
		if !validNotices[*req.Notice] {
			return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "notice must be one of: delay, security, normal")
		}
		notice = *req.Notice
	}

	surfaces := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		surfaces[k] = true
	}
	next := State{
		Active:    true,
		Surfaces:  surfaces,
		ChatMode:  "hard",
		Reason:    req.Reason,
		Notice:    notice,
		StartedBy: myid,
		StartedAt: timePtr(time.Now()),
	}

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to press lockdown")
	}
	return patchResult{rowID: rowID}, nil
}

func patchSurfaces(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}

	merged := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		merged[k] = fresh.Surfaces[k]
	}
	for k, v := range req.Surfaces {
		if isSurfaceKey(k) {
			merged[k] = v
		}
	}

	next := fresh
	next.Surfaces = merged
	if req.ChatMode != nil {
		if *req.ChatMode != "hard" && *req.ChatMode != "soft" {
			return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "chat_mode must be hard or soft")
		}
		next.ChatMode = *req.ChatMode
	}

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to update lockdown surfaces")
	}
	return patchResult{rowID: rowID}, nil
}

// patchNotice writes the free-standing "what members see" notice. Finding 7: while no incident
// is active the only notice that may be set is normal (a settled all-clear) or cleared outright
// - delay/security are incident wording and must not be revivable once there is nothing to warn
// members about.
func patchNotice(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	next := fresh
	if req.Notice == nil {
		next.Notice = ""
	} else if !validNotices[*req.Notice] {
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "notice must be one of: delay, security, normal")
	} else if !fresh.Active && *req.Notice != "normal" {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "Only the normal notice can be set while no lockdown incident is active.")
	} else {
		next.Notice = *req.Notice
	}

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to update lockdown notice")
	}
	return patchResult{rowID: rowID}, nil
}

// maxPhrases and maxPhraseLength cap patchPhrases (finding 10): with no limit, a support agent
// (or a script driving the endpoint) can grow the phrases column without bound, and every phrase
// on it is checked against every message the content checker sees.
const (
	maxPhrases      = 200
	maxPhraseLength = 200
)

func patchPhrases(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}
	if len(req.Phrases) > maxPhrases {
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("phrases is limited to %d entries", maxPhrases))
	}

	phrases := make([]string, 0, len(req.Phrases))
	for _, p := range req.Phrases {
		p = strings.ToLower(strings.TrimSpace(p))
		if p == "" {
			continue
		}
		if len(p) > maxPhraseLength {
			return patchResult{}, fiber.NewError(fiber.StatusBadRequest, fmt.Sprintf("each phrase is limited to %d characters", maxPhraseLength))
		}
		phrases = append(phrases, p)
	}

	next := fresh
	next.Phrases = phrases

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to update lockdown phrases")
	}
	return patchResult{rowID: rowID}, nil
}

// patchMarkspam marks every still-waiting spam hold as spam_marked and flags each distinct
// sender into spam_users (collection Spammer). Finding 10: spam_users.userid is unique, so a
// sender already on the table - PendingAdd, PendingRemove or Spammer from some earlier pass -
// made the old bare Create fail silently while the hold was still marked spam_marked, losing
// the failure and (for a sender who was actually Whitelisted) wrongly treating them as flagged.
// Now: no row yet -> create as Spammer; PendingAdd/PendingRemove -> upgrade to Spammer;
// Whitelisted -> leave alone and count it separately; already Spammer -> nothing to do.
func patchMarkspam(tx *gorm.DB, myid uint64, fresh State) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}

	var holds []struct {
		ID     uint64 `gorm:"column:id"`
		Userid uint64 `gorm:"column:userid"`
	}
	tx.Table("lockdown_holds").Select("id, userid").
		Where("lockdownid = ? AND risk = 'spam' AND outcome IS NULL", fresh.IncidentID).
		Scan(&holds)

	seen := map[uint64]bool{}
	newlyFlagged := 0
	skippedWhitelisted := 0
	for _, h := range holds {
		if h.Userid != 0 && !seen[h.Userid] {
			seen[h.Userid] = true

			var existing struct {
				Collection string `gorm:"column:collection"`
			}
			found := tx.Table("spam_users").Select("collection").
				Where("userid = ?", h.Userid).Scan(&existing)
			if found.Error != nil {
				return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to check spam_users")
			}

			switch {
			case found.RowsAffected == 0:
				if err := tx.Table("spam_users").Create(map[string]interface{}{
					"userid":     h.Userid,
					"collection": "Spammer",
					"byuserid":   myid,
					"reason":     fmt.Sprintf("Lockdown %d", fresh.IncidentID),
				}).Error; err != nil {
					return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to flag spam user")
				}
				newlyFlagged++
			case existing.Collection == "Whitelisted":
				skippedWhitelisted++
			case existing.Collection != "Spammer":
				if err := tx.Table("spam_users").Where("userid = ?", h.Userid).
					Update("collection", "Spammer").Error; err != nil {
					return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to flag spam user")
				}
				newlyFlagged++
			}
		}
		if err := tx.Table("lockdown_holds").Where("id = ?", h.ID).
			Update("outcome", "spam_marked").Error; err != nil {
			return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to mark hold as spam")
		}
	}

	rowID, err := writeStateRow(tx, myid, fresh)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to record lockdown action")
	}
	return patchResult{
		rowID: rowID,
		extra: fiber.Map{
			"holds":              len(holds),
			"users":              newlyFlagged,
			"skippedwhitelisted": skippedWhitelisted,
		},
	}, nil
}

// patchReleaseclass lets Support release or reject a whole risk class at once, having already
// looked at samples of it: release approves every matching hold still waiting or under
// review, reject marks the sender's holds spam_marked so the batch rejects them.
func patchReleaseclass(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}
	if req.Kind == "" || req.Risk == "" {
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "kind and risk are required")
	}

	var outcome string
	switch req.Decision {
	case "release":
		outcome = "approved"
	case "reject":
		outcome = "spam_marked"
	default:
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "decision must be release or reject")
	}

	result := tx.Table("lockdown_holds").
		Where("lockdownid = ? AND kind = ? AND risk = ? AND (outcome IS NULL OR outcome = 'review')",
			fresh.IncidentID, req.Kind, req.Risk).
		Update("outcome", outcome)
	if result.Error != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to release class")
	}

	rowID, err := writeStateRow(tx, myid, fresh)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to record lockdown action")
	}
	return patchResult{
		rowID: rowID,
		extra: fiber.Map{"holds": result.RowsAffected},
	}, nil
}

func patchLiftall(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}

	next := fresh
	lowered := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		lowered[k] = false
	}
	next.Surfaces = lowered
	if req.Endnote != "" {
		next.EndNote = req.Endnote
	}

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to lift lockdown surfaces")
	}
	return patchResult{rowID: rowID}, nil
}

// patchClose ends the incident. Finding 7: delay/security notice wording belongs to the
// incident that set it, so close clears it; normal (a settled all-clear set independently of
// any incident) is left untouched.
func patchClose(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	if !fresh.Active {
		return patchResult{}, fiber.NewError(fiber.StatusConflict, "No lockdown incident is active.")
	}
	if strings.TrimSpace(req.Endnote) == "" {
		return patchResult{}, fiber.NewError(fiber.StatusBadRequest, "endnote is required")
	}

	next := fresh
	next.Active = false
	lowered := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		lowered[k] = false
	}
	next.Surfaces = lowered
	next.Phrases = []string{}
	if next.Notice == "delay" || next.Notice == "security" {
		next.Notice = ""
	}
	next.EndedBy = myid
	next.EndedAt = timePtr(time.Now())
	next.EndNote = req.Endnote

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to close lockdown")
	}
	return patchResult{rowID: rowID}, nil
}

func boolToInt(b bool) int {
	if b {
		return 1
	}
	return 0
}

func timePtr(t time.Time) *time.Time {
	return &t
}

func derefUint(p *uint64) uint64 {
	if p == nil {
		return 0
	}
	return *p
}

func derefString(p *string) string {
	if p == nil {
		return ""
	}
	return *p
}

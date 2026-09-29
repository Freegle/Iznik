package lockdown

// Handlers for the lockdown switch's own endpoints (plans/active/2026-09-27-lockdown-switch.md,
// sections 11.2 and 11.11): GET /lockdown, GET /modtools/lockdown, GET /modtools/lockdown/stats,
// GET /modtools/lockdown/history, GET /modtools/lockdown/held and PATCH /lockdown.
//
// The lockdown only holds. Working out who is behind a wave and dealing with them happens with
// the existing Support tools, so nothing here classifies held items or acts on them: Support
// sees counts of what is held and can browse and search the held items themselves.
//
// Every PATCH action writes a new row to the append-only lockdowns table and the cache is
// invalidated once the transaction commits, so the change is visible immediately.

import (
	"encoding/json"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

// surfaceKeys are the eight areas the surfaces JSON column always carries.
var surfaceKeys = []string{"chat", "posts", "chitchat", "events", "email", "push", "export", "mods"}

func isSurfaceKey(k string) bool {
	for _, sk := range surfaceKeys {
		if sk == k {
			return true
		}
	}
	return false
}

// maxNoticeLength caps the member notice the presser writes.
const maxNoticeLength = 500

// heldPageSize is how many held items GET /modtools/lockdown/held returns per page.
const heldPageSize = 50

// heldTextLength is how much of a held item's text the browse list returns.
const heldTextLength = 1000

// GetLockdown is the public, unauthenticated endpoint. It never returns active or surfaces:
// telling an anonymous caller whether a lockdown is active, or which areas are held, would let
// an attacker time their actions around it.
//
// While a lockdown is active it returns the current notice. Close clears the notice, so after
// close any notice was set afterwards (a "things are back to normal" message); it shows for 24
// hours from the row that set it and then stops.
//
// @Summary Get the public lockdown notice
// @Description Returns the current member-facing notice text, if any. Never reveals whether a
// @Description lockdown is active or which areas are held.
// @Tags lockdown
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /lockdown [get]
func GetLockdown(c *fiber.Ctx) error {
	s := Current()

	notice := s.Notice
	if !s.Active && !within24Hours(rowCreatedAt(s.ID)) {
		notice = ""
	}

	if notice == "" {
		return c.JSON(fiber.Map{"notice": nil})
	}
	return c.JSON(fiber.Map{"notice": fiber.Map{"text": notice}})
}

// within24Hours reports whether t is set and less than 24 hours in the past.
func within24Hours(t *time.Time) bool {
	return t != nil && time.Since(*t) < 24*time.Hour
}

// GetModtoolsLockdown is visible to any moderator, so ModTools can show the banner and hide
// the moderator actions that are refused.
//
// @Summary Get the lockdown state for moderators
// @Description Active, incidentid, surfaces, reason, notice and who started it.
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
	return c.JSON(modtoolsView(Current()))
}

// modtoolsView is the shared shape returned by GetModtoolsLockdown and every PATCH action.
func modtoolsView(s State) fiber.Map {
	var notice interface{}
	if s.Notice != "" {
		notice = s.Notice
	}
	return fiber.Map{
		"active":        s.Active,
		"incidentid":    s.IncidentID,
		"surfaces":      surfacesMap(s),
		"reason":        s.Reason,
		"notice":        notice,
		"startedat":     s.StartedAt,
		"startedby":     s.StartedBy,
		"startedbyname": userFullname(s.StartedBy),
	}
}

func surfacesMap(s State) map[string]bool {
	out := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		out[k] = s.Surfaces[k]
	}
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
// newest first: the full record of every change.
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
		Surfaces   *string    `gorm:"column:surfaces"`
		Changedby  *uint64    `gorm:"column:changedby"`
		Created    time.Time  `gorm:"column:created"`
		Startedby  *uint64    `gorm:"column:startedby"`
		Startedat  *time.Time `gorm:"column:startedat"`
		Endedby    *uint64    `gorm:"column:endedby"`
		Endedat    *time.Time `gorm:"column:endedat"`
		Endnote    *string    `gorm:"column:endnote"`
	}
	database.DBConn.Table("lockdowns").
		Select("id, incidentid, active, reason, notice, surfaces, changedby, created, startedby, startedat, endedby, endedat, endnote").
		Order("id DESC").Limit(50).Scan(&raw)

	rows := make([]fiber.Map, 0, len(raw))
	for _, r := range raw {
		rows = append(rows, fiber.Map{
			"id":            r.ID,
			"incidentid":    derefUint(r.Incidentid),
			"active":        r.Active == 1,
			"reason":        derefString(r.Reason),
			"notice":        derefString(r.Notice),
			"surfaces":      surfacesMap(State{Surfaces: decodeSurfaces(r.Surfaces)}),
			"changedby":     derefUint(r.Changedby),
			"changedbyname": userFullname(derefUint(r.Changedby)),
			"created":       r.Created,
			"startedby":     derefUint(r.Startedby),
			"startedat":     r.Startedat,
			"endedby":       derefUint(r.Endedby),
			"endedbyname":   userFullname(derefUint(r.Endedby)),
			"endedat":       r.Endedat,
			"endnote":       derefString(r.Endnote),
		})
	}
	return c.JSON(rows)
}

// GetModtoolsLockdownStats is what the Lockdown tab shows while a lockdown is on: how many of
// each kind of thing are held, what got out after the press, and whether each batch loop has
// picked up the latest change.
//
// @Summary Get lockdown stats
// @Description Counts of what is held, what leaked, and batch loop acknowledgements. Support/Admin only.
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
		"pressedat":     s.StartedAt,
		"pressedby":     s.StartedBy,
		"pressedbyname": userFullname(s.StartedBy),
		"minutesago":    minutesAgo,
		"rowid":         s.ID,
		"changedat":     rowCreatedAt(s.ID),
		"counts":        heldCounts(s.IncidentID),
		"leaked":        leakedSince(s.IncidentID, s.StartedAt),
		"acks":          acksStatus(s.ID),
		"api":           fiber.Map{"delayseconds": int(TTL.Seconds())},
	})
}

// rowCreatedAt is a lockdowns row's own "created" timestamp. Nil before anything has ever been
// pressed.
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

// heldCounts is one number per kind of thing the incident has held so far, released or not:
// held chat messages, posts and ChitChat posts from lockdown_holds; everything else from the
// counters the gates and batch loops write.
func heldCounts(incidentID uint64) fiber.Map {
	out := fiber.Map{
		"chat": int64(0), "post": int64(0), "chitchat": int64(0), "events": int64(0),
		"email": int64(0), "push": int64(0), "export": int64(0), "refused": int64(0),
	}
	if incidentID == 0 {
		return out
	}

	var holds []struct {
		Kind  string `gorm:"column:kind"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_holds").
		Select("kind, COUNT(*) AS count").
		Where("lockdownid = ?", incidentID).
		Group("kind").Scan(&holds)
	for _, h := range holds {
		if _, ok := out[h.Kind]; ok {
			out[h.Kind] = h.Count
		}
	}

	var counters []struct {
		Kind  string `gorm:"column:kind"`
		Count int64  `gorm:"column:count"`
	}
	database.DBConn.Table("lockdown_counters").
		Select("kind, count").
		Where("lockdownid = ?", incidentID).
		Scan(&counters)

	var events, email, push, export, refused int64
	for _, r := range counters {
		switch {
		case r.Kind == "held:events":
			events += r.Count
		case strings.HasPrefix(r.Kind, "deferred:"), strings.HasPrefix(r.Kind, "spooled_held:"):
			email += r.Count
		case r.Kind == "push":
			push += r.Count
		case r.Kind == "export":
			export += r.Count
		case r.Kind == "refused_member", strings.HasPrefix(r.Kind, "refused:"):
			refused += r.Count
		}
	}
	out["events"] = events
	out["email"] = email
	out["push"] = push
	out["export"] = export
	out["refused"] = refused
	return out
}

// ackLoops are the batch loops that call LockdownService::ack() (plan 11.6). Named here so a
// loop that has never acted since the press still appears, listed as not caught up.
var ackLoops = []string{
	"chat-process", "content-check", "auto-approve", "mail-spool",
	"mail-loops", "background-tasks", "push", "tick",
}

// acksStatus reads lockdown_acks (at most one row per loop) and reports, per loop, the lockdowns
// row id it last acted on, when, how many seconds after that row was written, and whether it has
// caught up with the current row.
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

// leakedSince is what still went out after the press despite the hold: every "leaked:*" counter
// the batch writes (mail that reached the relay, for instance), plus User2User chat messages the
// chat processor let through without ever holding them. A flat map with prefixes stripped; the
// page sums the values for one "sent since the press" figure.
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

// leakedChatCount counts User2User chat messages delivered after startedat by someone who is
// neither Support/Admin nor a moderator, which never went through the hold at all. A message
// with its own lockdown_holds row was held and later released, so it is not a leak.
//
// chat_messages.date is the only timestamp on the row, so it stands in for when it went out.
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

// heldItem is one row of GET /modtools/lockdown/held.
type heldItem struct {
	ID            uint64    `json:"id" gorm:"column:id"`
	Kind          string    `json:"kind" gorm:"column:kind"`
	Refid         uint64    `json:"refid" gorm:"column:refid"`
	Created       time.Time `json:"created" gorm:"column:created"`
	Userid        uint64    `json:"userid" gorm:"column:userid"`
	Name          string    `json:"name" gorm:"column:name"`
	Email         string    `json:"email" gorm:"column:email"`
	Text          string    `json:"text" gorm:"column:text"`
	Recipientid   *uint64   `json:"recipientid" gorm:"column:recipientid"`
	Recipientname *string   `json:"recipientname" gorm:"column:recipientname"`
}

// escapeLike escapes the LIKE wildcards in a search term so it matches literally.
func escapeLike(s string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(s)
}

// GetModtoolsLockdownHeld lets Support browse and search what the current incident is still
// holding, so they can see who is behind a wave and deal with them in Support tools. Read only:
// nothing here acts on a held item.
//
// @Summary Browse held items
// @Description Chat messages, posts or ChitChat posts still held by the current lockdown, newest
// @Description first, 50 per page. q searches the text and the sender's name and email.
// @Description Support/Admin only.
// @Tags lockdown
// @Produce json
// @Security BearerAuth
// @Param kind query string true "chat, post or chitchat"
// @Param q query string false "Search text, sender name or email"
// @Param userid query integer false "Only this sender"
// @Param before query integer false "Hold id to page from (exclusive)"
// @Success 200 {object} map[string]interface{}
// @Failure 400 {object} map[string]interface{}
// @Failure 401 {object} map[string]interface{}
// @Failure 403 {object} map[string]interface{}
// @Router /modtools/lockdown/held [get]
func GetModtoolsLockdownHeld(c *fiber.Ctx) error {
	kind := c.Query("kind")

	var textJoin, textExpr string
	switch kind {
	case "chat":
		textJoin = "INNER JOIN chat_messages t ON t.id = h.refid"
		textExpr = "t.message"
	case "post":
		textJoin = "INNER JOIN messages t ON t.id = h.refid"
		textExpr = "CONCAT(COALESCE(t.subject, ''), '\n', COALESCE(t.textbody, ''))"
	case "chitchat":
		textJoin = "INNER JOIN newsfeed t ON t.id = h.refid"
		textExpr = "t.message"
	default:
		return fiber.NewError(fiber.StatusBadRequest, "kind must be chat, post or chitchat")
	}

	s := Current()
	items := make([]heldItem, 0)
	if s.IncidentID == 0 {
		return c.JSON(fiber.Map{"items": items, "next": nil})
	}

	selects := "h.id, h.kind, h.refid, h.created, h.userid, COALESCE(u.fullname, '') AS name, " +
		"COALESCE((SELECT ue.email FROM users_emails ue WHERE ue.userid = h.userid ORDER BY ue.preferred DESC, ue.id ASC LIMIT 1), '') AS email, " +
		"COALESCE(" + textExpr + ", '') AS text"
	query := database.DBConn.Table("lockdown_holds AS h").
		Joins(textJoin).
		Joins("LEFT JOIN users u ON u.id = h.userid").
		Where("h.lockdownid = ? AND h.kind = ? AND h.outcome IS NULL", s.IncidentID, kind)

	if kind == "chat" {
		selects += ", CASE WHEN cr.user1 = h.userid THEN cr.user2 ELSE cr.user1 END AS recipientid, " +
			"ru.fullname AS recipientname"
		query = query.
			Joins("LEFT JOIN chat_rooms cr ON cr.id = t.chatid").
			Joins("LEFT JOIN users ru ON ru.id = CASE WHEN cr.user1 = h.userid THEN cr.user2 ELSE cr.user1 END")
	}

	if before, err := strconv.ParseUint(c.Query("before"), 10, 64); err == nil && before > 0 {
		query = query.Where("h.id < ?", before)
	}
	if userid, err := strconv.ParseUint(c.Query("userid"), 10, 64); err == nil && userid > 0 {
		query = query.Where("h.userid = ?", userid)
	}
	if q := strings.TrimSpace(c.Query("q")); q != "" {
		like := "%" + escapeLike(q) + "%"
		query = query.Where("("+textExpr+" LIKE ? OR u.fullname LIKE ? OR EXISTS "+
			"(SELECT 1 FROM users_emails se WHERE se.userid = h.userid AND se.email LIKE ?))", like, like, like)
	}

	query.Select(selects).Order("h.id DESC").Limit(heldPageSize + 1).Scan(&items)

	var next interface{}
	if len(items) > heldPageSize {
		items = items[:heldPageSize]
		next = items[len(items)-1].ID
	}
	for i := range items {
		items[i].Text = utils.TruncateStringUtil(items[i].Text, heldTextLength)
	}
	return c.JSON(fiber.Map{"items": items, "next": next})
}

// patchLockdownRequest covers every PATCH /lockdown action's body; each action handler reads
// only the fields it needs.
type patchLockdownRequest struct {
	Action   string          `json:"action"`
	Reason   string          `json:"reason"`
	Notice   *string         `json:"notice"`
	Surfaces map[string]bool `json:"surfaces"`
	Endnote  string          `json:"endnote"`
}

// patchResult carries the id of the row a sub-handler wrote back out to PatchLockdown.
type patchResult struct {
	rowID uint64
}

// PatchLockdown is the single Support/Admin write endpoint (RequireSupportOrAdminMiddleware on
// the route). Every action decides against the newest row, read FOR UPDATE inside one
// transaction, not against the five-second cache: two Support users acting within that window
// must not both believe they are pressing, or merge onto a state that has already moved on.
//
// @Summary Change the lockdown state
// @Description press, surfaces, notice, liftall or close. Support/Admin only. Each writes a new
// @Description row and returns the new state.
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
	return c.JSON(patchResponse(result.rowID, Current()))
}

// cleanNotice trims a requested notice. Nil or empty means no notice; over maxNoticeLength is
// refused.
func cleanNotice(n *string) (string, error) {
	if n == nil {
		return "", nil
	}
	text := strings.TrimSpace(*n)
	if len([]rune(text)) > maxNoticeLength {
		return "", fiber.NewError(fiber.StatusBadRequest, "The notice is limited to 500 characters")
	}
	return text, nil
}

// writeStateRow inserts the next row of the append-only lockdowns table on the transaction
// PatchLockdown holds the lock in; the caller invalidates the cache once it has committed. A
// fresh press needs a second write once the row's own id is known, because the incident is
// keyed on its pressing row.
func writeStateRow(tx *gorm.DB, myid uint64, next State) (uint64, error) {
	surfacesJSON, err := json.Marshal(surfacesMap(next))
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

func patchResponse(rowID uint64, s State) fiber.Map {
	view := modtoolsView(s)
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
	notice, err := cleanNotice(req.Notice)
	if err != nil {
		return patchResult{}, err
	}

	surfaces := make(map[string]bool, len(surfaceKeys))
	for _, k := range surfaceKeys {
		surfaces[k] = true
	}
	next := State{
		Active:    true,
		Surfaces:  surfaces,
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

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to update lockdown surfaces")
	}
	return patchResult{rowID: rowID}, nil
}

// patchNotice sets or clears the member notice. Allowed after close too, for a "things are
// back to normal" message, which GetLockdown shows for 24 hours.
func patchNotice(tx *gorm.DB, myid uint64, fresh State, req patchLockdownRequest) (patchResult, error) {
	notice, err := cleanNotice(req.Notice)
	if err != nil {
		return patchResult{}, err
	}
	next := fresh
	next.Notice = notice

	rowID, err := writeStateRow(tx, myid, next)
	if err != nil {
		return patchResult{}, fiber.NewError(fiber.StatusInternalServerError, "Failed to update lockdown notice")
	}
	return patchResult{rowID: rowID}, nil
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

// patchClose ends the incident and clears its member notice: a notice written for the incident
// must not outlive it.
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
	next.Notice = ""
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

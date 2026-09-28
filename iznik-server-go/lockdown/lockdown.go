// Package lockdown implements the site-wide lockdown switch (plans/active/2026-09-27-lockdown-switch.md,
// section 10): a manual, reversible hold that stops member-written content - chat, posts,
// ChitChat - from reaching anyone until a Support or Admin user lifts it, used to contain a
// spam wave without taking the site down.
//
// The current state is the newest row of the append-only lockdowns table, read through a
// five-second cache so the switch does not add a query to every request. The cache is
// deliberately not fail-open: a database error after a successful read keeps the last known
// state rather than silently lifting the lockdown, and only a lockdown that has never been
// read at all - a fresh process against a table with no rows - defaults to open.
package lockdown

import (
	"encoding/json"
	"fmt"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// TTL is how long a read is reused before Current re-reads the database. A package variable
// rather than a constant so tests can shrink it instead of sleeping five seconds.
var TTL = 5 * time.Second

// State is the lockdown state as of the last successful read. The zero value is the open
// state: not active, nothing held.
type State struct {
	ID         uint64
	IncidentID uint64
	Active     bool
	Surfaces   map[string]bool
	ChatMode   string
	Reason     string
	Notice     string
	Phrases    []string
	StartedAt  *time.Time
	StartedBy  uint64
	EndedAt    *time.Time
	EndedBy    uint64
	EndNote    string
}

var (
	mu      sync.Mutex
	current State
	expires time.Time
)

// loadLatest reads the newest lockdowns row from the database. It is a package variable so
// tests can substitute a failing implementation without touching the real connection.
var loadLatest = loadLatestFromDB

// Current returns the live lockdown state, refreshing from the database at most once per
// TTL. A read that fails keeps whatever was last known - the zero value if there has never
// been a successful read, which is the open state.
func Current() State {
	mu.Lock()
	defer mu.Unlock()

	if time.Now().Before(expires) {
		return current
	}

	s, err := loadLatest()
	if err != nil {
		return current
	}

	current = s
	expires = time.Now().Add(TTL)
	return current
}

// Invalidate forces the next Current call to re-read the database. Called after every
// PATCH /lockdown write so the change takes effect immediately rather than waiting out the
// cache.
func Invalidate() {
	mu.Lock()
	defer mu.Unlock()
	expires = time.Time{}
}

// SetTestState overrides the in-process lockdown state for tests, without ever touching the
// database. It exists so that gate tests living OUTSIDE this package - a membership, message
// or newsfeed handler test proving its endpoint is held or refused - do not need a real
// "lockdowns" row to make Held/GateMod/GateMember/GateDownload see the state they are testing.
//
// This matters because `go test ./...` runs different packages' test binaries concurrently
// (the plain, non-coverage run has no -p 1), each against the SAME shared test database. A
// test that wrote a genuine active row - even one cleaned up a moment later - would be
// visible to every other package's process the instant its own cache read it, which turns
// "gate refuses this action" tests across a dozen packages into a source of unrelated,
// timing-dependent failures elsewhere in the suite. SetTestState instead only ever touches
// this process's own package-level cache variables, so it is invisible to every other test
// binary: a database race that only a real PATCH /lockdown write can cause (see the small,
// deliberately real-row set of tests in test/lockdown_handlers_test.go, which accept that
// exposure because they are testing the persistence layer itself, the same tradeoff already
// made by setActiveLockdownRow in this package's own tests).
//
// Returns a restore function that puts the real database-backed state back; call it via
// defer so the override never leaks into the next test in the same binary.
func SetTestState(s State) func() {
	mu.Lock()
	current = s
	expires = time.Now().Add(time.Hour)
	loadLatest = func() (State, error) { return s, nil }
	mu.Unlock()

	return func() {
		mu.Lock()
		defer mu.Unlock()
		current = State{}
		expires = time.Time{}
		loadLatest = loadLatestFromDB
	}
}

// Held reports whether the given surface is currently held back: the switch is active and
// this surface is one of the ones it covers. Held applies uniformly to everyone, including
// Support and Admin - there is no exemption from being held, only from being refused.
func Held(surface string) bool {
	s := Current()
	return s.Active && s.Surfaces[surface]
}

// ChatMode returns the chat hold mode ("hard" or "soft") when chat is currently held, or ""
// when it is not.
func ChatMode() string {
	s := Current()
	if !s.Active || !s.Surfaces["chat"] {
		return ""
	}
	return s.ChatMode
}

// Count increments the counter for kind against the current incident. It is a no-op when
// the site is not locked down, since there is then no incident to attach the count to.
//
// Keyed on IncidentID, not ID: ID is the newest row's own primary key, which changes every
// time a surfaces/notice/phrases sub-action writes another row for the same incident, while
// IncidentID is carried unchanged on every row of that incident (the migration's own doc
// comment on lockdowns: "holds and counters hang off it [incidentid]"). Keying on ID would
// split one incident's counters across as many lockdownid values as it had sub-actions.
func Count(kind string) {
	s := Current()
	if !s.Active || s.IncidentID == 0 {
		return
	}

	database.DBConn.Table("lockdown_counters").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"count": gorm.Expr("count + 1"),
		}),
	}).Create(map[string]interface{}{
		"lockdownid": s.IncidentID,
		"kind":       kind,
		"count":      1,
	})
}

// InsertHold records a held chat message, post or ChitChat post against the current
// incident so it can be triaged and released later. It is a no-op when the site is not
// locked down.
//
// Keyed on IncidentID rather than ID for the same reason as Count above.
func InsertHold(kind string, refid uint64, userid uint64, risk string) {
	s := Current()
	if !s.Active || s.IncidentID == 0 {
		return
	}

	var riskVal interface{}
	if risk != "" {
		riskVal = risk
	}

	database.DBConn.Table("lockdown_holds").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{
			"risk": riskVal,
		}),
	}).Create(map[string]interface{}{
		"lockdownid": s.IncidentID,
		"kind":       kind,
		"refid":      refid,
		"userid":     userid,
		"risk":       riskVal,
	})
}

// Refuse writes the 409 response for an action refused during lockdown. Support and Admin
// callers are exempt from every refusal except downloads - callers must check that
// themselves before calling Refuse.
func Refuse(c *fiber.Ctx) error {
	return c.Status(fiber.StatusConflict).JSON(fiber.Map{
		"ret":      409,
		"status":   "Changes are paused for a few hours while we deal with a security incident.",
		"lockdown": true,
	})
}

// RefuseDownload writes the 409 response for a download refused during lockdown. Nobody is
// exempt from this one, not even Support or Admin.
func RefuseDownload(c *fiber.Ctx) error {
	return c.Status(fiber.StatusConflict).JSON(fiber.Map{
		"ret":      409,
		"status":   "Downloads are paused while we deal with a security incident.",
		"lockdown": true,
	})
}

// GateMod is a one-liner for a moderator-action dispatch point (approve-with-text, reject,
// delete, ban, and the other actions section 10.5 refuses). When "mods" is held and the
// caller is not Support or Admin, it writes the refusal, counts it against the acting
// moderator - so a hijacked account's actions show up in the stats within a minute - and
// returns true; the caller must return immediately. Support and Admin pass through untouched,
// and nothing is written when "mods" is not held.
func GateMod(c *fiber.Ctx, myid uint64) bool {
	if !Held("mods") || auth.IsAdminOrSupport(myid) {
		return false
	}
	Count(fmt.Sprintf("refused:%d", myid))
	_ = Refuse(c)
	return true
}

// CountApproval records a moderator approval made while "mods" is held. Approve stays allowed
// for everyone even during a hold, but a wave of approvals from a hijacked moderator account
// must still be visible in the stats. A no-op when "mods" is not held.
func CountApproval(myid uint64) {
	if Held("mods") {
		Count(fmt.Sprintf("approved:%d", myid))
	}
}

// GateMember is a one-liner for a member-facing write gated by a named surface (posts, chat,
// chitchat, events, ...). When that surface is held and the caller is not Support or Admin,
// it writes the refusal, counts it as refused_member, and returns true; the caller must
// return immediately. Use Held directly instead when the surface must instead be held rather
// than refused - GateMember is only for the refused treatments in section 10.5.
func GateMember(c *fiber.Ctx, myid uint64, surface string) bool {
	if !Held(surface) || auth.IsAdminOrSupport(myid) {
		return false
	}
	Count("refused_member")
	_ = Refuse(c)
	return true
}

// GateDownload is a one-liner for a data-export endpoint. When "export" is held it writes the
// downloads-specific refusal and counts it. Nobody is exempt from this one, not even Support
// or Admin: a download is exactly what an attacker holding a hijacked Support account would
// want, so unlike every other gate this one applies to everybody.
func GateDownload(c *fiber.Ctx) bool {
	if !Held("export") {
		return false
	}
	Count("export")
	_ = RefuseDownload(c)
	return true
}

// ItemHeld reports whether a chat message, post or ChitChat post (kind "chat", "post" or
// "chitchat", refid the item's own id) is currently held pending triage: a lockdown_holds
// row exists for it with no outcome recorded yet. This is a plain, uncached read of the
// unique (kind, refid) key - unlike Held, it is not a surface-level in-memory state, so a
// caller always sees a hold recorded moments ago by another process (e.g. the Laravel batch
// triage service, which inserts kind='post' rows Go never writes itself) without waiting out
// Current's TTL.
//
// For labelling a card "held by lockdown" on read (section 10.6/10.12) - never for gating a
// write; use Held/GateMod/GateMember/GateDownload for that.
func ItemHeld(kind string, refid uint64) bool {
	var count int64
	database.DBConn.Table("lockdown_holds").
		Where("kind = ? AND refid = ? AND outcome IS NULL", kind, refid).
		Count(&count)
	return count > 0
}

func loadLatestFromDB() (State, error) {
	var row struct {
		ID         uint64
		Incidentid uint64
		Active     int
		Surfaces   *string
		Reason     *string
		Notice     *string
		Phrases    *string
		Startedby  *uint64
		Startedat  *time.Time
		Endedby    *uint64
		Endedat    *time.Time
		Endnote    *string
	}

	result := database.DBConn.Table("lockdowns").
		Select("id, incidentid, active, surfaces, reason, notice, phrases, startedby, startedat, endedby, endedat, endnote").
		Order("id DESC").
		Limit(1).
		Scan(&row)

	if result.Error != nil {
		return State{}, result.Error
	}

	if row.ID == 0 {
		// No rows yet: open.
		return State{}, nil
	}

	s := State{
		ID:         row.ID,
		IncidentID: row.Incidentid,
		Active:     row.Active == 1,
		Surfaces:   map[string]bool{},
		StartedAt:  row.Startedat,
		EndedAt:    row.Endedat,
	}
	if row.Reason != nil {
		s.Reason = *row.Reason
	}
	if row.Notice != nil {
		s.Notice = *row.Notice
	}
	if row.Endnote != nil {
		s.EndNote = *row.Endnote
	}
	if row.Startedby != nil {
		s.StartedBy = *row.Startedby
	}
	if row.Endedby != nil {
		s.EndedBy = *row.Endedby
	}

	if row.Surfaces != nil && *row.Surfaces != "" {
		var raw map[string]interface{}
		if err := json.Unmarshal([]byte(*row.Surfaces), &raw); err == nil {
			for k, v := range raw {
				switch vv := v.(type) {
				case bool:
					s.Surfaces[k] = vv
				case string:
					if k == "chat_mode" {
						s.ChatMode = vv
					}
				}
			}
		}
	}

	if row.Phrases != nil && *row.Phrases != "" {
		var phrases []string
		if err := json.Unmarshal([]byte(*row.Phrases), &phrases); err == nil {
			s.Phrases = phrases
		}
	}

	return s, nil
}

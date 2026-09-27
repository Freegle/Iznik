package lockdown

import (
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func init() {
	database.InitDatabase()
}

func reset() {
	mu.Lock()
	current = State{}
	expires = time.Time{}
	mu.Unlock()
	loadLatest = loadLatestFromDB
}

// A process that has never managed a successful read - a fresh boot, or a database that has
// never answered - must behave as if the site is not locked down. Failing open on a database
// blip would be the wrong default here, but failing open before there is any "last known
// state" to stick to is the only sane one.
func TestNeverReadDefaultsToOpen(t *testing.T) {
	reset()
	loadLatest = func() (State, error) { return State{}, errors.New("db unavailable") }
	defer func() { loadLatest = loadLatestFromDB }()

	s := Current()
	assert.False(t, s.Active)
	assert.False(t, Held("posts"))
}

func TestFreshReadPopulatesTheCache(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, ID: 5, Surfaces: map[string]bool{"posts": true}}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	s := Current()
	assert.True(t, s.Active)
	assert.Equal(t, uint64(5), s.ID)
	assert.True(t, Held("posts"))
}

func TestASecondCallWithinTheTTLDoesNotReRead(t *testing.T) {
	reset()
	oldTTL := TTL
	TTL = 50 * time.Millisecond
	defer func() { TTL = oldTTL }()

	calls := 0
	loadLatest = func() (State, error) {
		calls++
		return State{Active: true, ID: 1}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	Current()
	Current()
	assert.Equal(t, 1, calls, "a second call within the TTL must reuse the cached read")
}

func TestACallAfterTheTTLReReads(t *testing.T) {
	reset()
	oldTTL := TTL
	TTL = 20 * time.Millisecond
	defer func() { TTL = oldTTL }()

	calls := 0
	loadLatest = func() (State, error) {
		calls++
		return State{Active: true, ID: 1}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	Current()
	time.Sleep(30 * time.Millisecond)
	Current()
	assert.Equal(t, 2, calls, "a call after the TTL has passed must re-read")
}

// A lockdown must not lift itself just because a query timed out - that would turn a
// database blip into a spam wave reaching everyone mid-incident.
func TestAFailedReadAfterASuccessKeepsTheLastKnownState(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, ID: 9, Surfaces: map[string]bool{"chat": true}}, nil
	}
	s := Current()
	require.True(t, s.Active)

	Invalidate()
	loadLatest = func() (State, error) { return State{}, errors.New("db unavailable") }
	defer func() { loadLatest = loadLatestFromDB }()

	s2 := Current()
	assert.True(t, s2.Active, "a failed read must keep the last known state, not fail open")
	assert.True(t, Held("chat"))
}

func TestInvalidateForcesAReReadEvenWithinTheTTL(t *testing.T) {
	reset()
	calls := 0
	loadLatest = func() (State, error) {
		calls++
		return State{Active: true, ID: 1}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	Current()
	Invalidate()
	Current()
	assert.Equal(t, 2, calls, "Invalidate must force the next call to re-read regardless of TTL")
}

func TestHeldIsFalseWhenTheSwitchIsNotActive(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: false, Surfaces: map[string]bool{"posts": true}}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	assert.False(t, Held("posts"), "a surface flag left over from a closed incident must not hold anything")
}

func TestHeldIsFalseForASurfaceNotCovered(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, Surfaces: map[string]bool{"posts": true}}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	assert.False(t, Held("chitchat"))
}

func TestChatModeIsEmptyUnlessChatIsHeld(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, Surfaces: map[string]bool{"posts": true}, ChatMode: "hard"}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	assert.Equal(t, "", ChatMode(), "chat_mode must be ignored when chat itself is not held")
}

func TestChatModeReflectsTheHeldMode(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, Surfaces: map[string]bool{"chat": true}, ChatMode: "soft"}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	assert.Equal(t, "soft", ChatMode())
}

// loadLatestFromDB is the one function the mocked tests above never exercise, so its
// SQL/JSON round trip is covered separately, directly against the real database.
func TestLoadLatestFromDBRoundTrip(t *testing.T) {
	db := database.DBConn

	result := db.Table("lockdowns").Create(map[string]interface{}{
		"active":     1,
		"incidentid": 12345,
		"surfaces":   `{"chat":true,"chat_mode":"hard","posts":true,"chitchat":false}`,
		"reason":     "test incident",
		"notice":     "security",
		"phrases":    `["buy now","click here"]`,
		"startedby":  1,
		"startedat":  time.Now(),
	})
	require.NoError(t, result.Error)

	var id uint64
	db.Table("lockdowns").Select("id").Order("id DESC").Limit(1).Scan(&id)
	require.NotZero(t, id)
	t.Cleanup(func() {
		db.Table("lockdowns").Where("id = ?", id).Delete(nil)
	})

	s, err := loadLatestFromDB()
	require.NoError(t, err)
	assert.Equal(t, id, s.ID)
	assert.Equal(t, uint64(12345), s.IncidentID)
	assert.True(t, s.Active)
	assert.True(t, s.Surfaces["chat"])
	assert.True(t, s.Surfaces["posts"])
	assert.False(t, s.Surfaces["chitchat"])
	assert.Equal(t, "hard", s.ChatMode)
	assert.Equal(t, "test incident", s.Reason)
	assert.Equal(t, "security", s.Notice)
	assert.Equal(t, []string{"buy now", "click here"}, s.Phrases)
	assert.Equal(t, uint64(1), s.StartedBy)
	require.NotNil(t, s.StartedAt)
}

// ---------------------------------------------------------------------------
// GateMod, CountApproval, GateMember, GateDownload
//
// These compose Held/Count/Refuse with a real auth.IsAdminOrSupport check, so unlike the
// tests above they need a real user row with a known systemrole rather than a mocked
// loadLatest. They cannot use the test package's CreateTestUser: that package imports
// lockdown for its own black-box gate tests, so lockdown importing it back would cycle.
// ---------------------------------------------------------------------------

func createTestUser(t *testing.T, role string) uint64 {
	t.Helper()
	db := database.DBConn
	name := fmt.Sprintf("Lockdown Gate Test %d", time.Now().UnixNano())
	result := db.Exec("INSERT INTO users (firstname, lastname, fullname, systemrole, lastlocation) "+
		"VALUES ('Lockdown', 'Gate Test', ?, ?, NULL)", name, role)
	require.NoError(t, result.Error)

	var id uint64
	db.Raw("SELECT id FROM users WHERE fullname = ? ORDER BY id DESC LIMIT 1", name).Scan(&id)
	require.NotZero(t, id)

	t.Cleanup(func() {
		db.Exec("DELETE FROM users WHERE id = ?", id)
	})
	return id
}

func counterCount(lockdownID uint64, kind string) int {
	var n int
	database.DBConn.Table("lockdown_counters").
		Select("count").Where("lockdownid = ? AND kind = ?", lockdownID, kind).Scan(&n)
	return n
}

func newLockdownTestApp(handler fiber.Handler) *fiber.App {
	app := fiber.New()
	app.All("/probe", handler)
	return app
}

func probeRequest(t *testing.T, app *fiber.App) *http.Response {
	t.Helper()
	resp, err := app.Test(httptest.NewRequest("POST", "/probe", nil))
	require.NoError(t, err)
	return resp
}

func setActiveLockdownRow(t *testing.T, surfaces string) uint64 {
	t.Helper()
	db := database.DBConn
	result := db.Table("lockdowns").Create(map[string]interface{}{
		"active":     1,
		"incidentid": 1,
		"surfaces":   surfaces,
		"startedby":  1,
		"startedat":  time.Now(),
	})
	require.NoError(t, result.Error)

	var id uint64
	db.Table("lockdowns").Select("id").Order("id DESC").Limit(1).Scan(&id)
	require.NotZero(t, id)
	t.Cleanup(func() {
		db.Table("lockdowns").Where("id = ?", id).Delete(nil)
		db.Table("lockdown_counters").Where("lockdownid = ?", id).Delete(nil)
	})

	loadLatest = loadLatestFromDB
	Invalidate()
	return id
}

func TestGateModReturnsFalseWhenModsNotHeld(t *testing.T) {
	reset()
	loadLatest = func() (State, error) {
		return State{Active: true, ID: 1, Surfaces: map[string]bool{"posts": true}}, nil
	}
	defer func() { loadLatest = loadLatestFromDB }()

	var refused bool
	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		refused = GateMod(c, 999999)
		if refused {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.False(t, refused)
}

func TestGateModRefusesANonExemptModerator(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"mods":true}`)
	modid := createTestUser(t, utils.SYSTEMROLE_MODERATOR)

	var refused bool
	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		refused = GateMod(c, modid)
		if refused {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.True(t, refused)
	assert.Equal(t, fiber.StatusConflict, resp.StatusCode)
	assert.Equal(t, 1, counterCount(id, fmt.Sprintf("refused:%d", modid)))
}

func TestGateModPassesSupportAndAdminThrough(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"mods":true}`)
	supportid := createTestUser(t, utils.SYSTEMROLE_SUPPORT)

	var refused bool
	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		refused = GateMod(c, supportid)
		if refused {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.False(t, refused)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.Equal(t, 0, counterCount(id, fmt.Sprintf("refused:%d", supportid)))
}

func TestCountApprovalCountsOnlyWhenModsHeld(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"mods":true}`)
	modid := createTestUser(t, utils.SYSTEMROLE_MODERATOR)

	CountApproval(modid)
	assert.Equal(t, 1, counterCount(id, fmt.Sprintf("approved:%d", modid)))
}

func TestCountApprovalIsANoOpWhenModsNotHeld(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"posts":true}`)
	modid := createTestUser(t, utils.SYSTEMROLE_MODERATOR)

	CountApproval(modid)
	assert.Equal(t, 0, counterCount(id, fmt.Sprintf("approved:%d", modid)))
}

func TestGateMemberRefusesANonExemptMember(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"posts":true}`)
	userid := createTestUser(t, utils.SYSTEMROLE_USER)

	var refused bool
	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		refused = GateMember(c, userid, "posts")
		if refused {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.True(t, refused)
	assert.Equal(t, fiber.StatusConflict, resp.StatusCode)
	assert.Equal(t, 1, counterCount(id, "refused_member"))
}

func TestGateMemberExemptsSupportAndAdmin(t *testing.T) {
	reset()
	setActiveLockdownRow(t, `{"posts":true}`)
	adminid := createTestUser(t, utils.SYSTEMROLE_ADMIN)

	var refused bool
	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		refused = GateMember(c, adminid, "posts")
		if refused {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.False(t, refused)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestGateMemberIgnoresAnUnrelatedSurface(t *testing.T) {
	reset()
	setActiveLockdownRow(t, `{"chat":true}`)
	userid := createTestUser(t, utils.SYSTEMROLE_USER)

	assert.False(t, GateMember(nil, userid, "posts"), "posts is not held, so GateMember must return false without touching the (nil) ctx")
}

func TestGateDownloadRefusesEvenSupportAndAdmin(t *testing.T) {
	reset()
	id := setActiveLockdownRow(t, `{"export":true}`)

	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		if GateDownload(c) {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.Equal(t, fiber.StatusConflict, resp.StatusCode)
	assert.Equal(t, 1, counterCount(id, "export"))
}

func TestGateDownloadIsANoOpWhenExportNotHeld(t *testing.T) {
	reset()
	setActiveLockdownRow(t, `{"posts":true}`)

	app := newLockdownTestApp(func(c *fiber.Ctx) error {
		if GateDownload(c) {
			return nil
		}
		return c.SendStatus(fiber.StatusOK)
	})
	resp := probeRequest(t, app)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestLoadLatestFromDBWithNoRowsIsOpen(t *testing.T) {
	db := database.DBConn
	db.Table("lockdowns").Where("1 = 1").Delete(nil)

	s, err := loadLatestFromDB()
	require.NoError(t, err)
	assert.False(t, s.Active)
	assert.Equal(t, uint64(0), s.ID)
}

package test

// Gate test for partnerships/partnerships.go (section 11.3 of the lockdown plan,
// plans/active/2026-09-27-lockdown-switch.md; review finding 4): the eight write
// handlers (Create, Update, Delete, PatchGroups, PutYears, CreatePayment,
// UpdatePayment, DeletePayment) are reachable by the Partnerships team, who are not
// Support or Admin, and had no lockdown gate at all. They are gated on GateMod, the
// same "mods" surface every other moderator-only write uses - Support/Admin stay
// exempt, everyone else is refused while "mods" is held.
//
// Uses lockdown.SetTestState (see modsHeld() in lockdown_gates_moderation_test.go) so
// this test never writes a real "lockdowns" row and cannot race the other packages'
// test binaries that `go test ./...` runs concurrently against the same test database.
// partnershipsUser, createPartnershipAuthority and defaultBody are defined in
// partnerships_test.go, same package.

import (
	"fmt"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

func TestLockdownRefusesPartnershipCreate(t *testing.T) {
	prefix := uniquePrefix("ld_partner_create")
	_, token := partnershipsUser(t, prefix)
	authorityID := createPartnershipAuthority(t, prefix)

	restore := modsHeld()
	defer restore()

	req := httptest.NewRequest("POST", fmt.Sprintf("/api/partnership?jwt=%s", token),
		strings.NewReader(defaultBody(authorityID)))
	req.Header.Set("Content-Type", "application/json")
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	assertLockdownRefused(t, resp)

	db := database.DBConn
	var count int64
	db.Raw("SELECT COUNT(*) FROM partnerships WHERE authorityid = ?", authorityID).Scan(&count)
	assert.Equal(t, int64(0), count, "no partnership must be created while mods is held")
}

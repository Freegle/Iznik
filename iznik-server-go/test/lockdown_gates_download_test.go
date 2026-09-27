package test

// Gate tests for the download-shaped endpoints named in section 11.3 of the lockdown
// plan (plans/active/2026-09-27-lockdown-switch.md): export.go's PostExport/GetExport
// (a member's own GDPR data export), userdump.go's GetUserDump and
// partnerships/stats.go's DownloadStatsFile. All are refused while "export" is held,
// and - like spammers.go's ExportSpammers (covered in lockdown_gates_moderation_test.go)
// - nobody is exempt, not even Support/Admin (GateDownload's own rule, distinct from
// GateMod's Support/Admin exemption).
//
// Uses lockdown.SetTestState (see modsHeld()/postsHeld() in the other
// lockdown_gates_*_test.go files) so these tests never write a real "lockdowns" row
// and cannot race the other packages' test binaries that `go test ./...` runs
// concurrently against the same test database.

import (
	"fmt"
	"net/http/httptest"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/lockdown"
)

func exportHeld() func() {
	return lockdown.SetTestState(lockdown.State{
		Active:   true,
		Surfaces: map[string]bool{"export": true},
	})
}

func TestLockdownRefusesExportRequestEvenForSupport(t *testing.T) {
	prefix := uniquePrefix("ld_export_post")
	db := database.DBConn
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	restore := exportHeld()
	defer restore()

	req := httptest.NewRequest("POST", "/api/export?jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assertLockdownDownloadRefused(t, resp)

	var count int64
	db.Raw("SELECT COUNT(*) FROM users_exports WHERE userid = ?", supportID).Scan(&count)
	if count != 0 {
		t.Fatalf("no export request must be created while export is held, found %d", count)
	}
}

func TestLockdownRefusesExportDownloadEvenForSupport(t *testing.T) {
	prefix := uniquePrefix("ld_export_get")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)

	restore := exportHeld()
	defer restore()

	// GateDownload runs before id/tag are parsed, so a real export row is not needed:
	// the handler must never get far enough to look for one.
	req := httptest.NewRequest("GET", "/api/export?id=999999999&tag=doesnotmatter&jwt="+token, nil)
	resp, _ := getApp().Test(req)
	assertLockdownDownloadRefused(t, resp)
}

func TestLockdownRefusesUserDumpEvenForSupport(t *testing.T) {
	prefix := uniquePrefix("ld_userdump")
	supportID := CreateTestUser(t, prefix+"_sup", "Support")
	_, token := CreateTestSession(t, supportID)
	targetID := CreateTestUser(t, prefix+"_target", "User")

	restore := exportHeld()
	defer restore()

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/modtools/user/%d/dump?jwt=%s", targetID, token), nil)
	resp, _ := getApp().Test(req)
	assertLockdownDownloadRefused(t, resp)
}

func TestLockdownRefusesStatsFileDownload(t *testing.T) {
	prefix := uniquePrefix("ld_statsfile")
	db := database.DBConn
	// Downloading a stats file requires CanUse (partnerships.go): Admin/Support, or a
	// member of the partnerships team - a plain member is refused with 403 before the
	// handler ever reaches the lockdown gate, which would prove the wrong thing here.
	// Support is used, matching TestLockdownRefusesUserDumpEvenForSupport above, so the
	// test actually exercises "nobody is exempt from downloads, not even Support/Admin".
	userID := CreateTestUser(t, prefix+"_user", "Support")
	_, token := CreateTestSession(t, userID)

	// partnerships_statsfiles.jobid is a not-null FK to partnerships_statsjobs
	// (cascade delete), so a job row must exist first.
	jobRow := map[string]interface{}{"authorityids": "1", "quarter": "3 months ago"}
	if err := db.Table("partnerships_statsjobs").Create(jobRow).Error; err != nil {
		t.Fatalf("failed to create partnerships_statsjobs row: %v", err)
	}
	jobIDInt, _ := jobRow["@id"].(int64)
	jobID := uint64(jobIDInt)
	if jobID == 0 {
		t.Fatalf("partnerships_statsjobs insert did not report an id")
	}

	row := map[string]interface{}{"jobid": jobID, "filename": prefix + ".xlsx", "content": []byte("test")}
	if err := db.Table("partnerships_statsfiles").Create(row).Error; err != nil {
		t.Fatalf("failed to create partnerships_statsfiles row: %v", err)
	}
	idInt, _ := row["@id"].(int64)
	fileID := uint64(idInt)
	if fileID == 0 {
		t.Fatalf("partnerships_statsfiles insert did not report an id")
	}

	restore := exportHeld()
	defer restore()

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/partnership/statsfile/%d?jwt=%s", fileID, token), nil)
	resp, _ := getApp().Test(req)
	assertLockdownDownloadRefused(t, resp)

	db.Exec("DELETE FROM partnerships_statsfiles WHERE id = ?", fileID)
	db.Exec("DELETE FROM partnerships_statsjobs WHERE id = ?", jobID)
}

package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/ux"
)

// writeServerArmConfig writes the two config rows an arm is read from, flushes the API's copy of them,
// and puts back what was there when the test ends. An empty experiments string writes no ux_experiments
// row, and haveSwitch false writes no ux_server_arms row.
func writeServerArmConfig(t *testing.T, experiments string, switchValue string, haveSwitch bool) {
	t.Helper()
	db := database.DBConn

	var old []struct {
		Key   string
		Value string
	}
	db.Raw("SELECT `key` AS `key`, value AS value FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')").Scan(&old)
	db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
	if experiments != "" {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_experiments', ?)", experiments)
	}
	if haveSwitch {
		db.Exec("INSERT INTO config (`key`, value) VALUES ('ux_server_arms', ?)", switchValue)
	}
	ux.FlushServerArmCache()

	t.Cleanup(func() {
		db.Exec("DELETE FROM config WHERE `key` IN ('ux_experiments', 'ux_server_arms')")
		for _, r := range old {
			db.Exec("INSERT INTO config (`key`, value) VALUES (?, ?)", r.Key, r.Value)
		}
		ux.FlushServerArmCache()
	})
}

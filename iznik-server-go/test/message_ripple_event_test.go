package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/message"
	"github.com/stretchr/testify/assert"
)

// RecordRippleEvent upserts a per-day counter (§15/§16 instrumentation).
func TestRecordRippleEvent(t *testing.T) {
	db := database.DBConn
	db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'test_evt'")
	defer db.Exec("DELETE FROM rippling_event_metrics WHERE event = 'test_evt'")

	message.RecordRippleEvent(db, "test_evt")
	message.RecordRippleEvent(db, "test_evt")

	var count int
	db.Raw("SELECT count FROM rippling_event_metrics WHERE day = CURDATE() AND event = 'test_evt'").Scan(&count)
	assert.Equal(t, 2, count, "per-event counter increments in place via upsert")
}

package queue

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

func TestTaskTypeConstants(t *testing.T) {
	// Verify task type string values — iznik-batch Laravel code expects these
	// exact strings; a rename would silently break the hand-off between Go and PHP.
	assert.Equal(t, "push_notify_group_mods", TaskPushNotifyGroupMods)
	assert.Equal(t, "email_chitchat_report", TaskEmailChitchatReport)
	assert.Equal(t, "email_charity_signup", TaskEmailCharitySignup)
	assert.Equal(t, "email_donate_external", TaskEmailDonateExternal)
	assert.Equal(t, "email_forgot_password", TaskEmailForgotPassword)
	assert.Equal(t, "email_unsubscribe", TaskEmailUnsubscribe)
	assert.Equal(t, "email_merge", TaskEmailMerge)
	assert.Equal(t, "email_verify", TaskEmailVerify)
	assert.Equal(t, "freebie_alerts_add", TaskFreebieAlertsAdd)
	assert.Equal(t, "freebie_alerts_remove", TaskFreebieAlertsRemove)
	assert.Equal(t, "remap_postcodes", TaskRemapPostcodes)
	assert.Equal(t, "user_forget", TaskUserForget)
}

func TestQueueTaskMarshalError(t *testing.T) {
	// Channels are not JSON-serializable; QueueTask must return an error before
	// reaching the database — safe to run without a DB connection.
	err := QueueTask("test", map[string]interface{}{
		"bad": make(chan int),
	})
	assert.Error(t, err)
}

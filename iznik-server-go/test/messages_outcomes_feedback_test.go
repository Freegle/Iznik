package test

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// messages_outcomes.feedback marks the outcomes whose comment is a member's own words. The
// Feedback badge counts on it, so it has to agree with what the badge has always excluded.
func TestMessagesOutcomesFeedbackColumn(t *testing.T) {
	prefix := uniquePrefix("fbcol")
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	userID := CreateTestUser(t, prefix+"_user", "User")
	msgID := CreateTestMessage(t, userID, groupID, prefix+" offer", 52.5, -1.8)

	cases := map[string]struct {
		comment  interface{}
		expected int
	}{
		"real":       {"Lovely, thank you!", 1},
		"empty":      {"", 0},
		"null":       {nil, 0},
		"auto":       {"Auto-Expired", 0},
		"taken":      {"Thanks, this has now been taken.", 0},
		"apostrophe": {"Thanks, I'm no longer looking for this.", 0},
	}

	for name, c := range cases {
		db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, reviewed) VALUES (?, 'Taken', 'Happy', ?, 0)", msgID, c.comment)
		var got int
		db.Raw("SELECT feedback FROM messages_outcomes WHERE msgid = ? ORDER BY id DESC LIMIT 1", msgID).Scan(&got)
		assert.Equal(t, c.expected, got, name)
	}

	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)
}

// The badge scan was ~0.9s because it read every unreviewed outcome's comment. An index that
// leads with feedback is what lets it read only the real ones.
func TestMessagesOutcomesFeedbackIndexAvailable(t *testing.T) {
	db := database.DBConn

	var rows []struct {
		PossibleKeys *string `gorm:"column:possible_keys"`
	}
	db.Raw("EXPLAIN SELECT COUNT(*) FROM messages_outcomes mo WHERE mo.feedback = 1 AND mo.reviewed = 0 " +
		"AND mo.timestamp >= '2026-01-01' AND (mo.happiness = 'Happy' OR mo.happiness IS NULL)").Scan(&rows)

	assert.Len(t, rows, 1)
	if len(rows) == 1 && assert.NotNil(t, rows[0].PossibleKeys) {
		assert.True(t, strings.Contains(*rows[0].PossibleKeys, "feedback_reviewed_timestamp"), *rows[0].PossibleKeys)
	}
}

// The session badge now matches the group badge: a rating with no comment is not feedback.
func TestWorkCountHappinessEmptyCommentExcluded(t *testing.T) {
	prefix := uniquePrefix("wc_happy_empty")
	db := database.DBConn
	groupID := CreateTestGroup(t, prefix)
	modID := CreateTestUser(t, prefix+"_mod", "User")
	CreateTestMembership(t, modID, groupID, "Moderator")
	_, token := CreateTestSession(t, modID)

	memberID := CreateTestUser(t, prefix+"_member", "User")
	msgID := CreateTestMessage(t, memberID, groupID, "OFFER: Rating only item", 55.95, -3.19)
	db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments, reviewed, timestamp) "+
		"VALUES (?, 'Taken', 'Happy', '', 0, NOW())", msgID)
	defer db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", msgID)

	work := getSessionWork(t, token)
	assert.Equal(t, float64(0), work["happiness"].(float64), "A rating with no comment is not feedback")
}

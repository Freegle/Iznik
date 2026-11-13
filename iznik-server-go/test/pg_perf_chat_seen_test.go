package test

import (
	"sync"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// rosterSeen returns one member's read position in one chat, and whether it is set at all.
func rosterSeen(chatID uint64, userID uint64) (uint64, bool) {
	var seen *uint64
	database.DBConn.Raw("SELECT lastmsgseen FROM chat_roster WHERE chatid = ? AND userid = ?", chatID, userID).Scan(&seen)
	if seen == nil {
		return 0, false
	}
	return *seen, true
}

// rosterRowVersion returns where PostgreSQL holds the current version of a roster row. Any
// update that writes the row, even one that changes no value, moves it. MySQL has no
// equivalent, and does not need one: it already writes nothing for an unchanged row. So this
// returns an empty string there.
func rosterRowVersion(chatID uint64, userID uint64) string {
	if database.Engine() != database.EnginePostgres {
		return ""
	}
	var v string
	database.DBConn.Raw("SELECT CAST(ctid AS text) FROM chat_roster WHERE chatid = ? AND userid = ?", chatID, userID).Scan(&v)
	return v
}

// TestAllSeenLeavesReadChatsAlone covers "mark all read" for a member with one chat already
// read, one unread and one never opened. All three must end up read, on both engines. On
// PostgreSQL the chat that was already read must not be written again: without the extra
// predicate every press rewrote every roster row the member has.
func TestAllSeenLeavesReadChatsAlone(t *testing.T) {
	prefix := uniquePrefix("allseen_skip")
	db := database.DBConn

	me := CreateTestUser(t, prefix+"_me", utils.SYSTEMROLE_USER)
	u2 := CreateTestUser(t, prefix+"_u2", utils.SYSTEMROLE_USER)
	u3 := CreateTestUser(t, prefix+"_u3", utils.SYSTEMROLE_USER)
	u4 := CreateTestUser(t, prefix+"_u4", utils.SYSTEMROLE_USER)
	_, token := CreateTestSession(t, me)

	readChat := CreateTestChatRoom(t, me, &u2, nil, "User2User")
	readMsg := CreateTestChatMessage(t, readChat, u2, "already read")
	unreadChat := CreateTestChatRoom(t, me, &u3, nil, "User2User")
	CreateTestChatMessage(t, unreadChat, u3, "first")
	unreadMsg := CreateTestChatMessage(t, unreadChat, u3, "second")
	nullChat := CreateTestChatRoom(t, me, &u4, nil, "User2User")
	nullMsg := CreateTestChatMessage(t, nullChat, u4, "never opened")

	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', ?, NOW())", readChat, me, readMsg)
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', 0, NOW())", unreadChat, me)
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', NULL, NOW())", nullChat, me)

	readVersion := rosterRowVersion(readChat, me)
	unreadVersion := rosterRowVersion(unreadChat, me)

	result := postChatRoom(t, token, map[string]interface{}{"action": "AllSeen"})
	assert.Equal(t, float64(0), result["ret"])

	seen, ok := rosterSeen(readChat, me)
	assert.True(t, ok)
	assert.Equal(t, readMsg, seen, "a chat that was already read stays read")
	seen, ok = rosterSeen(unreadChat, me)
	assert.True(t, ok)
	assert.Equal(t, unreadMsg, seen, "an unread chat is marked read")
	seen, ok = rosterSeen(nullChat, me)
	assert.True(t, ok, "a chat with no read position gets one")
	assert.Equal(t, nullMsg, seen)

	if database.Engine() == database.EnginePostgres {
		assert.Equal(t, readVersion, rosterRowVersion(readChat, me),
			"PostgreSQL must not rewrite a roster row that is already at the newest message")
		assert.NotEqual(t, unreadVersion, rosterRowVersion(unreadChat, me),
			"the unread row was written, so the check above can tell a write from no write")
	}
}

// TestAllSeenModToolsLeavesReadChatsAlone is the same check for the ModTools button, which
// builds its update from a list of chat ids instead of a subquery.
func TestAllSeenModToolsLeavesReadChatsAlone(t *testing.T) {
	prefix := uniquePrefix("allseen_mt_skip")
	db := database.DBConn

	groupID := CreateTestGroup(t, prefix+"_group")
	mod := CreateTestUser(t, prefix+"_mod", utils.SYSTEMROLE_MODERATOR)
	CreateTestMembership(t, mod, groupID, utils.ROLE_MODERATOR)
	m1 := CreateTestUser(t, prefix+"_m1", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, m1, groupID, utils.ROLE_MEMBER)
	m2 := CreateTestUser(t, prefix+"_m2", utils.SYSTEMROLE_USER)
	CreateTestMembership(t, m2, groupID, utils.ROLE_MEMBER)
	_, token := CreateTestSession(t, mod)

	readChat := CreateTestChatRoom(t, m1, nil, &groupID, "User2Mod")
	readMsg := CreateTestChatMessage(t, readChat, m1, "already read by the mod")
	unreadChat := CreateTestChatRoom(t, m2, nil, &groupID, "User2Mod")
	unreadMsg := CreateTestChatMessage(t, unreadChat, m2, "not read yet")

	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', ?, NOW())", readChat, mod, readMsg)
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', 0, NOW())", unreadChat, mod)

	readVersion := rosterRowVersion(readChat, mod)
	unreadVersion := rosterRowVersion(unreadChat, mod)

	result := postChatRoom(t, token, map[string]interface{}{"action": "AllSeen", "modtools": true})
	assert.Equal(t, float64(0), result["ret"])

	seen, _ := rosterSeen(readChat, mod)
	assert.Equal(t, readMsg, seen, "a chat that was already read stays read")
	seen, _ = rosterSeen(unreadChat, mod)
	assert.Equal(t, unreadMsg, seen, "an unread chat is marked read")

	if database.Engine() == database.EnginePostgres {
		assert.Equal(t, readVersion, rosterRowVersion(readChat, mod),
			"PostgreSQL must not rewrite a roster row that is already at the newest message")
		assert.NotEqual(t, unreadVersion, rosterRowVersion(unreadChat, mod),
			"the unread row was written, so the check above can tell a write from no write")
	}
}

// TestRosterSeenAllowBack covers the single-chat read position when the client allows it to
// move backwards. PostgreSQL skips the write when the position is already the one sent, so
// this checks the three cases that predicate has to get right: the same value, a lower value
// and a row with no position yet.
func TestRosterSeenAllowBack(t *testing.T) {
	prefix := uniquePrefix("seen_back")
	db := database.DBConn

	me := CreateTestUser(t, prefix+"_me", utils.SYSTEMROLE_USER)
	other := CreateTestUser(t, prefix+"_other", utils.SYSTEMROLE_USER)
	chatID := CreateTestChatRoom(t, me, &other, nil, "User2User")
	first := CreateTestChatMessage(t, chatID, other, "first")
	second := CreateTestChatMessage(t, chatID, other, "second")
	_, token := CreateTestSession(t, me)

	// No position yet: it is set.
	db.Exec("INSERT INTO chat_roster (chatid, userid, status, lastmsgseen, date) VALUES (?, ?, 'Online', NULL, NOW())", chatID, me)
	postChatRoom(t, token, map[string]interface{}{"id": chatID, "lastmsgseen": second, "allowback": true})
	seen, ok := rosterSeen(chatID, me)
	assert.True(t, ok, "a row with no read position gets one")
	assert.Equal(t, second, seen)

	// The same position again: unchanged. The roster row itself is still rewritten here, because the
	// request first records the member's address and the time, so this step cannot assert that no row
	// version was written; the two AllSeen tests do that for the bulk path.
	postChatRoom(t, token, map[string]interface{}{"id": chatID, "lastmsgseen": second, "allowback": true})
	seen, _ = rosterSeen(chatID, me)
	assert.Equal(t, second, seen)

	// A lower position: it moves back, which is what allowback is for.
	postChatRoom(t, token, map[string]interface{}{"id": chatID, "lastmsgseen": first, "allowback": true})
	seen, _ = rosterSeen(chatID, me)
	assert.Equal(t, first, seen, "allowback moves the read position backwards")
}

// TestGetOrCreateUser2ModChatConcurrentCreators starts several creators for a member and
// community that have no chat yet. PostgreSQL's SELECT ... FOR UPDATE locks nothing when no
// row exists, so without the advisory lock each creator inserted its own room. MySQL stops
// that a different way (a gap lock, which fails all but one creator with a deadlock), so the
// "everyone succeeds with the same room" outcome is only promised on PostgreSQL.
func TestGetOrCreateUser2ModChatConcurrentCreators(t *testing.T) {
	if database.Engine() != database.EnginePostgres {
		t.Skip("PostgreSQL only: MySQL resolves this race with a gap lock and a deadlock error")
	}

	prefix := uniquePrefix("u2m_race")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", utils.SYSTEMROLE_USER)
	groupID := CreateTestGroup(t, prefix+"_group")
	CreateTestMembership(t, userID, groupID, utils.ROLE_MEMBER)

	const creators = 8
	ids := make([]uint64, creators)
	errs := make([]error, creators)
	start := make(chan struct{})
	var wg sync.WaitGroup
	for i := 0; i < creators; i++ {
		wg.Add(1)
		go func(i int) {
			defer wg.Done()
			<-start
			ids[i], errs[i] = chat.GetOrCreateUser2ModChat(db, userID, groupID)
		}(i)
	}
	close(start)
	wg.Wait()

	for i := 0; i < creators; i++ {
		assert.NoError(t, errs[i])
		assert.Equal(t, ids[0], ids[i], "every creator must get the same room")
	}

	var count int64
	db.Raw("SELECT COUNT(*) FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = 'User2Mod'",
		userID, groupID).Scan(&count)
	assert.Equal(t, int64(1), count, "concurrent creators must leave exactly one User2Mod chat")

	db.Exec("DELETE FROM chat_roster WHERE chatid IN (SELECT id FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = 'User2Mod')", userID, groupID)
	db.Exec("DELETE FROM chat_rooms WHERE user1 = ? AND groupid = ? AND chattype = 'User2Mod'", userID, groupID)
}

package test

import (
	"fmt"
	"testing"

	"github.com/freegle/iznik-server-go/chat"
	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
)

// TestChatRoomListJoinsYieldOneRowPerRoom asserts the property that lets the chat room list
// query run without DISTINCT: none of its joins can fan out.
//
// The DISTINCT it used to carry removed nothing - every join is on a primary key, on a scalar
// `id = (SELECT ... LIMIT 1)`, or on a derived table cut to rn = 1 per chatid - but it cost a
// temporary table and a sort of ~20 wide columns on 926,265 calls a day, 0.39 cores of db3.
//
// This has to be asserted on the query's own row count rather than through the API, because the
// API cannot see it: ListChatRooms merges these rows into the outer list by matching ids
// (chatroom.go), so a duplicated row would be applied to the same entry twice and never show up
// as a duplicate chat. Removing DISTINCT is therefore invisible to any endpoint test - the risk
// it carries is a silently multiplied result set, and that is what this counts.
//
// Every table that could plausibly fan out is given extra rows first: both members have several
// profile images, the room's group has several images, and the room has several messages.
func TestChatRoomListJoinsYieldOneRowPerRoom(t *testing.T) {
	db := database.DBConn
	prefix := uniquePrefix("roomjoins")

	groupID := CreateTestGroup(t, prefix)
	posterID := CreateTestUser(t, prefix+"_poster", "User")
	replierID := CreateTestUser(t, prefix+"_replier", "User")
	CreateTestMembership(t, posterID, groupID, "Member")
	CreateTestMembership(t, replierID, groupID, "Member")

	postMsgID := CreateTestMessage(t, posterID, groupID, "OFFER: join fan-out probe", 51.5, -0.1)

	// Three rooms, so the count below is checked against more than one. The Mod2Mod room
	// carries a groupid, which is the only way the groups_images (i3) join is reachable - a
	// User2User room has none, so without it that join is never exercised.
	chatA := CreateTestChatRoom(t, replierID, &posterID, nil, "User2User")
	chatB := CreateTestChatRoom(t, posterID, &replierID, nil, "User2User")
	chatC := CreateTestChatRoom(t, posterID, nil, &groupID, "Mod2Mod")

	// Several profile images each: the i1/i2 joins must still pick exactly the newest.
	for i := 0; i < 3; i++ {
		db.Exec("INSERT INTO users_images (userid, url, `default`, contenttype) VALUES (?, ?, 0, 'image/jpeg')",
			posterID, fmt.Sprintf("https://example.test/%s_p%d.jpg", prefix, i))
		db.Exec("INSERT INTO users_images (userid, url, `default`, contenttype) VALUES (?, ?, 0, 'image/jpeg')",
			replierID, fmt.Sprintf("https://example.test/%s_r%d.jpg", prefix, i))
	}
	defer db.Exec("DELETE FROM users_images WHERE userid IN (?, ?)", posterID, replierID)

	// Several group images: the i3 join must still pick exactly the newest.
	for i := 0; i < 3; i++ {
		db.Exec("INSERT INTO groups_images (groupid, contenttype) VALUES (?, 'image/jpeg')", groupID)
	}
	defer db.Exec("DELETE FROM groups_images WHERE groupid = ?", groupID)

	// Several deliverable messages per room: the latest-message join and the rcm CTE must each
	// collapse them to one.
	for _, room := range []uint64{chatA, chatB, chatC} {
		for i := 0; i < 4; i++ {
			sender := replierID
			if i%2 == 1 {
				sender = posterID
			}
			db.Exec(
				"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
					"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
					"VALUES (?, ?, ?, 'Default', NOW(), ?, 0, 0, 0, 1)",
				room, sender, fmt.Sprintf("message %d", i), postMsgID,
			)
		}
		defer db.Exec("DELETE FROM chat_messages WHERE chatid = ?", room)
	}

	idlist := fmt.Sprintf("(%d,%d,%d) ", chatA, chatB, chatC)

	// ChatRoomListFrom binds the viewer's id three times - see its doc comment.
	var rows int64
	db.Raw("SELECT COUNT(*) "+chat.ChatRoomListFrom(idlist), posterID, posterID, posterID).Scan(&rows)

	assert.Equal(t, int64(3), rows,
		"the chat room list joins must yield exactly one row per room; more means a join fans "+
			"out and the select list can no longer run without DISTINCT")
}

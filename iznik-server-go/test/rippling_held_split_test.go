package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
)

// The sysadmin "% of replies held (waiting for reach)" figure lumps together two situations
// that cost the offerer very different things. Holding the ONLY reply a post has leaves them
// looking at silence. Holding a LATER one merely defers a choice they can already make.
//
// Since first replies started going through, the held count should be almost entirely
// "additional"; a stubborn "first" count means first replies are still being held up. The
// dashboard can only show that if the endpoint splits them.
//
// A small square around (51.5, -0.1) in EPSG:3857, used only for the outer_bound
// envelope - the analytics under test read scalar columns, never a grid.
const heldSplitTick = "POLYGON((-11150 6712000,-11050 6712000,-11050 6712100,-11150 6712100,-11150 6712000))"

// replyInRoom inserts an 'Interested' reply on msgID from userID, offset minutes before now
// (0 = now), and returns the chat message id. Every step is checked: a silently failed fixture
// shows up as an unexplained zero in the KPI, which says nothing about what actually broke.
func replyInRoom(t *testing.T, chatID, msgID, userID uint64, minutesAgo int) uint64 {
	t.Helper()
	db := database.DBConn

	res := db.Exec(
		"INSERT INTO chat_messages (chatid, userid, message, type, date, refmsgid, "+
			"reviewrequired, reviewrejected, processingrequired, processingsuccessful) "+
			"VALUES (?, ?, 'Can I collect this please?', 'Interested', NOW() - INTERVAL ? MINUTE, ?, 0, 0, 0, 1)",
		chatID, userID, minutesAgo, msgID,
	)
	if res.Error != nil {
		t.Fatalf("could not create reply chat message: %v", res.Error)
	}
	var chatMsgID uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? ORDER BY id DESC LIMIT 1", chatID).Scan(&chatMsgID)
	if chatMsgID == 0 {
		t.Fatal("reply chat message created but id not found")
	}
	return chatMsgID
}

// heldMinutesAgo backdates the held reply. The analytics window ends at time.Now() formatted
// to WHOLE SECONDS and the bound is exclusive, so a fixture stamped in the same second as the
// request falls outside it and the KPI reads zero — indistinguishable from "nothing held".
// Five minutes is also closer to life: a hold is never simultaneous with someone opening the
// dashboard.
const heldMinutesAgo = 5

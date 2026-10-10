package assistant

import (
	"encoding/json"
	"time"

	"github.com/freegle/iznik-server-go/firstreply"
	"github.com/freegle/iznik-server-go/message"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
)

// Every turn is written into the member's real Freegle chat room, so the conversation
// is visible in ModTools like any other chat. The room is the one User2User room between
// the Freegle system user and the member, created on first use exactly as the
// first-reply code creates it. Widgets go to chat_widgets keyed by message id.

// TranscriptTurn is one line of the transcript.
type TranscriptTurn struct {
	Who    string                 `json:"who"` // member | freegle
	Text   string                 `json:"text"`
	Widget map[string]interface{} `json:"widget,omitempty"`
}

// Transcript writes turns for a member.
type Transcript struct {
	DB *gorm.DB
}

// Room finds or creates the Freegle room for a member. 0 when the system user does not exist.
func (t *Transcript) Room(userid uint64) uint64 {
	freegleID := firstreply.SystemUserID(t.DB)
	if freegleID == 0 || userid == 0 || freegleID == userid {
		return 0
	}
	var id uint64
	t.DB.Table("chat_rooms").Select("id").
		Where("chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))", utils.CHAT_TYPE_USER2USER, freegleID, userid, userid, freegleID).
		Limit(1).Scan(&id)
	if id > 0 {
		return id
	}
	row := map[string]interface{}{"user1": freegleID, "user2": userid, "chattype": utils.CHAT_TYPE_USER2USER, "latestmessage": gorm.Expr("NOW()")}
	if err := t.DB.Table("chat_rooms").Create(row).Error; err != nil {
		return 0
	}
	if v, ok := row["@id"].(int64); ok {
		return uint64(v)
	}
	return 0
}

// Write appends turns to the member's Freegle room. Returns the chat id and message ids.
func (t *Transcript) Write(userid uint64, turns []TranscriptTurn) (uint64, []uint64) {
	roomID := t.Room(userid)
	if roomID == 0 {
		return 0, nil
	}
	freegleID := firstreply.SystemUserID(t.DB)
	var ids []uint64
	for _, turn := range turns {
		author := userid
		if turn.Who == "freegle" {
			author = freegleID
		}
		// A member's own words get the same worry-word check as any chat message, so
		// something concerning said to Freegle reaches the volunteers as it would
		// said to a person.
		review := "0"
		if turn.Who != "freegle" && len(message.WorryMatchesForText(t.DB, turn.Text)) > 0 {
			review = "1"
		}
		row := map[string]interface{}{
			"chatid":               roomID,
			"userid":               author,
			"type":                 utils.CHAT_MESSAGE_DEFAULT,
			"date":                 time.Now(),
			"message":              turn.Text,
			"reviewrequired":       gorm.Expr(review),
			"reviewrejected":       gorm.Expr("0"),
			"processingrequired":   gorm.Expr("0"),
			"processingsuccessful": gorm.Expr("1"),
			// Freegle's own lines are seen by definition; the member's lines are their own.
			"seenbyall":   gorm.Expr("1"),
			"mailedtoall": gorm.Expr("1"),
		}
		if err := t.DB.Table("chat_messages").Create(row).Error; err != nil {
			continue
		}
		id, _ := row["@id"].(int64)
		if id == 0 {
			continue
		}
		ids = append(ids, uint64(id))
		if turn.Widget != nil {
			payload, _ := json.Marshal(turn.Widget)
			t.DB.Table("chat_widgets").Create(map[string]interface{}{"chatmsgid": id, "kind": "assistant", "payload": string(payload)})
		}
	}
	t.DB.Table("chat_rooms").Where("id = ?", roomID).Update("latestmessage", gorm.Expr("NOW()"))
	return roomID, ids
}

// WidgetsFor returns the widgets for a set of chat message ids.
func (t *Transcript) WidgetsFor(chatmsgids []uint64) map[uint64]map[string]interface{} {
	out := map[uint64]map[string]interface{}{}
	if len(chatmsgids) == 0 {
		return out
	}
	var rows []struct {
		Chatmsgid uint64 `gorm:"column:chatmsgid"`
		Payload   string `gorm:"column:payload"`
	}
	t.DB.Table("chat_widgets").Select("chatmsgid, payload").Where("chatmsgid IN ?", chatmsgids).Find(&rows)
	for _, r := range rows {
		var m map[string]interface{}
		if json.Unmarshal([]byte(r.Payload), &m) == nil {
			out[r.Chatmsgid] = m
		}
	}
	return out
}

package test

// Tier 9 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): "literal-int
// WHERE-helper" sites, where a Go helper spliced ids directly into SQL text
// as decimal literals (via strings.Join/fmt.Sprintf/joinIDs) rather than
// binding them - collapseInLists (ormharness/golden.go) only folds runs of
// bind PLACEHOLDERS, so it could not normalise a literal-int list against a
// bind-based golden. The fix, per keep-raw site 032b7f1b9500's own
// already-converted precedent (isochrone/message.go markPinned): pass the
// ids as a []uint64 bind instead. GORM's native "IN ?" slice-bind expands
// to N placeholders at execution time, not in the source, so the source
// text becomes a single fixed "IN (?)" the extractor (and collapseInLists)
// can already handle - the same "clean IN-list, list is the only variable
// part" shape already proven throughout this codebase.
//
// The ids in all 4 sites below are server-derived (a moderator's own chat
// ids, a caller's own fetched message ids) rather than user input, so this
// is a low-risk rewrite - strictly safer too, since a bind can never need
// escaping.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// chat/chatroom.go: handleAllSeen, ModTools branch - the sibling UPDATE of
// tier4's 1d98111ea90d (same idlist, this is the other of the two
// statements built from it in that block).
func TestTier9_3519e352775b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3519e352775b", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_roster").
			Where("userid = ? AND chatid IN ?", uint64(1), []uint64{10, 20, 30}).
			Update("lastmsgseen", gorm.Expr("(SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE chatid = chat_roster.chatid)"))
	})
}

// chat/chatroom.go: countUnseenMT.
func TestTier9_fdeb2007824a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fdeb2007824a", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("chat_messages").
			Joins("INNER JOIN users ON users.id = chat_messages.userid").
			Joins("LEFT JOIN chat_roster ON chat_roster.chatid = chat_messages.chatid AND chat_roster.userid = ?", uint64(1)).
			Where("chat_messages.chatid IN ? "+
				"AND chat_messages.userid != ? "+
				"AND chat_messages.reviewrequired = 0 "+
				"AND chat_messages.reviewrejected = 0 "+
				"AND chat_messages.processingsuccessful = 1 "+
				"AND chat_messages.id > COALESCE(chat_roster.lastmsgseen, 0) "+
				"AND chat_messages.date >= ? "+
				"AND users.deleted IS NULL",
				[]uint64{10, 20, 30}, uint64(1), "2026-01-01").
			Count(new(int64))
	})
}

// chat/chatroom.go: getModeratorChatIDs, search-filter branch.
func TestTier9_d44b753b35c8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d44b753b35c8", func(tx *gorm.DB) *gorm.DB {
		var dest []uint64
		return tx.Table("chat_rooms").
			Select("DISTINCT chat_rooms.id").
			Joins("LEFT JOIN chat_messages ON chat_messages.chatid = chat_rooms.id").
			Joins("LEFT JOIN users u1 ON u1.id = chat_rooms.user1").
			Joins("LEFT JOIN users u2 ON u2.id = chat_rooms.user2").
			Joins("LEFT JOIN users_emails ue1 ON ue1.userid = chat_rooms.user1").
			Joins("LEFT JOIN users_emails ue2 ON ue2.userid = chat_rooms.user2").
			Where("chat_rooms.id IN ? "+
				"AND (chat_messages.message LIKE ? OR u1.fullname LIKE ? OR u2.fullname LIKE ? "+
				"OR u1.firstname LIKE ? OR u2.firstname LIKE ? "+
				"OR ue1.email LIKE ? OR ue2.email LIKE ?)",
				[]uint64{10, 20, 30}, "%jo%", "%jo%", "%jo%", "%jo%", "%jo%", "%jo%", "%jo%").
			Find(&dest)
	})
}

// chat/chatmessage.go: FetchChatMessages, rippling-held batch lookup.
func TestTier9_7470288c95b7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7470288c95b7", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("rippling_held_replies").
			Select("chatmsgid").
			Where("chatmsgid IN ? AND status <> 'released'", []uint64{1, 2, 3}).
			Find(&dest)
	})
}

// chat/chatroom.go: listChats, other-participants supporter-status lookup.
// The one site inside listChats that turned out to be the literal-int
// category rather than genuinely entangled with the function's WITH-CTE /
// variable-arm UNION complexity (see keep-raw.json's b184ef8e3ad2 and
// 537a2764efde, left raw - this is a separate, self-contained raw SQL
// statement in the same function, not part of either).
func TestTier9_366c64b4d972(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "366c64b4d972", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("DISTINCT users.id, (CASE WHEN "+
				"((users.systemrole != ? OR "+
				"EXISTS(SELECT id FROM users_donations WHERE userid = users.id AND users_donations.timestamp >= ?) OR "+
				"EXISTS(SELECT id FROM microactions WHERE userid = users.id AND microactions.timestamp >= ?)) AND "+
				"(CASE WHEN JSON_EXTRACT(users.settings, '$.hidesupporter') IS NULL THEN 0 ELSE JSON_EXTRACT(users.settings, '$.hidesupporter') END) = 0) "+
				"THEN 1 ELSE 0 END) "+
				"AS supporter",
				"User", "2026-01-01", "2026-01-01").
			Where("users.id IN ?", []uint64{1, 2, 3}).
			Find(&dest)
	})
}

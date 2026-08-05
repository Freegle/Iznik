package test

// Wave 4, batch A (plan section 7.3+): multi-table SELECTs (joins) in
// session/session.go, message/message.go, authority/stats.go,
// message/message_list.go, story/story.go, communityevent/communityEvent.go,
// location/location.go, chat/chatmessage.go, sso/discourse.go,
// systemlogs/systemlogs.go, user/authorization.go, address/address.go,
// message/similar.go, auth/auth.go, visualise/visualise.go,
// donations/donations.go, membership/membership.go, modtools/modconfig.go,
// aiimage/aiimage.go, embedding/store.go, item/item.go, config/config.go and
// emailtracking/compact.go.
//
// Join conventions, pinned by ormharness/join_test.go before this batch was
// written (read that file first if this looks unfamiliar):
//   - .Table("name") for an unaliased table, .Table("name alias") for an
//     aliased one - the alias survives unquoted, exactly as the raw SQL wrote
//     it (TestJoin_TableAliasSurvives).
//   - .Joins("INNER/LEFT JOIN x ON ...") verbatim from the original SQL, one
//     call per join, in the SAME order the original text listed them - a
//     later join's ON clause routinely names an earlier join's alias, so
//     reordering would not resolve (TestJoin_MultipleJoinsKeepCallOrder).
//   - Where(...) can be written wherever reads best in the Go chain; GORM
//     assembles by clause, not call order, so it always renders after every
//     Joins() regardless (TestJoin_WhereLandsAfterJoinsRegardlessOfCallOrder).
//   - A bind inside a Joins(...) ON clause is passed as a trailing arg to
//     that Joins call, and renders before the WHERE's own binds, in the same
//     relative position the original text had (TestJoin_BindArgsInJoinCondition).
//   - SELECT DISTINCT goes in Select("DISTINCT col, ..."), not GORM's
//     Distinct() - keeps the text closest to the original and renders exactly
//     once (TestJoin_DistinctRendersInSelect).
//   - GROUP BY / HAVING use Group(...) / Having(...); both land after WHERE
//     and in that order (TestJoin_GroupByAndHaving).
//   - `groups` is a reserved word: every reference is written with literal
//     backticks, exactly as the original SQL had them - as a bare
//     .Table("`groups`"), a .Table("`groups` g") alias, or inside a Joins(...)
//     ON clause. GORM does not re-quote a string already containing backticks,
//     so the original text survives untouched (matches chat/chatroom.go's
//     existing .Table("`groups` g") from an earlier wave).
//
// Terminal choice matters and is NOT interchangeable with what production
// uses. Two different production shapes appear in this batch:
//   - A COUNT(*) built through GORM's own .Count(&n) - the test also calls
//     .Count(&n), since Count() itself decides what "count(*)" text to emit.
//   - A COUNT(*) or COUNT(DISTINCT col) written as a literal .Select(...)
//     expression, executed with .Scan(&n) in production. Scan is not usable
//     under dry-run (GORM rejects it - "dry run mode unsupported"), so the
//     test terminal is .Find(&dest) instead: it renders the same SELECT
//     clause GORM would send, without needing a live connection. Using
//     .Count() here would be wrong - it overrides whatever Select(...) was
//     set with GORM's own "count(*)", silently dropping the DISTINCT.
//   - A plain row read uses .Find(&dest) (into []map[string]interface{}),
//     matching the read shape pinned in ormharness/join_test.go.
//   - Pluck(...) is a safe dry-run terminal for the same reason Find is: it
//     calls the query callback directly rather than going through Rows(),
//     which is what makes Scan (and Row/Rows themselves) unusable under
//     DryRun. Used wherever production plucks a single column into a slice.
//
// Nothing here is taken on trust: each converted render is compared against
// the recorded golden.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- address/address.go: ListForUser -----------------------------------------

func TestWave4A_b9b602714ff9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b9b602714ff9", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_addresses").
			Select("users_addresses.id, users_addresses.userid, instructions,"+
				"COALESCE(users_addresses.lat, locations.lat) AS lat, "+
				"COALESCE(users_addresses.lng, locations.lng) AS lng, "+
				"locations.name AS postcode, "+
				"posttown,dependentlocality,doubledependentlocality,thoroughfaredescriptor,dependentthoroughfaredescriptor,buildingname,subbuildingname,pobox,departmentname,organisationname").
			Joins("INNER JOIN paf_addresses ON paf_addresses.id = users_addresses.pafid").
			Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
			Joins("LEFT JOIN paf_posttown ON paf_posttown.id = paf_addresses.posttownid").
			Joins("LEFT JOIN paf_dependentlocality ON paf_dependentlocality.id = paf_addresses.dependentlocalityid").
			Joins("LEFT JOIN paf_doubledependentlocality ON paf_doubledependentlocality.id = paf_addresses.doubledependentlocalityid").
			Joins("LEFT JOIN paf_thoroughfaredescriptor ON paf_thoroughfaredescriptor.id = paf_addresses.thoroughfaredescriptorid").
			Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON paf_dependentthoroughfaredescriptor.id = paf_addresses.dependentthoroughfaredescriptorid").
			Joins("LEFT JOIN paf_buildingname ON paf_buildingname.id = paf_addresses.buildingnameid").
			Joins("LEFT JOIN paf_subbuildingname ON paf_subbuildingname.id = paf_addresses.subbuildingnameid").
			Joins("LEFT JOIN paf_pobox ON paf_pobox.id = paf_addresses.poboxid").
			Joins("LEFT JOIN paf_departmentname ON paf_departmentname.id = paf_addresses.departmentnameid").
			Joins("LEFT JOIN paf_organisationname ON paf_organisationname.id = paf_addresses.organisationnameid").
			Where("users_addresses.userid = ?", 1).
			Find(&dest)
	})
}

// --- address/address.go: GetAddress -------------------------------------------

func TestWave4A_608507c3053f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "608507c3053f", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_addresses").
			Select("users_addresses.id, users_addresses.userid, instructions,"+
				"COALESCE(users_addresses.lat, locations.lat) AS lat, "+
				"COALESCE(users_addresses.lng, locations.lng) AS lng, "+
				"locations.name AS postcode, "+
				"posttown,dependentlocality,doubledependentlocality,thoroughfaredescriptor,dependentthoroughfaredescriptor,buildingname,subbuildingname,pobox,departmentname,organisationname").
			Joins("LEFT JOIN chat_rooms ON chat_rooms.user1 = ? OR chat_rooms.user2 = ?", 1, 1).
			Joins("LEFT JOIN chat_messages ON chat_messages.chatid = chat_rooms.id").
			Joins("LEFT JOIN users ON users.id = ?", 1).
			Joins("INNER JOIN paf_addresses ON paf_addresses.id = users_addresses.pafid").
			Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
			Joins("LEFT JOIN paf_posttown ON paf_posttown.id = paf_addresses.posttownid").
			Joins("LEFT JOIN paf_dependentlocality ON paf_dependentlocality.id = paf_addresses.dependentlocalityid").
			Joins("LEFT JOIN paf_doubledependentlocality ON paf_doubledependentlocality.id = paf_addresses.doubledependentlocalityid").
			Joins("LEFT JOIN paf_thoroughfaredescriptor ON paf_thoroughfaredescriptor.id = paf_addresses.thoroughfaredescriptorid").
			Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON paf_dependentthoroughfaredescriptor.id = paf_addresses.dependentthoroughfaredescriptorid").
			Joins("LEFT JOIN paf_buildingname ON paf_buildingname.id = paf_addresses.buildingnameid").
			Joins("LEFT JOIN paf_subbuildingname ON paf_subbuildingname.id = paf_addresses.subbuildingnameid").
			Joins("LEFT JOIN paf_pobox ON paf_pobox.id = paf_addresses.poboxid").
			Joins("LEFT JOIN paf_departmentname ON paf_departmentname.id = paf_addresses.departmentnameid").
			Joins("LEFT JOIN paf_organisationname ON paf_organisationname.id = paf_addresses.organisationnameid").
			Where("users_addresses.id = ? AND (users_addresses.userid = ? OR (chat_messages.type = ? AND chat_messages.message = ?) OR users.systemrole IN (?, ?, ?))",
				1, 1, "Address", 1, "Moderator", "Admin", "Support").
			Limit(1).
			Find(&dest)
	})
}

// --- aiimage/aiimage.go: ListReview --------------------------------------------

func TestWave4A_7940b0e0d9ff(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7940b0e0d9ff", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("microactions ma").
			Select("ma.aiimageid, ma.userid, "+
				"CASE WHEN u.fullname IS NOT NULL THEN u.fullname ELSE CONCAT(u.firstname, ' ', u.lastname) END AS displayname, "+
				"ma.result, ma.containspeople, ma.timestamp").
			Joins("INNER JOIN users u ON u.id = ma.userid").
			Where("ma.aiimageid IN (?) AND ma.actiontype = 'AIImageReview'", []uint64{1, 2}).
			Order("ma.timestamp ASC").
			Find(&dest)
	})
}

// --- auth/auth.go: IsChitChatMod -----------------------------------------------

func TestWave4A_204fbc700672(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "204fbc700672", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("teams_members tm").
			Joins("INNER JOIN teams t ON tm.teamid = t.id").
			Where("t.name = 'ChitChat Moderation' AND tm.userid = ?", 1).
			Count(&count)
	})
}

// --- authority/stats.go: GetStatsByAuthority (offers/wanteds) -----------------

func TestWave4A_f3d569f16f75(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f3d569f16f75", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ?",
				"Postcode", "Offer", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- authority/stats.go: GetStatsByAuthority (bulk interest replies) ----------

func TestWave4A_5a510c1a72cd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "5a510c1a72cd", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) as count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Joins("INNER JOIN messages_bulk_items_interest mbi ON mbi.msgid = messages.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.type = ? AND messages.arrival BETWEEN ? AND ?",
				"Postcode", "Offer", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- authority/stats.go: GetStatsByAuthority (bulk outcomes, available=0) -----

func TestWave4A_02b7865c1d55(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "02b7865c1d55", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, CAST(SUM(bi.quantity) AS SIGNED) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items bi ON bi.msgid = messages.id AND bi.available = 0").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				"Postcode", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- authority/stats.go: GetStatsByAuthority (bulk outcomes, Collected) -------

func TestWave4A_e37b20384491(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e37b20384491", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, CAST(SUM(mbii.quantity) AS SIGNED) AS count").
			Joins("INNER JOIN messages ON messages.locationid = pc.locationid").
			Joins("INNER JOIN messages_bulk_items_interest mbii ON mbii.msgid = messages.id AND mbii.state = 'Collected'").
			Joins("INNER JOIN locations ON messages.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND messages.arrival BETWEEN ? AND ?",
				"Postcode", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- authority/stats.go: GetStatsByAuthority (searches) ------------------------

func TestWave4A_077cd04df2db(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "077cd04df2db", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("pc").
			Select("SUBSTRING(locations.name, 1, LENGTH(locations.name) - 2) AS PartialPostcode, COUNT(*) AS count").
			Joins("INNER JOIN search_history ON search_history.locationid = pc.locationid").
			Joins("INNER JOIN locations ON search_history.locationid = locations.id").
			Where("locations.type = ? AND LOCATE(' ', locations.name) > 0 AND search_history.date BETWEEN ? AND ?",
				"Postcode", "2026-01-01", "2026-01-31").
			Group("PartialPostcode").
			Order("locations.name").
			Find(&dest)
	})
}

// --- chat/chatmessage.go: CreateChatMessageLoveJunk ----------------------------

func TestWave4A_1f5fe9f4e306(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "1f5fe9f4e306", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages").
			Select("fromuser, groupid").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("INNER JOIN users ON users.id = messages.fromuser").
			Where("messages.id = ? AND users.deleted IS NULL", 1).
			Find(&dest)
	})
}

// --- chat/chatmessage.go: canSeeChatRoom (fallback mod check) -----------------

func TestWave4A_c49605990641(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c49605990641", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m1").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.userid IN (?, ?)",
				1, "Moderator", "Owner", 2, 3).
			Count(&count)
	})
}

// --- chat/chatmessage.go: fetchReviewMessage -----------------------------------

func TestWave4A_f3911b4339db(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f3911b4339db", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("chat_messages").
			Select("chat_messages.id, chat_messages.chatid, chat_messages.userid, chat_messages.message, "+
				"COALESCE(chat_messages_held.userid, 0) AS heldbyuser").
			Joins("LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id").
			Joins("INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid").
			Where("chat_messages.id = ? AND chat_messages.reviewrequired = 1", 1).
			Find(&dest)
	})
}

// --- communityevent/communityEvent.go: List (pending, mod groups) -------------

func TestWave4A_3ce758530901(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3ce758530901", func(tx *gorm.DB) *gorm.DB {
		var ids []uint64
		return tx.Table("communityevents").
			Select("DISTINCT communityevents.id").
			Joins("INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
			Joins("INNER JOIN communityevents_dates ON communityevents_dates.eventid = communityevents.id").
			Where("groupid IN (?) AND communityevents.deleted = 0 AND pending = 1 AND communityevents_dates.end >= ?", []uint64{1, 2}, "2026-01-01").
			Order("communityevents_dates.end ASC").
			Pluck("id", &ids)
	})
}

// --- communityevent/communityEvent.go: List (non-pending, member groups) ------

func TestWave4A_29edc144f5c9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29edc144f5c9", func(tx *gorm.DB) *gorm.DB {
		var ids []uint64
		return tx.Table("communityevents").
			Select("DISTINCT communityevents.id").
			Joins("INNER JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
			Joins("LEFT JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid").
			Joins("LEFT JOIN users ON communityevents.userid = users.id").
			Where("groupid IN (?) AND end IS NOT NULL AND end >= ? AND communityevents.deleted = 0 AND (pending = 0 OR communityevents.userid = ?) AND users.deleted IS NULL",
				[]uint64{1, 2}, "2026-01-01", 1).
			Order("end ASC").
			Pluck("id", &ids)
	})
}

// --- communityevent/communityEvent.go: ListGroup --------------------------------

func TestWave4A_a0cd1607e066(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a0cd1607e066", func(tx *gorm.DB) *gorm.DB {
		var ids []uint64
		return tx.Table("communityevents").
			Select("DISTINCT communityevents.id").
			Joins("LEFT JOIN communityevents_groups ON communityevents.id = communityevents_groups.eventid").
			Joins("LEFT JOIN communityevents_dates ON communityevents.id = communityevents_dates.eventid").
			Joins("LEFT JOIN users ON communityevents.userid = users.id").
			Where("groupid = ? AND end IS NOT NULL AND end >= ? AND communityevents.deleted = 0 AND pending = 0 AND users.deleted IS NULL",
				1, "2026-01-01").
			Order("end ASC").
			Pluck("id", &ids)
	})
}

// --- communityevent/communityEvent.go: isModerator ------------------------------

func TestWave4A_f8e861bf3fa6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f8e861bf3fa6", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m").
			Joins("INNER JOIN communityevents_groups ceg ON ceg.groupid = m.groupid").
			Where("ceg.eventid = ? AND m.userid = ? AND m.collection = ? AND m.role IN (?, ?)",
				1, 2, "Approved", "Moderator", "Owner").
			Count(&count)
	})
}

// --- config/config.go: RequireSupportOrAdminMiddleware --------------------------

func TestWave4A_a2a6a74e67d6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a2a6a74e67d6", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("sessions").
			Select("users.id, users.systemrole").
			Joins("INNER JOIN users ON users.id = sessions.userid").
			Where("sessions.id = ? AND users.id = ?", "sess-1", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- donations/donations.go: MatchUserByEmailOrPriorDonation ------------------

func TestWave4A_36a30e931616(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "36a30e931616", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_donations ud").
			Select("ud.userid").
			Joins("JOIN users u ON u.id = ud.userid").
			Where("ud.Payer = ? AND ud.userid IS NOT NULL AND u.deleted IS NULL", "payer@example.org").
			Order("ud.timestamp DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- emailtracking/compact.go: reconstructAvatarURL -----------------------------

func TestWave4A_ded0ef5a98c8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ded0ef5a98c8", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Select("ui.id AS image_id, ui.url AS image_url, ue.email AS email").
			Joins("LEFT JOIN users_images ui ON ui.userid = u.id").
			Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
			Where("u.id = ?", 1).
			Order("ui.default DESC, ui.id ASC, ue.preferred DESC").
			Limit(1).
			Find(&dest)
	})
}

// --- embedding/store.go: Refresh -------------------------------------------------

func TestWave4A_80d6f1951971(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "80d6f1951971", func(tx *gorm.DB) *gorm.DB {
		var openIds []uint64
		return tx.Table("messages_embeddings me").
			Joins("INNER JOIN messages_spatial ms ON ms.msgid = me.msgid").
			Where("ms.successful = 0 AND ms.promised = 0").
			Pluck("me.msgid", &openIds)
	})
}

// --- item/item.go: FetchForMessage -----------------------------------------------

func TestWave4A_977e85ee18fd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "977e85ee18fd", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("items").
			Select("items.id, items.name").
			Joins("INNER JOIN messages_items ON items.id = messages_items.itemid").
			Where("msgid = ?", 1).
			Find(&dest)
	})
}

// --- location/location.go: ClosestPostcode ---------------------------------------

func TestWave4A_c7c4b8699bcc(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c7c4b8699bcc", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations l1").
			Select("l1.id, l1.name, l1.type, l1.lat, l1.lng, l1.areaid, l2.name AS areaname").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("l1.id = ?", 1).
			Find(&dest)
	})
}

// --- location/location.go: FetchSingle ---------------------------------------------

func TestWave4A_3acefabd6672(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3acefabd6672", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations l1").
			Select("l1.id, l1.name, l1.areaid, l1.lat, l1.lng, l2.name as areaname").
			Joins("LEFT JOIN locations l2 ON l2.id = l1.areaid").
			Where("l1.id = ?", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- location/location.go: SearchLocations (dodgy) -----------------------------

func TestWave4A_fdebc3317226(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "fdebc3317226", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations_dodgy ld").
			Select("ld.locationid, ld.oldlocationid, ld.newlocationid, ld.lat, ld.lng, "+
				"l0.name AS name, l1.name AS oldname, l2.name AS newname").
			Joins("INNER JOIN locations l0 ON l0.id = ld.locationid").
			Joins("INNER JOIN locations l1 ON l1.id = ld.oldlocationid").
			Joins("INNER JOIN locations l2 ON l2.id = ld.newlocationid").
			Where("ld.lat BETWEEN ? AND ? AND ld.lng BETWEEN ? AND ?", 51.0, 52.0, -1.0, 0.0).
			Find(&dest)
	})
}

// --- location/location.go: GetLocationAddresses ---------------------------------

func TestWave4A_8344ba8f9aa5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8344ba8f9aa5", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("paf_addresses").
			Select("paf_addresses.id,"+
				"locations.name as postcode, "+
				"buildingname, "+
				"buildingnumber, "+
				"p.subbuildingname, "+
				"departmentname, "+
				"dependentlocality, "+
				"doubledependentlocality, "+
				"dependentthoroughfaredescriptor, "+
				"organisationname, "+
				"suorganisationindicator, "+
				"deliverypointsuffix, "+
				"udprn, "+
				"posttown, "+
				"postcodetype, "+
				"pobox, "+
				"thoroughfaredescriptor").
			Joins("INNER JOIN locations ON locations.id = paf_addresses.postcodeid").
			Joins("LEFT JOIN paf_buildingname ON buildingnameid = paf_buildingname.id").
			Joins("LEFT JOIN paf_subbuildingname ON subbuildingnameid = paf_subbuildingname.id").
			Joins("LEFT JOIN paf_departmentname ON departmentnameid = paf_departmentname.id").
			Joins("LEFT JOIN paf_dependentlocality ON dependentlocalityid = paf_dependentlocality.id").
			Joins("LEFT JOIN paf_doubledependentlocality ON doubledependentlocalityid = paf_doubledependentlocality.id").
			Joins("LEFT JOIN paf_dependentthoroughfaredescriptor ON dependentthoroughfaredescriptorid = paf_dependentthoroughfaredescriptor.id").
			Joins("LEFT JOIN paf_organisationname ON organisationnameid = paf_organisationname.id").
			Joins("LEFT JOIN paf_pobox ON poboxid = paf_pobox.id").
			Joins("LEFT JOIN paf_posttown ON posttownid = paf_posttown.id").
			Joins("LEFT JOIN paf_subbuildingname p ON subbuildingnameid = p.id").
			Joins("LEFT JOIN paf_thoroughfaredescriptor ON thoroughfaredescriptorid = paf_thoroughfaredescriptor.id").
			Where("paf_addresses.postcodeid = ?", 1).
			Find(&dest)
	})
}

// --- membership/membership.go: GetMemberships (received mod mails filter) -----

func TestWave4A_4a525e0d44db(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4a525e0d44db", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships m").
			Select("m.id, m.userid, m.groupid, m.role, m.collection, m.added, m.heldby, "+
				"u.fullname, u.firstname, u.lastname, m.settings, "+
				"m.emailfrequency, m.ourPostingStatus, m.eventsallowed, m.volunteeringallowed, "+
				"b.date AS bandate, b.byuser AS bannedby, "+
				"m.reviewrequestedat, m.reviewedat, m.reviewreason, u.engagement, "+
				"MAX(l.timestamp) AS lastmodmail").
			Joins("JOIN users u ON u.id = m.userid").
			Joins("LEFT JOIN users_banned b ON b.userid = m.userid AND b.groupid = m.groupid").
			Joins("INNER JOIN logs l ON l.user = m.userid AND l.groupid = m.groupid "+
				"AND ((l.type = 'Message' AND l.subtype IN ('Rejected', 'Deleted', 'Replied')) "+
				"OR (l.type = 'User' AND l.subtype IN ('Mailed', 'Rejected', 'Deleted'))) "+
				"AND l.byuser != l.user").
			Where("m.groupid = ? AND m.collection = ?", 1, "Approved").
			Group("m.userid").
			Order("m.added DESC").
			Limit(20).
			Find(&dest)
	})
}

// --- message/message.go: GetMessagesByIds (bulk attachments, AI masking) ------

func TestWave4A_625141fb1180(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "625141fb1180", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_attachments ma").
			Select("ma.id, ma.msgid, bia.bulkitemid, ma.archived, "+
				"CASE WHEN ai.id IS NOT NULL THEN '' ELSE COALESCE(ma.externaluid, '') END AS externaluid, "+
				"ma.externalmods").
			Joins("LEFT JOIN ai_images ai ON ai.externaluid = ma.externaluid AND ai.status IN ('rejected', 'regenerating', 'suppressed')").
			Joins("LEFT JOIN messages_bulk_item_attachments bia ON bia.attachmentid = ma.id").
			Where("ma.msgid = ?", 1).
			Order("ma.`primary` DESC, ma.id ASC").
			Find(&dest)
	})
}

// --- message/message.go: GetMessagesByIds (postings) ---------------------------

func TestWave4A_d99fe717309f(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d99fe717309f", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_postings mp").
			Select("mp.msgid, mp.groupid, mp.date, mp.repost, mp.autorepost, COALESCE(g.namefull, g.nameshort) AS namedisplay").
			Joins("INNER JOIN `groups` g ON mp.groupid = g.id").
			Where("mp.msgid = ?", 1).
			Order("mp.date ASC").
			Find(&dest)
	})
}

// --- message/message.go: GetMessagesByIds (repost settings) --------------------

func TestWave4A_490e45f9be50(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "490e45f9be50", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("`groups`").
			Select("messages_groups.groupid AS groupid, JSON_EXTRACT(settings, '$.reposts') AS reposts").
			Joins("INNER JOIN messages_groups ON messages_groups.groupid = groups.id").
			Where("msgid = ? AND messages_groups.deleted = 0", 1).
			Find(&dest)
	})
}

// --- message/message.go: GetMessagesByIds (reach-blocked / banned) -------------

func TestWave4A_b8d33139d873(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b8d33139d873", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_groups mg").
			Select("mg.msgid").
			Joins("LEFT JOIN users_banned ub ON ub.groupid = mg.groupid AND ub.userid = ?", 1).
			Where("mg.msgid IN (?) AND mg.deleted = 0", []uint64{1, 2}).
			Group("mg.msgid").
			Having("COUNT(mg.groupid) = COUNT(ub.groupid)").
			Find(&dest)
	})
}

// --- message/message.go: GetRecentActivity --------------------------------------

func TestWave4A_627297867656(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "627297867656", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages").
			Select("messages.id, messages_groups.arrival, messages_groups.groupid, messages.subject, "+
				"groups.nameshort, groups.namefull, groups.lat, groups.lng").
			Joins("INNER JOIN messages_groups ON messages.id = messages_groups.msgid").
			Joins("INNER JOIN `groups` ON messages_groups.groupid = groups.id").
			Joins("INNER JOIN users ON messages.fromuser = users.id").
			Where("messages_groups.arrival > ? AND collection = ?", "2026-01-01 00:00:00", "Approved").
			Order("messages_groups.arrival ASC").
			Limit(100).
			Find(&dest)
	})
}

// --- message/message.go: constructLocationString --------------------------------

func TestWave4A_e0be009ca12b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "e0be009ca12b", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("locations l").
			Select("l.name, l.type, COALESCE(l.areaid, 0) as areaid").
			Joins("INNER JOIN messages m ON m.locationid = l.id").
			Where("m.id = ?", 1).
			Find(&dest)
	})
}

// --- message/message.go: isModForMessage -----------------------------------------

func TestWave4A_509cbeda4fad(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "509cbeda4fad", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("JOIN memberships m ON m.groupid = mg.groupid").
			Where("mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)", 1, 2, "Moderator", "Owner").
			Count(&count)
	})
}

// --- message/message.go: MessageOriginGroup ---------------------------------------

func TestWave4A_3843c361ded2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "3843c361ded2", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_groups mg").
			Select("mg.groupid AS groupid, (mg.arrival <= m.arrival + INTERVAL 10 MINUTE) AS is_origin").
			Joins("JOIN messages m ON m.id = mg.msgid").
			Where("mg.msgid = ?", 1).
			Order("mg.arrival ASC, mg.groupid ASC").
			Limit(1).
			Find(&dest)
	})
}

// --- message/message.go: JoinAndPostAs (item name lookup) -----------------------

func TestWave4A_d4724f1cfc67(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d4724f1cfc67", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("items i").
			Select("i.name").
			Joins("INNER JOIN messages_items mi ON mi.itemid = i.id").
			Where("mi.msgid = ?", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- message/message.go: applyPatchMessageCore (item name lookup) ---------------

// Identical golden to d4724f1cfc67 (JoinAndPostAs): two call sites for the
// same shape, converted together per gate (h).
func TestWave4A_0f2ef8ae0f11(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0f2ef8ae0f11", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("items i").
			Select("i.name").
			Joins("INNER JOIN messages_items mi ON mi.itemid = i.id").
			Where("mi.msgid = ?", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- message/message.go: canModifyMessage -----------------------------------------

func TestWave4A_29da9bf8d686(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29da9bf8d686", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("JOIN memberships m ON mg.groupid = m.groupid").
			Where("mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)", 1, 2, "Moderator", "Owner").
			Count(&count)
	})
}

// --- message/message_list.go: ListMessages (searchall, numeric id) --------------

func TestWave4A_7a97721b36a2(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "7a97721b36a2", func(tx *gorm.DB) *gorm.DB {
		var msgIDs []uint64
		return tx.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL AND m.id = ?",
				[]uint64{1, 2}, "Approved", 3).
			Order("mg.arrival DESC").
			Limit(20).
			Pluck("msgid", &msgIDs)
	})
}

// --- message/message_list.go: ListMessages (searchall, subject LIKE) ------------

func TestWave4A_ab19aed302c7(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ab19aed302c7", func(tx *gorm.DB) *gorm.DB {
		var msgIDs []uint64
		return tx.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser IS NOT NULL AND m.subject LIKE ?",
				[]uint64{1, 2}, "Approved", "%chair%").
			Order("mg.arrival DESC").
			Limit(20).
			Pluck("msgid", &msgIDs)
	})
}

// --- message/message_list.go: ListMessages (searchmemb, numeric userid) ---------

func TestWave4A_b2c399283fd5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "b2c399283fd5", func(tx *gorm.DB) *gorm.DB {
		var msgIDs []uint64
		return tx.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND m.fromuser = ?",
				[]uint64{1, 2}, "Approved", 3).
			Order("mg.arrival DESC").
			Limit(20).
			Pluck("msgid", &msgIDs)
	})
}

// --- message/message_list.go: ListMessages (searchmemb, name/email LIKE) --------

func TestWave4A_f22d282e4e7e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f22d282e4e7e", func(tx *gorm.DB) *gorm.DB {
		var msgIDs []uint64
		return tx.Table("messages_groups mg").
			Select("DISTINCT mg.msgid").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Joins("LEFT JOIN users_emails ue ON ue.userid = u.id").
			Where("mg.groupid IN (?) AND mg.collection = ? AND mg.deleted = 0 AND (u.fullname LIKE ? OR ue.email LIKE ?)",
				[]uint64{1, 2}, "Approved", "%jo%", "%jo%").
			Order("mg.arrival DESC").
			Limit(20).
			Pluck("msgid", &msgIDs)
	})
}

// --- message/message_list.go: ListMessagesMT (Edit collection) ------------------

func TestWave4A_8a73414000b6(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "8a73414000b6", func(tx *gorm.DB) *gorm.DB {
		var msgIDs []uint64
		return tx.Table("messages_edits me").
			Select("DISTINCT me.msgid").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid AND mg.deleted = 0 AND mg.rippled_in = 0").
			Where("mg.groupid IN (?) AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)",
				[]uint64{1, 2}).
			Order("me.timestamp DESC").
			Limit(20).
			Pluck("msgid", &msgIDs)
	})
}

// --- message/similar.go: Similar --------------------------------------------------

func TestWave4A_00ea2c253192(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "00ea2c253192", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_embeddings me").
			Select("me.subject_embedding, m.fromuser, m.type, m.lat, m.lng").
			Joins("INNER JOIN messages m ON m.id = me.msgid").
			Where("me.msgid = ?", 1).
			Find(&dest)
	})
}

// --- modtools/modconfig.go: GetModConfig -------------------------------------------

func TestWave4A_67206ab9f1ab(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "67206ab9f1ab", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("mod_configs mc").
			Select("DISTINCT mc.*").
			Joins("LEFT JOIN memberships m ON m.configid = mc.id AND m.userid = ?", 1).
			Where("mc.createdby = ? OR mc.`default` = 1 OR m.id IS NOT NULL", 1).
			Order("mc.name").
			Find(&dest)
	})
}

// --- session/session.go: handleLostPassword ----------------------------------------

func TestWave4A_78b08f1a877d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "78b08f1a877d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("users.id, users_emails.email").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ?", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

// --- session/session.go: handleUnsubscribe -------------------------------------------

func TestWave4A_c6167c3afc65(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "c6167c3afc65", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ?", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

// --- session/session.go: handleEmailPasswordLogin --------------------------------------

func TestWave4A_d3280ea8d71b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d3280ea8d71b", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Select("u.id").
			Joins("JOIN users_emails ue ON ue.userid = u.id").
			Where("ue.email = ?", "a@example.org").
			Limit(1).
			Find(&dest)
	})
}

// --- session/session.go: GetSession (memberships + groups) ------------------------------

func TestWave4A_ca92bd0ba27d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "ca92bd0ba27d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("memberships m").
			Select("m.groupid, m.role, m.emailfrequency, m.eventsallowed, m.volunteeringallowed, m.configid, g.type, m.settings, g.microvolunteering AS microvolunteeringallowed").
			Joins("JOIN `groups` g ON g.id = m.groupid").
			Where("m.userid = ? AND m.collection = ?", 1, "Approved").
			Order("LOWER(CASE WHEN g.namefull IS NOT NULL THEN g.namefull ELSE g.nameshort END)").
			Find(&dest)
	})
}

// --- session/session.go: GetSession (pending badge: unheld, checked) --------------------

func TestWave4A_2045fb2e0152(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2045fb2e0152", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.heldby IS NULL AND mg.contentcheck_checked_at IS NOT NULL",
				[]uint64{1, 2}, "Pending").
			Count(&count)
	})
}

// --- session/session.go: GetSession (pending badge: held) -----------------------------

func TestWave4A_4b310a4913e0(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4b310a4913e0", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.heldby IS NOT NULL",
				[]uint64{1, 2}, "Pending").
			Count(&count)
	})
}

// --- session/session.go: GetSession (pending badge: inactive groups) ------------------

func TestWave4A_0ae47e468828(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "0ae47e468828", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND (mg.contentcheck_checked_at IS NOT NULL OR mg.heldby IS NOT NULL)",
				[]uint64{1, 2}, "Pending").
			Count(&count)
	})
}

// --- session/session.go: GetSession (spam badge) --------------------------------------

func TestWave4A_d8fa348393fe(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "d8fa348393fe", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("messages_groups mg").
			Joins("INNER JOIN messages m ON m.id = mg.msgid").
			Joins("INNER JOIN users u ON u.id = m.fromuser").
			Where("mg.groupid IN ? AND mg.collection = ? AND mg.deleted = 0 AND m.deleted IS NULL AND u.deleted IS NULL AND mg.arrival >= (NOW() - INTERVAL 30 DAY)",
				[]uint64{1, 2}, "Spam").
			Count(&count)
	})
}

// --- session/session.go: GetSession (pending community events) --------------------------

func TestWave4A_9f50349c1378(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "9f50349c1378", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("communityevents ce").
			Select("COUNT(DISTINCT ce.id)").
			Joins("INNER JOIN communityevents_groups ceg ON ceg.eventid = ce.id").
			Joins("INNER JOIN communityevents_dates ced ON ced.eventid = ce.id").
			Where("ceg.groupid IN ? AND ce.pending = 1 AND ce.deleted = 0 AND ced.end >= NOW()", []uint64{1, 2}).
			Find(&dest)
	})
}

// --- session/session.go: GetSession (edit reviews) ---------------------------------------

func TestWave4A_2edb182648ba(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "2edb182648ba", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_edits me").
			Select("COUNT(DISTINCT me.msgid)").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = me.msgid AND mg.deleted = 0 AND mg.rippled_in = 0").
			Where("mg.groupid IN ? AND me.reviewrequired = 1 AND me.approvedat IS NULL AND me.revertedat IS NULL AND me.timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)",
				[]uint64{1, 2}).
			Find(&dest)
	})
}

// --- session/session.go: GetSession (pending volunteering) --------------------------------

func TestWave4A_076c5c70eb8e(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "076c5c70eb8e", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("volunteering v").
			Select("COUNT(DISTINCT v.id)").
			Joins("INNER JOIN volunteering_groups vg ON vg.volunteeringid = v.id").
			Joins("LEFT JOIN volunteering_dates vd ON vd.volunteeringid = v.id").
			Where("vg.groupid IN ? AND v.pending = 1 AND v.deleted = 0 AND v.expired = 0 AND (vd.end IS NULL OR vd.end >= NOW())", []uint64{1, 2}).
			Find(&dest)
	})
}

// --- session/session.go: GetSession (stories, group scope) --------------------------------

func TestWave4A_93161dacd118(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "93161dacd118", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_stories us").
			Select("COUNT(DISTINCT us.id)").
			Joins("INNER JOIN memberships m ON m.userid = us.userid").
			Joins("INNER JOIN users ON users.id = us.userid").
			Where("m.groupid IN ? AND m.collection = ? AND us.date > ? AND us.reviewed = 0 AND users.deleted IS NULL",
				[]uint64{1, 2}, "Approved", "2026-01-01").
			Find(&dest)
	})
}

// --- session/session.go: GetSession (newsletter stories, global) --------------------------

func TestWave4A_6b1f5e2ded05(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "6b1f5e2ded05", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("users_stories").
			Joins("INNER JOIN users ON users.id = users_stories.userid AND users.deleted IS NULL").
			Where("reviewed = 1 AND public = 1 AND newsletterreviewed = 0").
			Count(&count)
	})
}

// --- session/session.go: GetSession (happiness badge) --------------------------------------

func TestWave4A_31e0b23915a9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "31e0b23915a9", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("messages_outcomes mo").
			Select("COUNT(DISTINCT mo.id)").
			Joins("INNER JOIN messages_groups mg ON mg.msgid = mo.msgid").
			Where("mo.timestamp >= ? AND mg.arrival >= ? AND mg.groupid IN ? "+
				"AND mg.rippled_in = 0 "+
				"AND mo.comments IS NOT NULL "+
				"AND mo.comments != 'Sorry, this is no longer available.' "+
				"AND mo.comments != 'Thanks, this has now been taken.' "+
				"AND mo.comments != 'Thanks, I''m no longer looking for this.' "+
				"AND mo.comments != 'Sorry, this has now been taken.' "+
				"AND mo.comments != 'Thanks for the interest, but this has now been taken.' "+
				"AND mo.comments != 'Thanks, these have now been taken.' "+
				"AND mo.comments != 'Thanks, this has now been received.' "+
				"AND mo.comments != 'Withdrawn on user unsubscribe' "+
				"AND mo.comments != 'Auto-Expired' "+
				"AND (mo.happiness = 'Happy' OR mo.happiness IS NULL) "+
				"AND mo.reviewed = 0",
				"2026-01-01", "2026-01-01", []uint64{1, 2}).
			Find(&dest)
	})
}

// --- sso/discourse.go: validateDiscourseSession (session lookup) --------------------------

func TestWave4A_f1f646ab3b9a(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "f1f646ab3b9a", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("sessions").
			Select("sessions.userid").
			Joins("INNER JOIN users ON sessions.userid = users.id").
			Where("users.systemrole IN ('Admin', 'Support', 'Moderator') AND sessions.id = ? AND sessions.token = ?",
				"sess-1", "tok-1").
			Find(&dest)
	})
}

// --- sso/discourse.go: validateDiscourseSession (Freegle mod check) -----------------------

func TestWave4A_cc828e26aabf(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "cc828e26aabf", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships").
			Joins("INNER JOIN `groups` ON memberships.groupid = `groups`.id").
			Where("memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') AND `groups`.type = 'Freegle'", 1).
			Count(&count)
	})
}

// --- sso/discourse.go: getModGroupList ------------------------------------------------------

func TestWave4A_a9deba91c9c5(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a9deba91c9c5", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("`groups`").
			Select("COALESCE(namefull, nameshort) AS namedisplay").
			Joins("INNER JOIN memberships ON memberships.groupid = `groups`.id").
			Where("memberships.userid = ? AND memberships.role IN ('Owner', 'Moderator') AND `groups`.type = 'Freegle'", 1).
			Find(&dest)
	})
}

// --- story/story.go: Single ----------------------------------------------------------------

func TestWave4A_34430e199ad1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "34430e199ad1", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_stories").
			Select("users_stories.*, users_stories_images.id AS imageid, users_stories_images.archived AS imagearchived, users_stories_images.externaluid AS imageuid, users_stories_images.externalmods AS imagemods").
			Joins("LEFT JOIN users_stories_images ON users_stories_images.storyid = users_stories.id").
			Where("users_stories.id = ?", "1").
			Find(&dest)
	})
}

// --- story/story.go: Group ------------------------------------------------------------------

func TestWave4A_a77da0b559f8(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "a77da0b559f8", func(tx *gorm.DB) *gorm.DB {
		var ids []uint64
		return tx.Table("users_stories").
			Select("DISTINCT users_stories.id").
			Joins("INNER JOIN memberships ON memberships.userid = users_stories.userid").
			Joins("INNER JOIN users ON users.id = users_stories.userid").
			Where("memberships.groupid = ? AND reviewed = ? AND public = ? AND users_stories.userid IS NOT NULL AND users.deleted IS NULL",
				uint64(1), "1", "1").
			Order("date DESC").
			Limit(100).
			Pluck("id", &ids)
	})
}

// --- story/story.go: canModStory -------------------------------------------------------------

func TestWave4A_27f1e940eddd(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "27f1e940eddd", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m1").
			Joins("INNER JOIN memberships m2 ON m2.groupid = m1.groupid").
			Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?) AND m1.collection = ? AND m2.collection = ?",
				1, 2, "Moderator", "Owner", "Approved", "Approved").
			Count(&count)
	})
}

// --- story/story.go: createStoryNewsfeedEntry -------------------------------------------------

func TestWave4A_4186ffeeb13b(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4186ffeeb13b", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users u").
			Select("l.lat, l.lng").
			Joins("LEFT JOIN locations l ON l.id = u.lastlocation").
			Where("u.id = ?", 1).
			Find(&dest)
	})
}

// --- systemlogs/systemlogs.go: RequireModeratorMiddleware --------------------------------------

func TestWave4A_35c00d19d797(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "35c00d19d797", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("sessions").
			Select("users.id, users.systemrole").
			Joins("INNER JOIN users ON users.id = sessions.userid").
			Where("sessions.id = ? AND users.id = ?", "sess-1", 1).
			Limit(1).
			Find(&dest)
	})
}

// --- systemlogs/systemlogs.go: canViewUserLogs -------------------------------------------------

func TestWave4A_843fab2e56c1(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "843fab2e56c1", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m1").
			Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
			Where("m1.userid = ? AND m1.role IN (?, ?) AND m2.userid = ?", 1, "Moderator", "Owner", 2).
			Count(&count)
	})
}

// --- user/authorization.go: IsModOfUser (active memberships) -------------------------------------

func TestWave4A_662ae0563eeb(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "662ae0563eeb", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m1").
			Joins("INNER JOIN memberships m2 ON m2.groupid = m1.groupid").
			Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?)", 1, 2, "Moderator", "Owner").
			Count(&count)
	})
}

// --- user/authorization.go: IsModOfUser (users_banned) ---------------------------------------------

func TestWave4A_4b1829c352b9(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "4b1829c352b9", func(tx *gorm.DB) *gorm.DB {
		var count int64
		return tx.Table("memberships m1").
			Joins("INNER JOIN users_banned b ON b.groupid = m1.groupid").
			Where("m1.userid = ? AND b.userid = ? AND m1.role IN (?, ?)", 1, 2, "Moderator", "Owner").
			Count(&count)
	})
}

// --- visualise/visualise.go: getUserIcon ------------------------------------------------------------

func TestWave4A_29e35a404d4d(t *testing.T) {
	ormharness.AssertGoldenSQL(t, "29e35a404d4d", func(tx *gorm.DB) *gorm.DB {
		var dest []map[string]interface{}
		return tx.Table("users_images ui").
			Select("ui.id AS profileid, ui.url, ui.externaluid, ui.externalmods, ui.archived").
			Joins("INNER JOIN users ON users.id = ui.userid").
			Where("ui.userid = ?", 1).
			Order("ui.id DESC").
			Limit(1).
			Find(&dest)
	})
}

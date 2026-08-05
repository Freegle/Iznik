package test

// Tier 1 of the ORM migration keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, section 4): plain,
// isolated, literal single-row INSERTs (and their siblings swept up by the
// same file/function-scoped keep-raw rules) that need no new harness
// infrastructure - just the map-Create "@id" writeback pattern already
// proven in test/orm_insertid_test.go.
//
// Each of these keep-raw rules previously carried one of two now-debunked
// reasons: "LAST_INSERT_ID() is connection-scoped session state" (already
// proven wrong twice before this review) or "GORM's '@id' writeback is
// undocumented and untested" (also proven wrong - see
// TestInsertID_MapCreateReturnsIDUnderAtID in orm_insertid_test.go). Every
// site here was individually checked against its actual source, not just its
// keep-raw reason text, because the review found the mechanism table's counts
// don't line up 1:1 with keep-raw.json's rule scoping (some rules are
// function-scoped and cover more than one manifest site).
//
// Sites deliberately NOT included here, found while triaging the same
// mechanism bucket, and left raw instead:
//   - the two image.go doCreate sites (b0445c89f59e, 1571f00a4ce8): the
//     table AND column name are looked up from typeConfigs[imgType] at
//     runtime, not literal - "table/column name built at runtime" in the
//     review, a different mechanism from this bucket.
//   - location.go CreateLocation (47417e0f74d7) and group.go CreateGroup
//     (8cbeeeb7e32f): utils.SRID is spliced through fmt.Sprintf("%d", ...)
//     before the extractor ever sees the SQL, so the recorded golden has a
//     literal unresolved "%d" with no fixed text to test against until the
//     extractor's numeric-const-fold gap is fixed - the review's Tier 5, not
//     Tier 1.
//   - newsfeed.go createRefer/createPost, newsfeed/create.go
//     CreateNewsfeedEntry, and noticeboard.go PostNoticeboard: each splices a
//     genuinely runtime-computed value (a member's live lat/lng, or a
//     moderation-status-dependent hidden flag) through fmt.Sprintf before the
//     INSERT, not a constant - the review's "coordinate text built via
//     fmt.Sprintf" bucket, needing gorm.Expr plus a %f-precision decision,
//     not a Tier 1 change.
//   - spammers.go PostSpammer (20dfce4d2228): REPLACE INTO, a different
//     mechanism entirely (clause.Insert.Name() hardcodes "INSERT", so
//     Modifier:"REPLACE" alone renders invalid SQL) - being worked on
//     separately as Tier 4 REPLACE INTO infrastructure.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestTier1Insert_02f8f0aee316(t *testing.T) {
	// team/team.go PostTeam
	ormharness.AssertGoldenSQL(t, "02f8f0aee316", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"name":        "Rehome Team",
			"email":       "team@example.org",
			"description": "A team",
		}
		return tx.Table("teams").Create(row)
	})
}

func TestTier1Insert_0adadbabde5b(t *testing.T) {
	// volunteering/volunteering.go Create
	ormharness.AssertGoldenSQL(t, "0adadbabde5b", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":         1,
			"pending":        gorm.Expr("1"),
			"title":          "Help needed",
			"online":         false,
			"location":       "Edinburgh",
			"contactname":    "A Volunteer",
			"contactphone":   "01234",
			"contactemail":   "v@example.org",
			"contacturl":     "https://example.org",
			"description":    "Description",
			"timecommitment": "1 hour",
		}
		return tx.Table("volunteering").Create(row)
	})
}

func TestTier1Insert_132b1f639e73(t *testing.T) {
	// stdmsg/stdmsg.go PostStdMsg
	ormharness.AssertGoldenSQL(t, "132b1f639e73", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"configid": 1,
			"title":    "Standard message",
			"subjpref": gorm.Expr("''"),
			"subjsuff": gorm.Expr("''"),
			"body":     gorm.Expr("''"),
		}
		return tx.Table("mod_stdmsgs").Create(row)
	})
}

func TestTier1Insert_322b611c86cc(t *testing.T) {
	// shortlink/shortlink.go PostShortlink
	ormharness.AssertGoldenSQL(t, "322b611c86cc", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"name":    "myshortlink",
			"type":    gorm.Expr("'Group'"),
			"groupid": 1,
		}
		return tx.Table("shortlinks").Create(row)
	})
}

func TestTier1Insert_45b8b0bc2060(t *testing.T) {
	// communityevent/communityEvent.go Create
	ormharness.AssertGoldenSQL(t, "45b8b0bc2060", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":       1,
			"pending":      gorm.Expr("1"),
			"title":        "Community picnic",
			"location":     "The park",
			"contactname":  "A Organiser",
			"contactphone": "01234",
			"contactemail": "e@example.org",
			"contacturl":   "https://example.org",
			"description":  "Description",
		}
		return tx.Table("communityevents").Create(row)
	})
}

func TestTier1Insert_4d2e18cf27e3(t *testing.T) {
	// merge/merge.go CreateMerge
	ormharness.AssertGoldenSQL(t, "4d2e18cf27e3", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"user1":     1,
			"user2":     2,
			"offeredby": 3,
			"uid":       "some-uid",
		}
		return tx.Table("merges").Create(row)
	})
}

func TestTier1Insert_54e869591bc4(t *testing.T) {
	// alert/alert.go CreateAlert
	ormharness.AssertGoldenSQL(t, "54e869591bc4", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"createdby": 1,
			"groupid":   2,
			"from":      "a@example.org",
			"to":        "Mods",
			"subject":   "Subject",
			"text":      "Text",
			"html":      "<p>Text</p>",
			"askclick":  1,
			"tryhard":   1,
			"created":   gorm.Expr("NOW()"),
		}
		return tx.Table("alerts").Create(row)
	})
}

func TestTier1Insert_f6190b74d8d5(t *testing.T) {
	// story/story.go CreateStory
	ormharness.AssertGoldenSQL(t, "f6190b74d8d5", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"public":   true,
			"userid":   1,
			"headline": "Headline",
			"story":    "Story text",
		}
		return tx.Table("users_stories").Create(row)
	})
}

func TestTier1Insert_40de8b0d3f98(t *testing.T) {
	// comment/comment.go Create
	ormharness.AssertGoldenSQL(t, "40de8b0d3f98", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":   1,
			"groupid":  2,
			"byuserid": 3,
			"user1":    "a",
			"user2":    "b",
			"user3":    "c",
			"user4":    "d",
			"user5":    "e",
			"user6":    "f",
			"user7":    "g",
			"user8":    "h",
			"user9":    "i",
			"user10":   "j",
			"user11":   "k",
			"flag":     1,
		}
		return tx.Table("users_comments").Create(row)
	})
}

func TestTier1Insert_58cb0a225ebe(t *testing.T) {
	// amp/amp.go PostChatReply
	ormharness.AssertGoldenSQL(t, "58cb0a225ebe", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"chatid":               1,
			"userid":               2,
			"message":              "Hello",
			"type":                 "Default",
			"date":                 gorm.Expr("NOW()"),
			"processingsuccessful": gorm.Expr("1"),
		}
		return tx.Table("chat_messages").Create(row)
	})
}

func TestTier1Insert_c1fca2fe89a0(t *testing.T) {
	// user/partner.go CreatePartnerUser (users insert)
	ormharness.AssertGoldenSQL(t, "c1fca2fe89a0", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"fullname": "A Partner User",
			"added":    gorm.Expr("NOW()"),
		}
		return tx.Table("users").Create(row)
	})
}

func TestTier1Insert_3c0db7c93a36(t *testing.T) {
	// user/partner.go CreatePartnerUser (set tnuserid)
	ormharness.AssertGoldenSQL(t, "3c0db7c93a36", func(tx *gorm.DB) *gorm.DB {
		return tx.Table("users").Where("id = ?", 1).Update("tnuserid", 2)
	})
}

func TestTier1Insert_52c033e59a9d(t *testing.T) {
	// user/partner.go CreatePartnerUser (users_emails insert)
	ormharness.AssertGoldenSQL(t, "52c033e59a9d", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":    1,
			"email":     "a@example.org",
			"preferred": gorm.Expr("1"),
			"added":     gorm.Expr("NOW()"),
			"canon":     "a@example.org",
			"backwards": "gro.elpmaxe@a",
		}
		return tx.Table("users_emails").Create(row)
	})
}

func TestTier1Insert_698ab1090087(t *testing.T) {
	// message/message.go findOrCreateUserForDraft (users insert)
	ormharness.AssertGoldenSQL(t, "698ab1090087", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{"added": gorm.Expr("NOW()")}
		return tx.Table("users").Create(row)
	})
}

func TestTier1Insert_033affad7d5a(t *testing.T) {
	// message/message.go findOrCreateUserForDraft (users_emails insert)
	ormharness.AssertGoldenSQL(t, "033affad7d5a", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":    1,
			"email":     "a@example.org",
			"preferred": gorm.Expr("1"),
			"validated": gorm.Expr("NOW()"),
			"canon":     "a@example.org",
			"backwards": "gro.elpmaxe@a",
		}
		return tx.Table("users_emails").Create(row)
	})
}

func TestTier1Insert_9a37292fb851(t *testing.T) {
	// message/message.go findOrCreateUserForDraft (sessions insert)
	ormharness.AssertGoldenSQL(t, "9a37292fb851", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{
			"userid":     1,
			"series":     uint64(12345),
			"token":      "sometoken",
			"lastactive": gorm.Expr("NOW()"),
		}
		return tx.Table("sessions").Create(row)
	})
}

func TestTier1Insert_da7e48606815(t *testing.T) {
	// export/export.go PostExport
	ormharness.AssertGoldenSQL(t, "da7e48606815", func(tx *gorm.DB) *gorm.DB {
		row := map[string]interface{}{"userid": 1, "tag": "sometag"}
		return tx.Table("users_exports").Create(row)
	})
}

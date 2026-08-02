package test

// Shapes pilot (plan 7.3 wave 5 triage, runtime-varying keep-raw sites):
// converts and proves 3 of the 86 sites kept raw with reason "runtime-
// varying: the statement has more than one possible rendered form", using
// the new AssertGoldenShapes mechanism (ormharness/shapes.go). Requested as
// a pilot before converting the rest, specifically so a broken mechanism
// shows up at 3 sites rather than 86.
//
// All 3 are in image/image.go, chosen because their branch count is
// mechanically derivable from the source rather than guessed:
//   - bc2f944bfbb7 (ownsImageParent): a switch statement with 6 cases that
//     reach the query (the other two branches return before it).
//   - 1be407fe0a15 and 1606033fd8f7 (Get, two separate raw-SQL sites in the
//     same function): both driven by the same 10-entry typeConfigs map, one
//     rendered form per entry (not a cross product with the column-list
//     variation, which is itself a function of the same map key).
//
// Every shape shapes.json declares for these 3 ids is covered here - see
// ormharness/shapes.json for the declared SQL and image/image.go for the
// conversions this proves.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

// --- image/image.go: ownsImageParent (site bc2f944bfbb7) --------------------

func TestShapesPilot_bc2f944bfbb7(t *testing.T) {
	ormharness.AssertGoldenShapes(t, "bc2f944bfbb7", []ormharness.Shape{
		{Name: "Message", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`messages`").Select("`fromuser`").Where("id = ?", 1).Find(&dest)
		}},
		{Name: "ChatMessage", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`chat_messages`").Select("`userid`").Where("id = ?", 1).Find(&dest)
		}},
		{Name: "CommunityEvent", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`communityevents`").Select("`userid`").Where("id = ?", 1).Find(&dest)
		}},
		{Name: "Volunteering", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`volunteering`").Select("`userid`").Where("id = ?", 1).Find(&dest)
		}},
		{Name: "Story", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`users_stories`").Select("`userid`").Where("id = ?", 1).Find(&dest)
		}},
		{Name: "Newsfeed", Build: func(tx *gorm.DB) *gorm.DB {
			var dest uint64
			return tx.Table("`newsfeed`").Select("`userid`").Where("id = ?", 1).Find(&dest)
		}},
	})
}

// --- image/image.go: Get, main column select (site 1be407fe0a15) -----------

func TestShapesPilot_1be407fe0a15(t *testing.T) {
	const baseCols = "COALESCE(externaluid, '') AS externaluid, COALESCE(externalmods, '') AS externalmods, COALESCE(archived, 0) AS archived"

	build := func(table, cols string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			var dest []map[string]interface{}
			return tx.Table("`"+table+"`").Select(cols).Where("id = ?", 1).Find(&dest)
		}
	}

	ormharness.AssertGoldenShapes(t, "1be407fe0a15", []ormharness.Shape{
		{Name: "Message", Build: build("messages_attachments", baseCols+", COALESCE(externalurl, '') AS externalurl")},
		{Name: "Group", Build: build("groups_images", baseCols)},
		{Name: "Newsletter", Build: build("newsletters_images", baseCols)},
		{Name: "CommunityEvent", Build: build("communityevents_images", baseCols)},
		{Name: "Volunteering", Build: build("volunteering_images", baseCols)},
		{Name: "ChatMessage", Build: build("chat_images", baseCols)},
		{Name: "User", Build: build("users_images", baseCols)},
		{Name: "Newsfeed", Build: build("newsfeed_images", baseCols)},
		{Name: "Story", Build: build("users_stories_images", baseCols)},
		{Name: "Noticeboard", Build: build("noticeboards_images", baseCols)},
	})
}

// --- image/image.go: Get, legacy blob select (site 1606033fd8f7) -----------

func TestShapesPilot_1606033fd8f7(t *testing.T) {
	const withContentType = "data, COALESCE(NULLIF(contenttype, ''), 'image/jpeg') AS contenttype"
	const withoutContentType = "data, 'image/jpeg' AS contenttype"

	build := func(table, cols string) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			var dest map[string]interface{}
			return tx.Table("`"+table+"`").Select(cols).Where("id = ?", 1).Find(&dest)
		}
	}

	ormharness.AssertGoldenShapes(t, "1606033fd8f7", []ormharness.Shape{
		{Name: "Message", Build: build("messages_attachments", withoutContentType)},
		{Name: "Group", Build: build("groups_images", withContentType)},
		{Name: "Newsletter", Build: build("newsletters_images", withContentType)},
		{Name: "CommunityEvent", Build: build("communityevents_images", withContentType)},
		{Name: "Volunteering", Build: build("volunteering_images", withContentType)},
		{Name: "ChatMessage", Build: build("chat_images", withContentType)},
		{Name: "User", Build: build("users_images", withContentType)},
		{Name: "Newsfeed", Build: build("newsfeed_images", withContentType)},
		{Name: "Story", Build: build("users_stories_images", withContentType)},
		{Name: "Noticeboard", Build: build("noticeboards_images", withContentType)},
	})
}

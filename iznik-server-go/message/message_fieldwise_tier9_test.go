package message

// Fieldwise coverage (not exhaustive shapes) for applyPatchMessageCore's
// single-UPDATE SET-list build (site e9f2c662be69 / 2de07c2af78b), built by
// buildApplyPatchMessageCoreUpdateSet (message.go) as a dynamically-assembled
// clause.Set rather than a string-concatenated "col = ?" fragment list. This
// test now exercises the SAME ORM chain applyPatchMessageCore itself calls
// (.Table("messages").Where(...).Clauses(set).Updates(...)) - not a
// reimplementation - against the identical goldens fieldwise.json already
// recorded when this site's SET list was still built by hand: the "2^n
// shapes" reasoning that kept this site raw applied to a FIXED GORM chain,
// not to a chain built from a runtime-varying clause.Set, so the same n+2
// cases that proved the string version now prove the ORM one.
//
// This is the worked example for GROUPED factors (ormharness.FieldwiseCase's
// doc comment): the site was previously parked on "policy decision needed,
// 2^8 = 256 shapes" alongside modconfig.go's PatchModConfig and
// session.go's PatchSession, but that count assumed all 8 named fields
// (Subject/Textbody/Type/Availablenow/Deadline/Locationid/Lat/Lng) are
// independent, which is false for two of them:
//
//   - Deadline contributes ONE OF TWO fragments depending on its own value
//     (an empty/"null" string means "deadline = NULL", anything else means
//     a bound "deadline = ?") - not a fixed single fragment, but still
//     independent of every OTHER field. Declared as a 2-form group.
//
//   - Locationid, Lat and Lng genuinely interact: when Locationid is set
//     and the caller did not supply both Lat and Lng directly,
//     effLat/effLng come from a live DB read of that location's own stored
//     coordinates (site 5b7a006dd0a5, message.go) - so whether "lat = ?"/
//     "lng = ?" appear in the FINAL rendered statement depends on
//     Locationid too, not on Lat/Lng's own presence alone. That lookup is
//     a separate, already-proven site; what THIS site needs to prove is
//     that buildApplyPatchMessageCoreUpdateSet renders correctly for every
//     reachable PRESENCE PATTERN of {locationid, lat, lng} in the SET list,
//     regardless of how effLat/effLng ended up resolved. All 8
//     combinations are reachable (the DB lookup can independently return
//     NULL or a real value for either coordinate); the 8th, all three
//     absent, coincides with the site's "empty" case when no other field
//     is set either, so only the 7 non-empty combinations need their own
//     form. Declared as a 7-form group, "LocationLatLng".
//
// Subject, Textbody, Type and Availablenow remain plain single fields: each
// is a fixed "col = ?" fragment regardless of what else is present. (Type
// also fires a separate UPDATE on messages_groups.msgtype - a different
// statement/table, already its own site 69685398ad05, not part of this
// SET list.)
//
// The independence precondition AssertGoldenFieldwise checks automatically
// (setOrderIsLoadBearing on the declared "all" statement) still applies and
// passes here: every assignment's value is a bind placeholder or a literal
// NULL, none reference another assigned column by name. What that
// automatic check CANNOT see - a fragment's presence depending on another
// field via a side DB read - is exactly why Locationid/Lat/Lng are declared
// as a group instead of 3 fields, per a human reading of the code (this
// file), not something the tooling infers.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestFieldwiseApplyPatchMessageCore_e9f2c662be69(t *testing.T) {
	const msgID = uint64(1)

	subject := "New subject"
	textbody := "New body text"
	msgType := "Offer"
	availablenow := 1
	deadlineSet := "2026-12-31"
	deadlineNull := ""
	locationid := uint64(5)
	lat := 51.5
	lng := -0.1

	build := func(subject, textbody, msgType, deadline *string, availablenow *int, locationid *uint64, effLat, effLng *float64) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			set := buildApplyPatchMessageCoreUpdateSet(subject, textbody, msgType, deadline, availablenow, locationid, effLat, effLng)
			if len(set) == 0 {
				// Mirrors applyPatchMessageCore's own "if len(set) > 0" guard - no
				// ORM call at all, matching the "empty" case's expectation that
				// renderDryRun sees no SQL (see ormharness.assertEmptyUpdate).
				return tx
			}
			return tx.Table("messages").Where("id = ?", msgID).Clauses(set).Updates(map[string]interface{}{})
		}
	}

	cases := []ormharness.FieldwiseCase{
		{Name: "empty", Build: build(nil, nil, nil, nil, nil, nil, nil, nil)},
		{Name: "all", Build: build(&subject, &textbody, &msgType, &deadlineSet, &availablenow, &locationid, &lat, &lng)},

		{Name: "Subject", Build: build(&subject, nil, nil, nil, nil, nil, nil, nil)},
		{Name: "Textbody", Build: build(nil, &textbody, nil, nil, nil, nil, nil, nil)},
		{Name: "Type", Build: build(nil, nil, &msgType, nil, nil, nil, nil, nil)},
		{Name: "Availablenow", Build: build(nil, nil, nil, nil, &availablenow, nil, nil, nil)},

		{Name: "Deadline:Set", Build: build(nil, nil, nil, &deadlineSet, nil, nil, nil, nil)},
		{Name: "Deadline:Null", Build: build(nil, nil, nil, &deadlineNull, nil, nil, nil, nil)},

		{Name: "LocationLatLng:LocationidOnly", Build: build(nil, nil, nil, nil, nil, &locationid, nil, nil)},
		{Name: "LocationLatLng:LatOnly", Build: build(nil, nil, nil, nil, nil, nil, &lat, nil)},
		{Name: "LocationLatLng:LngOnly", Build: build(nil, nil, nil, nil, nil, nil, nil, &lng)},
		{Name: "LocationLatLng:LocationidLat", Build: build(nil, nil, nil, nil, nil, &locationid, &lat, nil)},
		{Name: "LocationLatLng:LocationidLng", Build: build(nil, nil, nil, nil, nil, &locationid, nil, &lng)},
		{Name: "LocationLatLng:LatLng", Build: build(nil, nil, nil, nil, nil, nil, &lat, &lng)},
		{Name: "LocationLatLng:LocationidLatLng", Build: build(nil, nil, nil, nil, nil, &locationid, &lat, &lng)},
	}

	ormharness.AssertGoldenFieldwise(t, "e9f2c662be69", cases)
}

package session

// Fieldwise coverage (not exhaustive shapes) for PatchSession's single-UPDATE
// SET-list build (site f85b0b8ed693 / 64dbb28e0d7b), built by
// buildPatchSessionUpdateSet (session.go) as a dynamically-assembled
// clause.Set rather than a string-concatenated "col = ?" fragment list. This
// test now exercises the SAME ORM chain PatchSession itself calls
// (.Table("users").Where(...).Clauses(set).Updates(...)) - not a
// reimplementation - against the identical goldens fieldwise.json already
// recorded when this site's SET list was still built by hand: the "2^n
// shapes" reasoning that kept this site raw applied to a FIXED GORM chain,
// not to a chain built from a runtime-varying clause.Set, so the same n+2
// cases that proved the string version now prove the ORM one.
//
// This site was previously refused fieldwise coverage entirely (see the
// keep-raw reason before this conversion) because two of its fields
// genuinely interact - not a modelling gap, real Go control flow:
//
//   - Displayname's contributed fragments depend on whether Firstname/
//     Lastname are ALSO present in the same request ("firstname = NULL" /
//     "lastname = NULL" are each appended only when that OTHER field is
//     absent). Declared as a 7-form group, "NameFields" - all 8 presence
//     combinations of {Displayname, Firstname, Lastname} are reachable; the
//     8th, all three absent, coincides with "empty" when nothing else is
//     set either.
//
//   - Settings can contribute an extra "lastlocation = ?" fragment, but only
//     when user.ProcessSettingsUpdate's postcode-change detection fires,
//     which depends on a live DB read (comparing the new location id
//     against the user's CURRENT users.lastlocation) - not a static
//     presence check. PatchSession now calls ProcessSettingsUpdate with its
//     OWN local scratch slice (not the shared setClauses/setArgs every
//     other field appends to - see session.go), so that decision is
//     resolved and inspectable BEFORE buildPatchSessionUpdateSet ever runs -
//     the same technique user/user.go's PatchUser (site 941509171a6e)
//     already uses for its own, unrelated call to the same helper.
//     ProcessSettingsUpdate's signature, its internal logic, and PatchUser
//     are all unchanged by this. Declared as a 2-form group, "Settings".
//
// The remaining 6 fields (Onholidaytill, Relevantallowed,
// Newslettersallowed, Source, Deleted, Marketingconsent) are plain fields:
// each is a fixed fragment regardless of what else is present. (Deleted's
// side effect - the reinstatement audit log INSERT..SELECT, site
// 56c15677ea5b - is a separate statement/table, not part of this SET list.)
//
// The independence precondition AssertGoldenFieldwise checks automatically
// (setOrderIsLoadBearing on the declared "all" statement) passes: every
// assignment's value is a bind placeholder or a literal NULL, none
// reference another assigned column by name. What it cannot see - a
// fragment's presence depending on another field, whether via sibling
// presence (NameFields) or a side DB read (Settings) - is exactly why those
// two are declared as groups instead of plain fields, per a human reading
// of the code (this file and session.go), not something the tooling infers.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestFieldwisePatchSession_f85b0b8ed693(t *testing.T) {
	const myid = uint64(1)

	displayname := "New Display Name"
	firstname := "First"
	lastname := "Last"
	settingsJSON := `{"notifications":true}`
	lastlocationID := uint64(42)
	onholidaytill := "2026-12-31"
	relevantallowed := 1
	newslettersallowed := 1
	source := "web"
	marketingconsent := 1

	build := func(displayname, firstname, lastname, settingsJSON *string, lastlocationID *uint64, onholidaytill *string, relevantallowed, newslettersallowed *int, source *string, deletedNull bool, marketingconsent *int) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			set := buildPatchSessionUpdateSet(displayname, firstname, lastname, settingsJSON, lastlocationID,
				onholidaytill, relevantallowed, newslettersallowed, source, deletedNull, marketingconsent)
			if len(set) == 0 {
				// Mirrors PatchSession's own "if len(set) > 0" guard - no ORM call
				// at all, so renderDryRun sees no SQL, exactly like the "empty" case
				// expects (see ormharness.assertEmptyUpdate).
				return tx
			}
			return tx.Table("users").Where("id = ?", myid).Clauses(set).Updates(map[string]interface{}{})
		}
	}

	cases := []ormharness.FieldwiseCase{
		{Name: "empty", Build: build(nil, nil, nil, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "all", Build: build(&displayname, &firstname, &lastname, &settingsJSON, &lastlocationID,
			&onholidaytill, &relevantallowed, &newslettersallowed, &source, true, &marketingconsent)},

		{Name: "Onholidaytill", Build: build(nil, nil, nil, nil, nil, &onholidaytill, nil, nil, nil, false, nil)},
		{Name: "Relevantallowed", Build: build(nil, nil, nil, nil, nil, nil, &relevantallowed, nil, nil, false, nil)},
		{Name: "Newslettersallowed", Build: build(nil, nil, nil, nil, nil, nil, nil, &newslettersallowed, nil, false, nil)},
		{Name: "Source", Build: build(nil, nil, nil, nil, nil, nil, nil, nil, &source, false, nil)},
		{Name: "Deleted", Build: build(nil, nil, nil, nil, nil, nil, nil, nil, nil, true, nil)},
		{Name: "Marketingconsent", Build: build(nil, nil, nil, nil, nil, nil, nil, nil, nil, false, &marketingconsent)},

		{Name: "NameFields:DisplaynameOnly", Build: build(&displayname, nil, nil, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:FirstnameOnly", Build: build(nil, &firstname, nil, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:LastnameOnly", Build: build(nil, nil, &lastname, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:DisplaynameFirstname", Build: build(&displayname, &firstname, nil, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:DisplaynameLastname", Build: build(&displayname, nil, &lastname, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:FirstnameLastname", Build: build(nil, &firstname, &lastname, nil, nil, nil, nil, nil, nil, false, nil)},
		{Name: "NameFields:DisplaynameFirstnameLastname", Build: build(&displayname, &firstname, &lastname, nil, nil, nil, nil, nil, nil, false, nil)},

		{Name: "Settings:NoLocationChange", Build: build(nil, nil, nil, &settingsJSON, nil, nil, nil, nil, nil, false, nil)},
		{Name: "Settings:LocationChange", Build: build(nil, nil, nil, &settingsJSON, &lastlocationID, nil, nil, nil, nil, false, nil)},
	}

	ormharness.AssertGoldenFieldwise(t, "f85b0b8ed693", cases)
}

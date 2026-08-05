package test

// Fieldwise coverage (not exhaustive shapes) for modconfig.go's
// PatchModConfig (site a7b00c5503b7), one of the two sites parked on a
// "policy decision needed" reason: 17 independently-optional fields, so
// exhaustive AssertGoldenShapes coverage would mean declaring 2^17 shapes.
//
// Checked before building this: none of the 17 fields' fragments reference
// another field's assigned column - each is a plain "col = ?" bind, except
// Protected, which contributes two of its own ("protected = ?, createdby =
// ?") but still does not reference any OTHER field's column. That
// independence is what makes n+2 cases (each field alone, empty, all
// together) a real proof rather than a partial shape list masquerading as
// one; see ormharness.AssertGoldenFieldwise's package doc comment for the
// full reasoning, and its own precondition check (reusing
// setOrderIsLoadBearing/check-set-order.sh's rule) for how that independence
// claim is verified rather than assumed every time this runs.
//
// session.go's PatchSession (f85b0b8ed693), the other site parked on the
// same policy question, is NOT converted here: its fields genuinely
// interact - Displayname's contributed fragments depend on whether
// Firstname/Lastname are ALSO present in the same request, and Settings can
// contribute an extra "lastlocation = ?" fragment gated by a live DB
// comparison inside user.ProcessSettingsUpdate. Fieldwise coverage does not
// apply there; see the corrected keep-raw.json reason for that site.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestFieldwiseModConfig_a7b00c5503b7(t *testing.T) {
	build := func(fields map[string]interface{}) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			return tx.Table("mod_configs").Where("id = ?", 1).Updates(fields)
		}
	}

	name := "A name"
	fromname := "From name"
	ccrejectto := "reject@example.org"
	ccrejectaddr := "rejectaddr@example.org"
	ccfollowupto := "followup@example.org"
	ccfollowupaddr := "followupaddr@example.org"
	ccrejmembto := "rejmemb@example.org"
	ccrejmembaddr := "rejmembaddr@example.org"
	ccfollmembto := "follmemb@example.org"
	ccfollmembaddr := "follmembaddr@example.org"
	protected := 1
	messageorder := "OldestFirst"
	network := "Somenetwork"
	coloursubj := 1
	subjreg := "^\\[.*\\]"
	subjlen := 20
	chatread := 1
	myid := uint64(2)

	all := map[string]interface{}{
		"name":           name,
		"fromname":       fromname,
		"ccrejectto":     ccrejectto,
		"ccrejectaddr":   ccrejectaddr,
		"ccfollowupto":   ccfollowupto,
		"ccfollowupaddr": ccfollowupaddr,
		"ccrejmembto":    ccrejmembto,
		"ccrejmembaddr":  ccrejmembaddr,
		"ccfollmembto":   ccfollmembto,
		"ccfollmembaddr": ccfollmembaddr,
		"protected":      protected,
		"createdby":      myid,
		"messageorder":   messageorder,
		"network":        network,
		"coloursubj":     coloursubj,
		"subjreg":        subjreg,
		"subjlen":        subjlen,
		"chatread":       chatread,
	}

	cases := []ormharness.FieldwiseCase{
		{Name: "empty", Build: build(map[string]interface{}{})},
		{Name: "all", Build: build(all)},
		{Name: "Name", Build: build(map[string]interface{}{"name": name})},
		{Name: "Fromname", Build: build(map[string]interface{}{"fromname": fromname})},
		{Name: "Ccrejectto", Build: build(map[string]interface{}{"ccrejectto": ccrejectto})},
		{Name: "Ccrejectaddr", Build: build(map[string]interface{}{"ccrejectaddr": ccrejectaddr})},
		{Name: "Ccfollowupto", Build: build(map[string]interface{}{"ccfollowupto": ccfollowupto})},
		{Name: "Ccfollowupaddr", Build: build(map[string]interface{}{"ccfollowupaddr": ccfollowupaddr})},
		{Name: "Ccrejmembto", Build: build(map[string]interface{}{"ccrejmembto": ccrejmembto})},
		{Name: "Ccrejmembaddr", Build: build(map[string]interface{}{"ccrejmembaddr": ccrejmembaddr})},
		{Name: "Ccfollmembto", Build: build(map[string]interface{}{"ccfollmembto": ccfollmembto})},
		{Name: "Ccfollmembaddr", Build: build(map[string]interface{}{"ccfollmembaddr": ccfollmembaddr})},
		{Name: "Protected", Build: build(map[string]interface{}{"protected": protected, "createdby": myid})},
		{Name: "Messageorder", Build: build(map[string]interface{}{"messageorder": messageorder})},
		{Name: "Network", Build: build(map[string]interface{}{"network": network})},
		{Name: "Coloursubj", Build: build(map[string]interface{}{"coloursubj": coloursubj})},
		{Name: "Subjreg", Build: build(map[string]interface{}{"subjreg": subjreg})},
		{Name: "Subjlen", Build: build(map[string]interface{}{"subjlen": subjlen})},
		{Name: "Chatread", Build: build(map[string]interface{}{"chatread": chatread})},
	}

	ormharness.AssertGoldenFieldwise(t, "a7b00c5503b7", cases)
}

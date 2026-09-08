package assistant

import "testing"

func TestAsstChipsAtMostThree(t *testing.T) {
	slots := Slots{"typedItem": "old sofa", "suggestions": []interface{}{"Sofa", "3-seater sofa"}}
	facts := Facts{"recognised": []interface{}{"couch", "settee", "furniture"}, "hasRealPhoto": true}
	w, _ := LoadWorkflow()
	for _, state := range w.StateNames() {
		if n := len(ChipsFor(state, slots, facts)); n > 3 {
			t.Errorf("%s has %d chips", state, n)
		}
	}
}

func TestAsstItemChipsOrderAndDedupe(t *testing.T) {
	chips := ChipsFor("GIVE_ITEM", Slots{"typedItem": "Old sofa", "suggestions": []interface{}{"old sofa", "3-seater sofa"}}, Facts{"recognised": []interface{}{"Couch"}})
	want := []string{"Old sofa", "Couch", "3-seater sofa"}
	if len(chips) != 3 {
		t.Fatalf("got %d chips", len(chips))
	}
	for i, c := range chips {
		if c.Label != want[i] {
			t.Errorf("chip %d = %q, want %q", i, c.Label, want[i])
		}
	}
}

func TestAsstNextAfterSkipsWhatIsKnown(t *testing.T) {
	in := Facts{"signedIn": true, "locationKnown": true}
	cases := []struct {
		state string
		slots Slots
		facts Facts
		want  string
	}{
		{"GIVE_PHOTO", Slots{"photoDecided": true}, in, "GIVE_ITEM"},
		{"GIVE_ITEM", Slots{"item": "sofa"}, in, "GIVE_DESCRIPTION"},
		{"GIVE_DESCRIPTION", Slots{"item": "sofa", "description": "grey"}, in, "GIVE_CONFIRM"},
		{"GIVE_DESCRIPTION", Slots{"item": "4 chairs", "description": ""}, in, "GIVE_QUANTITY"},
		{"GIVE_DESCRIPTION", Slots{"item": "sofa", "description": ""}, Facts{"signedIn": false}, "GIVE_WHERE"},
		{"GIVE_WHERE", Slots{"item": "sofa", "description": "", "postcode": "EH3 6SS"}, Facts{"signedIn": false}, "GIVE_EMAIL"},
		{"ASK_ITEM", Slots{"item": "bike"}, in, "ASK_DESCRIPTION"},
	}
	for _, c := range cases {
		if got := NextAfter(c.state, c.slots, c.facts); got != c.want {
			t.Errorf("NextAfter(%s) = %s, want %s", c.state, got, c.want)
		}
	}
}

func TestAsstTaps(t *testing.T) {
	if r := TargetForChip("HUB", "give", Slots{}, Facts{}); r == nil || r.To != "GIVE_PHOTO" || !r.Reset {
		t.Fatal("give tap")
	}
	if r := TargetForChip("HUB", "nearby", Slots{}, Facts{"locationKnown": false}); r.To != "NEARBY_WHERE" {
		t.Fatal("nearby without location")
	}
	r := TargetForChip("GIVE_PHOTO", "no_photo", Slots{}, Facts{"signedIn": true, "locationKnown": true})
	if r.To != "GIVE_ITEM" || r.Slots["photoDecided"] != true {
		t.Fatal("no photo tap")
	}
	if TargetForChip("GIVE_PHOTO", "add_photo", Slots{}, Facts{}).HostAction.Type != "photo" {
		t.Fatal("add photo tap")
	}
	if TargetForChip("GIVE_CONFIRM", "post", Slots{"item": "sofa"}, Facts{}).To != "GIVE_POST" {
		t.Fatal("post tap")
	}
	if TargetForChip("GIVE_CONFIRM", "edit:description", Slots{}, Facts{}).To != "GIVE_DESCRIPTION" {
		t.Fatal("edit tap")
	}
	if TargetForChip("NEARBY", "offers", Slots{}, Facts{}) != nil {
		t.Fatal("filter chip is not a transition")
	}
}

func TestAsstEvents(t *testing.T) {
	a := ApplyEvent("GIVE_PHOTO", Event{Type: "photo_added", AttachmentID: 42, Recognised: []string{"sofa"}}, Slots{}, Facts{"signedIn": true, "locationKnown": true})
	if a.To != "GIVE_ITEM" || a.Facts["hasRealPhoto"] != true {
		t.Fatal("photo event")
	}
	b := ApplyEvent("GIVE_WHERE", Event{Type: "postcode_confirmed", Postcode: "EH3 6SS", Name: "Edinburgh", Community: "Edinburgh Freegle"}, Slots{"item": "sofa", "description": ""}, Facts{"signedIn": false})
	if b.To != "GIVE_EMAIL" || b.Facts["community"] != "Edinburgh Freegle" {
		t.Fatal("postcode event")
	}
	c := ApplyEvent("GIVE_POST", Event{Type: "posted", Msgid: 7, Pending: true, Newuser: true}, Slots{}, Facts{})
	if c.To != "GIVE_DONE" || c.Facts["signedIn"] != true {
		t.Fatal("posted event")
	}
	d := ApplyEvent("ASK_ITEM", Event{Type: "matches", Matches: []map[string]interface{}{{"id": 1}, {"id": 2}, {"id": 3}, {"id": 4}}}, Slots{}, Facts{})
	if d.To != "ASK_MATCHES" || len(d.Facts["matches"].([]interface{})) != 3 {
		t.Fatal("matches event")
	}
}

func TestAsstProgress(t *testing.T) {
	if ProgressFor("HUB", Slots{}) != nil {
		t.Fatal("no progress outside a flow")
	}
	p := ProgressFor("GIVE_DESCRIPTION", Slots{"item": "sofa"})
	if p.Label != "Giving" || p.Item != "sofa" || p.Step != 3 || p.Total != 5 {
		t.Fatalf("progress %+v", p)
	}
}

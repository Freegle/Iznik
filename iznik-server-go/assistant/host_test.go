package assistant

import (
	"strings"
	"testing"
)

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
	if ProgressFor("HUB", Slots{}, Facts{}) != nil {
		t.Fatal("no progress outside a flow")
	}
	if ProgressFor("GIVE_DONE", Slots{}, Facts{}) != nil {
		t.Fatal("no progress once the post has gone")
	}
	// A visitor meets every question.
	p := ProgressFor("GIVE_DESCRIPTION", Slots{"item": "sofa", "photoDecided": true}, Facts{})
	if p.Label != "Giving" || p.Item != "sofa" || p.Step != 3 || p.Total != 7 {
		t.Fatalf("visitor progress %+v", p)
	}
	// A signed-in member whose location is known skips where and email, and the count
	// must not go backwards between questions.
	member := Facts{"signedIn": true, "locationKnown": true}
	q := ProgressFor("GIVE_QUANTITY", Slots{"item": "sofa", "photoDecided": true, "description": "comfy"}, member)
	if q.Step != 4 || q.Total != 5 {
		t.Fatalf("member quantity progress %+v", q)
	}
	c := ProgressFor("GIVE_CONFIRM", Slots{"item": "sofa", "photoDecided": true, "description": "comfy", "quantity": 1}, member)
	if c.Step != 5 || c.Total != 5 {
		t.Fatalf("member confirm progress %+v", c)
	}
	// Matches shown for an ask sit at the item step.
	m := ProgressFor("ASK_MATCHES", Slots{"item": "bike"}, Facts{})
	if m.Label != "Asking" || m.Step != 1 || m.Total != 5 {
		t.Fatalf("ask matches progress %+v", m)
	}
}

// Every move the host can make on its own must be an edge the workflow defines (or an
// end state, which the conversation turns into a return to the hub). The engine now
// refuses anything else, so a gap here would be a dead button, not a bypass.
func TestAsstHostMovesAreLegal(t *testing.T) {
	wf := newTestEngine(&FakeLLM{}).Workflow
	legal := func(from, to string) bool {
		return to == "" || to == from || wf.IsValidTransition(from, to) || wf.States[to].NodeType == "end"
	}
	combos := []struct {
		slots Slots
		facts Facts
	}{
		{Slots{}, Facts{}},
		{Slots{"item": "chairs", "photoDecided": true, "description": "four of them", "quantity": 4, "postcode": "EH3 6SS", "email": "a@b.com"}, Facts{}},
		{Slots{"item": "sofa", "photoDecided": true, "description": "comfy"}, Facts{"signedIn": true, "locationKnown": true}},
		{Slots{"item": "bike"}, Facts{"signedIn": true, "locationKnown": true, "matches": []interface{}{map[string]interface{}{"id": 1.0}}, "postFailed": "x"}},
	}
	for state, def := range wf.States {
		if def.NodeType == "end" {
			continue
		}
		for _, c := range combos {
			for _, chip := range ChipsFor(state, c.slots, c.facts) {
				if tr := TargetForChip(state, chip.Value, c.slots, c.facts); tr != nil && !legal(state, tr.To) {
					t.Errorf("chip %s in %s leads to %s, which the workflow does not allow", chip.Value, state, tr.To)
				}
			}
			if IsQuestion(state) {
				if next := NextAfter(state, c.slots, c.facts); !legal(state, next) {
					t.Errorf("NextAfter(%s) = %s is not an edge", state, next)
				}
			}
			for _, ev := range []Event{{Type: "photo_added", AttachmentID: 1}, {Type: "postcode_confirmed", Postcode: "EH3 6SS", Name: "Edinburgh"}, {Type: "email_confirmed", Email: "a@b.com"}, {Type: "posted", Msgid: 5}, {Type: "post_failed"}, {Type: "matches"}, {Type: "nearby"}, {Type: "communities"}, {Type: "joined"}, {Type: "signed_in"}} {
				if r := ApplyEvent(state, ev, c.slots, c.facts); !legal(state, r.To) {
					t.Errorf("event %s in %s leads to %s, which the workflow does not allow", ev.Type, state, r.To)
				}
			}
		}
		if strings.HasSuffix(state, "_CONFIRM") {
			for _, f := range []string{"photo", "item", "description", "quantity", "where", "email"} {
				tr := TargetForChip(state, "edit:"+f, Slots{}, Facts{})
				if tr == nil {
					continue // asks have no photo or quantity
				}
				if _, ok := wf.States[tr.To]; ok && !legal(state, tr.To) {
					t.Errorf("edit:%s from %s leads to %s without an edge", f, state, tr.To)
				}
			}
		}
	}
}

func TestAsstEditTapAcceptsOnlyFlowQuestions(t *testing.T) {
	if tr := TargetForChip("ASK_CONFIRM", "edit:done", Slots{}, Facts{}); tr != nil {
		t.Fatalf("edit:done is not a question, got %+v", tr)
	}
	if tr := TargetForChip("ASK_CONFIRM", "edit:item", Slots{}, Facts{}); tr == nil || tr.To != "ASK_ITEM" {
		t.Fatalf("edit:item should revisit the item, got %+v", tr)
	}
}

func TestAsstPostFailedReturnsToTheCard(t *testing.T) {
	r := ApplyEvent("GIVE_POST", Event{Type: "post_failed", Reason: "network"}, Slots{"item": "sofa"}, Facts{})
	if r.To != "GIVE_CONFIRM" {
		t.Fatalf("post_failed should go back to the card, got %q", r.To)
	}
	if r.Facts["postFailed"] != "network" {
		t.Fatalf("reason kept for the composed line: %+v", r.Facts)
	}
}

func TestAsstNearbyCountIsAFactTheModelMaySay(t *testing.T) {
	ev := Event{Type: "nearby", Count: 14, Filter: "offers", Posts: []map[string]interface{}{{"id": 1, "title": "Grey sofa", "type": "Offer", "miles": 1}}}
	r := ApplyEvent("NEARBY", ev, Slots{}, Facts{})
	if r.Facts["nearbyCount"] != 14 {
		t.Fatalf("nearbyCount = %v, want 14", r.Facts["nearbyCount"])
	}
	vocab := AllowedVocabulary(r.Facts, "", "")
	if ok, reasons := CheckReply("14 offers nearby, here is a glimpse of three.", vocab, "NEARBY"); !ok {
		t.Fatalf("the count from the facts should pass: %v", reasons)
	}
	if ok, _ := CheckReply("15 offers nearby, here is a glimpse of three.", vocab, "NEARBY"); ok {
		t.Fatal("a count not in the facts should trip the check")
	}
}

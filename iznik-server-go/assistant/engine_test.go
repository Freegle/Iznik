package assistant

import (
	"context"
	"strings"
	"testing"
)

func newTestEngine(llm LLM) *Engine {
	w, err := LoadWorkflow()
	if err != nil {
		panic(err)
	}
	w.Guardrails = Guardrails()
	return &Engine{Workflow: w, Store: NewMemoryStore(), LLM: llm, MaxRetries: 1}
}

func TestAsstWorkflowLoadsAndValidates(t *testing.T) {
	w, err := LoadWorkflow()
	if err != nil {
		t.Fatal(err)
	}
	if w.InitialState != "HUB" || len(w.States) < 20 {
		t.Fatalf("unexpected workflow %s %d", w.InitialState, len(w.States))
	}
	// Every chip that moves the conversation must be a defined transition.
	facts := Facts{"signedIn": true, "locationKnown": true, "hasRealPhoto": true}
	for _, state := range w.StateNames() {
		if w.States[state].NodeType == "end" {
			continue // the service never rests in an end state; it returns to the hub
		}
		for _, chip := range ChipsFor(state, Slots{"typedItem": "sofa"}, facts) {
			r := TargetForChip(state, chip.Value, Slots{"item": "sofa", "typedItem": "sofa"}, facts)
			if r == nil || r.To == "" {
				continue
			}
			if !w.IsValidTransition(state, r.To) {
				t.Errorf("chip %s in %s goes to %s which is not a defined transition", chip.Value, state, r.To)
			}
		}
	}
}

func TestAsstDecideAppliesContextAndTransition(t *testing.T) {
	llm := &FakeLLM{Responses: []string{`{"contextUpdates":{"say":"A sofa, lovely. Have you got a photo?","newSlots":{"item":"sofa"},"understood":true},"reasoning":"give","actions":[],"proposedTransition":"GIVE_PHOTO"}`}}
	e := newTestEngine(llm)
	inst, _ := e.NewInstance("u:1", map[string]interface{}{})
	var streamed []string
	d, err := e.Decide(context.Background(), inst, map[string]interface{}{"type": "message", "text": "I have a sofa to give away"}, func(s string) { streamed = append(streamed, s) })
	if err != nil {
		t.Fatal(err)
	}
	if inst.State != "GIVE_PHOTO" || d.ContextUpdates["say"] != "A sofa, lovely. Have you got a photo?" {
		t.Fatalf("state %s say %v", inst.State, d.ContextUpdates["say"])
	}
	if strings.Join(streamed, "") != "A sofa, lovely. Have you got a photo?" {
		t.Fatalf("streamed %q", strings.Join(streamed, ""))
	}
	if !strings.Contains(llm.Calls[0], "Valid Next States") || !strings.Contains(llm.Calls[0], "GIVE_PHOTO") || !strings.Contains(llm.Calls[0], "How you talk") {
		t.Fatal("prompt lacks legal states or character sheet")
	}
}

func TestAsstDecideRejectsIllegalTransitionThenRetries(t *testing.T) {
	llm := &FakeLLM{Responses: []string{
		`{"contextUpdates":{"say":"x"},"reasoning":"","actions":[],"proposedTransition":"GIVE_CONFIRM"}`,
		`{"contextUpdates":{"say":"y"},"reasoning":"","actions":[],"proposedTransition":null}`,
	}}
	e := newTestEngine(llm)
	inst, _ := e.NewInstance("u:1", map[string]interface{}{})
	d, err := e.Decide(context.Background(), inst, map[string]interface{}{"type": "message"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if inst.State != "HUB" || d.ContextUpdates["say"] != "y" {
		t.Fatalf("expected retry to stay in HUB with second reply, got %s %v", inst.State, d.ContextUpdates["say"])
	}
	if !strings.Contains(llm.Calls[1], "PREVIOUS ATTEMPT ERROR") {
		t.Fatal("retry prompt should carry the error")
	}
}

func TestAsstHostTransitionsAreValidated(t *testing.T) {
	e := newTestEngine(&FakeLLM{})
	inst, _ := e.NewInstance("u:1", map[string]interface{}{})
	if err := e.Transition(inst, "GIVE_CONFIRM", "test"); err == nil {
		t.Fatal("HUB -> GIVE_CONFIRM is not defined")
	}
	if err := e.Transition(inst, "GIVE_PHOTO", "test"); err != nil || inst.State != "GIVE_PHOTO" {
		t.Fatal("HUB -> GIVE_PHOTO is defined")
	}
	if len(inst.History) != 1 || inst.History[0].Trigger != "host_driven" {
		t.Fatal("history recorded")
	}
}

// Package assistant is the Freegle chat assistant: a Go port of the ai-flower engine
// core (github.com/Freegle/ai-flower) running the same JSON workflow definitions, with
// the model composing the words inside validated transitions. The definition file is
// editable in ai-flower's Vue editor; nothing here changes its format.
package assistant

import (
	_ "embed"
	"encoding/json"
	"fmt"
	"sort"
)

//go:embed workflows/freegle.json
var freegleWorkflowJSON []byte

//go:embed voice/character.md
var Character string

//go:embed voice/facts.md
var FactSheet string

// StateDefinition mirrors ai-flower's StateDefinition.
type StateDefinition struct {
	Description  string   `json:"description"`
	NodeType     string   `json:"nodeType"`
	Prompt       string   `json:"prompt,omitempty"`
	ReadActions  []string `json:"readActions,omitempty"`
	WriteActions []string `json:"writeActions,omitempty"`
}

// TransitionDefinition mirrors ai-flower's TransitionDefinition.
type TransitionDefinition struct {
	ID        string `json:"id"`
	From      string `json:"from"`
	To        string `json:"to"`
	Trigger   string `json:"trigger"`
	Condition string `json:"condition,omitempty"`
	Label     string `json:"label,omitempty"`
}

// WorkflowDefinition mirrors ai-flower's WorkflowDefinition.
type WorkflowDefinition struct {
	ID           string                     `json:"id"`
	Name         string                     `json:"name"`
	Version      string                     `json:"version,omitempty"`
	Description  string                     `json:"description,omitempty"`
	InitialState string                     `json:"initialState"`
	Guardrails   string                     `json:"guardrails,omitempty"`
	States       map[string]StateDefinition `json:"states"`
	Transitions  []TransitionDefinition     `json:"transitions"`
}

// LoadWorkflow parses the embedded Freegle workflow and validates it.
func LoadWorkflow() (*WorkflowDefinition, error) {
	var w WorkflowDefinition
	if err := json.Unmarshal(freegleWorkflowJSON, &w); err != nil {
		return nil, fmt.Errorf("assistant: bad workflow json: %w", err)
	}
	if err := w.Validate(); err != nil {
		return nil, err
	}
	return &w, nil
}

// Validate applies ai-flower's definition rules: one start state, a known initial
// state, every transition between known states, and no duplicate transition ids.
func (w *WorkflowDefinition) Validate() error {
	if _, ok := w.States[w.InitialState]; !ok {
		return fmt.Errorf("assistant: initialState %q is not a state", w.InitialState)
	}
	starts := 0
	for id, s := range w.States {
		switch s.NodeType {
		case "start":
			starts++
		case "agent", "tool", "end":
		default:
			return fmt.Errorf("assistant: state %q has unknown nodeType %q", id, s.NodeType)
		}
	}
	if starts != 1 {
		return fmt.Errorf("assistant: expected exactly one start state, found %d", starts)
	}
	seen := map[string]bool{}
	for _, t := range w.Transitions {
		if seen[t.ID] {
			return fmt.Errorf("assistant: duplicate transition id %q", t.ID)
		}
		seen[t.ID] = true
		if _, ok := w.States[t.From]; !ok {
			return fmt.Errorf("assistant: transition %q from unknown state %q", t.ID, t.From)
		}
		if _, ok := w.States[t.To]; !ok {
			return fmt.Errorf("assistant: transition %q to unknown state %q", t.ID, t.To)
		}
	}
	return nil
}

// TransitionsFrom lists the transitions leaving a state, in definition order.
func (w *WorkflowDefinition) TransitionsFrom(state string) []TransitionDefinition {
	var out []TransitionDefinition
	for _, t := range w.Transitions {
		if t.From == state {
			out = append(out, t)
		}
	}
	return out
}

// IsValidTransition reports whether from -> to is defined.
func (w *WorkflowDefinition) IsValidTransition(from, to string) bool {
	for _, t := range w.Transitions {
		if t.From == from && t.To == to {
			return true
		}
	}
	return false
}

// StateNames returns the state ids sorted, for tests and the editor endpoint.
func (w *WorkflowDefinition) StateNames() []string {
	names := make([]string, 0, len(w.States))
	for k := range w.States {
		names = append(names, k)
	}
	sort.Strings(names)
	return names
}

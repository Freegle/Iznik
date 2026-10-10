package assistant

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/google/uuid"
	"gorm.io/gorm"
)

// The engine core, ported from ai-flower: instances move between the states of a
// definition only along defined transitions; the model may only propose one of them.

// Instance is one conversation's place in the flowchart.
type Instance struct {
	ID        uint64                 `json:"-"`
	UUID      string                 `json:"id"`
	Owner     string                 `json:"owner"`
	Workflow  string                 `json:"workflow"`
	State     string                 `json:"state"`
	Status    string                 `json:"status"`
	Context   map[string]interface{} `json:"context"`
	History   []TransitionEvent      `json:"history"`
	StayCount int                    `json:"stayCount"`
	CreatedAt time.Time              `json:"createdAt"`
	UpdatedAt time.Time              `json:"updatedAt"`
}

// TransitionEvent mirrors ai-flower's TransitionEvent.
type TransitionEvent struct {
	Timestamp   string                 `json:"timestamp"`
	FromState   string                 `json:"fromState"`
	ToState     string                 `json:"toState"`
	Trigger     string                 `json:"trigger"`
	TriggeredBy string                 `json:"triggeredBy,omitempty"`
	Delta       map[string]interface{} `json:"contextDelta,omitempty"`
}

// LLMDecision is what the model must return on a decision turn.
type LLMDecision struct {
	Reasoning                string                 `json:"reasoning"`
	ContextUpdates           map[string]interface{} `json:"contextUpdates"`
	Actions                  []DecisionAction       `json:"actions"`
	ProposedTransition       *string                `json:"proposedTransition"`
	ProposedTransitionReason string                 `json:"proposedTransitionReason,omitempty"`
}

// DecisionAction is an action the model asks for. The host performs everything, so
// the engine only records that the model asked.
type DecisionAction struct {
	Action string                 `json:"action"`
	Params map[string]interface{} `json:"params"`
}

// LLM is the model behind the engine: it takes a system prompt and a user message and
// returns the raw text. Streaming of the say field happens inside the implementation.
type LLM interface {
	Call(ctx context.Context, system, user string, onDelta func(string)) (string, error)
}

// Store persists instances.
type Store interface {
	Get(uuid string) (*Instance, error)
	Save(inst *Instance) error
}

// Engine runs one workflow definition.
type Engine struct {
	Workflow   *WorkflowDefinition
	Store      Store
	LLM        LLM
	MaxRetries int
}

var ErrNotFound = errors.New("assistant: instance not found")

// NewInstance starts a conversation at the initial state.
func (e *Engine) NewInstance(owner string, ctx map[string]interface{}) (*Instance, error) {
	now := time.Now()
	inst := &Instance{
		UUID:      uuid.NewString(),
		Owner:     owner,
		Workflow:  e.Workflow.ID,
		State:     e.Workflow.InitialState,
		Status:    "active",
		Context:   ctx,
		History:   []TransitionEvent{},
		CreatedAt: now,
		UpdatedAt: now,
	}
	if inst.Context == nil {
		inst.Context = map[string]interface{}{}
	}
	return inst, e.Store.Save(inst)
}

// Transition moves the instance along a defined edge (host-driven), recording history.
func (e *Engine) Transition(inst *Instance, to, reason string) error {
	if !e.Workflow.IsValidTransition(inst.State, to) {
		return fmt.Errorf("assistant: transition %s -> %s is not defined", inst.State, to)
	}
	return e.force(inst, to, "host_driven", reason)
}

// Force moves the instance to any defined state, recording it as host-driven.
func (e *Engine) Force(inst *Instance, to, reason string) error {
	if _, ok := e.Workflow.States[to]; !ok {
		return fmt.Errorf("assistant: state %q is not defined", to)
	}
	return e.force(inst, to, "host_driven", reason)
}

func (e *Engine) force(inst *Instance, to, trigger, reason string) error {
	from := inst.State
	inst.History = append(inst.History, TransitionEvent{
		Timestamp: time.Now().UTC().Format(time.RFC3339), FromState: from, ToState: to, Trigger: trigger, TriggeredBy: reason,
	})
	if len(inst.History) > 60 {
		inst.History = inst.History[len(inst.History)-60:]
	}
	if to != from {
		inst.State = to
		inst.StayCount = 0
	} else {
		inst.StayCount++
	}
	inst.UpdatedAt = time.Now()
	return e.Store.Save(inst)
}

// BuildDecisionPrompt reproduces ai-flower's PromptBuilder so the model sees the same
// contract: state, task, guardrails, context, actions, legal next states, JSON schema.
func (e *Engine) BuildDecisionPrompt(inst *Instance, input map[string]interface{}) (system, user string) {
	def := e.Workflow.States[inst.State]
	var b strings.Builder
	b.WriteString("You are operating within a controlled workflow. Follow these rules exactly.\n\n")
	fmt.Fprintf(&b, "## Current State: %s\n%s\n\n", inst.State, def.Description)
	if def.Prompt != "" {
		fmt.Fprintf(&b, "## Your Task\n%s\n\n", def.Prompt)
	}
	if e.Workflow.Guardrails != "" {
		fmt.Fprintf(&b, "## Guardrails (apply always)\n%s\n\n", e.Workflow.Guardrails)
	}
	valid := e.Workflow.TransitionsFrom(inst.State)
	if len(valid) > 0 {
		b.WriteString("## Valid Next States (you may ONLY propose these, or null to stay)\n")
		for _, t := range valid {
			cond := t.Condition
			if cond == "" {
				cond = t.Label
			}
			fmt.Fprintf(&b, "- \"%s\": %s\n", t.To, cond)
		}
		b.WriteString("\n")
	} else {
		b.WriteString("## Valid Next States\n(none)\n\n")
	}
	b.WriteString("## Response Format\nYou MUST respond with valid JSON matching this exact schema:\n```json\n")
	b.WriteString(`{
  "contextUpdates": { "say": "string", "newSlots": {}, "understood": true, "abusive": false },
  "reasoning": "string, brief",
  "actions": [],
  "proposedTransition": "STATE_ID or null",
  "proposedTransitionReason": "string (optional)"
}`)
	b.WriteString("\n```\n\nRULES:\n- Put contextUpdates first and \"say\" first inside it\n- Only propose a transition listed in Valid Next States, or null\n- Set proposedTransition to null if not enough information to decide yet\n- Never invent state names not listed above\n- actions must be an empty list; the app performs everything\n")
	b.WriteString(volatileMarker)
	ctxJSON, _ := json.MarshalIndent(inst.Context, "", "  ")
	fmt.Fprintf(&b, "```json\n%s\n```\n", string(ctxJSON))
	system = b.String()

	inJSON, _ := json.MarshalIndent(input, "", "  ")
	user = fmt.Sprintf("## New Input\n```json\n%s\n```\n\nRespond with the JSON decision object.", string(inJSON))
	return system, user
}

// volatileMarker separates the cacheable prefix from the per-turn context.
const volatileMarker = "\n## Current Context\n"

// ParseDecision reads the model's JSON, tolerating code fences and stray prose.
func ParseDecision(raw string) (*LLMDecision, error) {
	cleaned := strings.TrimSpace(raw)
	cleaned = strings.TrimPrefix(cleaned, "```json")
	cleaned = strings.TrimPrefix(cleaned, "```")
	cleaned = strings.TrimSuffix(cleaned, "```")
	cleaned = strings.TrimSpace(cleaned)
	var d LLMDecision
	if err := json.Unmarshal([]byte(cleaned), &d); err != nil {
		// Try the first balanced object in the text.
		start := strings.Index(cleaned, "{")
		end := strings.LastIndex(cleaned, "}")
		if start >= 0 && end > start {
			if err2 := json.Unmarshal([]byte(cleaned[start:end+1]), &d); err2 != nil {
				return nil, fmt.Errorf("assistant: decision is not JSON: %w", err)
			}
		} else {
			return nil, fmt.Errorf("assistant: decision is not JSON: %w", err)
		}
	}
	if d.ContextUpdates == nil {
		d.ContextUpdates = map[string]interface{}{}
	}
	return &d, nil
}

// ValidateDecision applies ai-flower's rules: the proposed state must be a defined
// transition from the current state (or null) and no actions may be requested.
func (e *Engine) ValidateDecision(d *LLMDecision, state string) error {
	if d.ProposedTransition != nil && *d.ProposedTransition != "" && *d.ProposedTransition != "null" {
		if !e.Workflow.IsValidTransition(state, *d.ProposedTransition) {
			return fmt.Errorf("proposedTransition %q is not a valid transition from %s", *d.ProposedTransition, state)
		}
	}
	if len(d.Actions) > 0 {
		return fmt.Errorf("actions are not allowed; the app performs everything")
	}
	return nil
}

// Decide runs one decision turn: prompt, model, parse, validate (with one retry),
// then apply context updates and any proposed transition. Returns the decision.
func (e *Engine) Decide(ctx context.Context, inst *Instance, input map[string]interface{}, onDelta func(string)) (*LLMDecision, error) {
	system, user := e.BuildDecisionPrompt(inst, input)
	var lastErr error
	retries := e.MaxRetries
	if retries < 0 {
		retries = 0
	}
	for attempt := 0; attempt <= retries; attempt++ {
		sys := system
		if attempt > 0 {
			sys = system + "\n\nPREVIOUS ATTEMPT ERROR: " + lastErr.Error() + "\n\nPlease fix your JSON response."
		}
		var deltaFn func(string)
		if attempt == 0 {
			deltaFn = onDelta
		}
		raw, err := e.LLM.Call(ctx, sys, user, deltaFn)
		if err != nil {
			return nil, err
		}
		d, err := ParseDecision(raw)
		if err != nil {
			lastErr = err
			continue
		}
		if err := e.ValidateDecision(d, inst.State); err != nil {
			lastErr = err
			continue
		}
		// Apply: context updates merge shallowly, then the transition.
		for k, v := range d.ContextUpdates {
			inst.Context[k] = v
		}
		if d.ProposedTransition != nil && *d.ProposedTransition != "" && *d.ProposedTransition != "null" {
			if err := e.force(inst, *d.ProposedTransition, "llm_decision", "llm"); err != nil {
				return nil, err
			}
		} else {
			inst.UpdatedAt = time.Now()
			if err := e.Store.Save(inst); err != nil {
				return nil, err
			}
		}
		return d, nil
	}
	return nil, fmt.Errorf("assistant: decision failed validation: %w", lastErr)
}

// ─── Stores ───────────────────────────────────────────────────────────────────

// MemoryStore keeps instances in a map; for tests.
type MemoryStore struct{ m map[string]*Instance }

func NewMemoryStore() *MemoryStore { return &MemoryStore{m: map[string]*Instance{}} }

func (s *MemoryStore) Get(id string) (*Instance, error) {
	inst, ok := s.m[id]
	if !ok {
		return nil, ErrNotFound
	}
	cp := *inst
	cp.Context = cloneMap(inst.Context)
	cp.History = append([]TransitionEvent{}, inst.History...)
	return &cp, nil
}

func (s *MemoryStore) Save(inst *Instance) error {
	cp := *inst
	cp.Context = cloneMap(inst.Context)
	cp.History = append([]TransitionEvent{}, inst.History...)
	s.m[inst.UUID] = &cp
	return nil
}

func cloneMap(m map[string]interface{}) map[string]interface{} {
	b, _ := json.Marshal(m)
	var out map[string]interface{}
	_ = json.Unmarshal(b, &out)
	if out == nil {
		out = map[string]interface{}{}
	}
	return out
}

// DBStore persists instances in assistant_instances.
type DBStore struct{ DB *gorm.DB }

type instanceRow struct {
	ID        uint64    `gorm:"column:id;primaryKey"`
	UUID      string    `gorm:"column:uuid"`
	Owner     string    `gorm:"column:owner"`
	Workflow  string    `gorm:"column:workflow"`
	State     string    `gorm:"column:state"`
	Status    string    `gorm:"column:status"`
	Context   string    `gorm:"column:context"`
	History   string    `gorm:"column:history"`
	StayCount int       `gorm:"column:stay_count"`
	CreatedAt time.Time `gorm:"column:created_at"`
	UpdatedAt time.Time `gorm:"column:updated_at"`
}

func (s *DBStore) Get(id string) (*Instance, error) {
	var row instanceRow
	res := s.DB.Table("assistant_instances").Where("uuid = ?", id).Limit(1).Find(&row)
	if res.Error != nil {
		return nil, res.Error
	}
	if row.ID == 0 {
		return nil, ErrNotFound
	}
	inst := &Instance{ID: row.ID, UUID: row.UUID, Owner: row.Owner, Workflow: row.Workflow, State: row.State, Status: row.Status, StayCount: row.StayCount, CreatedAt: row.CreatedAt, UpdatedAt: row.UpdatedAt}
	_ = json.Unmarshal([]byte(row.Context), &inst.Context)
	_ = json.Unmarshal([]byte(row.History), &inst.History)
	if inst.Context == nil {
		inst.Context = map[string]interface{}{}
	}
	return inst, nil
}

func (s *DBStore) Save(inst *Instance) error {
	ctxJSON, _ := json.Marshal(inst.Context)
	histJSON, _ := json.Marshal(inst.History)
	if inst.ID == 0 {
		row := map[string]interface{}{
			"uuid": inst.UUID, "owner": inst.Owner, "workflow": inst.Workflow, "state": inst.State,
			"status": inst.Status, "context": string(ctxJSON), "history": string(histJSON), "stay_count": inst.StayCount,
		}
		if err := s.DB.Table("assistant_instances").Create(row).Error; err != nil {
			return err
		}
		if id, ok := row["@id"].(int64); ok {
			inst.ID = uint64(id)
		}
		return nil
	}
	return s.DB.Table("assistant_instances").Where("id = ?", inst.ID).Updates(map[string]interface{}{
		"state": inst.State, "status": inst.Status, "context": string(ctxJSON), "history": string(histJSON), "stay_count": inst.StayCount,
	}).Error
}

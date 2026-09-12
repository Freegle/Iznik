package assistant

import (
	"context"
	"log"
	"regexp"
	"strings"
	"sync"
	"time"
)

// One turn of the conversation. The host (browser) sends typed text, a chip tap, or an
// event reporting something it did. The rules decide what they can; the engine keeps the
// transition legal; the model composes the words; the check keeps the words honest.

const recentLimit = 8

// Identity is who is talking.
type Identity struct {
	Key          string // u:<id> or a:<anon>
	UserID       uint64
	Name         string
	LocationName string
	Community    string
	AnonToken    string
	// WasKey is the anonymous key this browser used before signing in, so a visitor who
	// becomes a member in the middle of a chat keeps that chat.
	WasKey string
	// Throttled marks a visitor who could not be issued an identity (too many minted from
	// their address); they get the template lines and no model calls.
	Throttled bool
}

// Turns on one conversation run one at a time, so two taps in quick succession or two
// tabs cannot each load the same instance and overwrite the other's update.
type convLocks struct {
	mu sync.Mutex
	m  map[string]*convLock
}

type convLock struct {
	mu sync.Mutex
	n  int
}

var conversationLocks = &convLocks{m: map[string]*convLock{}}

// llmDeadline bounds one model call, and with it how long a stream can stay open.
const llmDeadline = 60 * time.Second

// flowLapse is how long a half-finished give or ask waits before it is dropped and the
// member is met at the hub again.
const flowLapse = time.Hour

func (l *convLocks) lock(key string) func() {
	l.mu.Lock()
	e := l.m[key]
	if e == nil {
		e = &convLock{}
		l.m[key] = e
	}
	e.n++
	l.mu.Unlock()
	e.mu.Lock()
	return func() {
		e.mu.Unlock()
		l.mu.Lock()
		e.n--
		if e.n == 0 {
			delete(l.m, key)
		}
		l.mu.Unlock()
	}
}

// TurnInput is what the browser sent.
type TurnInput struct {
	ConversationID string
	Text           string
	Tap            string
	Event          *Event
	IP             string
}

// TurnResult is what the browser gets back after the stream.
type TurnResult struct {
	Conversation string                 `json:"conversation"`
	State        string                 `json:"state"`
	Say          string                 `json:"say"`
	Chips        []Chip                 `json:"chips"`
	Progress     *Progress              `json:"progress"`
	HostAction   *HostAction            `json:"hostAction"`
	Slots        Slots                  `json:"slots"`
	Facts        Facts                  `json:"facts"`
	Fallback     bool                   `json:"fallback"`
	ChatID       uint64                 `json:"chatid,omitempty"`
	MessageIDs   []uint64               `json:"messageids,omitempty"`
	Widget       map[string]interface{} `json:"widget,omitempty"`
}

// Service holds the pieces a turn needs.
type Service struct {
	Engine     *Engine
	Quota      *Quota
	Strikes    *Strikes
	Transcript *Transcript
	Log        func(format string, args ...interface{})
}

func (s *Service) logf(format string, args ...interface{}) {
	if s.Log != nil {
		s.Log(format, args...)
	} else {
		log.Printf("assistant: "+format, args...)
	}
}

func memberFacts(id Identity) Facts {
	return Facts{
		"signedIn":      id.UserID > 0,
		"memberName":    nilIfEmpty(id.Name),
		"locationKnown": id.LocationName != "",
		"locationName":  nilIfEmpty(id.LocationName),
		"community":     nilIfEmpty(id.Community),
	}
}

func nilIfEmpty(s string) interface{} {
	if s == "" {
		return nil
	}
	return s
}

func (s *Service) instanceFor(id Identity, conversationID string) (*Instance, error) {
	if conversationID != "" {
		inst, err := s.Engine.Store.Get(conversationID)
		if err == nil && inst.Owner == id.Key {
			if inst.Status != "active" {
				inst.Status = "active"
			}
			return inst, nil
		}
		if err == nil && id.UserID > 0 && id.WasKey != "" && inst.Owner == id.WasKey {
			// The visitor who started this chat has just signed in (or posted, which
			// creates their account): the chat is theirs and carries on.
			inst.Owner = id.Key
			inst.Status = "active"
			return inst, nil
		}
	}
	ctx := map[string]interface{}{"facts": map[string]interface{}(memberFacts(id)), "slots": map[string]interface{}{}, "recent": []interface{}{}, "turn": 0, "pendingTranscript": []interface{}{}}
	return s.Engine.NewInstance(id.Key, ctx)
}

func toFacts(v interface{}) Facts {
	f := Facts{}
	if m, ok := v.(map[string]interface{}); ok {
		for k, x := range m {
			f[k] = x
		}
	}
	return f
}

func toSlots(v interface{}) Slots {
	f := Slots{}
	if m, ok := v.(map[string]interface{}); ok {
		for k, x := range m {
			f[k] = x
		}
	}
	return f
}

type recentLine struct {
	Who  string `json:"who"`
	Text string `json:"text"`
}

func toRecent(v interface{}) []recentLine {
	var out []recentLine
	if list, ok := v.([]interface{}); ok {
		for _, x := range list {
			if m, ok := x.(map[string]interface{}); ok {
				who, _ := m["who"].(string)
				text, _ := m["text"].(string)
				out = append(out, recentLine{who, text})
			}
		}
	}
	return out
}

func recentToAny(r []recentLine) []interface{} {
	out := []interface{}{}
	for _, x := range r {
		out = append(out, map[string]interface{}{"who": x.Who, "text": x.Text})
	}
	return out
}

func toTurns(v interface{}) []TranscriptTurn {
	var out []TranscriptTurn
	if list, ok := v.([]interface{}); ok {
		for _, x := range list {
			if m, ok := x.(map[string]interface{}); ok {
				who, _ := m["who"].(string)
				text, _ := m["text"].(string)
				w, _ := m["widget"].(map[string]interface{})
				out = append(out, TranscriptTurn{Who: who, Text: text, Widget: w})
			}
		}
	}
	return out
}

func turnsToAny(t []TranscriptTurn) []interface{} {
	out := []interface{}{}
	for _, x := range t {
		m := map[string]interface{}{"who": x.Who, "text": x.Text}
		if x.Widget != nil {
			m["widget"] = x.Widget
		}
		out = append(out, m)
	}
	return out
}

var confirmYes = regexp.MustCompile(`(?i)^(yes|yep|yeah|ok|okay|go|post it|post|do it|fine|sure)\b`)
var deliveryRe = regexp.MustCompile(`(?i)\b(can deliver|could deliver|happy to deliver|drop it (off|round))\b`)
var switchRe = regexp.MustCompile(`(?i)\b(instead|actually|rather)\b`)

// parsed is the rules' verdict on typed text.
type parsed struct {
	to         string
	slots      Slots
	factUpd    Facts
	hostAction *HostAction
	decide     bool
}

func (s *Service) parseTyped(state, t string, slots Slots, facts Facts) parsed {
	sl := cloneSlots(slots)
	switch state {
	case "GIVE_ITEM", "ASK_ITEM":
		want := "give"
		if state == "ASK_ITEM" {
			want = "ask"
		}
		if in := DetectIntent(t); in != "" && in != want && len(strings.Fields(t)) > 3 {
			return parsed{decide: true}
		}
		if IsUnpostableItem(t) {
			problem := "too general"
			if HasNoDescriptiveText(t) {
				problem = "no words that say what it is"
			}
			return parsed{factUpd: Facts{"itemProblem": problem}}
		}
		sl["typedItem"] = t
		if len([]rune(t)) > 60 {
			return parsed{slots: sl, decide: true}
		}
		sl["item"] = t
		return parsed{slots: sl, to: NextAfter(state, sl, facts)}
	case "GIVE_DESCRIPTION", "ASK_DESCRIPTION":
		if DetectIntent(t) != "" && len(strings.Fields(t)) > 3 && switchRe.MatchString(t) {
			return parsed{decide: true}
		}
		sl["description"] = t
		if state == "GIVE_DESCRIPTION" {
			if q := ParseQuantity(t); q > 0 && LooksPlural(t) {
				sl["quantity"] = q
			}
		}
		if deliveryRe.MatchString(t) {
			sl["delivery"] = true
		}
		if pc := ExtractPostcode(t); pc != "" {
			return parsed{slots: sl, hostAction: &HostAction{Type: "lookup_postcode", Text: pc}}
		}
		return parsed{slots: sl, to: NextAfter(state, sl, facts)}
	case "GIVE_QUANTITY":
		if q := ParseQuantity(t); q > 0 {
			sl["quantity"] = q
			return parsed{slots: sl, to: NextAfter(state, sl, facts)}
		}
		return parsed{decide: true}
	case "GIVE_WHERE", "ASK_WHERE", "NEARBY_WHERE", "COMMUNITY_WHERE":
		if pc := ExtractPostcode(t); pc != "" {
			return parsed{hostAction: &HostAction{Type: "lookup_postcode", Text: pc}}
		}
		return parsed{hostAction: &HostAction{Type: "lookup_postcode", Text: t}}
	case "GIVE_EMAIL", "ASK_EMAIL":
		if em := ExtractEmail(t); em != "" {
			return parsed{hostAction: &HostAction{Type: "check_email", Email: em}}
		}
		return parsed{decide: true}
	case "GIVE_CONFIRM", "ASK_CONFIRM":
		if confirmYes.MatchString(t) {
			post := strings.Replace(state, "CONFIRM", "POST", 1)
			return parsed{to: post, hostAction: HostActionForState(post, slots, facts)}
		}
		return parsed{decide: true}
	case "NEARBY":
		switch DetectIntent(t) {
		case "give":
			return parsed{to: "GIVE_PHOTO"}
		case "ask":
			return parsed{slots: Slots{}, to: "ASK_ITEM", decide: false}
		}
		return parsed{hostAction: &HostAction{Type: "search", Term: t}}
	case "HUB", "CANCELLED", "GIVE_DONE", "ASK_DONE", "COMMUNITY", "HELP":
		switch DetectIntent(t) {
		case "nearby":
			if facts.truthy("locationKnown") {
				return parsed{to: "NEARBY"}
			}
			return parsed{to: "NEARBY_WHERE"}
		case "community":
			if facts.truthy("locationKnown") {
				return parsed{to: "COMMUNITY"}
			}
			return parsed{to: "COMMUNITY_WHERE"}
		}
		return parsed{decide: true}
	}
	return parsed{decide: true}
}

// Turn runs one turn. onDelta receives fragments of Freegle's reply as they arrive.
func (s *Service) Turn(ctx context.Context, id Identity, in TurnInput, onDelta func(string)) (*TurnResult, error) {
	if in.ConversationID != "" {
		unlock := conversationLocks.lock(in.ConversationID)
		defer unlock()
	}
	inst, err := s.instanceFor(id, in.ConversationID)
	if err != nil {
		return nil, err
	}
	facts := toFacts(inst.Context["facts"])
	for k, v := range memberFacts(id) {
		facts[k] = v
	}
	if id.UserID == 0 && facts.truthy("signedInByEvent") {
		facts["signedIn"] = true
	}
	slots := toSlots(inst.Context["slots"])
	recent := toRecent(inst.Context["recent"])
	// A flow left for over an hour has lapsed: they are met at the hub, told what was left
	// unfinished, and can start it again. The history stays; only the half-done state goes.
	lapsed := ""
	if IsQuestion(inst.State) && !inst.UpdatedAt.IsZero() && time.Since(inst.UpdatedAt) > flowLapse && (in.Event == nil || in.Event.Type != "resume") {
		lapsed = shortItem(slots.str("item"))
		if lapsed == "" {
			lapsed = "something"
		}
		if strings.HasPrefix(inst.State, "ASK_") {
			lapsed = "asking for " + lapsed
		} else {
			lapsed = "giving away " + lapsed
		}
		slots = Slots{}
		_ = s.Engine.Force(inst, "HUB", "lapsed")
	}
	unclassified := 0
	if v, ok := inst.Context["unclassified"].(float64); ok {
		unclassified = int(v)
	}
	var memberLine string
	var hostAction *HostAction
	var instruction string
	fallback := false

	move := func(to, reason string) {
		if to == "" || to == inst.State {
			return
		}
		if s.Engine.Workflow.States[to].NodeType == "end" {
			// End states would close the conversation; keep it alive at the hub with a note.
			// The hub is the start node, the one place every flow may return to.
			facts["cancelled"] = true
			_ = s.Engine.Force(inst, "HUB", reason)
			unclassified = 0
			return
		}
		if err := s.Engine.Transition(inst, to, reason); err != nil {
			// The graph is the contract. An edge the workflow does not define is not
			// taken, whoever asked for it: not a tap, not a browser event, not a rule.
			s.logf("refused %s: %v", reason, err)
			return
		}
		// Moving on by tap, event or rule means they are on track again.
		unclassified = 0
	}

	switch {
	case in.Tap != "":
		chips := ChipsFor(inst.State, slots, facts)
		memberLine = in.Tap
		for _, c := range chips {
			if c.Value == in.Tap {
				memberLine = c.Label
			}
		}
		if strings.HasPrefix(in.Tap, "edit:") {
			memberLine = "Change " + strings.TrimPrefix(in.Tap, "edit:")
		}
		if in.Tap == "cancel" {
			memberLine = "Cancel"
		}
		if tr := TargetForChip(inst.State, in.Tap, slots, facts); tr != nil {
			if tr.Reset {
				slots = Slots{}
				for _, k := range []string{"posted", "matches", "postFailed", "cancelled", "nearby", "nearbyCount", "recognised", "hasRealPhoto"} {
					delete(facts, k)
				}
			}
			if tr.Slots != nil {
				slots = tr.Slots
			}
			if tr.HostAction != nil {
				hostAction = tr.HostAction
			}
			if tr.To != "" {
				move(tr.To, "tap:"+in.Tap)
			}
		}
	case in.Event != nil && in.Event.Type == "resume":
		// The browser lost the end of a reply (a dropped connection) and asks where things
		// stand. Nothing is said or moved: the current state, chips and progress come back.
		return s.finish(inst, id, slots, facts, recent, "", "", nil, unclassified, false)
	case in.Event != nil:
		memberLine = describeEvent(*in.Event)
		r := ApplyEvent(inst.State, *in.Event, slots, facts)
		slots = r.Slots
		for k, v := range r.Facts {
			facts[k] = v
		}
		if in.Event.Type == "signed_in" {
			facts["signedInByEvent"] = true
		}
		if r.To != "" {
			move(r.To, "event:"+in.Event.Type)
		}
	case strings.TrimSpace(in.Text) != "":
		t := strings.TrimSpace(in.Text)
		if len([]rune(t)) > 1500 {
			t = string([]rune(t)[:1500])
		}
		memberLine = t
		switch DetectCommand(t) {
		case "cancel":
			slots = Slots{}
			move("CANCELLED", "command:cancel")
		case "help":
			move("HELP", "command:help")
		case "volunteers":
			hostAction = &HostAction{Type: "open", Target: "/help"}
			move("HELP", "command:volunteers")
		default:
			p := s.parseTyped(inst.State, t, slots, facts)
			if p.hostAction != nil {
				hostAction = p.hostAction
			}
			if p.slots != nil {
				slots = p.slots
			}
			for k, v := range p.factUpd {
				facts[k] = v
			}
			if p.to != "" {
				move(p.to, "rules")
			} else if p.decide {
				out := s.decide(ctx, inst, id, in.IP, t, slots, facts, recent, onDelta)
				slots = out.slots
				for k, v := range out.facts {
					facts[k] = v
				}
				if out.understood == false && out.decided {
					unclassified++
				} else if out.decided {
					unclassified = 0
				}
				if out.abusive {
					s.Strikes.Record(id.Key)
					facts["abusive"] = true
				}
				if out.say != "" {
					return s.finish(inst, id, slots, facts, recent, memberLine, out.say, hostAction, unclassified, out.fallback)
				}
				fallback = out.fallback
			}
		}
	}

	// Compose what Freegle says in the (possibly new) state.
	if lvl := EscalationLevel(unclassified); lvl > 0 {
		instruction = EscalationInstruction(lvl)
	}
	if facts.truthy("abusive") {
		instruction = "Their last message was abusive. One plain line saying that is not something Freegle can help with, then back to what Freegle can do. Nothing more."
		delete(facts, "abusive")
	}
	if p, ok := facts["itemProblem"].(string); ok {
		instruction += " The item they typed is not enough on its own (" + p + "). Ask again, kindly, with an example of what would work."
		delete(facts, "itemProblem")
	}
	if facts.truthy("postFailed") {
		instruction += " Posting did not go through. Say so in one plain line and offer to try again; nothing they typed is lost."
		delete(facts, "postFailed")
	}
	if em, ok := facts["emailInUse"].(string); ok && em != "" {
		instruction += " The email " + em + " already has a Freegle account. Say so in one line and that signing in will do it; the app shows a Sign in button."
		if hostAction == nil {
			hostAction = &HostAction{Type: "sign_in", Email: em}
		}
		delete(facts, "emailInUse")
	}
	if lapsed != "" {
		facts["leftOff"] = lapsed
		instruction += " They were part way through " + lapsed + " more than an hour ago and have come back. Welcome them back in a few words, say what was left unfinished, and that they can pick it up or do something else."
	}
	wasCancelled := facts.truthy("cancelled")
	if wasCancelled {
		instruction += " They stopped what they were doing. Acknowledge in a few words and mention the main things you can help with."
		delete(facts, "cancelled")
	}
	say, fb := s.compose(ctx, inst, id, in.IP, slots, facts, recent, instruction, onDelta)
	if fb && lapsed != "" {
		say = "Welcome back. We'd got part way through " + lapsed + ". Tap Give or Ask to pick that up, or tell me what you're after."
	}
	if fb && wasCancelled {
		// The hub line reads oddly straight after a cancel; the cancelled line fits.
		say = TemplateFor("CANCELLED")
	}
	return s.finish(inst, id, slots, facts, recent, memberLine, say, hostAction, unclassified, fallback || fb)
}

type decisionOut struct {
	slots      Slots
	facts      Facts
	say        string
	understood bool
	abusive    bool
	decided    bool
	fallback   bool
}

func (s *Service) allowed(id Identity, ip string) bool {
	if s.Strikes.Locked(id.Key) {
		return false
	}
	return !id.Throttled && s.Quota.Allow(id.Key, ip, id.UserID > 0)
}

// decide runs a decision turn through the engine.
func (s *Service) decide(ctx context.Context, inst *Instance, id Identity, ip, text string, slots Slots, facts Facts, recent []recentLine, onDelta func(string)) decisionOut {
	out := decisionOut{slots: slots, facts: Facts{}, understood: true}
	if !s.allowed(id, ip) {
		out.fallback = true
		if s.Strikes.Locked(id.Key) {
			out.say = templateAbuse
		}
		return out
	}
	inst.Context["facts"] = map[string]interface{}(facts)
	inst.Context["slots"] = map[string]interface{}(slots)
	inst.Context["recent"] = recentToAny(recent)
	inst.Context["lastMember"] = text
	before := inst.State
	ctx, cancel := context.WithTimeout(ctx, llmDeadline)
	defer cancel()
	d, err := s.Engine.Decide(ctx, inst, map[string]interface{}{"type": "message", "text": text}, onDelta)
	if err != nil {
		s.logf("decision failed: %v", err)
		out.fallback = true
		return out
	}
	out.decided = true
	if s.Engine.Workflow.States[inst.State].NodeType == "end" {
		// The model may end a flow; the conversation itself stays open at the hub.
		facts["cancelled"] = true
		_ = s.Engine.Force(inst, "HUB", "end")
	}
	if u, ok := d.ContextUpdates["understood"].(bool); ok {
		out.understood = u
	}
	if a, ok := d.ContextUpdates["abusive"].(bool); ok && a {
		out.abusive = true
	}
	newSlots := sanitiseSlots(d.ContextUpdates["newSlots"], text, facts)
	merged := cloneSlots(slots)
	for k, v := range newSlots {
		merged[k] = v
	}
	out.slots = merged
	say, _ := d.ContextUpdates["say"].(string)
	say = strings.TrimSpace(say)
	// If the model filled the answer to the question this state asks, move on to the
	// first question still open; otherwise stay and ask it, echoing what is known.
	if InFlow(inst.State) && len(newSlots) > 0 && !StateNeeded(inst.State, merged, facts) {
		next := NextAfter(inst.State, merged, facts)
		if flowIndex(next) > flowIndex(inst.State) {
			if err := s.Engine.Transition(inst, next, "slots"); err != nil {
				s.logf("refused slots move: %v", err)
			} else {
				say = "" // compose afresh in the new state
			}
		}
	}
	if say != "" && inst.State != before && !InFlow(before) && InFlow(inst.State) && len(newSlots) > 0 {
		// Entered a flow from the hub with details already understood: let the new
		// state's question be composed with those slots rather than trusting the
		// decision turn's wording, which was written before the skip logic ran.
		say = ""
	}
	if say != "" {
		vocab := AllowedVocabulary(map[string]interface{}{"facts": facts, "slots": merged}, text, FactSheet)
		if ok, reasons := CheckReply(say, vocab, inst.State); !ok {
			s.logf("decision reply failed check: %v", reasons)
			say = ""
		}
	}
	for _, k := range []string{"newSlots", "say", "understood", "abusive", "lastMember"} {
		delete(inst.Context, k)
	}
	out.say = say
	return out
}

// compose asks the model to say the next thing in the current state, checking it and
// retrying once before falling back to the template line.
func (s *Service) compose(ctx context.Context, inst *Instance, id Identity, ip string, slots Slots, facts Facts, recent []recentLine, instruction string, onDelta func(string)) (string, bool) {
	state := inst.State
	lastMember, lastSaid := "", ""
	for i := len(recent) - 1; i >= 0; i-- {
		if recent[i].Who == "member" && lastMember == "" {
			lastMember = recent[i].Text
		}
		if recent[i].Who == "freegle" && lastSaid == "" {
			lastSaid = recent[i].Text
		}
	}
	if s.Strikes.Locked(id.Key) {
		return templateAbuse, true
	}
	if id.Throttled || !s.Quota.Allow(id.Key, ip, id.UserID > 0) {
		return WarmTemplateAfter(state, lastSaid, slots, facts), true
	}
	ctx, cancel := context.WithTimeout(ctx, llmDeadline)
	defer cancel()
	context := map[string]interface{}{"facts": facts, "slots": slots, "recent": recentToAny(recent)}
	note := instruction
	for attempt := 0; attempt < 2; attempt++ {
		system, user := BuildComposePrompt(s.Engine.Workflow, state, context, note)
		var deltaFn func(string)
		if attempt == 0 {
			deltaFn = onDelta
		}
		raw, err := s.Engine.LLM.Call(ctx, system, user, deltaFn)
		if err != nil {
			s.logf("compose failed: %v", err)
			return WarmTemplateAfter(state, lastSaid, slots, facts), true
		}
		say := ParseSay(raw)
		if say == "" {
			continue
		}
		vocab := AllowedVocabulary(map[string]interface{}{"facts": facts, "slots": slots}, lastMember, FactSheet)
		ok, reasons := CheckReply(say, vocab, state)
		if ok {
			return say, false
		}
		s.logf("compose reply failed check: %v", reasons)
		note = instruction + " Your previous reply was rejected for: " + strings.Join(reasons, ", ") + ". Write it again using only the facts given, no exclamation marks, no promises."
	}
	return WarmTemplateAfter(state, lastSaid, slots, facts), true
}

func (s *Service) finish(inst *Instance, id Identity, slots Slots, facts Facts, recent []recentLine, memberLine, say string, hostAction *HostAction, unclassified int, fallback bool) (*TurnResult, error) {
	state := inst.State
	chips := ChipsFor(state, slots, facts)
	action := hostAction
	if action == nil {
		action = HostActionForState(state, slots, facts)
	}
	if action != nil && action.Type == "create_post" {
		// The browser posts once per key, however many turns re-ask while posting.
		action.Key = inst.UUID + ":" + state
	}
	progress := ProgressFor(state, slots, facts)
	if memberLine != "" {
		recent = append(recent, recentLine{"member", memberLine})
	}
	if say != "" {
		recent = append(recent, recentLine{"freegle", say})
	}
	for len(recent) > recentLimit {
		recent = recent[1:]
	}
	widget := map[string]interface{}{"chips": chips, "progress": progress}
	if action != nil {
		widget["hostAction"] = map[string]interface{}{"type": action.Type}
	}
	var turns []TranscriptTurn
	if memberLine != "" {
		turns = append(turns, TranscriptTurn{Who: "member", Text: memberLine})
	}
	if say != "" {
		turns = append(turns, TranscriptTurn{Who: "freegle", Text: say, Widget: widget})
	}
	pending := append(toTurns(inst.Context["pendingTranscript"]), turns...)
	res := &TurnResult{Conversation: inst.UUID, State: state, Say: say, Chips: chips, Progress: progress, HostAction: action, Slots: slots, Facts: publicFacts(facts), Fallback: fallback, Widget: widget}
	if id.UserID > 0 && len(pending) > 0 && s.Transcript != nil {
		chatid, ids := s.Transcript.Write(id.UserID, pending)
		if chatid > 0 {
			res.ChatID = chatid
			res.MessageIDs = ids
			pending = nil
		}
	}
	if len(pending) > 40 {
		pending = pending[len(pending)-40:]
	}
	if chips == nil {
		res.Chips = []Chip{}
	}
	inst.Context["facts"] = map[string]interface{}(facts)
	inst.Context["slots"] = map[string]interface{}(slots)
	inst.Context["recent"] = recentToAny(recent)
	inst.Context["unclassified"] = float64(unclassified)
	inst.Context["pendingTranscript"] = turnsToAny(pending)
	turn := 0
	if v, ok := inst.Context["turn"].(float64); ok {
		turn = int(v)
	}
	inst.Context["turn"] = float64(turn + 1)
	if err := s.Engine.Store.Save(inst); err != nil {
		return nil, err
	}
	return res, nil
}

func describeEvent(ev Event) string {
	switch ev.Type {
	case "photo_added":
		if len(ev.Recognised) > 0 {
			return "Added a photo (looks like: " + strings.Join(ev.Recognised, ", ") + ")"
		}
		return "Added a photo"
	case "postcode_confirmed":
		if ev.Name != "" {
			return ev.Postcode + " (" + ev.Name + ")"
		}
		return ev.Postcode
	case "email_confirmed", "email_in_use":
		return ev.Email
	case "posted":
		return "Posted"
	case "post_failed":
		return "Post failed"
	case "joined":
		if ev.Community != "" {
			return "Joined " + ev.Community
		}
		return "Joined a community"
	case "signed_in":
		return "Signed in"
	}
	return ""
}

var slotWordSplit = regexp.MustCompile(`[^a-z0-9£]+`)

// Only slot values made of words the member (or the app) actually used survive.
func sanitiseSlots(raw interface{}, text string, facts Facts) Slots {
	out := Slots{}
	m, ok := raw.(map[string]interface{})
	if !ok {
		return out
	}
	bag := map[string]bool{}
	for _, w := range slotWordSplit.Split(strings.ToLower(text), -1) {
		if w != "" {
			bag[w] = true
		}
	}
	for _, r := range facts.strings("recognised") {
		for _, w := range slotWordSplit.Split(strings.ToLower(r), -1) {
			if w != "" {
				bag[w] = true
			}
		}
	}
	filler := map[string]bool{"a": true, "an": true, "the": true, "of": true, "and": true, "for": true, "in": true, "with": true}
	echo := func(v interface{}, max int) string {
		sv, ok := v.(string)
		if !ok {
			return ""
		}
		sv = strings.TrimSpace(sv)
		if r := []rune(sv); len(r) > max {
			sv = string(r[:max])
		}
		if sv == "" {
			return ""
		}
		ws := slotWordSplit.Split(strings.ToLower(sv), -1)
		total, known := 0, 0
		for _, w := range ws {
			if w == "" {
				continue
			}
			total++
			if bag[w] || filler[w] || regexp.MustCompile(`^\d+$`).MatchString(w) {
				known++
			}
		}
		if total == 0 || float64(known) < float64(total)*0.6 {
			return ""
		}
		return sv
	}
	if item := echo(m["item"], 60); item != "" && !IsUnpostableItem(item) {
		out["item"] = item
	}
	if typed := echo(m["typedItem"], 80); typed != "" {
		out["typedItem"] = typed
	}
	if desc := echo(m["description"], 400); desc != "" {
		out["description"] = desc
	}
	if q, ok := m["quantity"].(float64); ok && q >= 1 && q <= 99 && q == float64(int(q)) {
		out["quantity"] = int(q)
	}
	if pcv, ok := m["postcode"].(string); ok {
		if pc := ExtractPostcode(pcv); pc != "" && ExtractPostcode(text) == pc {
			out["postcode"] = pc
		}
	}
	if emv, ok := m["email"].(string); ok {
		if em := ExtractEmail(emv); em != "" && ExtractEmail(text) == em {
			out["email"] = em
		}
	}
	if d, ok := m["delivery"].(bool); ok && d && regexp.MustCompile(`(?i)deliver|drop`).MatchString(text) {
		out["delivery"] = true
	}
	if sug, ok := m["suggestions"].([]interface{}); ok {
		var keep []string
		for _, x := range sug {
			if v := echo(x, 60); v != "" && !IsUnpostableItem(v) {
				keep = append(keep, v)
			}
			if len(keep) == 2 {
				break
			}
		}
		if len(keep) > 0 {
			out["suggestions"] = keep
		}
	}
	return out
}

func publicFacts(f Facts) Facts {
	out := cloneFacts(f)
	delete(out, "signedInByEvent")
	return out
}

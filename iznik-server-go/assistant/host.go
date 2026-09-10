package assistant

import (
	"strconv"
	"strings"
)

// What the host (browser) does at each state, and the deterministic parts of the flow:
// which chips exist, where a tap goes, which question comes next once a slot is filled,
// and which existing store action the browser must run. The model composes the words;
// none of this is up to it.

// Chip is a tappable option under a Freegle message. Kind tells the shell to render an
// input (photo, postcode, email) instead of a plain button.
type Chip struct {
	Value string `json:"value"`
	Label string `json:"label"`
	Kind  string `json:"kind,omitempty"`
}

// HostAction tells the browser to do something with its existing stores.
type HostAction struct {
	Type     string                 `json:"type"`
	PostType string                 `json:"postType,omitempty"`
	Slots    map[string]interface{} `json:"slots,omitempty"`
	Text     string                 `json:"text,omitempty"`
	Email    string                 `json:"email,omitempty"`
	Item     string                 `json:"item,omitempty"`
	Term     string                 `json:"term,omitempty"`
	Target   string                 `json:"target,omitempty"`
	// Key makes an action idempotent: the browser runs each key once.
	Key    string `json:"key,omitempty"`
	Filter string `json:"filter,omitempty"`
}

// Progress is the small line above the composer during a flow.
type Progress struct {
	Label string `json:"label"`
	Item  string `json:"item,omitempty"`
	Step  int    `json:"step"`
	Total int    `json:"total"`
}

var giveStates = []string{"GIVE_PHOTO", "GIVE_ITEM", "GIVE_DESCRIPTION", "GIVE_QUANTITY", "GIVE_WHERE", "GIVE_EMAIL", "GIVE_CONFIRM", "GIVE_POST", "GIVE_DONE"}
var askStates = []string{"ASK_ITEM", "ASK_MATCHES", "ASK_DESCRIPTION", "ASK_WHERE", "ASK_EMAIL", "ASK_CONFIRM", "ASK_POST", "ASK_DONE"}

// MainChips are the persistent actions above the composer.
var MainChips = []Chip{{Value: "give", Label: "Give something"}, {Value: "ask", Label: "Ask for something"}, {Value: "nearby", Label: "See what's nearby"}}

// Slots is what has been collected in the current flow.
type Slots map[string]interface{}

// Facts is what the model may rely on.
type Facts map[string]interface{}

func (s Slots) str(k string) string {
	if v, ok := s[k].(string); ok {
		return v
	}
	return ""
}

func (s Slots) has(k string) bool {
	_, ok := s[k]
	return ok
}

func (f Facts) str(k string) string {
	if v, ok := f[k].(string); ok {
		return v
	}
	return ""
}

func (f Facts) truthy(k string) bool {
	switch v := f[k].(type) {
	case bool:
		return v
	case string:
		return v != ""
	case nil:
		return false
	default:
		return true
	}
}

func (f Facts) strings(k string) []string {
	var out []string
	switch v := f[k].(type) {
	case []string:
		return v
	case []interface{}:
		for _, x := range v {
			if s, ok := x.(string); ok {
				out = append(out, s)
			}
		}
	}
	return out
}

// ChipsFor returns the chips for a state. At most three.
func ChipsFor(state string, slots Slots, facts Facts) []Chip {
	switch state {
	case "HUB", "CANCELLED":
		return MainChips
	case "GIVE_PHOTO":
		return []Chip{{Value: "add_photo", Label: "Add a photo", Kind: "photo"}, {Value: "no_photo", Label: "No photo"}}
	case "GIVE_ITEM", "ASK_ITEM":
		var out []Chip
		seen := map[string]bool{}
		candidates := []string{slots.str("typedItem")}
		candidates = append(candidates, facts.strings("recognised")...)
		if sug, ok := slots["suggestions"].([]interface{}); ok {
			for _, x := range sug {
				if s, ok := x.(string); ok {
					candidates = append(candidates, s)
				}
			}
		}
		if sug, ok := slots["suggestions"].([]string); ok {
			candidates = append(candidates, sug...)
		}
		for _, c := range candidates {
			c = strings.TrimSpace(c)
			key := strings.ToLower(c)
			if c == "" || seen[key] {
				continue
			}
			seen[key] = true
			out = append(out, Chip{Value: "item:" + c, Label: c})
			if len(out) == 3 {
				break
			}
		}
		return out
	case "GIVE_DESCRIPTION":
		if facts.truthy("hasRealPhoto") {
			return []Chip{{Value: "skip", Label: "Skip"}}
		}
		return nil
	case "ASK_DESCRIPTION":
		return []Chip{{Value: "skip", Label: "Skip"}}
	case "GIVE_QUANTITY":
		return []Chip{{Value: "qty:1", Label: "1"}, {Value: "qty:2", Label: "2"}, {Value: "qty:3+", Label: "3 or more"}}
	case "GIVE_WHERE", "ASK_WHERE", "NEARBY_WHERE", "COMMUNITY_WHERE":
		return []Chip{{Value: "postcode", Label: "Enter a postcode", Kind: "postcode"}}
	case "GIVE_EMAIL", "ASK_EMAIL":
		return []Chip{{Value: "email", Label: "Enter your email", Kind: "email"}}
	case "GIVE_CONFIRM", "ASK_CONFIRM":
		return []Chip{{Value: "post", Label: "Post it"}, {Value: "change", Label: "Change something"}}
	case "GIVE_DONE":
		return []Chip{{Value: "give", Label: "Give something else"}, {Value: "yourposts", Label: "See your posts"}}
	case "ASK_MATCHES":
		return []Chip{{Value: "carry_on", Label: "Carry on asking"}}
	case "ASK_DONE":
		return []Chip{{Value: "ask", Label: "Ask for something else"}, {Value: "nearby", Label: "See what's nearby"}}
	case "NEARBY":
		return []Chip{{Value: "offers", Label: "Offers"}, {Value: "wanted", Label: "Wanted"}, {Value: "nearest", Label: "Nearest"}}
	case "HELP":
		return []Chip{{Value: "how", Label: "How it works"}, {Value: "safety", Label: "Safety"}, {Value: "volunteers", Label: "Contact volunteers"}}
	}
	return nil
}

// NextAfter says which question comes next once the current one is answered, skipping
// anything already known.
func NextAfter(state string, slots Slots, facts Facts) string {
	give := strings.HasPrefix(state, "GIVE_")
	prefix := "ASK_"
	order := []string{"ITEM", "DESCRIPTION", "WHERE", "EMAIL", "CONFIRM"}
	if give {
		prefix = "GIVE_"
		order = []string{"PHOTO", "ITEM", "DESCRIPTION", "QUANTITY", "WHERE", "EMAIL", "CONFIRM"}
	}
	hasLocation := facts.truthy("locationKnown") || slots.str("postcode") != ""
	signedIn := facts.truthy("signedIn")
	needed := func(step string) bool {
		switch step {
		case "PHOTO":
			return !slots.has("photoDecided")
		case "ITEM":
			return slots.str("item") == ""
		case "DESCRIPTION":
			return !slots.has("description")
		case "QUANTITY":
			return give && !slots.has("quantity") && LooksPlural(strings.Join([]string{slots.str("item"), slots.str("typedItem"), slots.str("description")}, " "))
		case "WHERE":
			return !hasLocation
		case "EMAIL":
			return !signedIn && slots.str("email") == ""
		case "CONFIRM":
			return true
		}
		return false
	}
	current := strings.TrimPrefix(state, prefix)
	start := 0
	for i, s := range order {
		if s == current {
			start = i + 1
		}
	}
	for i := start; i < len(order); i++ {
		if needed(order[i]) {
			return prefix + order[i]
		}
	}
	return prefix + "CONFIRM"
}

// StateNeeded says whether the question a flow state asks is still unanswered.
func StateNeeded(state string, slots Slots, facts Facts) bool {
	switch state {
	case "GIVE_PHOTO":
		return !slots.has("photoDecided")
	case "GIVE_ITEM", "ASK_ITEM":
		return slots.str("item") == ""
	case "GIVE_DESCRIPTION", "ASK_DESCRIPTION":
		return !slots.has("description")
	case "GIVE_QUANTITY":
		return !slots.has("quantity")
	case "GIVE_WHERE", "ASK_WHERE", "NEARBY_WHERE", "COMMUNITY_WHERE":
		return !(facts.truthy("locationKnown") || slots.str("postcode") != "")
	case "GIVE_EMAIL", "ASK_EMAIL":
		return !facts.truthy("signedIn") && slots.str("email") == ""
	}
	return true
}

// TapResult is where a tapped chip takes the conversation.
type TapResult struct {
	To         string
	Reset      bool
	Slots      Slots
	HostAction *HostAction
}

// TargetForChip resolves a tap. Nil means the tap is not a transition (a filter chip).
func TargetForChip(state, value string, slots Slots, facts Facts) *TapResult {
	if value == "cancel" {
		return &TapResult{To: "CANCELLED"}
	}
	switch state {
	case "HUB", "CANCELLED", "GIVE_DONE", "ASK_DONE", "NEARBY", "HELP", "COMMUNITY":
		switch value {
		case "give":
			return &TapResult{To: "GIVE_PHOTO", Reset: true}
		case "ask":
			return &TapResult{To: "ASK_ITEM", Reset: true}
		case "nearby":
			if facts.truthy("locationKnown") {
				return &TapResult{To: "NEARBY", Reset: true}
			}
			return &TapResult{To: "NEARBY_WHERE", Reset: true}
		case "community":
			if facts.truthy("locationKnown") {
				return &TapResult{To: "COMMUNITY", Reset: true}
			}
			return &TapResult{To: "COMMUNITY_WHERE", Reset: true}
		case "help":
			return &TapResult{To: "HELP", Reset: true}
		case "yourposts":
			return &TapResult{HostAction: &HostAction{Type: "open", Target: "/chats/posts"}}
		}
	}
	s := cloneSlots(slots)
	switch state {
	case "GIVE_PHOTO":
		if value == "no_photo" {
			s["photoDecided"] = true
			return &TapResult{To: NextAfter(state, s, facts), Slots: s}
		}
		if value == "add_photo" {
			return &TapResult{HostAction: &HostAction{Type: "photo"}}
		}
	case "GIVE_ITEM", "ASK_ITEM":
		if strings.HasPrefix(value, "item:") {
			s["item"] = strings.TrimPrefix(value, "item:")
			return &TapResult{To: NextAfter(state, s, facts), Slots: s}
		}
	case "GIVE_DESCRIPTION", "ASK_DESCRIPTION":
		if value == "skip" {
			s["description"] = ""
			return &TapResult{To: NextAfter(state, s, facts), Slots: s}
		}
	case "GIVE_QUANTITY":
		if strings.HasPrefix(value, "qty:") {
			q := 3
			if v := strings.TrimPrefix(value, "qty:"); v != "3+" {
				q, _ = strconv.Atoi(v)
			}
			s["quantity"] = q
			return &TapResult{To: NextAfter(state, s, facts), Slots: s}
		}
	case "GIVE_CONFIRM", "ASK_CONFIRM":
		if value == "post" {
			post := strings.Replace(state, "CONFIRM", "POST", 1)
			return &TapResult{To: post, HostAction: HostActionForState(post, slots, facts)}
		}
		if value == "change" {
			return &TapResult{HostAction: &HostAction{Type: "edit"}}
		}
		if strings.HasPrefix(value, "edit:") {
			// Only the questions of the flow can be revisited; anything else is not a tap we offer.
			field := strings.ToUpper(strings.TrimPrefix(value, "edit:"))
			switch field {
			case "PHOTO", "ITEM", "DESCRIPTION", "QUANTITY", "WHERE", "EMAIL":
				return &TapResult{To: strings.Replace(state, "CONFIRM", field, 1)}
			}
			return nil
		}
	case "ASK_MATCHES":
		if value == "carry_on" {
			return &TapResult{To: NextAfter("ASK_ITEM", s, facts)}
		}
		if value == "sorted" {
			return &TapResult{To: "CANCELLED"}
		}
	}
	return nil
}

// Event is something the browser reports after doing something.
type Event struct {
	Type         string                   `json:"type"`
	AttachmentID uint64                   `json:"attachmentId,omitempty"`
	Recognised   []string                 `json:"recognised,omitempty"`
	Postcode     string                   `json:"postcode,omitempty"`
	Name         string                   `json:"name,omitempty"`
	Community    string                   `json:"community,omitempty"`
	Email        string                   `json:"email,omitempty"`
	Msgid        uint64                   `json:"msgid,omitempty"`
	Pending      bool                     `json:"pending,omitempty"`
	Newuser      bool                     `json:"newuser,omitempty"`
	Reason       string                   `json:"reason,omitempty"`
	Matches      []map[string]interface{} `json:"matches,omitempty"`
	Posts        []map[string]interface{} `json:"posts,omitempty"`
	Communities  []map[string]interface{} `json:"communities,omitempty"`
	Filter       string                   `json:"filter,omitempty"`
	LocationName string                   `json:"locationName,omitempty"`
}

// EventResult is the effect of an event on slots, facts and state.
type EventResult struct {
	To    string
	Slots Slots
	Facts Facts
}

func capMaps(in []map[string]interface{}, n int) []interface{} {
	out := []interface{}{}
	for i, m := range in {
		if i >= n {
			break
		}
		out = append(out, m)
	}
	return out
}

// ApplyEvent updates slots and facts for an event and says where the flow moves.
func ApplyEvent(state string, ev Event, slots Slots, facts Facts) EventResult {
	s := cloneSlots(slots)
	f := cloneFacts(facts)
	r := EventResult{Slots: s, Facts: f}
	switch ev.Type {
	case "photo_added":
		s["photoDecided"] = true
		var atts []interface{}
		if existing, ok := s["attachments"].([]interface{}); ok {
			atts = existing
		}
		if ev.AttachmentID > 0 {
			atts = append(atts, float64(ev.AttachmentID))
		}
		s["attachments"] = atts
		f["hasRealPhoto"] = true
		if len(ev.Recognised) > 0 {
			n := len(ev.Recognised)
			if n > 3 {
				n = 3
			}
			f["recognised"] = ev.Recognised[:n]
		}
		if state == "GIVE_PHOTO" {
			r.To = NextAfter(state, s, f)
		}
	case "postcode_confirmed":
		s["postcode"] = ev.Postcode
		f["locationKnown"] = true
		if ev.Name != "" {
			f["locationName"] = ev.Name
		} else {
			f["locationName"] = ev.Postcode
		}
		if ev.Community != "" {
			f["community"] = ev.Community
		}
		switch {
		case state == "NEARBY_WHERE":
			r.To = "NEARBY"
		case state == "COMMUNITY_WHERE":
			r.To = "COMMUNITY"
		case IsQuestion(state):
			// A postcode typed while another question was open: kept, and the flow
			// moves on only past questions now answered.
			r.To = NextAfter(state, s, f)
		}
	case "email_confirmed":
		s["email"] = ev.Email
		if IsQuestion(state) {
			r.To = NextAfter(state, s, f)
		}
	case "email_in_use":
		f["emailInUse"] = ev.Email
	case "posted":
		community := ev.Community
		if community == "" {
			if c, ok := f["community"].(string); ok {
				community = c
			}
		}
		f["posted"] = map[string]interface{}{"msgid": float64(ev.Msgid), "community": community, "pending": ev.Pending, "newuser": ev.Newuser}
		if ev.Newuser {
			f["signedIn"] = true
		}
		r.To = strings.Replace(state, "POST", "DONE", 1)
	case "post_failed":
		reason := ev.Reason
		if reason == "" {
			reason = "failed"
		}
		f["postFailed"] = reason
		// Back to the card, where Post it is the way to try again.
		r.To = strings.Replace(state, "POST", "CONFIRM", 1)
	case "matches":
		m := capMaps(ev.Matches, 3)
		f["matches"] = m
		if state == "ASK_ITEM" && len(m) > 0 {
			r.To = "ASK_MATCHES"
		}
	case "nearby":
		f["nearby"] = capMaps(ev.Posts, 8)
		f["nearbyFilter"] = ev.Filter
	case "communities":
		f["communities"] = capMaps(ev.Communities, 5)
	case "joined":
		joined := facts.strings("joined")
		if ev.Community != "" {
			joined = append(joined, ev.Community)
		}
		f["joined"] = joined
	case "signed_in":
		f["signedIn"] = true
		if ev.Name != "" {
			f["memberName"] = ev.Name
		}
		if ev.LocationName != "" {
			f["locationKnown"] = true
			f["locationName"] = ev.LocationName
		}
		if ev.Community != "" {
			f["community"] = ev.Community
		}
	}
	return r
}

// HostActionForState says what the browser must do on arriving in a state.
func HostActionForState(state string, slots Slots, facts Facts) *HostAction {
	switch state {
	case "GIVE_POST":
		return &HostAction{Type: "create_post", PostType: "Offer", Slots: slots}
	case "ASK_POST":
		return &HostAction{Type: "create_post", PostType: "Wanted", Slots: slots}
	case "ASK_ITEM":
		if item := slots.str("item"); item != "" {
			return &HostAction{Type: "find_matches", Item: item}
		}
	case "NEARBY":
		filter, _ := facts["nearbyFilter"].(string)
		return &HostAction{Type: "list_nearby", Filter: filter}
	case "COMMUNITY":
		return &HostAction{Type: "list_communities"}
	case "GIVE_CONFIRM":
		return &HostAction{Type: "confirm_card", PostType: "Offer", Slots: slots}
	case "ASK_CONFIRM":
		return &HostAction{Type: "confirm_card", PostType: "Wanted", Slots: slots}
	}
	return nil
}

// InFlow says whether the state is inside a give or ask flow.
func InFlow(state string) bool {
	for _, s := range giveStates {
		if s == state {
			return true
		}
	}
	for _, s := range askStates {
		if s == state {
			return true
		}
	}
	return false
}

func flowIndex(state string) int {
	list := askStates
	if strings.HasPrefix(state, "GIVE_") {
		list = giveStates
	}
	for i, s := range list {
		if s == state {
			return i
		}
	}
	return -1
}

// ProgressFor returns the progress line inside a flow, nil elsewhere.
// IsQuestion says whether a state asks the member something a flow needs (so an answer
// arriving by event may move it on). Hubs, lists, posting and done states are not.
func IsQuestion(state string) bool {
	if state == "ASK_MATCHES" {
		return true
	}
	for _, s := range giveSteps {
		if s == state {
			return true
		}
	}
	for _, s := range askSteps {
		if s == state {
			return true
		}
	}
	return false
}

// The questions of each flow, in the order they are asked.
var giveSteps = []string{"GIVE_PHOTO", "GIVE_ITEM", "GIVE_DESCRIPTION", "GIVE_QUANTITY", "GIVE_WHERE", "GIVE_EMAIL", "GIVE_CONFIRM"}
var askSteps = []string{"ASK_ITEM", "ASK_DESCRIPTION", "ASK_WHERE", "ASK_EMAIL", "ASK_CONFIRM"}

// answered reports whether the member has given the answer a step asks for.
func answered(state string, slots Slots) bool {
	switch state {
	case "GIVE_PHOTO":
		return slots.has("photoDecided")
	case "GIVE_ITEM", "ASK_ITEM":
		return slots.str("item") != ""
	case "GIVE_DESCRIPTION", "ASK_DESCRIPTION":
		return slots.has("description")
	case "GIVE_QUANTITY":
		return slots.has("quantity")
	case "GIVE_WHERE", "ASK_WHERE":
		return slots.str("postcode") != ""
	case "GIVE_EMAIL", "ASK_EMAIL":
		return slots.str("email") != ""
	}
	return false
}

// ProgressFor is the "Giving · sofa · 3 of 6" line under the transcript. The total is the
// number of questions this member will actually meet: steps already answered and steps still
// needed count, steps that will be skipped (email when signed in, where when the location is
// already known) do not. Nil once the post has gone, so the composer's actions come back.
func ProgressFor(state string, slots Slots, facts Facts) *Progress {
	if !InFlow(state) {
		return nil
	}
	steps, label := askSteps, "Asking"
	if strings.HasPrefix(state, "GIVE_") {
		steps, label = giveSteps, "Giving"
	}
	probe := state
	switch state {
	case "ASK_MATCHES":
		probe = "ASK_ITEM"
	case "GIVE_POST":
		probe = "GIVE_CONFIRM"
	case "ASK_POST":
		probe = "ASK_CONFIRM"
	}
	cur := -1
	for i, s := range steps {
		if s == probe {
			cur = i
		}
	}
	if cur < 0 {
		return nil
	}
	step, total := 0, 0
	for i, s := range steps {
		counts := i == cur || (i < cur && answered(s, slots)) || (i > cur && StateNeeded(s, slots, facts))
		if !counts {
			continue
		}
		total++
		if i <= cur {
			step++
		}
	}
	return &Progress{Label: label, Item: slots.str("item"), Step: step, Total: total}
}

func cloneSlots(s Slots) Slots {
	out := Slots{}
	for k, v := range s {
		out[k] = v
	}
	return out
}

func cloneFacts(f Facts) Facts {
	out := Facts{}
	for k, v := range f {
		out[k] = v
	}
	return out
}

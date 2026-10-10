package assistant

import (
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
)

// ResponseContract tells the model what to put in contextUpdates on a decision turn.
const ResponseContract = `
## What to put in contextUpdates (required every time)
Put contextUpdates FIRST in your JSON, and inside it put "say" first, so the member sees your words as they arrive.
- "say": what Freegle says to the member. In character. One or two sentences unless the task says otherwise.
- "newSlots": an object with only the things you newly understood from their message, using these keys when they apply: item (a short title, a few words), description, quantity (a number), postcode, email, delivery (true if they said they could deliver), typedItem (their own words for the item, if they named it), suggestions (up to two cleaner titles you would offer as chips, built only from words they used or the app recognised).
- "understood": false if their message was not something Freegle can act on (off the point, nonsense, a joke, small talk), otherwise true.
- "abusive": true only if the message is abusive, threatening or obscene.
Do not put anything else in contextUpdates. Never request actions; the app performs everything.
Propose a transition only when the facts clearly call for one of the listed next states; otherwise null.
`

// Guardrails is the block the engine puts into every decision prompt.
func Guardrails() string {
	return strings.Join([]string{Character, "## Freegle facts you may rely on", FactSheet, ResponseContract}, "\n")
}

// BuildComposePrompt builds a composition-only prompt: Freegle has just arrived in a
// state and needs to say the next thing; no transition is decided.
func BuildComposePrompt(w *WorkflowDefinition, state string, context map[string]interface{}, instruction string) (system, user string) {
	def := w.States[state]
	var b strings.Builder
	b.WriteString("You are composing one message from Freegle to a member. Follow these rules exactly.\n\n")
	fmt.Fprintf(&b, "## Current State: %s\n%s\n\n", state, def.Description)
	if def.Prompt != "" {
		fmt.Fprintf(&b, "## Your Task\n%s\n\n", def.Prompt)
	}
	fmt.Fprintf(&b, "## How Freegle talks (apply always)\n%s\n\n## Freegle facts you may rely on\n%s\n\n", Character, FactSheet)
	b.WriteString("## Response Format\nRespond with valid JSON only: {\"say\": \"what Freegle says\"}. Put \"say\" first. Nothing else.\n")
	b.WriteString(volatileMarker)
	ctxJSON, _ := json.MarshalIndent(context, "", "  ")
	fmt.Fprintf(&b, "```json\n%s\n```\n", string(ctxJSON))
	if instruction != "" {
		fmt.Fprintf(&b, "\n## Note\n%s\n", instruction)
	}
	return b.String(), "Compose the message now. Respond with the JSON object."
}

var sayRe = regexp.MustCompile(`"say"\s*:\s*"((?:[^"\\]|\\.)*)"`)

// ParseSay reads {"say": "..."} tolerating fences and stray prose.
func ParseSay(raw string) string {
	cleaned := strings.TrimSpace(raw)
	cleaned = strings.TrimPrefix(cleaned, "```json")
	cleaned = strings.TrimPrefix(cleaned, "```")
	cleaned = strings.TrimSuffix(cleaned, "```")
	cleaned = strings.TrimSpace(cleaned)
	var obj struct {
		Say string `json:"say"`
	}
	if err := json.Unmarshal([]byte(cleaned), &obj); err == nil && obj.Say != "" {
		return strings.TrimSpace(obj.Say)
	}
	if m := sayRe.FindStringSubmatch(cleaned); m != nil {
		var s string
		if err := json.Unmarshal([]byte(`"`+m[1]+`"`), &s); err == nil {
			return strings.TrimSpace(s)
		}
		return strings.TrimSpace(m[1])
	}
	return ""
}

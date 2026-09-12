package assistant

import (
	"strings"
	"testing"
)

func TestAsstSayExtractorStreamsAndUnescapes(t *testing.T) {
	var out []string
	x := NewSayExtractor(func(s string) { out = append(out, s) })
	js := `{"contextUpdates": {"say": "That's posted, all around \"Edinburgh\".\nLovely.", "newSlots": {"item": "sofa"}}, "proposedTransition": null}`
	for i := 0; i < len(js); i += 7 {
		end := i + 7
		if end > len(js) {
			end = len(js)
		}
		x.Push(js[i:end])
	}
	got := strings.Join(out, "")
	want := "That's posted, all around \"Edinburgh\".\nLovely."
	if got != want {
		t.Fatalf("got %q want %q", got, want)
	}
}

func TestAsstSayExtractorNothingWithoutSay(t *testing.T) {
	var out []string
	x := NewSayExtractor(func(s string) { out = append(out, s) })
	x.Push(`{"reasoning": "x", "proposedTransition": null}`)
	if len(out) != 0 {
		t.Fatal("nothing should stream")
	}
}

func TestAsstSplitSystem(t *testing.T) {
	a, b := SplitSystem("stable" + volatileMarker + "changing")
	if a != "stable" || !strings.HasPrefix(b, volatileMarker) {
		t.Fatal("split")
	}
}

func TestAsstParseDecisionAndSay(t *testing.T) {
	d, err := ParseDecision("```json\n{\"contextUpdates\":{\"say\":\"Hi\"},\"reasoning\":\"r\",\"actions\":[],\"proposedTransition\":\"GIVE_PHOTO\"}\n```")
	if err != nil || d.ContextUpdates["say"] != "Hi" || *d.ProposedTransition != "GIVE_PHOTO" {
		t.Fatalf("parse decision: %v %+v", err, d)
	}
	if _, err := ParseDecision("not json"); err == nil {
		t.Fatal("expected error")
	}
	if ParseSay(`{"say": "Hello there"}`) != "Hello there" || ParseSay("prose {\"say\": \"Hi\"} more") != "Hi" || ParseSay("nope") != "" {
		t.Fatal("parse say")
	}
}

func TestAsstSystemBlocksPrefixFirst(t *testing.T) {
	t.Setenv("ANTHROPIC_API_KEY", "")
	t.Setenv("ANTHROPIC_AUTH_TOKEN", "token")
	t.Setenv("ASSISTANT_SYSTEM_PREFIX", "You are Claude Code, Anthropic's official CLI for Claude.")
	l := NewAnthropicLLM()
	if l == nil {
		t.Fatal("a bearer token alone should build the client")
	}
	blocks := l.systemBlocks("stable part" + volatileMarker + "volatile part")
	if len(blocks) != 3 || blocks[0].Text != l.Prefix || blocks[1].CacheControl.Type == "" || blocks[2].Text == "" {
		t.Fatalf("want prefix, cached stable, volatile; got %+v", blocks)
	}
	l.Prefix = ""
	if got := l.systemBlocks("only stable"); len(got) != 1 || got[0].Text != "only stable" {
		t.Fatalf("no prefix should mean a single cached block, got %+v", got)
	}
	t.Setenv("ANTHROPIC_AUTH_TOKEN", "")
	if NewAnthropicLLM() != nil {
		t.Fatal("no credential should mean no client")
	}
}

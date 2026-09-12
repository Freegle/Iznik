package assistant

import "testing"

func TestAsstTemplateAfterAvoidsRepeat(t *testing.T) {
	first := TemplateFor("GIVE_ITEM")
	if TemplateAfter("GIVE_ITEM", "") != first {
		t.Fatal("first time through, the ordinary line")
	}
	if TemplateAfter("GIVE_ITEM", "something else") != first {
		t.Fatal("a different last line means the ordinary line")
	}
	again := TemplateAfter("GIVE_ITEM", first)
	if again == first || again == "" {
		t.Fatalf("repeat should rephrase, got %q", again)
	}
	if TemplateAfter("HUB", TemplateFor("HUB")) != templateSteer {
		t.Fatal("a repeated hub line gives way to the steer")
	}
	for state := range templatesAgain {
		if _, ok := templates[state]; !ok {
			t.Fatalf("second form for unknown state %s", state)
		}
		if templatesAgain[state] == templates[state] {
			t.Fatalf("second form for %s is the first form", state)
		}
	}
}

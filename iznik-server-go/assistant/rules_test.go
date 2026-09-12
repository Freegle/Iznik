package assistant

import "testing"

func TestAsstUnpostableItems(t *testing.T) {
	cases := map[string]bool{"123": true, "£20": true, "anything": true, "Stuff": true, "3 seater sofa": false, "anything blue": false, "Kinderwagen": false}
	for in, want := range cases {
		if got := IsUnpostableItem(in); got != want {
			t.Errorf("IsUnpostableItem(%q) = %v, want %v", in, got, want)
		}
	}
}

func TestAsstPluralAndQuantity(t *testing.T) {
	if !LooksPlural("4 dining chairs") || !LooksPlural("set of pans") || LooksPlural("grey sofa") {
		t.Fatal("plural detection wrong")
	}
	if ParseQuantity("there are four of them") != 4 || ParseQuantity("a couple") != 2 || ParseQuantity("12 mugs") != 12 || ParseQuantity("lovely") != 0 {
		t.Fatal("quantity parsing wrong")
	}
}

func TestAsstPostcodeAndEmail(t *testing.T) {
	if ExtractPostcode("it is in eh3 6ss near the shops") != "EH3 6SS" || ExtractPostcode("SW1A1AA") != "SW1A 1AA" || ExtractPostcode("no code") != "" {
		t.Fatal("postcode extraction wrong")
	}
	if ExtractEmail("mail me at Jane.Doe@Example.co.uk please") != "jane.doe@example.co.uk" {
		t.Fatal("email extraction wrong")
	}
}

func TestAsstCommandsAndIntents(t *testing.T) {
	if DetectCommand("Cancel") != "cancel" || DetectCommand("start again") != "cancel" || DetectCommand("can I talk to a volunteer") != "volunteers" || DetectCommand("I want to give away a sofa") != "" {
		t.Fatal("command detection wrong")
	}
	intents := map[string]string{"I'm giving away a sofa": "give", "looking for a bike for my son": "ask", "what's nearby": "nearby", "how do I join my local group": "community", "is it free?": "help", "asdfgh": ""}
	for in, want := range intents {
		if got := DetectIntent(in); got != want {
			t.Errorf("DetectIntent(%q) = %q, want %q", in, got, want)
		}
	}
}

func TestAsstLooksPluralCastsAWiderNet(t *testing.T) {
	for _, s := range []string{"a couple of lamps", "sofas", "kids bikes", "few plates", "6 pieces", "assorted tools"} {
		if !LooksPlural(s) {
			t.Errorf("%q should read as more than one", s)
		}
	}
	for _, s := range []string{"glass vase", "bus pass", "chess set", "christmas tree", "tennis racket", "mattress", "grey sofa"} {
		if LooksPlural(s) {
			t.Errorf("%q is one thing", s)
		}
	}
}

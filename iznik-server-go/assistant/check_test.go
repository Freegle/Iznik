package assistant

import (
	"strings"
	"testing"
)

var checkFacts = map[string]interface{}{"memberName": "Edward", "community": "Edinburgh Freegle", "slots": map[string]interface{}{"item": "grey sofa", "postcode": "EH3 6SS"}}

func vocab(recent string, extra map[string]interface{}) Vocabulary {
	f := map[string]interface{}{}
	for k, v := range checkFacts {
		f[k] = v
	}
	for k, v := range extra {
		f[k] = v
	}
	return AllowedVocabulary(f, recent, FactSheet)
}

func TestAsstGroundedReplyPasses(t *testing.T) {
	ok, reasons := CheckReply("That's with your local freeglers now, all around Edinburgh Freegle. When someone's keen you'll hear from them right here.", vocab("", nil), "GIVE_DONE")
	if !ok {
		t.Fatalf("expected pass, got %v", reasons)
	}
}

func TestAsstStyleFailures(t *testing.T) {
	for _, s := range []string{"Great news! Posted.", "Posted 🎉", "Your item has been successfully posted.", "I am unable to do that."} {
		if ok, _ := CheckReply(s, vocab("", nil), ""); ok {
			t.Errorf("expected %q to fail", s)
		}
	}
}

func TestAsstFabricationFails(t *testing.T) {
	ok, reasons := CheckReply("Jane will collect it on Tuesday, about 4 miles away.", vocab("", nil), "")
	if ok {
		t.Fatal("expected failure")
	}
	joined := strings.Join(reasons, ",")
	if !strings.Contains(joined, "name:jane") || !strings.Contains(joined, "number:4") {
		t.Fatalf("expected name and number reasons, got %v", reasons)
	}
}

func TestAsstFactsAndMemberWordsPass(t *testing.T) {
	ok, reasons := CheckReply("Jane's interested in your sofa; she's about 4 miles away.", vocab("", map[string]interface{}{"replies": []interface{}{map[string]interface{}{"name": "Jane", "miles": 4}}}), "")
	if !ok {
		t.Fatalf("expected pass, got %v", reasons)
	}
	ok, reasons = CheckReply("Two chairs, lovely. Have you got a photo?", vocab("two chairs to go", nil), "")
	if !ok {
		t.Fatalf("expected pass, got %v", reasons)
	}
}

func TestAsstPromisesFail(t *testing.T) {
	for _, s := range []string{"We'll deliver it by tomorrow.", "Someone will collect it within 2 hours."} {
		if ok, _ := CheckReply(s, vocab("", nil), ""); ok {
			t.Errorf("expected %q to fail", s)
		}
	}
}

func TestAsstLengthLimit(t *testing.T) {
	long := strings.Repeat("x", 400)
	if ok, _ := CheckReply(long, vocab("", nil), ""); ok {
		t.Fatal("expected too long to fail")
	}
	if ok, _ := CheckReply(long, vocab("", nil), "HELP"); !ok {
		t.Fatal("HELP allows longer replies")
	}
}

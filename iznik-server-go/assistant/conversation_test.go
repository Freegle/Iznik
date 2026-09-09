package assistant

import (
	"context"
	"strings"
	"testing"
	"time"
)

func newTestService(llm *FakeLLM) *Service {
	e := newTestEngine(llm)
	return &Service{Engine: e, Quota: NewQuota(1000, 1000, 1000, nil), Strikes: NewStrikes(3, time.Hour, nil), Log: func(string, ...interface{}) {}}
}

var member = Identity{Key: "u:1", UserID: 1, Name: "Edward", LocationName: "EH3 6SS", Community: "Edinburgh Freegle"}
var visitor = Identity{Key: "a:abc"}

func say(s string) string { return `{"say":"` + s + `"}` }

func TestAsstTapGivePhotoNoPhotoLandsOnItem(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo? Posts with one get far more interest."), say("What is it, in a few words?")}}
	s := newTestService(llm)
	ctx := context.Background()
	r1, err := s.Turn(ctx, member, TurnInput{Tap: "give"}, nil)
	if err != nil || r1.State != "GIVE_PHOTO" {
		t.Fatalf("give tap: %v %+v", err, r1)
	}
	if r1.Progress == nil || r1.Progress.Label != "Giving" {
		t.Fatal("progress line during a flow")
	}
	r2, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "no_photo"}, nil)
	if err != nil || r2.State != "GIVE_ITEM" || r2.Say != "What is it, in a few words?" {
		t.Fatalf("no photo tap: %v %+v", err, r2)
	}
}

func TestAsstTypedItemAndDescriptionSkipKnownSteps(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("Anything people should know?"), say("Here's what will go up. Happy with it?")}}
	s := newTestService(llm)
	ctx := context.Background()
	r1, _ := s.Turn(ctx, member, TurnInput{Tap: "give"}, nil)
	r2, _ := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "no_photo"}, nil)
	if r2.State != "GIVE_ITEM" {
		t.Fatal(r2.State)
	}
	// This turn needs no model: the rules fill the item and move on; the model only composes.
	llm.Responses = append([]string{say("A grey sofa, lovely. Anything people should know?")}, llm.Responses...)
	r3, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "grey sofa"}, nil)
	if err != nil || r3.State != "GIVE_DESCRIPTION" || r3.Slots["item"] != "grey sofa" {
		t.Fatalf("typed item: %v %+v", err, r3)
	}
	r4, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "Good condition, collection from the flat"}, nil)
	if err != nil || r4.State != "GIVE_CONFIRM" {
		t.Fatalf("signed-in member with location skips where and email: %v %s", err, r4.State)
	}
	if r4.HostAction == nil || r4.HostAction.Type != "confirm_card" {
		t.Fatal("confirm card host action")
	}
}

func TestAsstVisitorIsAskedWhereAndEmail(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("What is it?"), say("Anything to add?"), say("Roughly where is it?"), say("Where should replies go?"), say("Happy with it?")}}
	s := newTestService(llm)
	ctx := context.Background()
	r, _ := s.Turn(ctx, visitor, TurnInput{Tap: "give"}, nil)
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Tap: "no_photo"}, nil)
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Text: "bike"}, nil)
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Tap: "skip"}, nil)
	if r.State != "GIVE_WHERE" {
		t.Fatalf("visitor should be asked where, got %s", r.State)
	}
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Text: "EH3 6SS"}, nil)
	if r.HostAction == nil || r.HostAction.Type != "lookup_postcode" || r.HostAction.Text != "EH3 6SS" {
		t.Fatalf("typed postcode asks the app to look it up: %+v", r.HostAction)
	}
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Event: &Event{Type: "postcode_confirmed", Postcode: "EH3 6SS", Name: "Edinburgh", Community: "Edinburgh Freegle"}}, nil)
	if r.State != "GIVE_EMAIL" {
		t.Fatalf("after postcode a visitor is asked for email, got %s", r.State)
	}
	r, _ = s.Turn(ctx, visitor, TurnInput{ConversationID: r.Conversation, Event: &Event{Type: "email_confirmed", Email: "a@b.com"}}, nil)
	if r.State != "GIVE_CONFIRM" || r.Slots["email"] != "a@b.com" {
		t.Fatalf("confirm after email, got %s", r.State)
	}
}

func TestAsstHubDecisionFillsSlotsAndSkips(t *testing.T) {
	llm := &FakeLLM{Responses: []string{
		`{"contextUpdates":{"say":"A grey sofa, lovely.","newSlots":{"item":"grey sofa","description":"three seater, good condition","typedItem":"grey sofa"},"understood":true},"reasoning":"","actions":[],"proposedTransition":"GIVE_PHOTO"}`,
		say("A grey three seater in good condition. Have you got a photo?"),
	}}
	s := newTestService(llm)
	r, err := s.Turn(context.Background(), member, TurnInput{Text: "I've got a grey sofa to give away, three seater, good condition"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if r.State != "GIVE_PHOTO" || r.Slots["item"] != "grey sofa" {
		t.Fatalf("expected GIVE_PHOTO with item, got %s %v", r.State, r.Slots)
	}
	if r.Say != "A grey three seater in good condition. Have you got a photo?" {
		t.Fatalf("composed opener expected, got %q", r.Say)
	}
}

func TestAsstModelFailureFallsBackToTemplate(t *testing.T) {
	llm := &FakeLLM{}
	s := newTestService(llm)
	r, err := s.Turn(context.Background(), member, TurnInput{Tap: "give"}, nil)
	if err != nil || !r.Fallback || r.Say != TemplateFor("GIVE_PHOTO") {
		t.Fatalf("fallback: %v %+v", err, r)
	}
}

func TestAsstFabricatedReplyIsRegeneratedThenTemplated(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Jane will collect it tomorrow!"), say("Someone will collect it within 2 hours.")}}
	s := newTestService(llm)
	r, _ := s.Turn(context.Background(), member, TurnInput{Tap: "give"}, nil)
	if !r.Fallback || r.Say != TemplateFor("GIVE_PHOTO") {
		t.Fatalf("expected template after two failed checks, got %q", r.Say)
	}
	if len(llm.Calls) != 2 || !strings.Contains(llm.Calls[1], "rejected for") {
		t.Fatal("second attempt should carry the rejection reasons")
	}
}

func TestAsstAbuseStrikesLockTheModel(t *testing.T) {
	abusive := `{"contextUpdates":{"say":"That's not something Freegle can help with.","understood":false,"abusive":true},"reasoning":"","actions":[],"proposedTransition":null}`
	llm := &FakeLLM{Responses: []string{abusive, abusive, abusive}}
	s := newTestService(llm)
	ctx := context.Background()
	r, _ := s.Turn(ctx, member, TurnInput{Text: "you are all idiots"}, nil)
	r, _ = s.Turn(ctx, member, TurnInput{ConversationID: r.Conversation, Text: "still idiots"}, nil)
	r, _ = s.Turn(ctx, member, TurnInput{ConversationID: r.Conversation, Text: "idiots"}, nil)
	r, _ = s.Turn(ctx, member, TurnInput{ConversationID: r.Conversation, Text: "more"}, nil)
	if r.Say != templateAbuse || !r.Fallback {
		t.Fatalf("after three strikes the fixed line answers, got %q", r.Say)
	}
	if len(r.Chips) == 0 {
		t.Fatal("chips stay available")
	}
}

func TestAsstCancelReturnsToHubWithChips(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("No problem. Whenever you like, I can help you give or find something.")}}
	s := newTestService(llm)
	ctx := context.Background()
	r, _ := s.Turn(ctx, member, TurnInput{Tap: "give"}, nil)
	r, _ = s.Turn(ctx, member, TurnInput{ConversationID: r.Conversation, Text: "cancel"}, nil)
	if r.State != "HUB" || len(r.Chips) != 3 || r.Progress != nil {
		t.Fatalf("cancel: %s %d", r.State, len(r.Chips))
	}
}

func TestAsstQuotaExhaustedUsesTemplates(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("x")}}
	s := newTestService(llm)
	s.Quota = NewQuota(1000, 0, 1000, nil)
	r, _ := s.Turn(context.Background(), visitor, TurnInput{Tap: "give"}, nil)
	if !r.Fallback || len(llm.Calls) != 0 {
		t.Fatal("anonymous daily cap of 0 means no model call")
	}
}

func TestAsstIllegalTapIsRefusedNotForced(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("Anything people should know?"), say("Here's what will go up. Happy with it?"), say("Happy with it?"), say("Happy with it?")}}
	s := newTestService(llm)
	ctx := context.Background()
	r1, _ := s.Turn(ctx, member, TurnInput{Tap: "give"}, nil)
	s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "no_photo"}, nil)
	s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "grey sofa"}, nil)
	r4, _ := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "Good condition"}, nil)
	if r4.State != "GIVE_CONFIRM" {
		t.Fatalf("expected the card, got %s", r4.State)
	}
	// A hand-made tap naming a real state the card has no edge to.
	r5, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "edit:done"}, nil)
	if err != nil || r5.State != "GIVE_CONFIRM" {
		t.Fatalf("undefined edge must not be taken: %v %s", err, r5.State)
	}
	if r5.HostAction != nil && r5.HostAction.Type == "create_post" {
		t.Fatal("nothing may be posted by an illegal tap")
	}
	// A legal edit still works.
	r6, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "edit:item"}, nil)
	if err != nil || r6.State != "GIVE_ITEM" {
		t.Fatalf("edit item: %v %s", err, r6.State)
	}
}

func TestAsstPostActionCarriesAnIdempotencyKey(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("Anything people should know?"), say("Happy with it?"), say("Posting that for you now."), say("Posting that for you now.")}}
	s := newTestService(llm)
	ctx := context.Background()
	r1, _ := s.Turn(ctx, member, TurnInput{Tap: "give"}, nil)
	s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "no_photo"}, nil)
	s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "grey sofa"}, nil)
	s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Text: "Good condition"}, nil)
	r5, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "post"}, nil)
	if err != nil || r5.State != "GIVE_POST" || r5.HostAction == nil || r5.HostAction.Type != "create_post" {
		t.Fatalf("post tap: %v %+v", err, r5)
	}
	if r5.HostAction.Key == "" {
		t.Fatal("create_post needs a key so the browser posts once")
	}
	// Posting failed: back to the card, where Post it tries again.
	r6, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Event: &Event{Type: "post_failed", Reason: "network"}}, nil)
	if err != nil || r6.State != "GIVE_CONFIRM" {
		t.Fatalf("post_failed should return to the card: %v %s", err, r6.State)
	}
}

func TestAsstThrottledIdentityGetsTemplatesOnly(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Composed line that must not be used.")}}
	s := newTestService(llm)
	throttled := Identity{Key: "a:throttled:1.2.3.4", Throttled: true}
	r, err := s.Turn(context.Background(), throttled, TurnInput{Tap: "give"}, nil)
	if err != nil || !r.Fallback || r.Say != TemplateFor("GIVE_PHOTO") {
		t.Fatalf("throttled visitor: %v %+v", err, r)
	}
	if len(llm.Calls) != 0 {
		t.Fatalf("no model calls for a throttled identity, got %d", len(llm.Calls))
	}
}

func TestAsstVisitorWhoSignsInKeepsTheChat(t *testing.T) {
	llm := &FakeLLM{Responses: []string{say("Have you got a photo?"), say("What is it, in a few words?"), say("What is it?")}}
	s := newTestService(llm)
	ctx := context.Background()
	r1, err := s.Turn(ctx, visitor, TurnInput{Tap: "give"}, nil)
	if err != nil || r1.State != "GIVE_PHOTO" {
		t.Fatalf("visitor give: %v %+v", err, r1)
	}
	// The same browser, now signed in, still sends the anonymous token it was given.
	member := Identity{Key: "u:9", UserID: 9, Name: "Sam", WasKey: visitor.Key}
	r2, err := s.Turn(ctx, member, TurnInput{ConversationID: r1.Conversation, Tap: "no_photo"}, nil)
	if err != nil || r2.Conversation != r1.Conversation || r2.State != "GIVE_ITEM" {
		t.Fatalf("the chat should carry on as the member: %v %+v", err, r2)
	}
	// Somebody else's token does not hand over the chat.
	stranger := Identity{Key: "u:10", UserID: 10, WasKey: "a:someone-else"}
	r3, err := s.Turn(ctx, stranger, TurnInput{ConversationID: r1.Conversation, Tap: "give"}, nil)
	if err != nil || r3.Conversation == r1.Conversation {
		t.Fatalf("a stranger gets a fresh chat: %v %+v", err, r3)
	}
}

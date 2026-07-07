package message

// Tests for pure functions that don't require a database connection:
//   - isTNImageURL  (URL pattern matching)
//   - TableName() methods on all ORM types
//   - MessageAttachment.ComputeAI() (JSON-based field population)
//
// These tests live in the same package so they can reach unexported helpers.

import (
	"encoding/json"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ── TableName() ───────────────────────────────────────────────────────────────

func TestMessageTableName(t *testing.T) {
	// The ORM table name for Message must exactly match the DB table.
	assert.Equal(t, "messages", Message{}.TableName())
}

func TestMessageAttachmentTableName(t *testing.T) {
	assert.Equal(t, "messages_attachments", MessageAttachment{}.TableName())
}

func TestMessageGroupTableName(t *testing.T) {
	assert.Equal(t, "messages_groups", MessageGroup{}.TableName())
}

func TestMessageOutcomeTableName(t *testing.T) {
	assert.Equal(t, "messages_outcomes", MessageOutcome{}.TableName())
}

func TestMessagePromiseTableName(t *testing.T) {
	assert.Equal(t, "messages_promises", MessagePromise{}.TableName())
}

func TestMessageReplyTableName(t *testing.T) {
	// MessageReply rows are stored in the chat_messages table.
	assert.Equal(t, "chat_messages", MessageReply{}.TableName())
}

// ── isTNImageURL ─────────────────────────────────────────────────────────────

func TestIsTNImageURL_TrashNothingImgPath(t *testing.T) {
	// Standard TN CDN path.
	assert.True(t, isTNImageURL("https://trashnothing.com/img/abc123.jpg"))
}

func TestIsTNImageURL_ImgTrashNothingDomain(t *testing.T) {
	// Subdomain CDN.
	assert.True(t, isTNImageURL("https://img.trashnothing.com/photos/xyz.jpg"))
}

func TestIsTNImageURL_TNPhotosPath(t *testing.T) {
	// Local /tn-photos/ path.
	assert.True(t, isTNImageURL("https://www.example.com/tn-photos/image.jpg"))
}

func TestIsTNImageURL_PhotosTrashNothingDomain(t *testing.T) {
	// photos subdomain.
	assert.True(t, isTNImageURL("https://photos.trashnothing.com/abc/def.jpg"))
}

func TestIsTNImageURL_NonTNURL(t *testing.T) {
	// Arbitrary external URL must not match.
	assert.False(t, isTNImageURL("https://www.ilovefreegle.org/img/photo.jpg"))
}

func TestIsTNImageURL_EmptyString(t *testing.T) {
	assert.False(t, isTNImageURL(""))
}

func TestIsTNImageURL_JustHostname(t *testing.T) {
	// trashnothing.com without the /img/ path is NOT a TN image URL.
	assert.False(t, isTNImageURL("https://trashnothing.com/"))
}

func TestIsTNImageURL_S3OrOtherHost(t *testing.T) {
	assert.False(t, isTNImageURL("https://s3.amazonaws.com/freegle/images/foo.jpg"))
}

func TestIsTNImageURL_PartialMatch_TrashNothingInPath(t *testing.T) {
	// The check is strings.Contains so a URL containing "trashnothing.com/img/" anywhere qualifies.
	assert.True(t, isTNImageURL("http://cdn.example.com/trashnothing.com/img/pic.jpg"))
}

func TestIsTNImageURL_TNPhotosSubstring(t *testing.T) {
	// /tn-photos/ anywhere in the URL triggers the rule.
	assert.True(t, isTNImageURL("/tn-photos/image.png"))
}

func TestIsTNImageURL_ImgTNSubdomainWithPath(t *testing.T) {
	// Deep path on img.trashnothing.com still matches.
	assert.True(t, isTNImageURL("https://img.trashnothing.com/2024/01/15/photo.jpg"))
}

func TestIsTNImageURL_CaseVariation(t *testing.T) {
	// The check is case-sensitive (uses plain Contains).
	// "TRASHNOTHING.COM/IMG/" must NOT match because only lowercase is in the condition.
	assert.False(t, isTNImageURL("https://TRASHNOTHING.COM/IMG/abc.jpg"))
}

// Table-driven — exhaustive coverage of all four isTNImageURL branches.

var isTNImageURLTests = []struct {
	name string
	url  string
	want bool
}{
	// Branch 1: trashnothing.com/img/
	{"tn/img positive", "https://trashnothing.com/img/abc.jpg", true},
	{"tn/img negative similar", "https://trashnothing.com/image/abc.jpg", false},

	// Branch 2: img.trashnothing.com
	{"img.tn positive", "https://img.trashnothing.com/x.jpg", true},
	{"img.tn false friend", "https://img-trashnothing.com/x.jpg", false},

	// Branch 3: /tn-photos/
	{"tn-photos positive", "/tn-photos/foo.jpg", true},
	{"tn-photos negative", "/photos/foo.jpg", false},

	// Branch 4: photos.trashnothing.com
	{"photos.tn positive", "https://photos.trashnothing.com/a/b.jpg", true},
	{"photos.tn false friend", "https://photo.trashnothing.com/a/b.jpg", false},

	// Empties / edge cases
	{"empty string", "", false},
	{"whitespace", "   ", false},
}

func TestIsTNImageURL_Table(t *testing.T) {
	for _, tt := range isTNImageURLTests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.want, isTNImageURL(tt.url), "url=%q", tt.url)
		})
	}
}

// ── MessageAttachment.ComputeAI() ────────────────────────────────────────────

func TestComputeAI_BoolTrue(t *testing.T) {
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": true}`)}
	a.ComputeAI()
	assert.True(t, a.AI)
}

func TestComputeAI_BoolFalse(t *testing.T) {
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": false}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_Float64Nonzero(t *testing.T) {
	// JSON number 1 unmarshals to float64; non-zero → true.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": 1}`)}
	a.ComputeAI()
	assert.True(t, a.AI)
}

func TestComputeAI_Float64Zero(t *testing.T) {
	// JSON 0 → float64(0) → false.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": 0}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_AIFieldAbsent(t *testing.T) {
	// No "ai" key → AI remains false.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"other": "value"}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_EmptyJSON(t *testing.T) {
	a := MessageAttachment{Externalmods: json.RawMessage(`{}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_NilExternalmods(t *testing.T) {
	// Nil slice → early return, no panic.
	a := MessageAttachment{Externalmods: nil}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_EmptyExternalmods(t *testing.T) {
	a := MessageAttachment{Externalmods: json.RawMessage{}}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_InvalidJSON(t *testing.T) {
	// Malformed JSON → Unmarshal error → no panic, AI stays false.
	a := MessageAttachment{Externalmods: json.RawMessage(`{not valid json}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_AINull(t *testing.T) {
	// JSON null → interface{} nil → no case matched → AI stays false.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": null}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_AIString(t *testing.T) {
	// A JSON string is neither bool nor float64, so no case matches → false.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": "yes"}`)}
	a.ComputeAI()
	assert.False(t, a.AI)
}

func TestComputeAI_ExtraFields(t *testing.T) {
	// Extra fields must not interfere with the ai field.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"other":"data","count":3,"ai":true}`)}
	a.ComputeAI()
	assert.True(t, a.AI)
}

func TestComputeAI_CalledTwice(t *testing.T) {
	// Idempotent: calling ComputeAI twice must not flip the value.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": true}`)}
	a.ComputeAI()
	a.ComputeAI()
	assert.True(t, a.AI)
}

func TestComputeAI_LargeFloat64(t *testing.T) {
	// Any non-zero float64 should yield AI=true.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": 99}`)}
	a.ComputeAI()
	assert.True(t, a.AI)
}

func TestComputeAI_NegativeFloat64(t *testing.T) {
	// Negative non-zero float64 should also yield AI=true.
	a := MessageAttachment{Externalmods: json.RawMessage(`{"ai": -1}`)}
	a.ComputeAI()
	assert.True(t, a.AI)
}

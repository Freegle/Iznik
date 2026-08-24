package message

import (
	"encoding/json"
	"testing"
)

// These tests cover AttachmentIDs.UnmarshalJSON, which makes attachment-id arrays
// tolerant of the synthetic "ai-<ms>" string ids posted by pre-#650 mobile builds.
// Before this, the request structs declared []uint64 so fiber's BodyParser rejected
// the whole body with HTTP 400, trapping members at the final "Freegle it" step
// (Sentry CAPACITOR-44Z).

// testAttReq mirrors the shape of the real request structs (which are unexported /
// function-local) just for the attachments field.
type testAttReq struct {
	Attachments AttachmentIDs `json:"attachments"`
}

func TestAttachmentIDs_NumericOnly(t *testing.T) {
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":[1,2,3]}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	want := AttachmentIDs{1, 2, 3}
	if len(r.Attachments) != len(want) {
		t.Fatalf("got %v, want %v", r.Attachments, want)
	}
	for i := range want {
		if r.Attachments[i] != want[i] {
			t.Fatalf("got %v, want %v", r.Attachments, want)
		}
	}
}

func TestAttachmentIDs_DropsAiString(t *testing.T) {
	// The exact failure mode: an "ai-<ms>" synthetic id mixed in with real numeric ids.
	var r testAttReq
	body := `{"attachments":[10,"ai-1749050000000",20]}`
	if err := json.Unmarshal([]byte(body), &r); err != nil {
		t.Fatalf("must not error on mixed-type array, got: %v", err)
	}
	want := AttachmentIDs{10, 20}
	if len(r.Attachments) != len(want) || r.Attachments[0] != 10 || r.Attachments[1] != 20 {
		t.Fatalf("got %v, want %v (ai- string must be dropped)", r.Attachments, want)
	}
}

func TestAttachmentIDs_AiStringOnly(t *testing.T) {
	// A no-photo post from a broken build: the only attachment is the synthetic id.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":["ai-1749050000000"]}`), &r); err != nil {
		t.Fatalf("must not error, got: %v", err)
	}
	if len(r.Attachments) != 0 {
		t.Fatalf("got %v, want empty (ai- string must be dropped)", r.Attachments)
	}
	if r.Attachments == nil {
		t.Fatalf("got nil, want empty non-nil slice for a present array")
	}
}

func TestAttachmentIDs_NumericStringsKept(t *testing.T) {
	// Some clients stringify ids; keep plain numeric strings.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":["5","6"]}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.Attachments) != 2 || r.Attachments[0] != 5 || r.Attachments[1] != 6 {
		t.Fatalf("got %v, want [5 6]", r.Attachments)
	}
}

func TestAttachmentIDs_DropsObjectsAndBools(t *testing.T) {
	var r testAttReq
	body := `{"attachments":[7,{"id":8},true,null,9]}`
	if err := json.Unmarshal([]byte(body), &r); err != nil {
		t.Fatalf("must not error, got: %v", err)
	}
	if len(r.Attachments) != 2 || r.Attachments[0] != 7 || r.Attachments[1] != 9 {
		t.Fatalf("got %v, want [7 9]", r.Attachments)
	}
}

func TestAttachmentIDs_NullIsNil(t *testing.T) {
	// JSON null must stay nil so patch handlers that distinguish nil ("don't touch")
	// from [] ("remove all attachments") keep working.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":null}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.Attachments != nil {
		t.Fatalf("got %v, want nil for JSON null", r.Attachments)
	}
}

func TestAttachmentIDs_AbsentIsNil(t *testing.T) {
	var r testAttReq
	if err := json.Unmarshal([]byte(`{}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.Attachments != nil {
		t.Fatalf("got %v, want nil when field absent", r.Attachments)
	}
}

func TestAttachmentIDs_EmptyArrayIsEmptyNonNil(t *testing.T) {
	// [] means "remove all attachments" in patchMessage and must stay non-nil.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":[]}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if r.Attachments == nil {
		t.Fatalf("got nil, want empty non-nil slice for []")
	}
	if len(r.Attachments) != 0 {
		t.Fatalf("got %v, want empty", r.Attachments)
	}
}

func TestAttachmentIDs_NotAnArrayFailsOpen(t *testing.T) {
	// A malformed value must not 400 the whole request; treat as no attachments.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":"ai-1749050000000"}`), &r); err != nil {
		t.Fatalf("must fail open, not error, got: %v", err)
	}
	if r.Attachments != nil {
		t.Fatalf("got %v, want nil for non-array value", r.Attachments)
	}
}

func TestAttachmentIDs_LargeIdExact(t *testing.T) {
	// Ids beyond float64's exact-integer range must survive intact.
	var r testAttReq
	if err := json.Unmarshal([]byte(`{"attachments":[9007199254740993]}`), &r); err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(r.Attachments) != 1 || r.Attachments[0] != 9007199254740993 {
		t.Fatalf("got %v, want [9007199254740993]", r.Attachments)
	}
}

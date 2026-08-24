package message

import (
	"net/http"
	"net/http/httptest"
	"testing"
)

// Pure unit tests for the bulk-offer helpers that need no database: the
// interest-active predicate, the plain-text catalogue summary, and the remote
// image fetcher (exercised against an httptest server, no real network).

func TestInterestIsActive(t *testing.T) {
	cases := map[string]bool{
		"Offered":    true,
		"Promised":   true,
		"Interested": true,
		"":           true,
		"Withdrawn":  false,
		"Rejected":   false,
	}
	for state, want := range cases {
		if got := interestIsActive(state); got != want {
			t.Errorf("interestIsActive(%q) = %v, want %v", state, got, want)
		}
	}
}

func TestBuildBulkSummary_FullCatalogueAndSlots(t *testing.T) {
	items := []BulkItemInput{
		{Name: "   ", Quantity: 2},                         // blank name -> skipped, no number
		{Name: "Sofa", Quantity: 0, Condition: "Good"},     // qty < 1 -> 1; condition shown
		{Name: "Chair", Quantity: 3, Condition: "Unknown"}, // "Unknown" condition hidden
		{Name: "Lamp", Quantity: 1, Condition: ""},         // empty condition hidden
	}
	slots := []string{"  Mon 9-5  ", "", "Tue 10-2"} // blank slot dropped, others trimmed

	got := buildBulkSummary(items, slots)
	want := "Items available in this offer:\n" +
		"1) 1× Sofa (Good)\n" +
		"2) 3× Chair\n" +
		"3) 1× Lamp" +
		"\n\nCollection times (let us know which suits you):\n" +
		"- Mon 9-5\n" +
		"- Tue 10-2"
	if got != want {
		t.Fatalf("buildBulkSummary mismatch:\n got=%q\nwant=%q", got, want)
	}
}

func TestBuildBulkSummary_NoSlotsOmitsWindowSection(t *testing.T) {
	got := buildBulkSummary([]BulkItemInput{{Name: "Table", Quantity: 1}}, nil)
	want := "Items available in this offer:\n1) 1× Table"
	if got != want {
		t.Fatalf("got=%q want=%q", got, want)
	}
}

func TestBuildBulkSummary_NoNamedItemsReturnsEmpty(t *testing.T) {
	// All items blank-named -> nothing to summarise -> empty string (even with slots).
	got := buildBulkSummary([]BulkItemInput{{Name: " "}, {Name: ""}}, []string{"Mon"})
	if got != "" {
		t.Fatalf("expected empty summary, got %q", got)
	}
}

func TestFetchRemoteImage_RejectsNonHTTPURL(t *testing.T) {
	if _, _, err := fetchRemoteImage("ftp://example.com/x.png"); err == nil {
		t.Fatal("expected error for non-http(s) url, got nil")
	}
}

func TestFetchRemoteImage_Non200IsError(t *testing.T) {
	// Allow the loopback httptest server so we test the non-200 handling, not the SSRF block.
	allowLoopbackFetch = true
	defer func() { allowLoopbackFetch = false }()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()
	if _, _, err := fetchRemoteImage(srv.URL); err == nil {
		t.Fatal("expected error for non-200 response, got nil")
	}
}

func TestFetchRemoteImage_NonImageContentIsError(t *testing.T) {
	// Allow the loopback httptest server so we test the content-type check, not the SSRF block.
	allowLoopbackFetch = true
	defer func() { allowLoopbackFetch = false }()
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain")
		_, _ = w.Write([]byte("this is definitely not an image, just some plain text body"))
	}))
	defer srv.Close()
	if _, _, err := fetchRemoteImage(srv.URL); err == nil {
		t.Fatal("expected error for non-image content, got nil")
	}
}

func TestFetchRemoteImage_DetectsImageWhenContentTypeMissing(t *testing.T) {
	// httptest listens on loopback, which the SSRF dialer blocks in production; allow it here.
	allowLoopbackFetch = true
	defer func() { allowLoopbackFetch = false }()

	// PNG signature; with no Content-Type header the fetcher must sniff the bytes.
	png := []byte{0x89, 'P', 'N', 'G', 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 0}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header()["Content-Type"] = nil // force empty so DetectContentType runs
		_, _ = w.Write(png)
	}))
	defer srv.Close()

	data, mime, err := fetchRemoteImage(srv.URL)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if len(data) != len(png) {
		t.Fatalf("got %d bytes, want %d", len(data), len(png))
	}
	if mime != "image/png" {
		t.Fatalf("got mime %q, want image/png", mime)
	}
}

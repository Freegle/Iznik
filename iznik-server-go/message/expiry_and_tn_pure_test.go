package message

import (
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"testing"
	"time"

	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// Pure unit tests for message expiry, ripple env-flag, and TrashNothing photo
// scraping helpers that don't need a database. All cases below take a path
// that returns before touching the *gorm.DB argument.

func TestRippleEnabled(t *testing.T) {
	orig, had := os.LookupEnv("RIPPLE_ENABLED")
	t.Cleanup(func() {
		if had {
			os.Setenv("RIPPLE_ENABLED", orig)
		} else {
			os.Unsetenv("RIPPLE_ENABLED")
		}
	})

	cases := map[string]bool{
		"true":  true,
		"1":     true,
		"false": false,
		"0":     false,
		"":      false,
		"yes":   false,
		"TRUE":  false, // case-sensitive on the wire
	}
	for v, want := range cases {
		os.Setenv("RIPPLE_ENABLED", v)
		if got := rippleEnabled(); got != want {
			t.Errorf("rippleEnabled() with RIPPLE_ENABLED=%q = %v, want %v", v, got, want)
		}
	}

	os.Unsetenv("RIPPLE_ENABLED")
	if got := rippleEnabled(); got != false {
		t.Errorf("rippleEnabled() with RIPPLE_ENABLED unset = %v, want false", got)
	}
}

func TestComputeExpiresat_NoGroups(t *testing.T) {
	if got := computeExpiresat(nil, utils.OFFER, nil); got != nil {
		t.Errorf("computeExpiresat(nil groups) = %v, want nil", got)
	}
	if got := computeExpiresat(nil, utils.OFFER, []MessageGroup{}); got != nil {
		t.Errorf("computeExpiresat(empty groups) = %v, want nil", got)
	}
}

func allCompleteSummaries() []MessageSummary {
	return []MessageSummary{
		{ID: 1, Groupid: 100, Hasoutcome: true, Type: utils.OFFER, Arrival: time.Now().Add(-200 * 24 * time.Hour)},
		{ID: 2, Groupid: 200, Hasoutcome: true, Type: utils.WANTED, Arrival: time.Now().Add(-5 * 24 * time.Hour)},
	}
}

func TestApplyExpiry_EmptyAndAllCompleted(t *testing.T) {
	// Empty input never touches db.
	if got := applyExpiry(nil, nil); got != nil {
		t.Errorf("applyExpiry(nil) = %v, want nil", got)
	}

	// Every message already has an outcome, so the group-settings lookup is
	// skipped entirely (groupIDs stays empty) and no candidates are found.
	msgs := allCompleteSummaries()
	got := applyExpiry(nil, msgs)
	if got != nil {
		t.Errorf("applyExpiry(all-completed) = %v, want nil", got)
	}
	// Messages must be left untouched.
	for i, m := range msgs {
		if !m.Hasoutcome {
			t.Errorf("msgs[%d].Hasoutcome flipped to false unexpectedly", i)
		}
	}
}

func TestFilterExpiredMessages_AllCompletedFilteredOut(t *testing.T) {
	msgs := allCompleteSummaries()
	got := filterExpiredMessages(nil, msgs)
	assert.Empty(t, got, "all-completed messages should all be filtered out of the active view")
}

func TestFilterExpiredSummaries_DelegatesToFilterExpiredMessages(t *testing.T) {
	msgs := allCompleteSummaries()
	got := FilterExpiredSummaries(nil, msgs)
	assert.Empty(t, got)
}

func TestMarkExpiredMessages_AlreadyCompletedUnaffected(t *testing.T) {
	msgs := allCompleteSummaries()
	markExpiredMessages(nil, msgs)
	for i, m := range msgs {
		assert.True(t, m.Hasoutcome, "msgs[%d] should remain marked complete", i)
	}
}

func TestMarkExpiredMessages_EmptyInput(t *testing.T) {
	// Must not panic on an empty slice, and must not touch db.
	markExpiredMessages(nil, nil)
}

// --- TrashNothing photo helpers -------------------------------------------------

func TestDownloadTNImage_Success(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "image/png")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("fake-image-bytes"))
	}))
	defer srv.Close()

	data, mime, err := downloadTNImage(srv.URL)
	assert.NoError(t, err)
	assert.Equal(t, "fake-image-bytes", string(data))
	assert.Equal(t, "image/png", mime)
}

func TestDownloadTNImage_DefaultsMimeWhenMissing(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Explicitly blank (rather than merely absent) Content-Type, so Go's
		// server doesn't sniff and substitute its own before we can observe
		// downloadTNImage's own "" -> image/jpeg fallback.
		w.Header().Set("Content-Type", "")
		w.WriteHeader(http.StatusOK)
		w.Write([]byte("bytes"))
	}))
	defer srv.Close()

	_, mime, err := downloadTNImage(srv.URL)
	assert.NoError(t, err)
	assert.Equal(t, "image/jpeg", mime)
}

func TestDownloadTNImage_NonSuccessStatus(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusNotFound)
	}))
	defer srv.Close()

	_, _, err := downloadTNImage(srv.URL)
	assert.Error(t, err)
}

func TestDownloadTNImage_NetworkError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	badURL := srv.URL
	srv.Close() // closed immediately, so the connection is refused

	_, _, err := downloadTNImage(badURL)
	assert.Error(t, err)
}

func TestExtractTNImageURLsFromPage_AnchorHrefs(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`<html><body>
			<a href="https://img.trashnothing.com/photo1.jpg">photo</a>
			<a href="https://example.com/not-an-image">other link</a>
			<a href="https://photos.trashnothing.com/photo2.jpg">photo2</a>
		</body></html>`))
	}))
	defer srv.Close()

	got := extractTNImageURLsFromPage(srv.URL)
	assert.Equal(t, []string{
		"https://img.trashnothing.com/photo1.jpg",
		"https://photos.trashnothing.com/photo2.jpg",
	}, got)
}

func TestExtractTNImageURLsFromPage_FallsBackToImgSrc(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`<html><body>
			<a href="https://example.com/not-an-image">no direct image link here</a>
			<img src="https://img.trashnothing.com/embedded.jpg" />
		</body></html>`))
	}))
	defer srv.Close()

	got := extractTNImageURLsFromPage(srv.URL)
	assert.Equal(t, []string{"https://img.trashnothing.com/embedded.jpg"}, got)
}

func TestExtractTNImageURLsFromPage_NoMatches(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`<html><body><a href="https://example.com/page">link</a></body></html>`))
	}))
	defer srv.Close()

	got := extractTNImageURLsFromPage(srv.URL)
	assert.Empty(t, got)
}

func TestExtractTNImageURLsFromPage_NonSuccessStatus(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	got := extractTNImageURLsFromPage(srv.URL)
	assert.Nil(t, got)
}

func TestExtractTNImageURLsFromPage_NetworkError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {}))
	badURL := srv.URL
	srv.Close()

	got := extractTNImageURLsFromPage(badURL)
	assert.Nil(t, got)
}

// --- scrapeTNPhotosToAttachments: exercised via the swappable fetcher vars ------
// so the dedup/error paths run without ever reaching the db.Exec insert.

func TestScrapeTNPhotosToAttachments_EmptyPages(t *testing.T) {
	// No pages at all: the outer loop body never runs, so db is never touched.
	scrapeTNPhotosToAttachments(nil, 123, nil)
}

func TestScrapeTNPhotosToAttachments_DedupAndFetchErrorSkipDB(t *testing.T) {
	origPageFetcher := TNPageFetcher
	origImageFetcher := TNImageFetcher
	t.Cleanup(func() {
		TNPageFetcher = origPageFetcher
		TNImageFetcher = origImageFetcher
	})

	pageCalls := 0
	TNPageFetcher = func(pageURL string) []string {
		pageCalls++
		switch pageURL {
		case "page1":
			return []string{"url1", "url1"} // duplicate within one page
		case "page2":
			return []string{"url1", "url2"} // "url1" duplicate across pages
		}
		return nil
	}

	imageCalls := 0
	TNImageFetcher = func(imageURL string) ([]byte, string, error) {
		imageCalls++
		return nil, "", errors.New("simulated download failure")
	}

	// db is nil: if the dedup logic or the fetch-error "continue" branch didn't
	// work as expected, this would panic trying to reach db.Exec.
	scrapeTNPhotosToAttachments(nil, 456, []string{"page1", "page2"})

	assert.Equal(t, 2, pageCalls)
	// url1 appears twice across the two pages but must only be fetched once;
	// url2 is fetched once. Total = 2 unique attempts, not 3.
	assert.Equal(t, 2, imageCalls)
}

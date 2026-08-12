package user

import (
	"os"
	"strconv"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// singleUnsubscribeCategories
// ---------------------------------------------------------------------------

func TestSingleUnsubscribeCategories_ExcludesCombinations(t *testing.T) {
	got := singleUnsubscribeCategories()

	assert.Equal(t, len(UnsubscribeTypes)-2, len(got))
	assert.NotContains(t, got, UnsubAll)
	assert.NotContains(t, got, UnsubAllExceptReplies)
}

func TestSingleUnsubscribeCategories_PreservesOrder(t *testing.T) {
	got := singleUnsubscribeCategories()

	want := []string{
		UnsubDigest, UnsubEvents, UnsubVolunteering, UnsubNewsletter,
		UnsubRelevant, UnsubChat, UnsubNotifications, UnsubEngagement,
	}
	assert.Equal(t, want, got)
}

// ---------------------------------------------------------------------------
// UnsubscribeDescription / describeFor
// ---------------------------------------------------------------------------

func TestUnsubscribeDescription_KnownTypes(t *testing.T) {
	cases := []struct {
		unsubType string
		want      string
	}{
		{UnsubDigest, "emails about new posts in your communities"},
		{UnsubEvents, "emails about community events"},
		{UnsubVolunteering, "emails about volunteer opportunities"},
		{UnsubNewsletter, "newsletters and community news"},
		{UnsubRelevant, "emails suggesting posts that match what you are looking for"},
		{UnsubChat, "emails telling you about new chat messages"},
		{UnsubNotifications, "emails about replies and notifications"},
		{UnsubEngagement, "occasional emails asking how we are doing"},
		{UnsubAll, "all our non-essential emails"},
		{UnsubAllExceptReplies, "everything except replies to your posts"},
	}
	for _, c := range cases {
		t.Run(c.unsubType, func(t *testing.T) {
			assert.Equal(t, c.want, UnsubscribeDescription(c.unsubType))
		})
	}
}

func TestUnsubscribeDescription_UnknownTypeReturnsEmpty(t *testing.T) {
	assert.Equal(t, "", UnsubscribeDescription("not-a-real-category"))
	assert.Equal(t, "", UnsubscribeDescription(""))
}

func TestDescribeFor_KnownTypeReturnsDescription(t *testing.T) {
	assert.Equal(t, "emails about new posts in your communities", describeFor(UnsubDigest))
}

func TestDescribeFor_UnknownTypeFallsBackToGeneric(t *testing.T) {
	assert.Equal(t, "these emails", describeFor("bogus"))
	assert.Equal(t, "these emails", describeFor(""))
}

// ---------------------------------------------------------------------------
// validUnsubscribeType
// ---------------------------------------------------------------------------

func TestValidUnsubscribeType_AllKnownTypesValid(t *testing.T) {
	for _, ty := range UnsubscribeTypes {
		assert.True(t, validUnsubscribeType(ty), "expected %q to be valid", ty)
	}
}

func TestValidUnsubscribeType_UnknownAndEmptyInvalid(t *testing.T) {
	assert.False(t, validUnsubscribeType("bogus"))
	assert.False(t, validUnsubscribeType(""))
	assert.False(t, validUnsubscribeType("DIGEST")) // case-sensitive
}

// ---------------------------------------------------------------------------
// pageHead
// ---------------------------------------------------------------------------

func TestPageHead_ContainsEscapedTitle(t *testing.T) {
	html := pageHead("Stop these emails?")
	assert.True(t, strings.HasPrefix(html, "<!doctype html>"))
	assert.Contains(t, html, "<title>Stop these emails?</title>")
	assert.Contains(t, html, "<body")
}

func TestPageHead_EscapesHTMLInTitle(t *testing.T) {
	html := pageHead("<script>alert(1)</script>")
	assert.NotContains(t, html, "<script>alert(1)</script>")
	assert.Contains(t, html, "&lt;script&gt;")
}

// ---------------------------------------------------------------------------
// userSiteBase
// ---------------------------------------------------------------------------

func TestUserSiteBase_DefaultsWhenUnset(t *testing.T) {
	orig, had := os.LookupEnv("USER_SITE")
	os.Unsetenv("USER_SITE")
	t.Cleanup(func() {
		if had {
			os.Setenv("USER_SITE", orig)
		} else {
			os.Unsetenv("USER_SITE")
		}
	})

	assert.Equal(t, "https://www.ilovefreegle.org", userSiteBase())
}

func TestUserSiteBase_UsesEnvWhenSet(t *testing.T) {
	orig, had := os.LookupEnv("USER_SITE")
	os.Setenv("USER_SITE", "example.org")
	t.Cleanup(func() {
		if had {
			os.Setenv("USER_SITE", orig)
		} else {
			os.Unsetenv("USER_SITE")
		}
	})

	assert.Equal(t, "https://example.org", userSiteBase())
}

// ---------------------------------------------------------------------------
// confirmPage
// ---------------------------------------------------------------------------

func TestConfirmPage_ContainsExpectedFields(t *testing.T) {
	page := confirmPage(42, "the-key", UnsubDigest)

	assert.Contains(t, page, "Stop these emails?")
	assert.Contains(t, page, describeFor(UnsubDigest))
	assert.Contains(t, page, "value=\""+strconv.FormatUint(42, 10)+"\"")
	assert.Contains(t, page, "name=\"k\" value=\"the-key\"")
	assert.Contains(t, page, "name=\"t\" value=\""+UnsubDigest+"\"")
	assert.Contains(t, page, "name=\"confirm\" value=\"1\"")
	assert.Contains(t, page, "method=\"POST\"")
}

func TestConfirmPage_AllExceptRepliesShowsExtraReassurance(t *testing.T) {
	page := confirmPage(1, "k", UnsubAllExceptReplies)
	assert.Contains(t, page, "You'll still hear when someone replies to your posts")
}

func TestConfirmPage_OtherTypesOmitReassurance(t *testing.T) {
	page := confirmPage(1, "k", UnsubDigest)
	assert.NotContains(t, page, "You'll still hear when someone replies to your posts")
}

func TestConfirmPage_EscapesKeyAndType(t *testing.T) {
	// The key comes straight from the query/form and must be HTML-escaped before
	// being embedded in a hidden input value.
	page := confirmPage(1, "\"><script>alert(1)</script>", UnsubDigest)
	assert.NotContains(t, page, "\"><script>alert(1)</script>")
	assert.Contains(t, page, "&#34;&gt;&lt;script&gt;alert(1)&lt;/script&gt;")
}

func TestConfirmPage_LinksToDefaultSettingsPage(t *testing.T) {
	orig, had := os.LookupEnv("USER_SITE")
	os.Unsetenv("USER_SITE")
	t.Cleanup(func() {
		if had {
			os.Setenv("USER_SITE", orig)
		} else {
			os.Unsetenv("USER_SITE")
		}
	})

	page := confirmPage(1, "k", UnsubDigest)
	assert.Contains(t, page, "https://www.ilovefreegle.org/settings")
}

// ---------------------------------------------------------------------------
// donePage
// ---------------------------------------------------------------------------

func TestDonePage_IndividualCategoryShowsOtherMailNotice(t *testing.T) {
	page := donePage(UnsubDigest)
	assert.Contains(t, page, "Done")
	assert.Contains(t, page, describeFor(UnsubDigest))
	assert.Contains(t, page, "You may still get other kinds of email from Freegle.")
	assert.NotContains(t, page, "You'll still hear when someone replies to your posts")
}

func TestDonePage_AllExceptRepliesShowsReassuranceNotOtherNotice(t *testing.T) {
	page := donePage(UnsubAllExceptReplies)
	assert.Contains(t, page, "You'll still hear when someone replies to your posts")
	assert.NotContains(t, page, "You may still get other kinds of email from Freegle.")
}

func TestDonePage_UnsubAllShowsNeitherNotice(t *testing.T) {
	page := donePage(UnsubAll)
	assert.NotContains(t, page, "You'll still hear when someone replies to your posts")
	assert.NotContains(t, page, "You may still get other kinds of email from Freegle.")
}

func TestDonePage_LinksToSettingsPage(t *testing.T) {
	orig, had := os.LookupEnv("USER_SITE")
	os.Setenv("USER_SITE", "my.example.com")
	t.Cleanup(func() {
		if had {
			os.Setenv("USER_SITE", orig)
		} else {
			os.Unsetenv("USER_SITE")
		}
	})

	page := donePage(UnsubDigest)
	assert.Contains(t, page, "https://my.example.com/settings")
}

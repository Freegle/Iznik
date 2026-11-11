package admin

import (
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/stretchr/testify/assert"
)

func sp(s string) *string { return &s }
func bp(b bool) *bool     { return &b }

func TestCheckText(t *testing.T) {
	cases := []struct {
		name    string
		in      string
		wantErr bool
	}{
		{"plain", "Hello there", false},
		{"empty", "", false},
		{"placeholder not a tag", "Dear <your names here>, hi", false},
		{"less-than maths", "1 < 2 and 3 > 2", false},
		{"anchor", `see <a href="x">here</a>`, true},
		{"closing div", "text </div>", true},
		{"br self closing", "line<br/>line", true},
		{"upper case", "<B>bold</B>", true},
		{"comment", "x <!-- hidden -->", true},
		{"mjml element", "<mj-section>", true},
		{"heading", "<h2>Hi</h2>", true},
		{"script", "<script>alert(1)</script>", true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := checkText(c.in)
			if c.wantErr {
				assert.Contains(t, got, "plain text")
			} else {
				assert.Equal(t, "", got)
			}
		})
	}
}

func TestCheckMjml(t *testing.T) {
	cases := []struct {
		name string
		in   string
		want string // substring, "" means ok
	}{
		{"empty", "", ""},
		{"whitespace only", "  \n\t ", ""},
		{"section", "<mj-section><mj-column></mj-column></mj-section>", ""},
		{"wrapper", "<MJ-WRAPPER></MJ-WRAPPER>", ""},
		{"hero", "<mj-hero></mj-hero>", ""},
		{"too long", "<mj-section>" + strings.Repeat("a", maxMjmlBytes), "too long"},
		{"mjml root", "<mjml><mj-section/></mjml>", "inside <mj-body>"},
		{"mj-head", "<mj-head></mj-head><mj-section/>", "inside <mj-body>"},
		{"mj-body", "<mj-body><mj-section/></mj-body>", "inside <mj-body>"},
		{"mj-include", `<mj-include path="/etc/passwd" /><mj-section/>`, "inside <mj-body>"},
		{"no top level", "<mj-text>hello</mj-text>", "at least one"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := checkMjml(c.in)
			if c.want == "" {
				assert.Equal(t, "", got)
			} else {
				assert.Contains(t, got, c.want)
			}
		})
	}
}

func TestCheckTestEmail(t *testing.T) {
	cases := []struct {
		in, want string
	}{
		{"a@example.com", "a@example.com"},
		{"  a@example.com  ", "a@example.com"},
		{"", ""},
		{"not an email", ""},
		{"Name <a@example.com>", ""},
		{"a@example.com, b@example.com", ""},
		{"a@", ""},
	}
	for _, c := range cases {
		assert.Equal(t, c.want, checkTestEmail(c.in), c.in)
	}
}

func TestContentOf(t *testing.T) {
	// Nil pointers give empty strings, and Essential defaults true when absent.
	got := contentOf(PostAdminRequest{GroupID: 5, Subject: "S", Text: "T"})
	assert.Equal(t, emailContent{GroupID: 5, Subject: "S", Text: "T", Essential: true}, got)

	got = contentOf(PostAdminRequest{
		GroupID: 7, Subject: "S", Text: "T",
		Mjml: sp("<mj-section/>"), CTA_Text: sp("Go"), CTA_Link: sp("https://x"),
		Essential: bp(false), Template: sp("tpl"),
	})
	assert.Equal(t, emailContent{
		GroupID: 7, Subject: "S", Text: "T", Mjml: "<mj-section/>", CTAText: "Go",
		CTALink: "https://x", Essential: false, Template: "tpl",
	}, got)

	got = contentOf(PostAdminRequest{Essential: bp(true)})
	assert.True(t, got.Essential)
}

func TestSignContentAndTokens(t *testing.T) {
	t.Setenv("JWT_SECRET", "unit-test-secret")
	content := emailContent{GroupID: 1, Subject: "S", Text: "T", Essential: true}

	// Deterministic for the same inputs.
	a := signContent(10, content, 1000)
	assert.Equal(t, a, signContent(10, content, 1000))
	assert.Len(t, a, 64)

	// Any change in user, expiry or content changes the signature.
	assert.NotEqual(t, a, signContent(11, content, 1000))
	assert.NotEqual(t, a, signContent(10, content, 1001))
	changed := content
	changed.Subject = "other"
	assert.NotEqual(t, a, signContent(10, changed, 1000))

	// And so does the secret.
	t.Setenv("JWT_SECRET", "different")
	assert.NotEqual(t, a, signContent(10, content, 1000))
	t.Setenv("JWT_SECRET", "unit-test-secret")

	token := testToken(10, content)
	assert.True(t, testTokenValid(token, 10, content))
	assert.False(t, testTokenValid(token, 11, content), "other user")
	assert.False(t, testTokenValid(token, 10, changed), "other content")
	assert.False(t, testTokenValid("", 10, content))
	assert.False(t, testTokenValid("nodot", 10, content))
	assert.False(t, testTokenValid("abc.def", 10, content), "non-numeric expiry")

	parts := strings.SplitN(token, ".", 2)
	expiry, err := strconv.ParseInt(parts[0], 10, 64)
	assert.NoError(t, err)
	assert.WithinDuration(t, time.Now().Add(testTokenLifetime), time.Unix(expiry, 0), 5*time.Second)

	// Tampered signature.
	assert.False(t, testTokenValid(parts[0]+"."+strings.Repeat("0", 64), 10, content))

	// Expired but correctly signed.
	past := time.Now().Add(-time.Minute).Unix()
	expired := strconv.FormatInt(past, 10) + "." + signContent(10, content, past)
	assert.False(t, testTokenValid(expired, 10, content))
}

func TestNormaliseSendAfter(t *testing.T) {
	assert.Nil(t, normaliseSendAfter(nil))
	assert.Nil(t, normaliseSendAfter(sp("")))

	cases := []struct{ in, want string }{
		{"2026-03-04T05:06:07Z", "2026-03-04 05:06:07"},
		{"2026-03-04T05:06:07+01:00", "2026-03-04 04:06:07"},
		{"2026-03-04T05:06:07", "2026-03-04 05:06:07"},
		{"2026-03-04T05:06", "2026-03-04 05:06:00"},
		{"2026-03-04 05:06:07", "2026-03-04 05:06:07"},
		{"garbage", "garbage"},
	}
	for _, c := range cases {
		assert.Equal(t, c.want, normaliseSendAfter(sp(c.in)), c.in)
	}
}

func TestNilIfBlank(t *testing.T) {
	assert.Nil(t, nilIfBlank(nil))
	assert.Nil(t, nilIfBlank(sp("")))
	assert.Nil(t, nilIfBlank(sp("  \n ")))
	assert.Equal(t, "<mj-section/>", nilIfBlank(sp("<mj-section/>")))
	assert.Equal(t, " x ", nilIfBlank(sp(" x ")))
}

func TestContentChanges(t *testing.T) {
	assert.False(t, contentChanges(PatchAdminRequest{}))
	assert.False(t, contentChanges(PatchAdminRequest{ID: 3}))

	fields := map[string]PatchAdminRequest{
		"subject":   {Subject: sp("s")},
		"text":      {Text: sp("t")},
		"mjml":      {Mjml: sp("m")},
		"ctatext":   {CTA_Text: sp("c")},
		"ctalink":   {CTA_Link: sp("l")},
		"essential": {Essential: bp(false)},
		"template":  {Template: sp("t")},
	}
	for name, req := range fields {
		assert.True(t, contentChanges(req), name)
	}
}

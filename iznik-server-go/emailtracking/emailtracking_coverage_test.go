package emailtracking

import (
	"os"
	"strings"
	"testing"
)

func TestIsNumeric(t *testing.T) {
	cases := []struct {
		in   string
		want bool
	}{
		{"", true},
		{"0", true},
		{"123", true},
		{"0123456789", true},
		{"12a", false},
		{"a12", false},
		{"1.2", false},
		{"-12", false},
		{" 12", false},
		{"abc", false},
	}
	for _, c := range cases {
		if got := isNumeric(c.in); got != c.want {
			t.Errorf("isNumeric(%q) = %v, want %v", c.in, got, c.want)
		}
	}
}

func TestContainsString(t *testing.T) {
	if !containsString([]string{"a", "b", "c"}, "b") {
		t.Errorf("containsString should find 'b'")
	}
	if containsString([]string{"a", "b"}, "c") {
		t.Errorf("containsString should not find 'c'")
	}
	if containsString([]string{}, "a") {
		t.Errorf("containsString should not find anything in empty slice")
	}
	if containsString(nil, "a") {
		t.Errorf("containsString should not find anything in nil slice")
	}
	if !containsString([]string{""}, "") {
		t.Errorf("containsString should find empty string in slice containing empty string")
	}
}

func TestNormalizeURL(t *testing.T) {
	cases := []struct {
		in   string
		want string
	}{
		{"", ""},
		{"/message/12345", "/message/{id}"},
		{"/message/12345/edit", "/message/{id}/edit"},
		{"/user/42/group/99", "/user/{id}/group/{id}"},
		{"/message/12345?foo=bar", "/message/{id}"},
		{"/static/page", "/static/page"},
		{"nooslashes", "nooslashes"},
		{"/a/1/b/2/c/3", "/a/{id}/b/{id}/c/{id}"},
		{"/123/456", "/{id}/{id}"},
	}
	for _, c := range cases {
		if got := normalizeURL(c.in); got != c.want {
			t.Errorf("normalizeURL(%q) = %q, want %q", c.in, got, c.want)
		}
	}
}

func TestIsValidRedirectURL(t *testing.T) {
	origUser := os.Getenv("USER_SITE")
	origMod := os.Getenv("MOD_SITE")
	origImg := os.Getenv("IMAGE_DOMAIN")
	origArch := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	origGroup := os.Getenv("GROUP_DOMAIN")
	t.Cleanup(func() {
		os.Setenv("USER_SITE", origUser)
		os.Setenv("MOD_SITE", origMod)
		os.Setenv("IMAGE_DOMAIN", origImg)
		os.Setenv("IMAGE_ARCHIVED_DOMAIN", origArch)
		os.Setenv("GROUP_DOMAIN", origGroup)
	})

	os.Setenv("USER_SITE", "example.com")
	os.Setenv("MOD_SITE", "mod.example.com")
	os.Setenv("IMAGE_DOMAIN", "images.example.com")
	os.Setenv("IMAGE_ARCHIVED_DOMAIN", "archive.example.com")
	os.Setenv("GROUP_DOMAIN", "groups.example.com")

	if isValidRedirectURL("") {
		t.Errorf("empty URL must be invalid")
	}
	if isValidRedirectURL("ftp://example.com") {
		t.Errorf("ftp URL must be invalid")
	}
	if isValidRedirectURL("javascript:alert(1)") {
		t.Errorf("javascript URL must be invalid")
	}
	if isValidRedirectURL("//example.com") {
		t.Errorf("scheme-relative URL must be invalid")
	}
	if isValidRedirectURL("https://evil.com/phish") {
		t.Errorf("disallowed domain must be invalid")
	}

	valid := []string{
		"http://example.com/path",
		"https://example.com/path",
		"https://mod.example.com/foo",
		"https://images.example.com/i/1.jpg",
		"https://archive.example.com/old",
		"https://groups.example.com/g/123",
		"http://localhost:8192/",
		"https://maps.google.com/?q=x",
		"https://delivery.ilovefreegle.org/img/x",
		"https://modtools.org/chat/1",
	}
	for _, u := range valid {
		if !isValidRedirectURL(u) {
			t.Errorf("isValidRedirectURL(%q) = false, want true", u)
		}
	}
}

func TestIsValidRedirectURLEmptyEnv(t *testing.T) {
	origUser := os.Getenv("USER_SITE")
	origMod := os.Getenv("MOD_SITE")
	origImg := os.Getenv("IMAGE_DOMAIN")
	origArch := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	origGroup := os.Getenv("GROUP_DOMAIN")
	t.Cleanup(func() {
		os.Setenv("USER_SITE", origUser)
		os.Setenv("MOD_SITE", origMod)
		os.Setenv("IMAGE_DOMAIN", origImg)
		os.Setenv("IMAGE_ARCHIVED_DOMAIN", origArch)
		os.Setenv("GROUP_DOMAIN", origGroup)
	})

	os.Unsetenv("USER_SITE")
	os.Unsetenv("MOD_SITE")
	os.Unsetenv("IMAGE_DOMAIN")
	os.Unsetenv("IMAGE_ARCHIVED_DOMAIN")
	os.Unsetenv("GROUP_DOMAIN")

	if !isValidRedirectURL("http://localhost/") {
		t.Errorf("localhost should always be allowed")
	}
	if !isValidRedirectURL("https://modtools.org/x") {
		t.Errorf("modtools.org should always be allowed")
	}
	if isValidRedirectURL("https://unknown.example.net/") {
		t.Errorf("unknown domain must be invalid when env unset")
	}
}

func TestGenerateTrackingID(t *testing.T) {
	seen := map[string]bool{}
	for i := 0; i < 50; i++ {
		id := generateTrackingID()
		if len(id) != 32 {
			t.Errorf("generateTrackingID len = %d, want 32", len(id))
		}
		for _, c := range id {
			if !strings.ContainsRune("0123456789abcdef", c) {
				t.Errorf("generateTrackingID produced non-hex char %q in %q", c, id)
			}
		}
		if seen[id] {
			t.Errorf("generateTrackingID collision on %q", id)
		}
		seen[id] = true
	}
}

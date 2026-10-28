package auth_test

import (
	"testing"

	"github.com/freegle/iznik-server-go/auth"
)

func TestSocialLoginMethod(t *testing.T) {
	cases := []struct{ provider, uid, want string }{
		{"Google", "1234567890", "Using Google 1234567890"},
		{"Facebook", "abc", "Using Facebook abc"},
		{"Apple", "", "Using Apple"},
		{"", "", "Using "},
	}
	for _, c := range cases {
		if got := auth.SocialLoginMethod(c.provider, c.uid); got != c.want {
			t.Errorf("SocialLoginMethod(%q,%q) = %q, want %q", c.provider, c.uid, got, c.want)
		}
	}
}

func TestLoginMethodConstants(t *testing.T) {
	if auth.LoginMethodPassword != "Using email/password" || auth.LoginMethodLink != "Using link" {
		t.Errorf("login method wording changed: %q / %q", auth.LoginMethodPassword, auth.LoginMethodLink)
	}
}

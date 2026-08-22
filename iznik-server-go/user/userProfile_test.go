package user

import (
	"os"
	"strings"
	"testing"
)

const gravatarURL = "https://www.gravatar.com/avatar/992d65128ea079d3a407f734bb1f3ce2?s=200&d=identicon&r=g"

// Gravatar is on the common tracker blocklists, so a member running a blocker
// gets no image and ProfileImage.vue shows a generated avatar in its place -
// reported as an avatar having "changed" when nothing about it had. Mail has
// always proxied these; the website must resolve them the same way, or the same
// member has two different faces depending on where you look at them.
func TestProfileSetPathProxiesGravatar(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.example.org")
	defer os.Unsetenv("IMAGE_DELIVERY")

	var p UserProfile
	ProfileSetPath(2901451, gravatarURL, "", nil, 0, &p)

	if !strings.HasPrefix(p.Path, "https://delivery.example.org?url=") {
		t.Errorf("gravatar not served through the delivery proxy: %s", p.Path)
	}

	if !strings.Contains(p.Path, "gravatar.com%2Favatar%2F992d65128ea079d3a407f734bb1f3ce2") {
		t.Errorf("proxied URL lost the image it is meant to fetch: %s", p.Path)
	}

	if p.Paththumb != p.Path {
		t.Errorf("thumb and full path disagree: %s vs %s", p.Paththumb, p.Path)
	}

	if p.Ours {
		t.Error("proxying somebody else's image does not make it ours")
	}
}

// The other external hosts serve to a member's browser but refuse ours: a fetch
// of a stored lh3.googleusercontent.com avatar answers 400, and the proxy can
// only pass that on. Proxying every external host would break the 23,701 Google
// and 30,409 Facebook profiles that work today in order to fix Gravatar.
func TestProfileSetPathLeavesOtherExternalHostsAlone(t *testing.T) {
	os.Setenv("IMAGE_DELIVERY", "https://delivery.example.org")
	defer os.Unsetenv("IMAGE_DELIVERY")

	for _, url := range []string{
		"https://lh3.googleusercontent.com/a/ACg8ocIcAUVOflRylvJTD7pMw4HMw34HBL_lSQrEFkEd",
		"https://graph.facebook.com/1234567890/picture?type=large",
		"https://trashnothing.com/profile-image/abc123",
	} {
		var p UserProfile
		ProfileSetPath(1, url, "", nil, 0, &p)

		if p.Path != url || p.Paththumb != url {
			t.Errorf("%s was rewritten to %s; only gravatar goes through the proxy", url, p.Path)
		}
	}
}

// Matched on host, so that a path or query mentioning gravatar cannot pull an
// unrelated host - including a lookalike domain - into our proxy.
func TestIsGravatarMatchesHostNotSubstring(t *testing.T) {
	cases := map[string]bool{
		"https://www.gravatar.com/avatar/abc":  true,
		"https://gravatar.com/avatar/abc":      true,
		"https://secure.gravatar.com/avatar/x": true,
		"https://example.com/?u=gravatar.com":  false,
		"https://gravatar.com.evil.net/a":      false,
		"https://notgravatar.com/avatar/x":     false,
		"":                                     false,
	}

	for url, want := range cases {
		if got := isGravatar(url); got != want {
			t.Errorf("isGravatar(%q) = %v, want %v", url, got, want)
		}
	}
}

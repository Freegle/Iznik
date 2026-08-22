package user

import (
	"encoding/json"
	"github.com/freegle/iznik-server-go/misc"
	neturl "net/url"
	"os"
	"strconv"
	"strings"
)

type UserProfile struct {
	ID           uint64          `json:"id" gorm:"primary_key"`
	Userid       uint64          `json:"-"`
	Path         string          `json:"path"`
	Paththumb    string          `json:"paththumb"`
	Ours         bool            `json:"ours"`
	Externaluid  string          `json:"externaluid"`
	Ouruid       string          `json:"ouruid"`
	Externalmods json.RawMessage `json:"externalmods"`
}

// isGravatar reports whether an external profile URL is a Gravatar one. Matched
// on the host, not as a substring: a path or query string mentioning gravatar
// must not pull an unrelated host into our proxy.
func isGravatar(raw string) bool {
	parsed, err := neturl.Parse(raw)

	if err != nil {
		return false
	}

	host := strings.ToLower(parsed.Hostname())

	return host == "gravatar.com" || strings.HasSuffix(host, ".gravatar.com")
}

func ProfileSetPath(profileid uint64, url string, externaluid string, externalmods json.RawMessage, archived int, profile *UserProfile) {
	profile.ID = profileid

	if len(url) > 0 {
		// External.
		//
		// Gravatar goes through our delivery proxy; every other external host is
		// linked as stored.
		//
		// gravatar.com is on the common tracker blocklists, so for a member
		// running a blocker the image fails in their browser and
		// ProfileImage.vue falls back to a generated avatar. A moderator
		// reported that on 2026-08-22 as his icon having "changed", while the
		// stored identicon was untouched - its URL is still the md5 of his own
		// address - and served fine from our side. The email paths (amp.go,
		// emailtracking/compact.go) already proxy these same URLs, which is why
		// his identicon kept appearing in mail while the website showed a
		// generated one.
		//
		// Deliberately NOT applied to the other external hosts. They serve to a
		// member's browser but refuse our servers: a fetch of a stored
		// lh3.googleusercontent.com avatar answers 400, and the proxy can only
		// pass that on. Proxying everything external would therefore break the
		// 23,701 Google and 30,409 Facebook profiles that work today, to fix
		// Gravatar.
		if isGravatar(url) {
			profile.Path = misc.GetExternalImageDeliveryUrl(url, "")
			profile.Paththumb = profile.Path
		} else {
			profile.Path = url
			profile.Paththumb = url
		}

		profile.Ours = false
	} else if len(externaluid) > 0 {
		// Uploadcare is retired; every uid in circulation is a freegletusd- one. Ouruid is still
		// returned alongside the path for client code that reads it.
		if strings.Contains(externaluid, "freegletusd-") {
			profile.Ouruid = externaluid
			profile.Externalmods = externalmods
			profile.Path = misc.GetImageDeliveryUrl(externaluid, string(externalmods))
			profile.Paththumb = misc.GetImageDeliveryUrl(externaluid, string(externalmods))
		}
	} else if archived > 0 {
		// Archived.
		profile.Path = "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/uimg_" + strconv.FormatUint(profileid, 10) + ".jpg"
		profile.Paththumb = "https://" + os.Getenv("IMAGE_ARCHIVED_DOMAIN") + "/tuimg_" + strconv.FormatUint(profileid, 10) + ".jpg"
		profile.Ours = true
	} else {
		// Still in DB.
		profile.Path = "https://" + os.Getenv("IMAGE_DOMAIN") + "/uimg_" + strconv.FormatUint(profileid, 10) + ".jpg"
		profile.Paththumb = "https://" + os.Getenv("IMAGE_DOMAIN") + "/tuimg_" + strconv.FormatUint(profileid, 10) + ".jpg"
		profile.Ours = true
	}
}

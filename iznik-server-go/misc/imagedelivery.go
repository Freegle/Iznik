package misc

import (
	"encoding/json"
	"fmt"
	"net/url"
	"os"
	"strings"
)

// BuildChatImageUrl constructs the full and thumbnail URLs for a chat image.
// It uses external delivery if imageuid is set, the archived domain if archived > 0,
// or the live domain otherwise.
func BuildChatImageUrl(imageid uint64, imageuid string, imagemods string, archived int) (path string, paththumb string) {
	if imageuid != "" {
		url := GetImageDeliveryUrl(imageuid, imagemods)
		return url, url
	}
	idStr := fmt.Sprintf("%d", imageid)
	if archived > 0 {
		domain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
		return "https://" + domain + "/mimg_" + idStr + ".jpg",
			"https://" + domain + "/tmimg_" + idStr + ".jpg"
	}
	domain := os.Getenv("IMAGE_DOMAIN")
	return "https://" + domain + "/mimg_" + idStr + ".jpg",
		"https://" + domain + "/tmimg_" + idStr + ".jpg"
}

func GetImageDeliveryUrl(uid string, mods string) string {
	// We construct a wsrv.nl-compatible URL which points at our caching proxy.
	DELIVERY := os.Getenv("IMAGE_DELIVERY")
	UPLOADS := os.Getenv("UPLOADS")

	if len(DELIVERY) == 0 {
		DELIVERY = "https://delivery.ilovefreegle.org"
	}

	if len(UPLOADS) == 0 {
		UPLOADS = "https://uploads.ilovefreegle.org:8080/"
	}

	// Ensure DELIVERY doesn't already have ?url= appended (backward compat).
	DELIVERY = strings.TrimSuffix(DELIVERY, "?url=")

	// Strip "freegletusd-" prefix from the UID if present.
	if len(uid) > 12 {
		uid = uid[12:]
	}
	url := DELIVERY + "?url=" + UPLOADS + uid

	if len(mods) > 0 {
		// Add the stored mods to the URL.  Currently only rotate is stored.
		var modifiers = struct {
			Rotate int `json:"rotate"`
		}{}

		err := json.Unmarshal([]byte(mods), &modifiers)

		if err == nil {
			url += fmt.Sprintf("&ro=%d", modifiers.Rotate)
		}
	}

	return url
}

// deliveryBase returns the delivery proxy base URL without any trailing
// "?url=" (backward compat with older config values).
func deliveryBase() string {
	DELIVERY := os.Getenv("IMAGE_DELIVERY")

	if len(DELIVERY) == 0 {
		DELIVERY = "https://delivery.ilovefreegle.org"
	}

	return strings.TrimSuffix(DELIVERY, "?url=")
}

// rotateParam renders the "ro=N&" query fragment from stored externalmods,
// or "" when there is no rotation. V1 puts ro before url, so the fragment
// ends with & for prepending.
func rotateParam(mods string) string {
	if len(mods) == 0 {
		return ""
	}

	var modifiers = struct {
		Rotate int `json:"rotate"`
	}{}

	if err := json.Unmarshal([]byte(mods), &modifiers); err != nil || modifiers.Rotate == 0 {
		return ""
	}

	return fmt.Sprintf("ro=%d&", modifiers.Rotate)
}

// GetExternalImageDeliveryUrl builds the delivery URL for an image hosted
// elsewhere (V1 Attachment::getExternalImageDeliveryUrl - e.g. TrashNothing
// images referenced by messages_attachments.externalurl).
func GetExternalImageDeliveryUrl(externalurl string, mods string) string {
	return deliveryBase() + "?" + rotateParam(mods) + "url=" + url.QueryEscape(externalurl)
}

// GetArchivedImageDeliveryUrl builds the delivery URL for a legacy attachment
// archived to Azure storage (the archive branch of V1
// Attachment::canRedirect). prefix is the per-type filename prefix
// (img/mimg/fimg/cimg/bimg); w/h are optional resize params - the archive
// case is the only one where V1 honours them.
func GetArchivedImageDeliveryUrl(prefix string, id uint64, w int, h int) string {
	ARCHIVED := os.Getenv("IMAGE_ARCHIVED_DOMAIN")

	ret := deliveryBase() + "?"

	if w > 0 {
		ret += fmt.Sprintf("w=%d&", w)
	}

	if h > 0 {
		ret += fmt.Sprintf("h=%d&", h)
	}

	return ret + "url=" + url.QueryEscape(fmt.Sprintf("https://%s/%s_%d.jpg", ARCHIVED, prefix, id))
}

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
	if archived > 0 {
		return ArchivedImageUrls("mimg", imageid)
	}
	idStr := fmt.Sprintf("%d", imageid)
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
		// Add the stored mods to the URL: the rotation, and the crop of an auto-split
		// item photo.
		if m, ok := ParseMods(mods); ok {
			url += fmt.Sprintf("&ro=%d", m.Rotate)
			if c := m.Crop(); c != nil {
				url += fmt.Sprintf("&cx=%d&cy=%d&cw=%d&ch=%d&precrop=true", c.X, c.Y, c.W, c.H)
			}
		}
	}

	return url
}

// Mods is what an image row's externalmods holds. Rotate is the turn the poster gave the
// photo, in degrees clockwise. Cx, Cy, Cw and Ch are a crop, in pixels of the photo as
// turned; Fw and Fh are that turned photo's size, kept so the crop can turn with it. The
// auto-splitter writes a crop for each item in a photo of several items, one row per item.
// The delivery service applies ro first and then the crop; precrop crops before any resize.
// docs/developers/reference/auto-split.md.
type Mods struct {
	Rotate int `json:"rotate,omitempty"`
	Cx     int `json:"cx,omitempty"`
	Cy     int `json:"cy,omitempty"`
	Cw     int `json:"cw,omitempty"`
	Ch     int `json:"ch,omitempty"`
	Fw     int `json:"fw,omitempty"`
	Fh     int `json:"fh,omitempty"`
}

// Crop is a rectangle cut out of a photo, in pixels of the photo as turned.
type Crop struct{ X, Y, W, H int }

// ParseMods reads externalmods. ok is false when it is not a JSON object.
func ParseMods(mods string) (Mods, bool) {
	var m Mods
	if len(mods) == 0 {
		return m, false
	}
	if err := json.Unmarshal([]byte(mods), &m); err != nil {
		return m, false
	}
	return m, true
}

// Crop returns the crop the mods describe, or nil when there is none.
func (m Mods) Crop() *Crop {
	if m.Cw <= 0 || m.Ch <= 0 || m.Cx < 0 || m.Cy < 0 {
		return nil
	}
	return &Crop{X: m.Cx, Y: m.Cy, W: m.Cw, H: m.Ch}
}

// IsCrop reports whether an image row's externalmods describe a crop.
func IsCrop(mods string) bool {
	m, ok := ParseMods(mods)
	return ok && m.Crop() != nil
}

// Rotated returns the mods for the photo turned to an absolute rotation of degrees. A crop
// turns with the photo: each quarter turn clockwise moves a rectangle (x, y, w, h) in a
// W x H photo to (H - y - h, x, h, w) in the H x W photo. A crop whose photo size was not
// kept cannot be turned, so it is dropped and the whole photo shows rather than the wrong
// part of it.
func (m Mods) Rotated(degrees int) Mods {
	out := Mods{Rotate: degrees}
	c := m.Crop()
	if c == nil || m.Fw <= 0 || m.Fh <= 0 {
		return out
	}

	x, y, w, h, fw, fh := c.X, c.Y, c.W, c.H, m.Fw, m.Fh
	steps := (((degrees - m.Rotate) % 360) + 360) % 360 / 90
	for i := 0; i < steps; i++ {
		x, y, w, h = fh-y-h, x, h, w
		fw, fh = fh, fw
	}
	out.Cx, out.Cy, out.Cw, out.Ch, out.Fw, out.Fh = x, y, w, h, fw, fh
	return out
}

// JSON renders the mods for storing in externalmods. A crop carries "precrop":true, which
// is for the delivery service: the frontend hands it every key.
func (m Mods) JSON() string {
	b, _ := json.Marshal(m)
	if m.Crop() != nil {
		return strings.TrimSuffix(string(b), "}") + `,"precrop":true}`
	}
	return string(b)
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

// ArchivedThumbSize is the box a thumbnail of an archived image is resized into, the size the
// images.ilovefreegle.org t*img_ rewrites ask for.
const ArchivedThumbSize = 250

// ArchivedImageUrls builds the full-size and thumbnail URLs for an image V1 archived
// (archived > 0) under prefix, from IMAGE_ARCHIVED_DOMAIN. V1 wrote a t-prefixed thumbnail blob
// beside each image, but those are not copied off Azure (docs/ops/runbooks/azure-archive-migration.md),
// so once the domain is our archive address the thumbnail is made from the full image by the
// delivery resizer. While the domain is still Azure, V1's own thumbnail is used, so that members'
// Azure egress does not change until the address is switched on purpose.
func ArchivedImageUrls(prefix string, id uint64) (path string, paththumb string) {
	domain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	path = fmt.Sprintf("https://%s/%s_%d.jpg", domain, prefix, id)

	if ArchivedDomainIsAzure(domain) {
		return path, fmt.Sprintf("https://%s/t%s_%d.jpg", domain, prefix, id)
	}

	return path, GetArchivedImageDeliveryUrl(prefix, id, ArchivedThumbSize, ArchivedThumbSize)
}

// ArchivedDomainIsAzure reports whether the archive is still served straight from V1's Azure
// container, where V1's thumbnail blobs exist.
func ArchivedDomainIsAzure(domain string) bool {
	return strings.Contains(strings.ToLower(domain), ".blob.core.windows.net")
}

// GetArchivedImageDeliveryUrl builds the delivery URL for a legacy attachment
// archived to Azure storage (the archive branch of V1
// Attachment::canRedirect). prefix is the per-type filename prefix
// (img/mimg/fimg/cimg); w/h are optional resize params - the archive
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

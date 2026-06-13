package emailtracking

import (
	"crypto/md5"
	"encoding/base64"
	"encoding/binary"
	"encoding/hex"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/gofiber/fiber/v2"
)

// Compact email tracking URLs
//
// The Laravel batch side (see iznik-batch App\Models\EmailTracking) emits
// shrunk tracking URLs for emails with many of OUR OWN resources (e.g. the
// multi-post digest). Instead of base64-encoding a full destination URL per
// link, the destination is encoded as {type}+{id} and reconstructed here:
//
//	link:  /e/d/r/{ref}/{type}/{idEnc}/{pos}
//	image: /e/d/i/{ref}/{type}/{idEnc}/{preset}/{pos}
//
// where:
//   - {ref}    = first 12 chars of the 32-char tracking_id (looked up by prefix)
//   - {idEnc}  = base64url (RFC4648 §5, no padding) of the id's minimal
//     big-endian bytes — see DecodeID / the PHP encodeId()
//   - {pos}    = opaque short analytics label, stored like the legacy `p` param
//   - {preset} = index into presetDimensions (shared with the batch)
//
// The legacy single-segment routes (/e/d/r/{id}, /e/d/i/{id}) are untouched —
// these have more path segments so Fiber matches them as distinct routes.

// presetDimension is a shared image dimension preset. The numbers MUST match
// the batch UnifiedDigest presets passed to trackedResourceImageUrl():
//
//	't' 0 -> 240x240 cover (daily card thumbnail)
//	't' 1 -> 600x400 cover (immediate hero)
//	'u' 2 -> 22x22         (poster avatar)
type presetDimension struct {
	Width  int
	Height int
	Cover  bool
}

var presetDimensions = map[int]presetDimension{
	0: {Width: 240, Height: 240, Cover: true},
	1: {Width: 600, Height: 400, Cover: true},
	2: {Width: 22, Height: 22, Cover: false},
}

// DecodeID reverses the batch encodeId(): base64url-decode (no padding) to the
// id's minimal big-endian bytes, then read those bytes as a big-endian uint64.
// Returns (0, false) on malformed input so callers can redirect gracefully.
func DecodeID(enc string) (uint64, bool) {
	if enc == "" {
		return 0, false
	}
	// PHP encodeId(0) and any non-positive id emit the literal "0".
	if enc == "0" {
		return 0, true
	}

	raw, err := base64.RawURLEncoding.DecodeString(enc)
	if err != nil || len(raw) == 0 || len(raw) > 8 {
		return 0, false
	}

	// Left-pad to 8 bytes so we can read a big-endian uint64. The batch emits
	// the *minimal* big-endian bytes (no leading zero byte), so a 1..8 byte
	// slice is valid.
	var buf [8]byte
	copy(buf[8-len(raw):], raw)
	return binary.BigEndian.Uint64(buf[:]), true
}

// findTrackingByRef looks up an EmailTracking row by the 12-char compact ref
// using an index-usable prefix LIKE. The ref carries ~72 bits of the original
// 32-char random tracking_id, so collisions are negligible.
func findTrackingByRef(ref string) (*EmailTracking, bool) {
	if ref == "" {
		return nil, false
	}
	db := database.DBConn
	var tracking EmailTracking
	// LIKE 'ref%' is index-usable (no leading wildcard). Escape any LIKE
	// metacharacters that could appear — tracking_id is [A-Za-z0-9] from
	// Str::random so this is belt-and-braces.
	pattern := strings.NewReplacer("%", "\\%", "_", "\\_").Replace(ref) + "%"
	result := db.Where("tracking_id LIKE ?", pattern).First(&tracking)
	if result.Error != nil {
		return nil, false
	}
	return &tracking, true
}

// userSiteBase returns the user-facing site as a full https:// base URL.
// USER_SITE is a bare host (e.g. www.ilovefreegle.org).
func userSiteBase() string {
	userSite := os.Getenv("USER_SITE")
	if userSite == "" {
		userSite = "www.ilovefreegle.org"
	}
	return "https://" + userSite
}

// reconstructCompactLink builds the destination URL for a compact link from
// its {type} + decoded id, mirroring the batch prepareCard() choices.
// Returns "" for unknown types.
func reconstructCompactLink(linkType string, id uint64) string {
	base := userSiteBase()
	idStr := strconv.FormatUint(id, 10)
	switch linkType {
	case "m":
		// Message card link — auto-opens the reply compose form.
		return base + "/message/" + idStr + "?reply=1"
	case "s":
		// Summary "In this digest" index link — just jumps to the post.
		return base + "/message/" + idStr
	case "g":
		// Group byline — /explore/{id} resolves the numeric group id.
		return base + "/explore/" + idStr
	default:
		return ""
	}
}

// deliveryBase returns the image delivery/resizing service as a full https://
// base URL. DELIVERY_DOMAIN is a bare host (e.g. delivery.ilovefreegle.org).
func deliveryBase() string {
	deliveryDomain := os.Getenv("DELIVERY_DOMAIN")
	if deliveryDomain == "" {
		deliveryDomain = "delivery.ilovefreegle.org"
	}
	return "https://" + deliveryDomain
}

// imagesDomainHost returns the bare images host (e.g. images.ilovefreegle.org).
func imagesDomainHost() string {
	imageDomain := os.Getenv("IMAGE_DOMAIN")
	if imageDomain == "" {
		imageDomain = "images.ilovefreegle.org"
	}
	return imageDomain
}

// buildDeliveryURL mirrors the batch getDeliveryUrl(): resize sourceURL via the
// delivery service. When cover is set a fixed-box cover crop is applied (the
// shape the batch uses for hero/thumbnail presets).
func buildDeliveryURL(sourceURL string, dim presetDimension) string {
	out := deliveryBase() + "/?url=" + url.QueryEscape(sourceURL) + "&w=" + strconv.Itoa(dim.Width)
	if dim.Cover && dim.Height > 0 {
		out += "&h=" + strconv.Itoa(dim.Height) + "&fit=cover"
	}
	return out
}

// reconstructCompactImage builds the delivery URL for a compact image from its
// {type} + decoded id + preset. Returns "" for unknown types.
func reconstructCompactImage(imageType string, id uint64, preset int) string {
	dim, ok := presetDimensions[preset]
	if !ok {
		return ""
	}

	switch imageType {
	case "t":
		// Message photo: timg_{id}.jpg on the images domain, resized via delivery.
		sourceURL := "https://" + imagesDomainHost() + "/timg_" + strconv.FormatUint(id, 10) + ".jpg"
		return buildDeliveryURL(sourceURL, dim)
	case "u":
		// User avatar — reconstruct the same URL the AMP chat reply handler
		// builds (custom image / Gravatar / default), sized per preset.
		return reconstructAvatarURL(id, dim.Width)
	default:
		return ""
	}
}

// reconstructAvatarURL rebuilds a user's avatar URL, matching the resolution
// order used by amp.getUserInfo: external image URL, then users_images thumbnail
// (tuimg_), then Gravatar (routed through the delivery service for resizing),
// then the default profile image.
func reconstructAvatarURL(userID uint64, width int) string {
	db := database.DBConn
	var result struct {
		ImageID  *uint64
		ImageURL *string
		Email    *string
	}
	db.Raw(`
		SELECT ui.id AS image_id, ui.url AS image_url, ue.email AS email
		FROM users u
		LEFT JOIN users_images ui ON ui.userid = u.id
		LEFT JOIN users_emails ue ON ue.userid = u.id
		WHERE u.id = ?
		ORDER BY ui.default DESC, ui.id ASC, ue.preferred DESC
		LIMIT 1
	`, userID).Scan(&result)

	imageDomain := imagesDomainHost()

	if result.ImageURL != nil && *result.ImageURL != "" {
		return *result.ImageURL
	}
	if result.ImageID != nil {
		return "https://" + imageDomain + "/tuimg_" + strconv.FormatUint(*result.ImageID, 10) + ".jpg"
	}
	if result.Email != nil && *result.Email != "" {
		emailHash := md5.Sum([]byte(strings.ToLower(strings.TrimSpace(*result.Email))))
		gravatarURL := "https://www.gravatar.com/avatar/" + hex.EncodeToString(emailHash[:]) + "?s=200&d=identicon&r=g"
		return deliveryBase() + "/?url=" + url.QueryEscape(gravatarURL) + "&w=" + strconv.Itoa(width)
	}
	return "https://" + imageDomain + "/defaultprofile.png"
}

// ClickCompact records a click on a compact tracking link and 302s to the
// reconstructed destination. Mirrors Click()'s tracking writes.
//
// @Router /e/d/r/{ref}/{type}/{idenc}/{pos} [get]
// @Summary Compact delivery redirect
// @Description Reconstructs an internal destination URL from type+id and redirects
// @Tags delivery
// @Param ref path string true "12-char tracking ref"
// @Param type path string true "Resource type (m, s, g)"
// @Param idenc path string true "base64url-encoded resource id"
// @Param pos path string true "Position label"
// @Success 302 {string} string "Redirect"
func ClickCompact(c *fiber.Ctx) error {
	ref := c.Params("ref")
	linkType := c.Params("type")
	idEnc := c.Params("idenc")
	position := c.Params("pos")

	id, ok := DecodeID(idEnc)
	if !ok {
		return c.Redirect("/")
	}

	destinationURL := reconstructCompactLink(linkType, id)
	if destinationURL == "" {
		return c.Redirect("/")
	}

	db := database.DBConn

	tracking, found := findTrackingByRef(ref)
	if !found {
		// Unknown ref — still send the user to the right place, just untracked.
		return c.Redirect(destinationURL)
	}

	ipAddress := c.IP()
	userAgent := c.Get("User-Agent")
	now := time.Now()

	if tracking.OpenedAt == nil {
		openedVia := "click"
		db.Model(tracking).Updates(map[string]interface{}{
			"opened_at":  now,
			"opened_via": openedVia,
		})
	}

	if tracking.ClickedAt == nil {
		db.Model(tracking).Updates(map[string]interface{}{
			"clicked_at":   now,
			"clicked_link": destinationURL,
		})
	}

	db.Model(tracking).UpdateColumn("links_clicked", tracking.LinksClicked+1)

	click := EmailTrackingClick{
		EmailTrackingID: tracking.ID,
		LinkURL:         destinationURL,
		LinkPosition:    &position,
		IPAddress:       &ipAddress,
		UserAgent:       &userAgent,
		ClickedAt:       now,
	}
	db.Create(&click)

	return c.Redirect(destinationURL)
}

// ImageCompact records an image load on a compact image link and 302s to the
// reconstructed delivery URL. Mirrors Image()'s tracking writes.
//
// @Router /e/d/i/{ref}/{type}/{idenc}/{preset}/{pos} [get]
// @Summary Compact delivery image
// @Description Reconstructs an image delivery URL from type+id+preset and redirects
// @Tags delivery
// @Param ref path string true "12-char tracking ref"
// @Param type path string true "Resource type (t, u)"
// @Param idenc path string true "base64url-encoded resource id"
// @Param preset path int true "Dimension preset (0,1,2)"
// @Param pos path string true "Position label"
// @Success 302 {string} string "Redirect to image"
func ImageCompact(c *fiber.Ctx) error {
	ref := c.Params("ref")
	imageType := c.Params("type")
	idEnc := c.Params("idenc")
	presetStr := c.Params("preset")
	position := c.Params("pos")

	id, ok := DecodeID(idEnc)
	if !ok {
		c.Set("Content-Type", "image/gif")
		return c.Send(transparentGIF)
	}

	preset, err := strconv.Atoi(presetStr)
	if err != nil {
		c.Set("Content-Type", "image/gif")
		return c.Send(transparentGIF)
	}

	imageURL := reconstructCompactImage(imageType, id, preset)
	if imageURL == "" {
		c.Set("Content-Type", "image/gif")
		return c.Send(transparentGIF)
	}

	db := database.DBConn

	if tracking, found := findTrackingByRef(ref); found {
		now := time.Now()

		if tracking.OpenedAt == nil {
			openedVia := "image"
			db.Model(tracking).Updates(map[string]interface{}{
				"opened_at":  now,
				"opened_via": openedVia,
			})
		}

		imageLoad := EmailTrackingImage{
			EmailTrackingID: tracking.ID,
			ImagePosition:   position,
			LoadedAt:        now,
		}
		db.Create(&imageLoad)

		db.Model(tracking).UpdateColumn("images_loaded", tracking.ImagesLoaded+1)
	}

	return c.Redirect(imageURL)
}

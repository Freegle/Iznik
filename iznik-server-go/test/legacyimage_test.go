package test

import (
	"fmt"
	"io"
	"net/http/httptest"
	"net/url"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/stretchr/testify/assert"
	"github.com/gofiber/fiber/v2"
)

// Tests for GET /api/image - the legacy *img_N.jpg URL resolution that
// replaces the last live V1 endpoint (front-end migration plan Stage 4).
// Assertions are env-agnostic (contains, not equals) so they hold whatever
// IMAGE_DELIVERY / IMAGE_ARCHIVED_DOMAIN / USER_SITE are set to; the exact
// URL forms are unit-tested in misc/imagedelivery_test.go.

func legacyImageGet(t *testing.T, query string) (int, string) {
	req := httptest.NewRequest("GET", "/api/image"+query, nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	return resp.StatusCode, resp.Header.Get("Location")
}

// insertLegacyRow inserts a fixture row and returns its id via LastInsertId
// on the same connection (the house pattern from image.go doCreate - a
// separate SELECT LAST_INSERT_ID() is unsafe under parallel load, and the
// image tables' hash column is varchar(16), too short to use uniquePrefix
// markers as lookup keys).
func insertLegacyRow(t *testing.T, query string, args ...interface{}) uint64 {
	db := database.DBConn
	sqlDB, err := db.DB()
	assert.NoError(t, err)
	res, err := sqlDB.Exec(query, args...)
	assert.NoError(t, err)
	lastID, err := res.LastInsertId()
	assert.NoError(t, err)
	assert.NotZero(t, lastID)
	return uint64(lastID)
}

func TestLegacyImageNoID(t *testing.T) {
	status, loc := legacyImageGet(t, "")
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "/defaultprofile.png")
}

func TestLegacyImageUnknownID(t *testing.T) {
	status, loc := legacyImageGet(t, "?id=999999999")
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "/defaultprofile.png")
}

func TestLegacyImageExternalUID(t *testing.T) {
	prefix := uniquePrefix("LegacyImgUID")
	id := insertLegacyRow(t,
		"INSERT INTO messages_attachments (externaluid, externalmods) VALUES (?, ?)",
		"freegletusd-legacy"+prefix, `{"rotate":90}`)

	status, loc := legacyImageGet(t, fmt.Sprintf("?id=%d&w=250&h=250", id))
	assert.Equal(t, fiber.StatusFound, status)
	// Delivered via the caching proxy, freegletusd- prefix stripped, rotation
	// mod applied. V1 ignores w/h for tusd-uploaded images, so no w=/h=.
	assert.Contains(t, loc, "url=")
	assert.Contains(t, loc, "legacy"+prefix)
	assert.NotContains(t, loc, "freegletusd-")
	assert.Contains(t, loc, "ro=90")
	assert.NotContains(t, loc, "w=250")
}

func TestLegacyImageUserFlag(t *testing.T) {
	prefix := uniquePrefix("LegacyImgUser")
	id := insertLegacyRow(t,
		"INSERT INTO users_images (externaluid, contenttype) VALUES (?, ?)",
		"freegletusd-avatar"+prefix, "image/jpeg")

	// tuimg_ rewrites to user=1&w=100&h=100.
	status, loc := legacyImageGet(t, fmt.Sprintf("?id=%d&user=1&w=100&h=100", id))
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "avatar"+prefix)
	assert.NotContains(t, loc, "freegletusd-")
}

func TestLegacyImageExternalURL(t *testing.T) {
	prefix := uniquePrefix("LegacyImgExt")
	external := "https://photos.example.com/" + prefix + ".jpg"
	id := insertLegacyRow(t,
		"INSERT INTO messages_attachments (externalurl) VALUES (?)", external)

	status, loc := legacyImageGet(t, fmt.Sprintf("?id=%d", id))
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "url="+url.QueryEscape(external))
}

func TestLegacyImageArchived(t *testing.T) {
	id := insertLegacyRow(t, "INSERT INTO messages_attachments (archived) VALUES (1)")

	// timg_ form: archived rows are the one case where V1 honours w/h.
	status, loc := legacyImageGet(t, fmt.Sprintf("?id=%d&w=250&h=250", id))
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "w=250&h=250&url=")
	assert.Contains(t, loc, url.QueryEscape(fmt.Sprintf("img_%d.jpg", id)))
}

func TestLegacyImageNoBytesRowFallsBack(t *testing.T) {
	// A row with no external upload AND no data bytes (nothing to serve) falls back
	// to the default profile image.
	id := insertLegacyRow(t,
		"INSERT INTO users_images (contenttype) VALUES (?)", "image/jpeg")

	status, loc := legacyImageGet(t, fmt.Sprintf("?id=%d&user=1&w=100&h=100", id))
	assert.Equal(t, fiber.StatusFound, status)
	assert.Contains(t, loc, "/defaultprofile.png")
}

func TestLegacyImageDataBlobServed(t *testing.T) {
	// A pre-tusd row whose bytes live in the legacy `data` column must be SERVED
	// from the DB, not redirected to the default. Retiring V1's image.php dropped
	// this, leaving most blob-stored images (still blob-stored) showing the Freegle
	// logo. We serve the bytes with the row's content type.
	blob := []byte{0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x01, 0x02, 0x03} // PNG magic + a few bytes
	id := insertLegacyRow(t,
		"INSERT INTO users_images (contenttype, data) VALUES (?, ?)", "image/png", blob)

	req := httptest.NewRequest("GET", fmt.Sprintf("/api/image?id=%d&user=1", id), nil)
	resp, err := getApp().Test(req)
	assert.NoError(t, err)
	// Served inline (200), not a redirect to the default.
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/png", resp.Header.Get("Content-Type"))
	body, _ := io.ReadAll(resp.Body)
	assert.Equal(t, blob, body)
}

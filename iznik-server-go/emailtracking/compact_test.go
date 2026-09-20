package emailtracking

import (
	"encoding/json"
	"net/http/httptest"
	"net/url"
	"os"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// DecodeID — round-trips the PHP encodeId() (base64url of minimal big-endian
// bytes). Expected encodings computed from the PHP definition.
// ---------------------------------------------------------------------------

func TestDecodeID_RoundTrip(t *testing.T) {
	cases := []struct {
		id  uint64
		enc string
	}{
		{1, "AQ"},
		{255, "_w"},
		{256, "AQA"},
		{120414521, "By1hOQ"},
		{16777215, "____"},
		{16777216, "AQAAAA"},
	}
	for _, tc := range cases {
		got, ok := DecodeID(tc.enc)
		assert.True(t, ok, "decode %q should succeed", tc.enc)
		assert.Equal(t, tc.id, got, "decode %q", tc.enc)
	}
}

func TestDecodeID_Zero(t *testing.T) {
	// PHP encodeId() emits the literal "0" for non-positive ids.
	got, ok := DecodeID("0")
	assert.True(t, ok)
	assert.Zero(t, got)
}

func TestDecodeID_Invalid(t *testing.T) {
	for _, bad := range []string{"", "!!!", "====", "AQABCDEFGHIJ"} {
		_, ok := DecodeID(bad)
		assert.False(t, ok, "decode %q should fail", bad)
	}
}

// ---------------------------------------------------------------------------
// reconstructCompactLink — destination URL per type+id (no DB).
// ---------------------------------------------------------------------------

func TestReconstructCompactLink(t *testing.T) {
	os.Setenv("USER_SITE", "www.ilovefreegle.org")
	defer os.Unsetenv("USER_SITE")

	assert.Equal(t, "https://www.ilovefreegle.org/message/42?reply=1", reconstructCompactLink("m", 42))
	assert.Equal(t, "https://www.ilovefreegle.org/message/42", reconstructCompactLink("s", 42))
	assert.Equal(t, "https://www.ilovefreegle.org/explore/7", reconstructCompactLink("g", 7))
	assert.Equal(t, "", reconstructCompactLink("x", 42))
}

// ---------------------------------------------------------------------------
// reconstructCompactImage — message photo delivery URL (no DB for type 't').
// Must byte-match the batch getDeliveryUrl() output.
// ---------------------------------------------------------------------------

func TestReconstructCompactImage_MessagePhoto(t *testing.T) {
	os.Setenv("IMAGE_DOMAIN", "images.ilovefreegle.org")
	os.Setenv("DELIVERY_DOMAIN", "delivery.ilovefreegle.org")
	defer os.Unsetenv("IMAGE_DOMAIN")
	defer os.Unsetenv("DELIVERY_DOMAIN")

	source := "https://images.ilovefreegle.org/timg_99.jpg"
	enc := url.QueryEscape(source)

	// preset 0 = 240x240 cover
	assert.Equal(t,
		"https://delivery.ilovefreegle.org/?url="+enc+"&w=240&h=240&fit=cover",
		reconstructCompactImage("t", 99, 0))

	// preset 1 = 600x400 cover
	assert.Equal(t,
		"https://delivery.ilovefreegle.org/?url="+enc+"&w=600&h=400&fit=cover",
		reconstructCompactImage("t", 99, 1))
}

func TestReconstructCompactImage_UnknownPreset(t *testing.T) {
	assert.Equal(t, "", reconstructCompactImage("t", 99, 9))
}

func TestReconstructCompactImage_UnknownType(t *testing.T) {
	assert.Equal(t, "", reconstructCompactImage("z", 99, 0))
}

// ---------------------------------------------------------------------------
// ClickCompact — handler-level: bad id and unknown type redirect to "/".
// (Happy path with a known ref needs a live DB; we stop at the unknown-ref
// boundary which still 302s to the correct reconstructed destination.)
// ---------------------------------------------------------------------------

func newClickCompactApp() *fiber.App {
	app := fiber.New()
	app.Get("/e/d/r/:ref/:type/:idenc/:pos", ClickCompact)
	return app
}

func clickCompactLocation(t *testing.T, app *fiber.App, path string) (int, string) {
	t.Helper()
	req := httptest.NewRequest("GET", path, nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	loc := resp.Header.Get("Location")
	return resp.StatusCode, loc
}

func TestClickCompact_BadID_RedirectsToRoot(t *testing.T) {
	app := newClickCompactApp()
	status, loc := clickCompactLocation(t, app, "/e/d/r/abc123456789/m/!!!/p0")
	assert.Equal(t, 302, status)
	assert.Equal(t, "/", loc)
}

func TestClickCompact_UnknownType_RedirectsToRoot(t *testing.T) {
	app := newClickCompactApp()
	// Valid id (1 -> "AQ") but type "z" is unknown.
	status, loc := clickCompactLocation(t, app, "/e/d/r/abc123456789/z/AQ/p0")
	assert.Equal(t, 302, status)
	assert.Equal(t, "/", loc)
}

func TestClickCompact_UnknownRef_RedirectsToDestination(t *testing.T) {
	os.Setenv("USER_SITE", "www.ilovefreegle.org")
	defer os.Unsetenv("USER_SITE")

	app := newClickCompactApp()
	// Valid id 42 ("By1hOQ" is 120414521; use 42 -> "Kg") + type m.
	enc := encodeForTest(42)
	status, loc := clickCompactLocation(t, app, "/e/d/r/zzzzzzzzzzzz/m/"+enc+"/p0")
	assert.Equal(t, 302, status)
	assert.Equal(t, "https://www.ilovefreegle.org/message/42?reply=1", loc)
}

// ---------------------------------------------------------------------------
// ImageCompact — bad id / bad preset / unknown type fall back to the GIF;
// unknown ref still 302s to the reconstructed delivery URL.
// ---------------------------------------------------------------------------

func newImageCompactApp() *fiber.App {
	app := fiber.New()
	app.Get("/e/d/i/:ref/:type/:idenc/:preset/:pos", ImageCompact)
	return app
}

func TestImageCompact_BadID_ReturnsGIF(t *testing.T) {
	app := newImageCompactApp()
	req := httptest.NewRequest("GET", "/e/d/i/abc123456789/t/!!!/0/i0", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
}

func TestImageCompact_BadPreset_ReturnsGIF(t *testing.T) {
	app := newImageCompactApp()
	req := httptest.NewRequest("GET", "/e/d/i/abc123456789/t/AQ/notanumber/i0", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)
	assert.Equal(t, "image/gif", resp.Header.Get("Content-Type"))
}

func TestImageCompact_UnknownRef_RedirectsToDelivery(t *testing.T) {
	os.Setenv("IMAGE_DOMAIN", "images.ilovefreegle.org")
	os.Setenv("DELIVERY_DOMAIN", "delivery.ilovefreegle.org")
	defer os.Unsetenv("IMAGE_DOMAIN")
	defer os.Unsetenv("DELIVERY_DOMAIN")

	app := newImageCompactApp()
	enc := encodeForTest(99)
	req := httptest.NewRequest("GET", "/e/d/i/zzzzzzzzzzzz/t/"+enc+"/0/i0", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	assert.Equal(t, 302, resp.StatusCode)

	source := "https://images.ilovefreegle.org/timg_99.jpg"
	want := "https://delivery.ilovefreegle.org/?url=" + url.QueryEscape(source) + "&w=240&h=240&fit=cover"
	assert.Equal(t, want, resp.Header.Get("Location"))
}

// encodeForTest mirrors the PHP encodeId(): base64url (no padding) of the id's
// minimal big-endian bytes. Used to build request paths in tests.
func encodeForTest(id uint64) string {
	if id == 0 {
		return "0"
	}
	var b []byte
	for id > 0 {
		b = append([]byte{byte(id & 0xFF)}, b...)
		id >>= 8
	}
	// base64.RawURLEncoding is the same alphabet/no-padding PHP uses.
	return base64RawURL(b)
}

func base64RawURL(b []byte) string {
	const alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_"
	var out []byte
	for i := 0; i < len(b); i += 3 {
		var n uint32
		rem := len(b) - i
		n = uint32(b[i]) << 16
		if rem > 1 {
			n |= uint32(b[i+1]) << 8
		}
		if rem > 2 {
			n |= uint32(b[i+2])
		}
		out = append(out, alphabet[(n>>18)&0x3F], alphabet[(n>>12)&0x3F])
		if rem > 1 {
			out = append(out, alphabet[(n>>6)&0x3F])
		}
		if rem > 2 {
			out = append(out, alphabet[n&0x3F])
		}
	}
	return string(out)
}

// ---------------------------------------------------------------------------
// ?format=json: the app's way of following a tracked link. A universal link
// hands the app the tracker's URL, which it cannot follow across origins, so it
// asks for the destination instead - and gets exactly what a browser would be
// sent to, including the "/" fallback for a link it must not honour.
// ---------------------------------------------------------------------------

func clickCompactJSON(t *testing.T, app *fiber.App, path string) (int, string) {
	t.Helper()
	req := httptest.NewRequest("GET", path, nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	var body struct {
		URL string `json:"url"`
	}
	_ = json.NewDecoder(resp.Body).Decode(&body)
	return resp.StatusCode, body.URL
}

func TestClickCompact_JSON_ReturnsDestinationInsteadOfRedirecting(t *testing.T) {
	os.Setenv("USER_SITE", "www.ilovefreegle.org")
	defer os.Unsetenv("USER_SITE")

	app := newClickCompactApp()
	enc := encodeForTest(42)
	status, url := clickCompactJSON(t, app, "/e/d/r/zzzzzzzzzzzz/m/"+enc+"/p0?format=json")
	assert.Equal(t, 200, status, "JSON mode answers, it does not redirect")
	assert.Equal(t, "https://www.ilovefreegle.org/message/42?reply=1", url)
}

func TestClickCompact_JSON_BadLinkStillAnswersRoot(t *testing.T) {
	app := newClickCompactApp()
	status, url := clickCompactJSON(t, app, "/e/d/r/abc123456789/z/AQ/p0?format=json")
	assert.Equal(t, 200, status)
	assert.Equal(t, "/", url, "a link the redirect would bounce to / is answered as / too")
}

func TestClickCompact_WithoutFormat_StillRedirects(t *testing.T) {
	os.Setenv("USER_SITE", "www.ilovefreegle.org")
	defer os.Unsetenv("USER_SITE")

	app := newClickCompactApp()
	enc := encodeForTest(42)
	status, loc := clickCompactLocation(t, app, "/e/d/r/zzzzzzzzzzzz/m/"+enc+"/p0")
	assert.Equal(t, 302, status, "mail clients and browsers keep the 302")
	assert.Equal(t, "https://www.ilovefreegle.org/message/42?reply=1", loc)
}

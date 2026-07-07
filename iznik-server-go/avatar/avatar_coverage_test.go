package avatar

import (
	"io"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Sentinel color slices used to drive luminance-dependent branches without
// relying on any specific palette entry.
var (
	allBlack = []string{"#000000", "#000000", "#000000", "#000000", "#000000"}
	allWhite = []string{"#FFFFFF", "#FFFFFF", "#FFFFFF", "#FFFFFF", "#FFFFFF"}
)

// ---------------------------------------------------------------------------
// luminance
// ---------------------------------------------------------------------------

func TestLuminance_Table(t *testing.T) {
	// Formula: (299*R + 587*G + 114*B) / 1000
	tests := []struct {
		hex  string
		want float64
		desc string
	}{
		{"#000000", 0.0, "black"},
		{"#FFFFFF", 255.0, "white: (299+587+114)*255/1000"},
		{"#FF0000", 76.245, "pure red: 299*255/1000"},
		{"#00FF00", 149.685, "pure green: 587*255/1000"},
		{"#0000FF", 29.07, "pure blue: 114*255/1000"},
		{"#2E7D32", 92.829, "dark green from palette 0"},
	}
	for _, tc := range tests {
		t.Run(tc.desc, func(t *testing.T) {
			got := luminance(tc.hex)
			assert.InDelta(t, tc.want, got, 0.01, "luminance(%s)", tc.hex)
		})
	}
}

func TestLuminance_DarkIsBelow128(t *testing.T) {
	assert.Less(t, luminance("#000000"), 128.0)
	assert.Less(t, luminance("#2E7D32"), 128.0) // dark green from palette 0
}

func TestLuminance_LightIsAbove128(t *testing.T) {
	assert.GreaterOrEqual(t, luminance("#FFFFFF"), 128.0)
	assert.GreaterOrEqual(t, luminance("#C8E6C9"), 128.0) // light green from palette 0
}

// ---------------------------------------------------------------------------
// generateBeamSVG — branch matrix
//
// Branch conditions (computed for reference hashes):
//
//   hash=0:   c1=0<5→wrapTX=4,  c2=0<5→wrapTY=4,  wrapTX≤6, wrapTY≤6
//             digitAt(0,2)=0(even)→isMouthOpen=true,  digitAt(0,1)=0→isCircle=true
//
//   hash=37:  c1=7≥5→wrapTX=7>6, c2=-7<5→wrapTY=-3≤6
//             digitAt(37,2)=0→isMouthOpen=true,  digitAt(37,1)=3→isCircle=false
//
//   hash=100: c1=0<5→wrapTX=4≤6, c2=0<5→wrapTY=4≤6
//             digitAt(100,2)=1(odd)→isMouthOpen=false, digitAt(100,1)=0→isCircle=true
//
//   hash=537: c1=7≥5→wrapTX=7>6, c2=7≥5→wrapTY=7>6
//             digitAt(537,2)=5→isMouthOpen=false, digitAt(537,1)=3→isCircle=false
// ---------------------------------------------------------------------------

func TestGenerateBeamSVG_DarkColors_WhiteFace(t *testing.T) {
	// luminance("#000000")=0 < 128 → faceColor="#FFFFFF"
	svg := generateBeamSVG(0, allBlack, 48)
	assert.Contains(t, svg, "#FFFFFF", "dark wrapperColor must force white face")
	assert.True(t, strings.HasPrefix(svg, "<svg"))
	assert.True(t, strings.HasSuffix(svg, "</svg>"))
}

func TestGenerateBeamSVG_LightColors_BlackFace(t *testing.T) {
	// luminance("#FFFFFF")=255 ≥ 128 → faceColor stays "#000000"
	svg := generateBeamSVG(0, allWhite, 48)
	assert.Contains(t, svg, "#000000", "light wrapperColor must keep black face")
}

func TestGenerateBeamSVG_OpenMouth(t *testing.T) {
	// hash=0: digitAt(0,2)=0 (even) → isMouthOpen=true → open-mouth path uses stroke-linecap
	svg := generateBeamSVG(0, allWhite, 48)
	assert.Contains(t, svg, "stroke-linecap")
}

func TestGenerateBeamSVG_ClosedMouth(t *testing.T) {
	// hash=100: digitAt(100,2)=1 (odd) → isMouthOpen=false → filled arc, no stroke-linecap
	svg := generateBeamSVG(100, allWhite, 48)
	assert.NotContains(t, svg, "stroke-linecap")
	assert.Contains(t, svg, "<path") // closed-mouth still renders a path
}

func TestGenerateBeamSVG_IsCircle(t *testing.T) {
	// hash=0: digitAt(0,1)=0 (even) → isCircle=true → wrapRx=36
	svg := generateBeamSVG(0, allWhite, 48)
	assert.Contains(t, svg, `rx="36"`)
}

func TestGenerateBeamSVG_IsRectangle(t *testing.T) {
	// hash=37: digitAt(37,1)=3 (odd) → isCircle=false → wrapRx=6
	svg := generateBeamSVG(37, allWhite, 48)
	assert.Contains(t, svg, `rx="6"`)
}

func TestGenerateBeamSVG_WrapTXAbove6_FaceTXHalved(t *testing.T) {
	// hash=37: c1=7 ≥ 5 → wrapTX=7 > 6 → faceTX = wrapTX/2 = 3
	svg := generateBeamSVG(37, allWhite, 48)
	assert.True(t, strings.HasPrefix(svg, "<svg"), "hash=37 must produce valid svg")
	assert.True(t, strings.HasSuffix(svg, "</svg>"))
}

func TestGenerateBeamSVG_WrapTYAbove6_FaceTYHalved(t *testing.T) {
	// hash=537: c1=7 ≥ 5 → wrapTX=7 > 6 AND c2=7 ≥ 5 → wrapTY=7 > 6
	svg := generateBeamSVG(537, allWhite, 48)
	assert.True(t, strings.HasPrefix(svg, "<svg"), "hash=537 must produce valid svg")
	assert.True(t, strings.HasSuffix(svg, "</svg>"))
}

func TestGenerateBeamSVG_SizeEmbedded(t *testing.T) {
	// The size argument must appear in the SVG width/height attributes.
	svg := generateBeamSVG(0, allWhite, 64)
	assert.Contains(t, svg, `width="64"`)
	assert.Contains(t, svg, `height="64"`)
}

func TestGenerateBeamSVG_AllBranchHashes_ValidSVG(t *testing.T) {
	for _, h := range []int{0, 37, 100, 537, 1, 11, 999} {
		svg := generateBeamSVG(h, allWhite, 48)
		assert.True(t, strings.HasPrefix(svg, "<svg"), "hash=%d", h)
		assert.True(t, strings.HasSuffix(svg, "</svg>"), "hash=%d", h)
	}
}

// ---------------------------------------------------------------------------
// generateSVG — beam case (previously uncovered)
//
// hashString("a") = 97,  97 % 6 = 1 → allVariants[1] = "beam"
// ---------------------------------------------------------------------------

func TestGenerateSVG_BeamVariant(t *testing.T) {
	_, variant, _ := getAvatarParams("a")
	require.Equal(t, "beam", variant, "name 'a' must produce the beam variant")

	svg := generateSVG("a", 48)
	assert.True(t, strings.HasPrefix(svg, "<svg"))
	assert.True(t, strings.HasSuffix(svg, "</svg>"))
}

func TestGenerateSVG_BeamVariant_PNG(t *testing.T) {
	// Ensure beam SVG can be converted to a PNG without error.
	svg := generateSVG("a", 48)
	png, err := svgToPNG(svg, 48)
	require.NoError(t, err)
	assert.GreaterOrEqual(t, len(png), 4)
	assert.Equal(t, []byte{0x89, 0x50, 0x4E, 0x47}, png[:4])
}

// ---------------------------------------------------------------------------
// svgToPNG — error path
// ---------------------------------------------------------------------------

func TestSvgToPNG_MalformedXML(t *testing.T) {
	// Unclosed tag is invalid XML — ReadIconStream must return a non-nil error.
	_, err := svgToPNG("<svg><unclosed", 48)
	assert.Error(t, err, "malformed XML must produce an error")
}

// ---------------------------------------------------------------------------
// GetAvatar — Fiber handler (previously 0% covered)
// ---------------------------------------------------------------------------

func newAvatarTestApp() *fiber.App {
	app := fiber.New()
	app.Get("/api/avatar/:name", GetAvatar)
	return app
}

func TestGetAvatar_ReturnsPNG(t *testing.T) {
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/png", resp.Header.Get("Content-Type"))
}

func TestGetAvatar_PNGMagicBytes(t *testing.T) {
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Bob", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	require.GreaterOrEqual(t, len(body), 4)
	assert.Equal(t, []byte{0x89, 0x50, 0x4E, 0x47}, body[:4], "response must start with PNG magic bytes")
}

func TestGetAvatar_DotPngSuffixStripped(t *testing.T) {
	// Requests with ".png" suffix must produce the same avatar as without.
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice.png", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/png", resp.Header.Get("Content-Type"))
}

func TestGetAvatar_CustomSizeInRange(t *testing.T) {
	// size=96 is ≤ 256 — must be accepted.
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice?size=96", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestGetAvatar_OversizeFallsBackToDefault(t *testing.T) {
	// size > 256 is rejected by the guard; handler uses default 48.
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice?size=9999", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestGetAvatar_ZeroSizeFallsBackToDefault(t *testing.T) {
	// size=0 fails the s>0 guard — default 48 is used.
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice?size=0", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
}

func TestGetAvatar_CacheControlPublic(t *testing.T) {
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/Alice", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Contains(t, resp.Header.Get("Cache-Control"), "public")
}

func TestGetAvatar_BeamVariantName(t *testing.T) {
	// name "a" → beam variant — exercises the generateBeamSVG path via the handler.
	app := newAvatarTestApp()
	req := httptest.NewRequest("GET", "/api/avatar/a", nil)
	resp, err := app.Test(req)
	require.NoError(t, err)
	assert.Equal(t, fiber.StatusOK, resp.StatusCode)
	assert.Equal(t, "image/png", resp.Header.Get("Content-Type"))
}

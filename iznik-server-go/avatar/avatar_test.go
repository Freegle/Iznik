package avatar

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// Ground-truth hash values verified against the JS implementation via Chrome DevTools.
// Source: GeneratedAvatar.client.vue hashString() run on these names.
var hashGroundTruth = []struct {
	name     string
	hash     int
	variant  string
	colorIdx int
}{
	{"Alice", 63350368, "spots", 4},
	{"Bob", 66965, "tiles", 0},
	{"Charlie", 1891246254, "pixel", 9},
	{"Dave", 2122764, "pixel", 4},
	{"Eve", 70068, "pixel", 8},
	{"Frank", 68139378, "pixel", 3},
	{"user", 3599307, "ring", 4},
	{"Susan", 80251422, "pixel", 7},
	{"Edward", 2071405499, "tiles", 9},
	{"Freegle", 1060858388, "bauhaus", 1},
}

func TestHashString(t *testing.T) {
	for _, tc := range hashGroundTruth {
		got := hashString(tc.name)
		assert.Equal(t, tc.hash, got, "hashString(%q)", tc.name)
	}
}

func TestVariantAndColorIndex(t *testing.T) {
	for _, tc := range hashGroundTruth {
		hash, variant, colors := getAvatarParams(tc.name)
		assert.Equal(t, tc.hash, hash, "hash for %q", tc.name)
		assert.Equal(t, tc.variant, variant, "variant for %q", tc.name)
		expectedPalette := colorPalettes[tc.colorIdx]
		assert.Equal(t, expectedPalette, colors, "palette for %q", tc.name)
	}
}

func TestGenerateSVGProducesValidXML(t *testing.T) {
	names := []string{"Alice", "Bob", "Charlie", "Dave", "Eve", "Frank", "user", "Susan", "Edward", "Freegle"}
	for _, name := range names {
		svg := generateSVG(name, 48)
		assert.True(t, strings.HasPrefix(svg, "<svg"), "SVG for %q should start with <svg", name)
		assert.True(t, strings.HasSuffix(svg, "</svg>"), "SVG for %q should end with </svg>", name)
	}
}

func TestSVGToPNGProducesNonEmptyPNG(t *testing.T) {
	names := []string{"Alice", "Bob", "Charlie", "Dave", "Eve", "Frank", "user", "Susan", "Edward", "Freegle"}
	for _, name := range names {
		svg := generateSVG(name, 48)
		pngData, err := svgToPNG(svg, 48)
		require.NoError(t, err, "svgToPNG failed for %q", name)
		assert.Greater(t, len(pngData), 100, "PNG data for %q should be non-trivial", name)
		// PNG magic number: 0x89 0x50 0x4E 0x47
		assert.Equal(t, []byte{0x89, 0x50, 0x4E, 0x47}, pngData[:4], "should start with PNG magic bytes for %q", name)
	}
}

func TestAllSixVariantsReachable(t *testing.T) {
	variantsSeen := map[string]bool{}
	for i := 0; i < 1000; i++ {
		name := strings.Repeat("a", i+1)
		_, variant, _ := getAvatarParams(name)
		variantsSeen[variant] = true
		if len(variantsSeen) == 6 {
			break
		}
	}
	for _, v := range allVariants {
		assert.True(t, variantsSeen[v], "variant %q should be reachable", v)
	}
}

func TestEmptyNameFallsBackToUser(t *testing.T) {
	hash1, _, _ := getAvatarParams("")
	hash2, _, _ := getAvatarParams("user")
	assert.Equal(t, hash1, hash2, "empty name should use 'user' fallback")
}

func TestSizeClamping(t *testing.T) {
	svg := generateSVG("Alice", 256)
	assert.Contains(t, svg, `width="256"`)
}

package misc

import (
	"encoding/json"
	"io"
	"net/http/httptest"
	"testing"

	"github.com/gofiber/fiber/v2"
	"github.com/stretchr/testify/assert"
)

// --- Online ---

func TestOnline_ReturnsTrue(t *testing.T) {
	app := fiber.New()
	app.Get("/online", Online)

	resp, err := app.Test(httptest.NewRequest("GET", "/online", nil))
	assert.NoError(t, err)
	assert.Equal(t, 200, resp.StatusCode)

	body, _ := io.ReadAll(resp.Body)
	var result OnlineResult
	assert.NoError(t, json.Unmarshal(body, &result))
	assert.True(t, result.Online)
}

// --- GetIllustration (pre-DB validation paths only) ---

func newIllustrationApp() *fiber.App {
	app := fiber.New()
	app.Get("/illustration", GetIllustration)
	return app
}

func getIllustrationJSON(t *testing.T, query string) IllustrationResult {
	t.Helper()
	app := newIllustrationApp()
	req := httptest.NewRequest("GET", "/illustration"+query, nil)
	resp, err := app.Test(req)
	assert.NoError(t, err)
	body, _ := io.ReadAll(resp.Body)
	var result IllustrationResult
	assert.NoError(t, json.Unmarshal(body, &result))
	return result
}

func TestGetIllustration_MissingItem(t *testing.T) {
	// No ?item= parameter at all → ret:2 before any DB call.
	result := getIllustrationJSON(t, "")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_EmptyItem(t *testing.T) {
	result := getIllustrationJSON(t, "?item=")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_WhitespaceOnlyItem(t *testing.T) {
	result := getIllustrationJSON(t, "?item=+++")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixOnlyOffer(t *testing.T) {
	// "OFFER: " strips entirely → empty after normalisation → ret:2, no DB hit.
	result := getIllustrationJSON(t, "?item=OFFER%3A+")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixOnlyWanted(t *testing.T) {
	result := getIllustrationJSON(t, "?item=WANTED%3A+")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixOnlyTaken(t *testing.T) {
	result := getIllustrationJSON(t, "?item=TAKEN%3A+")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixOnlyReceived(t *testing.T) {
	result := getIllustrationJSON(t, "?item=RECEIVED%3A+")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_SuffixOnly(t *testing.T) {
	// "(London)" → suffix stripped → empty → ret:2, no DB hit.
	result := getIllustrationJSON(t, "?item=%28London%29")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixAndSuffixOnly(t *testing.T) {
	// "OFFER: (London)" → prefix stripped → "(London)" → suffix stripped → "" → ret:2.
	result := getIllustrationJSON(t, "?item=OFFER%3A+%28London%29")
	assert.Equal(t, 2, result.Ret)
}

func TestGetIllustration_PrefixCaseInsensitive(t *testing.T) {
	// The prefix regexp is (?i) so lowercase "offer:" should also be stripped.
	result := getIllustrationJSON(t, "?item=offer%3A+")
	assert.Equal(t, 2, result.Ret)
}

// --- Regex normalisation unit tests (without going through the HTTP handler) ---

func TestPrefixPatternStripsOffer(t *testing.T) {
	assert.Equal(t, "Chair", prefixPattern.ReplaceAllString("OFFER: Chair", ""))
}

func TestPrefixPatternStripsWanted(t *testing.T) {
	assert.Equal(t, "Sofa", prefixPattern.ReplaceAllString("WANTED: Sofa", ""))
}

func TestPrefixPatternStripsTaken(t *testing.T) {
	assert.Equal(t, "Lamp", prefixPattern.ReplaceAllString("TAKEN: Lamp", ""))
}

func TestPrefixPatternStripsReceived(t *testing.T) {
	assert.Equal(t, "Desk", prefixPattern.ReplaceAllString("RECEIVED: Desk", ""))
}

func TestPrefixPatternCaseInsensitive(t *testing.T) {
	assert.Equal(t, "Table", prefixPattern.ReplaceAllString("offer: Table", ""))
	assert.Equal(t, "Bed", prefixPattern.ReplaceAllString("Offer: Bed", ""))
}

func TestPrefixPatternNoMatchForNonPrefix(t *testing.T) {
	// A plain item name should pass through unchanged.
	assert.Equal(t, "Bicycle", prefixPattern.ReplaceAllString("Bicycle", ""))
}

func TestSuffixPatternStripsLocation(t *testing.T) {
	assert.Equal(t, "Chair", suffixPattern.ReplaceAllString("Chair (London)", ""))
}

func TestSuffixPatternStripsLocationWithSpace(t *testing.T) {
	assert.Equal(t, "Sofa", suffixPattern.ReplaceAllString("Sofa  (Near Bath)  ", ""))
}

func TestSuffixPatternNoMatchMidString(t *testing.T) {
	// Parentheses in the middle of the string should not be stripped.
	assert.Equal(t, "Table (oak) top", suffixPattern.ReplaceAllString("Table (oak) top", ""))
}

func TestSuffixPatternNoMatchForEmptyParens(t *testing.T) {
	// Empty parens "()" don't match [^)]+ (which requires ≥1 char inside).
	assert.Equal(t, "Lamp ()", suffixPattern.ReplaceAllString("Lamp ()", ""))
}

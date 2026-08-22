package aiimage

import (
	"bytes"
	"encoding/base64"
	"encoding/json"
	"image"
	"image/color"
	"image/jpeg"
	"image/png"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

func makeTestPNG() []byte {
	img := image.NewRGBA(image.Rect(0, 0, 10, 10))
	for y := 0; y < 10; y++ {
		for x := 0; x < 10; x++ {
			img.Set(x, y, color.RGBA{R: 128, G: 128, B: 128, A: 255})
		}
	}
	var buf bytes.Buffer
	png.Encode(&buf, img)
	return buf.Bytes()
}

// ---------------------------------------------------------------------------
// generateImageWithCloudflare
// ---------------------------------------------------------------------------

func TestGenerateImageWithCloudflare_Success(t *testing.T) {
	pngBytes := makeTestPNG()
	b64Image := base64.StdEncoding.EncodeToString(pngBytes)

	var capturedPath string
	var capturedBody map[string]interface{}
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		capturedPath = r.URL.Path
		assert.Equal(t, "POST", r.Method)
		assert.Equal(t, "Bearer test-ai-token", r.Header.Get("Authorization"))
		json.NewDecoder(r.Body).Decode(&capturedBody)
		resp := map[string]interface{}{
			"result":  map[string]string{"image": b64Image},
			"success": true,
			"errors":  []string{},
		}
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(resp)
	}))
	defer srv.Close()

	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "test-acct")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "test-ai-token")
	old := CloudflareAPIBase
	CloudflareAPIBase = srv.URL
	defer func() { CloudflareAPIBase = old }()

	result, err := generateImageWithCloudflare("bicycle")
	require.NoError(t, err)
	assert.Equal(t, pngBytes, result)
	assert.Contains(t, capturedPath, "flux-1-schnell")
	assert.Contains(t, capturedPath, "test-acct")

	// Flux Schnell rejects the whole request with a 400 if we send anything outside its
	// closed schema, so assert we send only what it accepts.
	assert.Contains(t, capturedBody, "prompt")
	assert.Equal(t, float64(8), capturedBody["steps"])
	for _, banned := range []string{"num_steps", "width", "height"} {
		assert.NotContains(t, capturedBody, banned)
	}
}

func TestGenerateImageWithCloudflare_NSFWRefusal(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		w.Write([]byte(`{"errors":[{"message":"AiError: Input prompt contains NSFW content.","code":8007}],"success":false}`))
	}))
	defer srv.Close()

	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "test-acct")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "test-ai-token")
	old := CloudflareAPIBase
	CloudflareAPIBase = srv.URL
	defer func() { CloudflareAPIBase = old }()

	_, err := generateImageWithCloudflare("bicycle")
	require.Error(t, err)
	// The moderator needs to know retrying cannot help until the description changes.
	assert.Contains(t, err.Error(), "edit the item description")
	assert.NotContains(t, err.Error(), "8007")
}

func TestGenerateImageWithCloudflare_ObjectErrors(t *testing.T) {
	// Cloudflare reports input-schema violations as error objects in a 200 envelope with
	// success=false. Those must surface as the API's message, not be mistaken for image bytes.
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte(`{"errors":[{"message":"AiError: Bad input","code":5006}],"success":false,"result":{},"messages":[]}`))
	}))
	defer srv.Close()

	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "test-acct")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "test-ai-token")
	old := CloudflareAPIBase
	CloudflareAPIBase = srv.URL
	defer func() { CloudflareAPIBase = old }()

	_, err := generateImageWithCloudflare("bicycle")
	require.Error(t, err)
	assert.Contains(t, err.Error(), "AiError: Bad input")
}

func TestGenerateImageWithCloudflare_MissingEnvVars(t *testing.T) {
	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "")

	_, err := generateImageWithCloudflare("bicycle")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "CLOUDFLARE_ACCOUNT_ID")
}

func TestGenerateImageWithCloudflare_APIError(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusUnauthorized)
		w.Write([]byte(`{"errors":["authentication error"],"success":false}`))
	}))
	defer srv.Close()

	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "test-acct")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "bad-token")
	old := CloudflareAPIBase
	CloudflareAPIBase = srv.URL
	defer func() { CloudflareAPIBase = old }()

	_, err := generateImageWithCloudflare("bicycle")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "401")
}

func TestGenerateImageWithCloudflare_NotSuccessJSON(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]interface{}{
			"success": false,
			"result":  map[string]string{"image": ""},
			"errors":  []string{"model unavailable"},
		})
	}))
	defer srv.Close()

	t.Setenv("CLOUDFLARE_ACCOUNT_ID", "test-acct")
	t.Setenv("CLOUDFLARE_AI_TOKEN", "test-ai-token")
	old := CloudflareAPIBase
	CloudflareAPIBase = srv.URL
	defer func() { CloudflareAPIBase = old }()

	_, err := generateImageWithCloudflare("bicycle")
	assert.Error(t, err)
}

// ---------------------------------------------------------------------------
// applyDuotoneGreen
// ---------------------------------------------------------------------------

func TestApplyDuotoneGreen_PNG(t *testing.T) {
	result, err := applyDuotoneGreen(makeTestPNG())
	require.NoError(t, err)
	assert.NotEmpty(t, result)

	_, format, err := image.Decode(bytes.NewReader(result))
	require.NoError(t, err)
	assert.Equal(t, "jpeg", format)
}

func TestApplyDuotoneGreen_JPEG(t *testing.T) {
	img := image.NewRGBA(image.Rect(0, 0, 10, 10))
	for y := 0; y < 10; y++ {
		for x := 0; x < 10; x++ {
			img.Set(x, y, color.RGBA{R: 200, G: 100, B: 50, A: 255})
		}
	}
	var buf bytes.Buffer
	jpeg.Encode(&buf, img, &jpeg.Options{Quality: 85})

	result, err := applyDuotoneGreen(buf.Bytes())
	require.NoError(t, err)
	assert.NotEmpty(t, result)
}

func TestApplyDuotoneGreen_InvalidData(t *testing.T) {
	_, err := applyDuotoneGreen([]byte("not an image"))
	assert.Error(t, err)
}

// ---------------------------------------------------------------------------
// uploadToTUS
// ---------------------------------------------------------------------------

func TestUploadToTUS_Success(t *testing.T) {
	fileID := "abc123xyz"
	var srvURL string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case "POST":
			assert.Equal(t, "1.0.0", r.Header.Get("Tus-Resumable"))
			w.Header().Set("Location", srvURL+"/"+fileID)
			w.WriteHeader(http.StatusCreated)
		case "PATCH":
			assert.Equal(t, "1.0.0", r.Header.Get("Tus-Resumable"))
			assert.Equal(t, "0", r.Header.Get("Upload-Offset"))
			w.WriteHeader(http.StatusNoContent)
		default:
			w.WriteHeader(http.StatusMethodNotAllowed)
		}
	}))
	srvURL = srv.URL
	defer srv.Close()

	t.Setenv("TUS_UPLOADER", srv.URL)

	uid, err := uploadToTUS(makeTestPNG(), "image/jpeg")
	require.NoError(t, err)
	assert.Equal(t, "freegletusd-"+fileID, uid)
}

func TestUploadToTUS_CreateFails(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer srv.Close()

	t.Setenv("TUS_UPLOADER", srv.URL)

	_, err := uploadToTUS(makeTestPNG(), "image/jpeg")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "500")
}

func TestUploadToTUS_PatchFails(t *testing.T) {
	fileID := "patchfail99"
	var srvURL string

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.Method {
		case "POST":
			w.Header().Set("Location", srvURL+"/"+fileID)
			w.WriteHeader(http.StatusCreated)
		case "PATCH":
			w.WriteHeader(http.StatusInternalServerError)
		}
	}))
	srvURL = srv.URL
	defer srv.Close()

	t.Setenv("TUS_UPLOADER", srv.URL)

	_, err := uploadToTUS(makeTestPNG(), "image/jpeg")
	assert.Error(t, err)
}

func TestUploadToTUS_NoLocationHeader(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// 201 but no Location header.
		w.WriteHeader(http.StatusCreated)
	}))
	defer srv.Close()

	t.Setenv("TUS_UPLOADER", srv.URL)

	_, err := uploadToTUS(makeTestPNG(), "image/jpeg")
	assert.Error(t, err)
	assert.Contains(t, err.Error(), "Location")
}

// ---------------------------------------------------------------------------
// subjectForName / buildImagePrompt
// ---------------------------------------------------------------------------

func TestSubjectForName_CanonicalJob(t *testing.T) {
	// Canonical job title resolves to its iconic object.
	assert.Equal(t, "calculator", subjectForName("Accountant"))
}

func TestSubjectForName_NonJob(t *testing.T) {
	// Non-job names pass through unchanged — used for both items and description overrides.
	assert.Equal(t, "large brown sofa", subjectForName("large brown sofa"))
	assert.Equal(t, "bicycle", subjectForName("bicycle"))
}

func TestBuildImagePrompt_UsesOverrideSubject(t *testing.T) {
	// When a moderator supplies a description override ("large brown sofa"),
	// it is passed as name directly and should appear in the prompt.
	prompt := buildImagePrompt("large brown sofa")
	assert.Contains(t, prompt, "large brown sofa")
	assert.NotContains(t, prompt, "Accountant")
}

func TestBuildImagePrompt_CanonicalJobResolvesToObject(t *testing.T) {
	prompt := buildImagePrompt("Accountant")
	assert.Contains(t, prompt, "calculator")
	assert.NotContains(t, prompt, "Accountant")
}

func TestSubjectForName_UnknownItemPassesThrough(t *testing.T) {
	assert.Equal(t, "pressure washer", subjectForName("pressure washer"))
	assert.Equal(t, "bicycle", subjectForName("bicycle"))
	assert.Equal(t, "cot", subjectForName("cot"))
}

func TestBuildImagePrompt_HouseholdItemContext(t *testing.T) {
	prompt := buildImagePrompt("pressure washer")
	assert.Contains(t, prompt, "household",
		"prompt must describe items as household objects to disambiguate compound names")
}

func TestBuildImagePrompt_UsesAmericanEnglish(t *testing.T) {
	prompt := buildImagePrompt("cot")
	assert.Contains(t, prompt, "American English",
		"prompt must instruct the model to use American English terminology")
}

func TestBuildImagePrompt_MultiItemName_DoesNotForceSingleObjectOnly(t *testing.T) {
	// Regression test for Discourse topic 9630/53: a post for "a sofa and a bed" stores
	// ai_images.name = "sofa and a bed" verbatim. Regenerate (aiimage.Regenerate) calls
	// buildImagePrompt() with that stored name, so a fix must live here — parsing "and" to
	// detect multiple items and dropping the self-contradictory "single object only"
	// instruction, which otherwise makes the model draw only the first item every time,
	// including on Regenerate.
	prompt := buildImagePrompt("sofa and a bed")
	assert.NotContains(t, prompt, "single object only",
		"a compound item name must not be forced into a 'single object only' prompt")
	assert.Contains(t, prompt, "sofa")
	assert.Contains(t, prompt, "bed")
}

func TestBuildImagePrompt_SingleItemName_StillForcesSingleObjectOnly(t *testing.T) {
	// Single-item names are unaffected — the existing "single object only" constraint
	// still applies so ordinary items keep their focused, uncluttered illustration.
	prompt := buildImagePrompt("sofa")
	assert.Contains(t, prompt, "single object only")
}

func TestBuildImagePrompt_BrandOrTitleName_NotSplitAsMultiItem(t *testing.T) {
	// Regression: a single item whose name merely contains "and" (a brand name, a title, or
	// a set) must NOT be treated as multiple items - that made the model draw two separate
	// objects side by side. Only an "and" that introduces a new item (article/quantifier/
	// digit) is a separator.
	for _, name := range []string{
		"Black and Decker drill",
		"Pride and Prejudice",
		"salt and pepper set",
		"Marks and Spencer jumper",
	} {
		prompt := buildImagePrompt(name)
		assert.Contains(t, prompt, "single object only",
			name+" is one item and must keep the single-object prompt")
	}
}

func TestBuildImagePrompt_GenuineMultiItem_StillSplits(t *testing.T) {
	for _, name := range []string{"sofa and a bed", "3 chairs and a table"} {
		prompt := buildImagePrompt(name)
		assert.NotContains(t, prompt, "single object only", name+" lists multiple items")
	}
}

func TestBuildImagePrompt_StripsCourtesyWords(t *testing.T) {
	// Regression test for Discourse topic 9209/98: "WANTED: iron please" stored
	// ai_images.name = "iron please", so the model was asked to draw an "iron please" and
	// produced a smooth white blob. Moderator regeneration goes through buildImagePrompt()
	// with the stored (uncleaned) name, so the strip has to happen here too.
	prompt := buildImagePrompt("iron please")
	assert.Contains(t, prompt, "single isolated iron centered")
	assert.NotContains(t, prompt, "please")
}

func TestBuildImagePrompt_CourtesyWordDoesNotBreakMultiItemSplit(t *testing.T) {
	// "sofa and a bed please" must still be recognised as two items once "please" has gone.
	prompt := buildImagePrompt("sofa and a bed please")
	assert.Contains(t, prompt, "sofa and a bed shown together")
	assert.NotContains(t, prompt, "please")
}

func TestBuildImagePrompt_LeavesRealWordsBeginningWithPlease(t *testing.T) {
	// Only the standalone courtesy word goes; a brand name that starts with it stays.
	prompt := buildImagePrompt("Pleaser platform boots")
	assert.Contains(t, prompt, "Pleaser platform boots")
}

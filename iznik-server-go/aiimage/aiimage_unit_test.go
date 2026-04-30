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
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		capturedPath = r.URL.Path
		assert.Equal(t, "POST", r.Method)
		assert.Equal(t, "Bearer test-ai-token", r.Header.Get("Authorization"))
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

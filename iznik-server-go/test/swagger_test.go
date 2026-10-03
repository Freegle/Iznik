package test

import (
	"encoding/json"
	"github.com/stretchr/testify/assert"
	"net/http/httptest"
	"os"
	"os/exec"
	"path/filepath"
	"testing"
)

// TestSwaggerGeneration tests that swagger.json can be generated
// and contains valid API paths.
func TestSwaggerGeneration(t *testing.T) {
	// Skip if not running locally (e.g., in CI environment)
	if os.Getenv("CI") != "" {
		t.Skip("Skipping swagger generation test in CI environment")
	}

	// Get the root directory of the project
	rootDir, err := filepath.Abs("../")
	if err != nil {
		t.Fatalf("Failed to get root directory: %v", err)
	}

	// Define paths for swagger binary and output file. Generate into a temp dir
	// via SWAGGER_OUT rather than over swagger/swagger.json: this test used to
	// delete the committed spec and regenerate it, so simply running it left the
	// checkout holding whatever that run produced. On 2026-08-09 that was 4 model
	// definitions in place of 115.
	swaggerScript := filepath.Join(rootDir, "generate-swagger.sh")
	swaggerJsonPath := filepath.Join(t.TempDir(), "swagger.json")

	// Run the generate-swagger.sh script, writing to the temp path so the
	// committed spec is left alone.
	cmd := exec.Command("/bin/bash", swaggerScript)
	cmd.Dir = rootDir
	cmd.Env = append(os.Environ(), "SWAGGER_OUT="+swaggerJsonPath)
	output, err := cmd.CombinedOutput()

	// Output command result for debugging
	t.Logf("Swagger generation output: %s", string(output))

	if err != nil {
		t.Fatalf("Failed to run swagger generation: %v", err)
	}

	// Check that swagger.json was created
	_, err = os.Stat(swaggerJsonPath)
	assert.NoError(t, err, "swagger.json should be created")

	// Read and parse swagger.json to verify it contains paths
	swaggerJson, err := os.ReadFile(swaggerJsonPath)
	assert.NoError(t, err, "Should be able to read swagger.json")

	var swaggerSpec map[string]interface{}
	err = json.Unmarshal(swaggerJson, &swaggerSpec)
	assert.NoError(t, err, "swagger.json should be valid JSON")

	// Check that paths are not empty
	paths, ok := swaggerSpec["paths"].(map[string]interface{})
	assert.True(t, ok, "swagger.json should have a paths object")
	assert.Greater(t, len(paths), 0, "swagger.json should have at least one path")

	// Check for specific critical paths
	criticalPaths := []string{
		"/message/{ids}",
		"/message/mygroups/{id}",
		"/user/{id}",
	}

	for _, path := range criticalPaths {
		_, exists := paths[path]
		assert.True(t, exists, "swagger.json should contain path %s", path)
	}
}

// TestSwaggerEndpoint tests that the Swagger UI endpoint is properly configured
func TestSwaggerEndpoint(t *testing.T) {
	app := getApp()

	// Test the swagger redirect endpoint
	req := httptest.NewRequest("GET", "/swagger", nil)
	resp, err := app.Test(req)
	assert.NoError(t, err, "Should be able to request /swagger endpoint")
	assert.Equal(t, 302, resp.StatusCode, "Should redirect from /swagger to /swagger/index.html")
	assert.Equal(t, "/swagger/index.html", resp.Header.Get("Location"), "Redirect location should be correct")

	// Test the swagger index file is served
	req = httptest.NewRequest("GET", "/swagger/index.html", nil)
	resp, err = app.Test(req)
	assert.NoError(t, err, "Should be able to request /swagger/index.html endpoint")
	assert.Equal(t, 200, resp.StatusCode, "Should serve the swagger UI")

	// Test the swagger.json file is served
	req = httptest.NewRequest("GET", "/swagger/swagger.json", nil)
	resp, err = app.Test(req)
	assert.NoError(t, err, "Should be able to request /swagger/swagger.json endpoint")
	assert.Equal(t, 200, resp.StatusCode, "Should serve the swagger.json file")
}

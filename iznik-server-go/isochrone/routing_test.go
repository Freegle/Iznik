package isochrone

import (
	"errors"
	"io"
	"net/http"
	"os"
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// ---------------------------------------------------------------------------
// Shared test doubles for isochroneHTTPClient - both mapbox.go and routing.go
// call through this shared client, so overriding its Transport lets these
// pure unit tests exercise every HTTP success/error branch with no network
// access and no external routing/Mapbox server.
// ---------------------------------------------------------------------------

type fakeRoundTripper struct {
	resp    *http.Response
	err     error
	lastURL string
}

func (f *fakeRoundTripper) RoundTrip(req *http.Request) (*http.Response, error) {
	f.lastURL = req.URL.String()
	if f.err != nil {
		return nil, f.err
	}
	return f.resp, nil
}

func withFakeTransport(t *testing.T, rt http.RoundTripper) {
	t.Helper()
	orig := isochroneHTTPClient.Transport
	isochroneHTTPClient.Transport = rt
	t.Cleanup(func() { isochroneHTTPClient.Transport = orig })
}

func fakeResponse(status int, body string) *http.Response {
	return &http.Response{
		StatusCode: status,
		Body:       io.NopCloser(strings.NewReader(body)),
		Header:     make(http.Header),
	}
}

// errReadCloser fails every Read, so the io.ReadAll error path is reachable
// without a real broken connection.
type errReadCloser struct{}

func (errReadCloser) Read([]byte) (int, error) { return 0, errors.New("simulated read failure") }
func (errReadCloser) Close() error              { return nil }

func withEnv(t *testing.T, key, val string) {
	t.Helper()
	orig, had := os.LookupEnv(key)
	os.Setenv(key, val)
	t.Cleanup(func() {
		if had {
			os.Setenv(key, orig)
		} else {
			os.Unsetenv(key)
		}
	})
}

func clearEnv(t *testing.T, key string) {
	t.Helper()
	orig, had := os.LookupEnv(key)
	os.Unsetenv(key)
	t.Cleanup(func() {
		if had {
			os.Setenv(key, orig)
		}
	})
}

// ---------------------------------------------------------------------------
// FetchIsochroneWKTFromRoutingServer
// ---------------------------------------------------------------------------

func TestFetchIsochroneWKTFromRoutingServer_NoURLConfigured(t *testing.T) {
	clearEnv(t, "SPATIAL_SERVER_URL")
	clearEnv(t, "ROUTING_SERVER_URL")
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func TestFetchIsochroneWKTFromRoutingServer_FallsBackToLegacyEnvVar(t *testing.T) {
	// SPATIAL_SERVER_URL unset, ROUTING_SERVER_URL set - the base must come
	// from the legacy var rather than short-circuiting to "".
	clearEnv(t, "SPATIAL_SERVER_URL")
	withEnv(t, "ROUTING_SERVER_URL", "http://legacy.invalid")
	rt := &fakeRoundTripper{resp: fakeResponse(200, routingResponseJSON())}
	withFakeTransport(t, rt)

	wkt := FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON(("))
	assert.True(t, strings.HasPrefix(rt.lastURL, "http://legacy.invalid/v1/isochrone"))
}

func TestFetchIsochroneWKTFromRoutingServer_NetworkError(t *testing.T) {
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	withFakeTransport(t, &fakeRoundTripper{err: errors.New("connection refused")})
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func TestFetchIsochroneWKTFromRoutingServer_ReadBodyError(t *testing.T) {
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	withFakeTransport(t, &fakeRoundTripper{resp: &http.Response{
		StatusCode: 200,
		Body:       errReadCloser{},
		Header:     make(http.Header),
	}})
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func TestFetchIsochroneWKTFromRoutingServer_NonOKStatus(t *testing.T) {
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(500, "internal error")})
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func TestFetchIsochroneWKTFromRoutingServer_NonOKStatusLongBody(t *testing.T) {
	// The error-logging path truncates the body to 500 bytes - exercise it
	// with a body long enough to require the truncation.
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	longBody := strings.Repeat("x", 1000)
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(503, longBody)})
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func TestFetchIsochroneWKTFromRoutingServer_BadJSON(t *testing.T) {
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(200, "not json")})
	assert.Equal(t, "", FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15))
}

func routingResponseJSON() string {
	return `{
		"walk": {"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-0.1,51.5],[-0.2,51.5],[-0.2,51.6],[-0.1,51.5]]]}},
		"cycle": {"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-0.3,51.5],[-0.4,51.5],[-0.4,51.6],[-0.3,51.5]]]}},
		"drive": {"type":"Feature","geometry":{"type":"Polygon","coordinates":[[[-0.5,51.5],[-0.6,51.5],[-0.6,51.6],[-0.5,51.5]]]}}
	}`
}

func TestFetchIsochroneWKTFromRoutingServer_TransportSelection(t *testing.T) {
	tests := []struct {
		name       string
		transport  string
		wantPoint  string
	}{
		{"walk maps to walk polygon", "Walk", "-0.100000 51.500000"},
		{"cycle maps to cycle polygon", "Cycle", "-0.300000 51.500000"},
		{"drive maps to drive polygon", "Drive", "-0.500000 51.500000"},
		{"unknown transport defaults to drive polygon", "Teleport", "-0.500000 51.500000"},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
			withFakeTransport(t, &fakeRoundTripper{resp: fakeResponse(200, routingResponseJSON())})

			wkt := FetchIsochroneWKTFromRoutingServer(tt.transport, 51.5, -0.1, 15)
			assert.True(t, strings.HasPrefix(wkt, "POLYGON(("))
			assert.Contains(t, wkt, tt.wantPoint)
		})
	}
}

func TestFetchIsochroneWKTFromRoutingServer_URLIncludesParams(t *testing.T) {
	withEnv(t, "SPATIAL_SERVER_URL", "http://spatial.invalid")
	rt := &fakeRoundTripper{resp: fakeResponse(200, routingResponseJSON())}
	withFakeTransport(t, rt)

	FetchIsochroneWKTFromRoutingServer("Walk", 51.5, -0.1, 15)
	assert.Contains(t, rt.lastURL, "lat=51.500000")
	assert.Contains(t, rt.lastURL, "lng=-0.100000")
	assert.Contains(t, rt.lastURL, "minutes=15")
}

// ---------------------------------------------------------------------------
// routingPolygonToWKT
// ---------------------------------------------------------------------------

func TestRoutingPolygonToWKT(t *testing.T) {
	tests := []struct {
		name string
		poly routingPolygon
		want string
	}{
		{
			name: "wrong geometry type returns empty",
			poly: routingPolygon{Geometry: routingGeometry{Type: "Point"}},
			want: "",
		},
		{
			name: "empty coordinates returns empty",
			poly: routingPolygon{Geometry: routingGeometry{Type: "Polygon"}},
			want: "",
		},
		{
			name: "too few points in ring returns empty",
			poly: routingPolygon{Geometry: routingGeometry{
				Type:        "Polygon",
				Coordinates: [][][2]float64{{{0, 0}, {1, 1}}},
			}},
			want: "",
		},
		{
			name: "zero-length ring returns empty",
			poly: routingPolygon{Geometry: routingGeometry{
				Type:        "Polygon",
				Coordinates: [][][2]float64{{}},
			}},
			want: "",
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			assert.Equal(t, tt.want, routingPolygonToWKT(tt.poly))
		})
	}
}

func TestRoutingPolygonToWKT_Valid(t *testing.T) {
	poly := routingPolygon{Geometry: routingGeometry{
		Type:        "Polygon",
		Coordinates: [][][2]float64{{{0, 0}, {1, 0}, {1, 1}, {0, 0}}},
	}}
	wkt := routingPolygonToWKT(poly)
	assert.True(t, strings.HasPrefix(wkt, "POLYGON(("))
	assert.True(t, strings.HasSuffix(wkt, "))"))
	assert.Contains(t, wkt, "0.000000 0.000000")
	assert.Contains(t, wkt, "1.000000 0.000000")
	assert.Contains(t, wkt, "1.000000 1.000000")
}

func TestRoutingTransportMap(t *testing.T) {
	assert.Equal(t, "walk", routingTransport["Walk"])
	assert.Equal(t, "cycle", routingTransport["Cycle"])
	assert.Equal(t, "drive", routingTransport["Drive"])
	_, ok := routingTransport["Teleport"]
	assert.False(t, ok)
}

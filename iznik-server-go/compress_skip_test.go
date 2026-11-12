package main

import "testing"

// The framed user dump is streamed, so the compress middleware must skip it on both the /api and
// the /apiv2 prefix the route is registered under.
func TestSkipCompressionCoversTheStreamedUserDumpOnBothPrefixes(t *testing.T) {
	for _, path := range []string{"/api/modtools/user/123/dump", "/apiv2/modtools/user/123/dump"} {
		if !skipCompression(path) {
			t.Errorf("%s is a streamed response and must not be compressed", path)
		}
	}

	for _, path := range []string{"/api/modtools/user/123", "/apiv2/modtools/user/123", "/apiv2/message/1"} {
		if skipCompression(path) {
			t.Errorf("%s is an ordinary response and may be compressed", path)
		}
	}
}

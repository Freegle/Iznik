package main

import "testing"

// resolvePlace's SQL path can't be unit-tested without a live MySQL connection (it queries the
// `locations` table directly), but the nil-db guard is pure and must be exercised: any caller
// with no DB configured (spatial service not up, MYSQL_HOST unset) must get back ("", "") rather
// than a panic or an error, so the group-extent response is still returned with the
// postcode/place fields simply omitted.
func TestGeocode_NilDBReturnsEmptyStrings(t *testing.T) {
	postcode, place := resolvePlace(nil, 51.4500, -2.5978)
	if postcode != "" {
		t.Errorf("expected empty postcode with nil db, got %q", postcode)
	}
	if place != "" {
		t.Errorf("expected empty place with nil db, got %q", place)
	}
}

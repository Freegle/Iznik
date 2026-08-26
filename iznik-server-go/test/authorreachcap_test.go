package test

import (
	"fmt"
	"strconv"
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/stretchr/testify/assert"
)

// The OUTBOUND half of the distance preference, exercised as SQL against a real MySQL rather than
// through a whole feed. utils.AuthorReachCapWhere is a string pasted into three hot queries (the
// browse feed, its unread badge, browse-scoped search), and everything interesting about it is
// MySQL's own JSON and DECIMAL behaviour - which is exactly what a Go-level unit test cannot see
// and a feed test buries under fixture setup.
//
// The table mirrors DistancePreferenceFilterTest's outbound cases in the batch. The two must agree:
// the feed and the digest resolving the same member differently is the bug class this guards.
func TestAuthorReachCapResolution(t *testing.T) {
	db := database.DBConn

	// Viewer in central London, post ~4.3 miles east (0.1 degrees of longitude at this latitude).
	// Every cap below is either comfortably above or comfortably below that, so nothing turns on
	// the exact figure.
	const viewerLat, viewerLng = 51.5, -0.1
	const postLat, postLng = 51.5, 0.0
	const sentinel = float64(9007199254740991)

	// The production clause, with the two tables it needs faked up as derived tables so the only
	// thing under test is the clause itself.
	query := "SELECT 1 FROM (SELECT CAST(? AS JSON) AS settings) au " +
		"JOIN (SELECT ST_SRID(POINT(?, ?), " + strconv.Itoa(utils.SRID) + ") AS point) ms " +
		"WHERE 1 = 1 " + utils.AuthorReachCapWhere

	cases := []struct {
		name     string
		settings interface{}
		visible  bool
	}{
		// No cap anywhere: the common case, and the arm that must stay first so the trigonometry
		// is never reached for most rows.
		{"settings NULL", nil, true},
		{"settings empty", `{}`, true},
		{"only the band default", `{"browseReachMaxDistance":2}`, true},

		// Linked - the outbound key is absent, so browseMaxDistance still governs. This is the
		// behaviour that must not change for anyone who never touches the new slider.
		{"linked, cap above the post", `{"browseMaxDistance":10}`, true},
		{"linked, cap below the post", `{"browseMaxDistance":2}`, false},

		// Split - the member's own outbound choice wins either way round.
		{"split wider", `{"browseMaxDistance":2,"myPostsMaxDistance":10}`, true},
		{"split narrower", `{"browseMaxDistance":10,"myPostsMaxDistance":2}`, false},
		{"split, no browse choice at all", `{"myPostsMaxDistance":10}`, true},
		{"split narrow, no browse choice at all", `{"myPostsMaxDistance":2}`, false},

		// The four spellings of "outbound not set", all of which must fall back to the browse
		// choice rather than being read as a cap or as no-limit.
		{"outbound JSON null falls back", `{"browseMaxDistance":2,"myPostsMaxDistance":null}`, false},
		{"outbound zero falls back", `{"browseMaxDistance":2,"myPostsMaxDistance":0}`, false},
		{"outbound negative falls back", `{"browseMaxDistance":2,"myPostsMaxDistance":-5}`, false},

		// The sentinel means an explicit "no limit" and must NOT fall back to a narrower browse
		// choice. This arm was dead before the split: the sentinel is 16 integer digits and the
		// clause used to CAST to DECIMAL(20,6), which holds 14, so it saturated and never compared
		// equal. It failed open through the distance comparison, so nothing was broken - but a
		// widened cap here would have been silently ignored.
		{"outbound sentinel is no limit", `{"browseMaxDistance":2,"myPostsMaxDistance":9007199254740991}`, true},
		{"browse sentinel is no limit", `{"browseMaxDistance":9007199254740991}`, true},
	}

	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			var got int
			err := db.Raw(query,
				c.settings,
				postLng, postLat,
				sentinel, viewerLat, viewerLng, viewerLat,
			).Row().Scan(&got)

			if c.visible {
				assert.NoError(t, err, "expected the post to be visible")
				assert.Equal(t, 1, got)
			} else {
				// No row: the clause excluded the post.
				assert.Error(t, err, "expected the post to be hidden by the author's cap")
			}
		})
	}
}

// The sentinel must survive the CAST the clause performs on it. This is the measurement behind the
// DECIMAL(30,6) width: at DECIMAL(20,6) the value saturates and the "no limit" arm silently stops
// firing. Asserted directly so a future narrowing of the width fails loudly here rather than
// showing up as members quietly losing reach.
func TestSentinelSurvivesTheDecimalCast(t *testing.T) {
	db := database.DBConn

	var wide, narrow float64
	err := db.Raw("SELECT CAST('9007199254740991' AS DECIMAL(30,6)), " +
		"CAST('9007199254740991' AS DECIMAL(20,6))").Row().Scan(&wide, &narrow)
	assert.NoError(t, err)

	assert.Equal(t, float64(9007199254740991), wide,
		"DECIMAL(30,6) must hold the sentinel exactly")
	assert.Less(t, narrow, float64(9007199254740991),
		fmt.Sprintf("DECIMAL(20,6) is expected to saturate below the sentinel (got %v) - "+
			"this is why utils.AuthorReachCapWhere casts to DECIMAL(30,6)", narrow))
}

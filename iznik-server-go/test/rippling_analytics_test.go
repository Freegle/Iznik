package test

import (
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/rippling"
	"github.com/stretchr/testify/assert"
)

// StratumFilter maps a density stratum to a total_freeglers SQL predicate (terciles from live
// data: rural <1700, suburban 1700-3800, dense >3800). "all"/unknown add no bound.
func TestStratumFilter(t *testing.T) {
	assert.Equal(t, "", rippling.StratumFilter("all"))
	assert.Equal(t, "", rippling.StratumFilter("nonsense"))

	rural := rippling.StratumFilter("rural")
	assert.Contains(t, rural, "total_freeglers < 1700")

	sub := rippling.StratumFilter("suburban")
	assert.Contains(t, sub, ">= 1700")
	assert.Contains(t, sub, "< 3800")

	dense := rippling.StratumFilter("dense")
	assert.Contains(t, dense, ">= 3800")

	// Every non-empty filter is an AND-clause so it can be appended into a WHERE.
	for _, s := range []string{rural, sub, dense} {
		assert.True(t, strings.HasPrefix(strings.TrimSpace(s), "AND"),
			"stratum filter must be an appendable AND clause: %q", s)
	}
}

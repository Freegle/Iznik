package partnerships

import (
	"strings"
	"testing"

	"github.com/stretchr/testify/assert"
)

// sanitiseFilename exists to stop a crafted authority name breaking out of a
// Content-Disposition header. Only its pass-through path was covered, so the
// sanitising it is named for — the whole point of the function — was untested.

func TestSanitiseFilenameLeavesAnOrdinaryNameAlone(t *testing.T) {
	assert.Equal(t, "Bristol City Council.xlsx", sanitiseFilename("Bristol City Council.xlsx"))
}

func TestSanitiseFilenameReplacesTheQuoteThatWouldCloseTheHeader(t *testing.T) {
	// A bare double quote would terminate filename="..." and let the rest be
	// read as further header parameters.
	got := sanitiseFilename(`evil".xlsx`)

	assert.NotContains(t, got, `"`)
	assert.Equal(t, "evil_.xlsx", got)
}

func TestSanitiseFilenameReplacesPathSeparatorsAndBackslashes(t *testing.T) {
	got := sanitiseFilename(`../../etc/passwd`)

	assert.NotContains(t, got, "/")
	assert.NotContains(t, got, `\`)
	// Each separator becomes one underscore; the dots are left alone.
	assert.Equal(t, ".._.._etc_passwd", got)
	assert.Equal(t, `a_b`, sanitiseFilename(`a\b`))
}

func TestSanitiseFilenameReplacesControlCharacters(t *testing.T) {
	// CR/LF are the header-injection characters: they would let a crafted name
	// append an entirely new header.
	got := sanitiseFilename("stats\r\nX-Injected: 1.xlsx")

	assert.NotContains(t, got, "\r")
	assert.NotContains(t, got, "\n")
	assert.False(t, strings.Contains(got, "\r\n"))
}

func TestSanitiseFilenameFallsBackWhenNothingIsLeft(t *testing.T) {
	// An empty name would produce filename="" — the fallback keeps the download
	// usable instead.
	assert.Equal(t, "statistics.xlsx", sanitiseFilename(""))
}

func TestSanitiseFilenameKeepsNonAsciiNames(t *testing.T) {
	// Only control characters and the three delimiters are replaced, so an
	// accented or non-Latin authority name survives intact.
	assert.Equal(t, "Sirhowy Ystrad Mynach.xlsx", sanitiseFilename("Sirhowy Ystrad Mynach.xlsx"))
	assert.Equal(t, "Powys–Gwynedd.xlsx", sanitiseFilename("Powys–Gwynedd.xlsx"))
}

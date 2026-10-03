package user

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// ── IsExemptBySystemrole ────────────────────────────────────────────────────

func TestIsExemptBySystemrole_ModeratorRole(t *testing.T) {
	assert.True(t, IsExemptBySystemrole("Moderator"))
}

func TestIsExemptBySystemrole_SupportRole(t *testing.T) {
	assert.True(t, IsExemptBySystemrole("Support"))
}

func TestIsExemptBySystemrole_AdminRole(t *testing.T) {
	assert.True(t, IsExemptBySystemrole("Admin"))
}

func TestIsExemptBySystemrole_UserRoleNotMod(t *testing.T) {
	// Ordinary user is NOT exempt.
	assert.False(t, IsExemptBySystemrole("User"))
}

func TestIsExemptBySystemrole_EmptyRoleNotMod(t *testing.T) {
	assert.False(t, IsExemptBySystemrole(""))
}

func TestIsExemptBySystemrole_UnknownRoleNotMod(t *testing.T) {
	assert.False(t, IsExemptBySystemrole("Guest"))
}

// ── normalise ─────────────────────────────────────────────────────────────────

func TestNormalise_LowercasesInput(t *testing.T) {
	assert.Equal(t, "freegle", normalise("FREEGLE"))
}

func TestNormalise_StripsDiacritics(t *testing.T) {
	// "é" decomposes to e + combining accent; combining mark is dropped.
	assert.Equal(t, "cafe", normalise("café"))
}

func TestNormalise_DeLeetsDigits(t *testing.T) {
	// 3→e, 0→o, 1→l, 5→s
	assert.Equal(t, "freegle", normalise("Fr33gle"))
}

func TestNormalise_StripsNonAlphanumeric(t *testing.T) {
	// Spaces, hyphens, dots are removed after de-leeting.
	assert.Equal(t, "ilovefreegle", normalise("i love freegle"))
}

func TestNormalise_AtSignDeLeetsToA(t *testing.T) {
	// '@' is a leet substitution for 'a'.
	assert.Equal(t, "admin", normalise("@dmin"))
}

func TestNormalise_EmptyString(t *testing.T) {
	assert.Equal(t, "", normalise(""))
}

func TestNormalise_PunctuationOnly(t *testing.T) {
	// Hyphens and dots are non-alphanumeric and not in the leet map — all removed.
	assert.Equal(t, "", normalise("---..."))
}

// ── tokenise ─────────────────────────────────────────────────────────────────

func TestTokenise_SplitsOnSpace(t *testing.T) {
	tokens := tokenise("Alice Smith")
	assert.Contains(t, tokens, "alice")
	assert.Contains(t, tokens, "smith")
}

func TestTokenise_SplitsOnHyphen(t *testing.T) {
	tokens := tokenise("some-name")
	assert.Contains(t, tokens, "some")
	assert.Contains(t, tokens, "name")
}

func TestTokenise_LowercasesTokens(t *testing.T) {
	tokens := tokenise("FREEGLE")
	assert.Contains(t, tokens, "freegle")
}

func TestTokenise_DeLeetsTokens(t *testing.T) {
	// "Fr33gle" → "freegle" after de-leeting.
	tokens := tokenise("Fr33gle")
	assert.Contains(t, tokens, "freegle")
}

func TestTokenise_EmptyString(t *testing.T) {
	tokens := tokenise("")
	// Splitting empty produces [""] — at least one element, possibly empty.
	assert.NotNil(t, tokens)
}

func TestTokenise_DropsDiacritics(t *testing.T) {
	tokens := tokenise("André")
	assert.Contains(t, tokens, "andre")
}

// ── fuzzyHitTierA ─────────────────────────────────────────────────────────────

func TestFuzzyHitTierA_ExactFreegle(t *testing.T) {
	// "freegle" is in tierAExactOnly — exact match required.
	assert.True(t, fuzzyHitTierA("freegle"))
}

func TestFuzzyHitTierA_ExactFreecycle(t *testing.T) {
	assert.True(t, fuzzyHitTierA("freecycle"))
}

func TestFuzzyHitTierA_ExactFreshare(t *testing.T) {
	assert.True(t, fuzzyHitTierA("freshare"))
}

func TestFuzzyHitTierA_FuzzyTrashnothing(t *testing.T) {
	// "trashnothing" is in tierA but NOT tierAExactOnly → fuzzy match (≤2 edits).
	// A one-edit typo should match.
	assert.True(t, fuzzyHitTierA("trashntohing"))
}

func TestFuzzyHitTierA_NoMatchRandomWord(t *testing.T) {
	// Completely unrelated word must not match any tier A brand.
	assert.False(t, fuzzyHitTierA("bicycle"))
}

func TestFuzzyHitTierA_NoMatchFreegler(t *testing.T) {
	// "freegler" is one edit from "freegle" but freegle is exactOnly,
	// so the fuzzy path is never taken. "freegle" itself requires exact match.
	assert.False(t, fuzzyHitTierA("freegler"))
}

func TestFuzzyHitTierA_IlovefreegleFuzzy(t *testing.T) {
	// "ilovefreegle" is not in exactOnly — a one-char truncation should match.
	assert.True(t, fuzzyHitTierA("ilovefreegl"))
}

// ── abs ───────────────────────────────────────────────────────────────────────

func TestAbs_Positive(t *testing.T) {
	assert.Equal(t, 5, abs(5))
}

func TestAbs_Negative(t *testing.T) {
	assert.Equal(t, 7, abs(-7))
}

func TestAbs_Zero(t *testing.T) {
	assert.Equal(t, 0, abs(0))
}

// ── minInt ────────────────────────────────────────────────────────────────────

func TestMinInt_TwoArgs(t *testing.T) {
	assert.Equal(t, 2, minInt(3, 2))
	assert.Equal(t, 2, minInt(2, 3))
}

func TestMinInt_ThreeArgs(t *testing.T) {
	assert.Equal(t, 1, minInt(3, 1, 2))
}

func TestMinInt_AllEqual(t *testing.T) {
	assert.Equal(t, 4, minInt(4, 4, 4))
}

func TestMinInt_Negative(t *testing.T) {
	assert.Equal(t, -5, minInt(-5, 0, 10))
}

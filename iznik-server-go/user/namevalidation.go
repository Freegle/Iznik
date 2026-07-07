package user

import (
	"regexp"
	"strings"
	"unicode"

	"github.com/freegle/iznik-server-go/utils"
	"golang.org/x/text/unicode/norm"
	"gorm.io/gorm"
)

// Detection of misleading display names — see Discourse thread #9587.
//
// A non-moderator user whose display name trades on the Freegle brand or
// poses as a generic authority persona ("Admin", "Prize Winner") has their
// name rewritten on display. Storage is untouched, so the user receives no
// signal that their name has been flagged.

// tierA holds brand words. Any token matching one of these (exactly or via
// Damerau-Levenshtein, per the rules in fuzzyHitTierA) is suspicious on its
// own — legitimate volunteers are exempted via IsNameExempt instead.
var tierA = []string{
	"freegle", "ilovefreegle", "thefreegle",
	"trashnothing", "freecycle", "freshare",
}

// tierAExactOnly brand words too short for fuzzy matching: one edit from
// "freegle" is "freegler" which is a real word we use for our users. For
// these words we require an exact match after normalisation.
var tierAExactOnly = map[string]bool{
	"freegle":   true,
	"freshare":  true,
	"freecycle": true,
}

// weakBrand — derivatives of a brand word that are common English
// constructions in our community ("freegler"). Only suspicious when combined
// with an authority word ("Freegler Support").
var weakBrand = map[string]bool{
	"freegler": true, "freeglers": true,
	"freegling": true, "freegled": true,
}

// tierB — authority / role / phishing words. Used for two rules:
//  1. all-authority: every token is in here (e.g. "Admin", "Support Team")
//  2. weak-brand + authority (e.g. "Freegler Support")
var tierB = map[string]bool{
	"support": true, "supportteam": true, "team": true, "admin": true,
	"administrator": true, "moderator": true, "mod": true, "staff": true,
	"official": true, "officialteam": true, "hq": true, "headquarters": true,
	"centre": true, "center": true, "customer": true, "customerservice": true,
	"customercare": true, "helpdesk": true, "help": true, "service": true,
	"services": true, "info": true, "contact": true, "enquiries": true,
	"security": true, "verify": true, "verification": true, "verified": true,
	"account": true, "accounts": true, "billing": true, "notification": true,
	"notifications": true, "alert": true, "alerts": true, "system": true,
	"systems": true, "update": true, "updates": true, "warning": true,
	"fraud": true, "abuse": true, "safety": true, "trust": true,
	"claims": true, "refund": true, "refunds": true, "winner": true,
	"prize": true, "prizes": true, "lottery": true, "giveaway": true,
	"reward": true, "rewards": true, "authority": true, "agent": true,
	"representative": true, "rep": true, "response": true, "responder": true,
	"policy": true, "compliance": true, "review": true, "reviewer": true,
	"audit": true, "suspension": true, "suspended": true, "ban": true,
	"banned": true,
}

// tnEmailSuffix — users imported from TrashNothing often have email-like
// fullnames such as "alice-g3486@user.trashnothing.com". These aren't
// deliberate impersonation, they're an import side-effect.
var tnEmailSuffix = regexp.MustCompile(`(?i)-g[0-9]+@user\.trashnothing\.com$`)

var nonAlnum = regexp.MustCompile(`[^a-z0-9]+`)

// leet maps common digit/punct substitutions to their letter equivalents so
// "Fr33gle" and "Freegle" both normalise the same way.
var leet = map[rune]rune{
	'0': 'o', '1': 'l', '3': 'e', '4': 'a', '5': 's',
	'7': 't', '@': 'a', '$': 's', '!': 'i',
}

// normalise lower-cases, strips diacritics/zero-width, de-leets and drops
// all non-alphanumeric characters. Used for the "concat" match rule.
func normalise(name string) string {
	// NFKD decomposes accented chars into base + combining mark so we can
	// drop the combining marks to defeat Cyrillic lookalikes and ZWJ.
	decomposed := norm.NFKD.String(name)
	var sb strings.Builder
	for _, r := range decomposed {
		if unicode.In(r, unicode.Mn) {
			continue
		}
		r = unicode.ToLower(r)
		if mapped, ok := leet[r]; ok {
			r = mapped
		}
		sb.WriteRune(r)
	}
	return nonAlnum.ReplaceAllString(sb.String(), "")
}

// tokenise splits by non-alphanumeric after the same lowercase / de-leet /
// de-accent pass as normalise. Used for per-token matching.
func tokenise(name string) []string {
	decomposed := norm.NFKD.String(name)
	var sb strings.Builder
	for _, r := range decomposed {
		if unicode.In(r, unicode.Mn) {
			continue
		}
		r = unicode.ToLower(r)
		if mapped, ok := leet[r]; ok {
			r = mapped
		}
		sb.WriteRune(r)
	}
	return nonAlnum.Split(strings.Trim(nonAlnum.ReplaceAllString(sb.String(), " "), " "), -1)
}

// damerauLevenshtein returns the Damerau-Levenshtein edit distance between a
// and b, early-exiting at maxD+1 to avoid doing work for distant pairs.
func damerauLevenshtein(a, b string, maxD int) int {
	la, lb := len(a), len(b)
	if abs(la-lb) > maxD {
		return maxD + 1
	}
	// prev2[j] = d[i-2][j], prev[j] = d[i-1][j], cur[j] = d[i][j]
	prev2 := make([]int, lb+1)
	prev := make([]int, lb+1)
	cur := make([]int, lb+1)
	for j := 0; j <= lb; j++ {
		prev[j] = j
	}
	for i := 1; i <= la; i++ {
		cur[0] = i
		minRow := cur[0]
		for j := 1; j <= lb; j++ {
			cost := 1
			if a[i-1] == b[j-1] {
				cost = 0
			}
			v := minInt(prev[j]+1, cur[j-1]+1, prev[j-1]+cost)
			if i > 1 && j > 1 && a[i-1] == b[j-2] && a[i-2] == b[j-1] {
				if prev2[j-2]+cost < v {
					v = prev2[j-2] + cost
				}
			}
			cur[j] = v
			if v < minRow {
				minRow = v
			}
		}
		if minRow > maxD {
			return maxD + 1
		}
		prev2, prev, cur = prev, cur, prev2
	}
	return prev[lb]
}

func abs(x int) int {
	if x < 0 {
		return -x
	}
	return x
}

func minInt(xs ...int) int {
	m := xs[0]
	for _, x := range xs[1:] {
		if x < m {
			m = x
		}
	}
	return m
}

// fuzzyHitTierA reports whether tok matches any Tier A brand word exactly
// (for short brand words) or within Damerau-Levenshtein distance 2 (for
// long ones, bounded by length-difference 1 to avoid trivial matches).
func fuzzyHitTierA(tok string) bool {
	tl := len(tok)
	for _, target := range tierA {
		if tierAExactOnly[target] {
			if tok == target {
				return true
			}
			continue
		}
		lt := len(target)
		if abs(tl-lt) > 1 {
			continue
		}
		if damerauLevenshtein(tok, target, 2) <= 2 {
			return true
		}
	}
	return false
}

// isSuspiciousName returns true if raw matches any of the rules:
//  1. Any token fuzzy-matches a Tier A brand word.
//  2. The concatenated form (obfuscation-resistant) matches a Tier A word.
//  3. A weak-brand token appears alongside an authority word.
//  4. Every token is an authority word (generic phishing persona).
func isSuspiciousName(raw string) bool {
	if raw == "" {
		return false
	}
	// Ignore email-like and TN-suffix fullnames (import side-effects, not
	// deliberate impersonation — and rewriting them is low-value).
	if strings.Contains(raw, "@") {
		return false
	}
	if tnEmailSuffix.MatchString(raw) {
		return false
	}

	tokens := tokenise(raw)
	if len(tokens) == 0 || (len(tokens) == 1 && tokens[0] == "") {
		return false
	}
	// Filter empty tokens produced by leading/trailing/adjacent separators.
	nonEmpty := tokens[:0]
	for _, t := range tokens {
		if t != "" {
			nonEmpty = append(nonEmpty, t)
		}
	}
	tokens = nonEmpty
	if len(tokens) == 0 {
		return false
	}

	concat := normalise(raw)

	hasTierB := false
	allTierB := true
	for _, t := range tokens {
		if tierB[t] {
			hasTierB = true
		} else {
			allTierB = false
		}
	}

	// Rule 1: Tier A token match.
	for _, t := range tokens {
		if fuzzyHitTierA(t) {
			return true
		}
	}
	// Rule 2: Tier A concat match (obfuscation-resistant).
	if fuzzyHitTierA(concat) {
		return true
	}
	// Rule 3: weak brand + authority.
	if hasTierB {
		for _, t := range tokens {
			if weakBrand[t] {
				return true
			}
		}
	}
	// Rule 4: all tokens are Tier B authority words.
	if allTierB {
		return true
	}
	return false
}

// IsNameExempt reports whether a user is exempt from name sanitisation —
// i.e. a platform moderator/support/admin, or an Owner/Moderator on any
// group. Exempt users keep whatever display name they set.
func IsNameExempt(db *gorm.DB, userid uint64) bool {
	var row struct {
		Systemrole string
		IsMod      int
	}
	db.Raw("SELECT u.systemrole, "+
		"IF(EXISTS(SELECT 1 FROM memberships m WHERE m.userid = u.id AND m.role IN (?, ?)), 1, 0) AS is_mod "+
		"FROM users u WHERE u.id = ?",
		utils.ROLE_OWNER, utils.ROLE_MODERATOR, userid).Scan(&row)
	switch row.Systemrole {
	case utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN:
		return true
	}
	return row.IsMod == 1
}

// IsExemptBySystemroleAndMod is a convenience for call sites that already
// have the systemrole in hand and know whether the user is a group mod —
// avoids an extra DB round-trip.
func IsExemptBySystemroleAndMod(systemrole string, isGroupMod bool) bool {
	switch systemrole {
	case utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN:
		return true
	}
	return isGroupMod
}

// SanitizeDisplayName returns a safe rewrite of raw for non-exempt users,
// or raw unchanged for exempt users and clean names.
//
// Rewrites suspicious names to "A freegler" — a neutral placeholder used
// elsewhere in the codebase. The caller is responsible for deciding
// exemption (typically: systemrole is Mod/Support/Admin OR user is
// Owner/Moderator on any group).
func SanitizeDisplayName(raw string, isExempt bool) string {
	if isExempt {
		return raw
	}
	if !isSuspiciousName(raw) {
		return raw
	}
	return "A freegler"
}

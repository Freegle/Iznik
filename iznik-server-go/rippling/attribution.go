package rippling

import (
	"regexp"
	"sync"

	"gorm.io/gorm"
)

// Attribution channel values - must match the rippling_reply_attribution.attribution enum
// (migration 2026_07_07_000002). Ladder precedence order.
const (
	AttributionHome           = "home"
	AttributionRippleNotified = "ripple_notified"
	AttributionRippleGroup    = "ripple_group"
	AttributionOrganicLocal   = "organic_local"
	AttributionRippleReach    = "ripple_reach"
	AttributionUnknown        = "unknown"
)

var attributionSchemaOnce sync.Once
var attributionSchemaWide bool

// AttributionSchemaReady reports whether rippling_reply_attribution has the graded-attribution
// columns (migration 2026_07_07_000002). The reply capture and the sysadmin metrics both need
// to work against a production DB that may not have been migrated yet, so each picks its wide
// or legacy variant off this. Checked once per process (schema changes need a restart to be
// noticed - deploys restart the API anyway).
func AttributionSchemaReady(db *gorm.DB) bool {
	attributionSchemaOnce.Do(func() {
		var n int
		db.Raw("SELECT COUNT(*) FROM information_schema.COLUMNS " +
			"WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution' " +
			"AND COLUMN_NAME = 'attribution'").Scan(&n)
		attributionSchemaWide = n > 0
	})
	return attributionSchemaWide
}

// DeriveAttribution runs the attribution ladder over the evidence bits captured at reply time
// and returns the channel. Precedence, and why:
//
//  1. home - an established origin-group member. Wins over all ripple evidence (conservative:
//     when exposure is ambiguous we do NOT credit rippling).
//  2. ripple_notified - we sent this user the ripple mail for this post. Direct delivery evidence.
//  3. ripple_group - they were already a member of a group the post rippled into, so they saw it
//     in their own group's feed/digest because of the ripple.
//  4. organic_local - non-member inside an origin group's catchment: they'd plausibly have seen
//     the post in Browse regardless of rippling. Deliberately ranked ABOVE ripple_reach because
//     the reach polygon always covers the origin area - without this ordering every local
//     non-member would mis-classify as reach.
//  5. ripple_reach - outside the origin catchment but inside the reach polygon: their Browse
//     exposure existed only because the ripple extended there (the nearby feed is reach-fed).
//  6. unknown - nothing resolvable (search / deep link / share, or no location on file).
//
// The hard guard - a post that never rippled can never be ripple-attributed - is structural:
// with no ripple there are no notified-ledger rows and no rippled-in copies (so 2-3 can't fire),
// and 5 additionally requires postHadRippled.
//
// inOriginCatchment/inReach are pointers because location evidence can be unavailable
// (replier has no location on file): nil = unknown, distinct from a definite 0.
func DeriveAttribution(wasHomeMember, wasNotified, wasRippleGroupMember, postHadRippled int, inOriginCatchment, inReach *int) string {
	switch {
	case wasHomeMember == 1:
		return AttributionHome
	case wasNotified == 1:
		return AttributionRippleNotified
	case wasRippleGroupMember == 1:
		return AttributionRippleGroup
	case inOriginCatchment != nil && *inOriginCatchment == 1:
		return AttributionOrganicLocal
	case postHadRippled == 1 && inReach != nil && *inReach == 1:
		return AttributionRippleReach
	default:
		return AttributionUnknown
	}
}

var clientSourceRE = regexp.MustCompile(`^[a-z0-9][a-z0-9_-]{0,31}$`)

// SanitizeClientSource validates the client-reported reply surface (browse, search,
// message_page, notification, ...). It is client-supplied and spoofable, so it is stored as
// advisory evidence only, never folded into the attribution ladder. A regex constraint rather
// than a whitelist: new client surfaces shouldn't need an API deploy, but arbitrary strings
// (or injection attempts) must not reach the DB. Returns nil when absent or invalid.
func SanitizeClientSource(source *string) *string {
	if source == nil || !clientSourceRE.MatchString(*source) {
		return nil
	}
	return source
}

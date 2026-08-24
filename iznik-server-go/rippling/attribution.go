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
	AttributionRippleJoin     = "ripple_join"
	AttributionOrganicLocal   = "organic_local"
	AttributionRippleReach    = "ripple_reach"
	AttributionUnknown        = "unknown"
)

// EstablishedOriginMemberExists builds the EXISTS(...) test every rippling statistic uses for
// "was this replier already a member here, independently of rippling?" - an approved membership of
// an ORIGIN group of the post (never a rippled-in copy's group), joined before the post arrived so
// a join-made-in-order-to-reply never counts.
//
// The mem.rippled = 0 clause is the load-bearing part. Rippling auto-joins a poster to every group
// their post rippled into (memberships.rippled = 1, ExpandService::addPosterMembershipToRippledGroups
// in iznik-batch), so a frequent poster accumulates memberships of distant groups purely as a
// side-effect of rippling. Counting those as pre-existing local membership meant that when one of
// those groups later hosted a post of its own, the reply was scored as one rippling had nothing to
// do with - the exact opposite of the truth, since without rippling that member would not have
// been in the group to see it. That mis-scored ~7% of all "home" replies on production.
//
// msgCol/userCol are the caller's outer columns to correlate against; the fragment owns the
// aliases og/mem, so it can be nested anywhere those two are free.
func EstablishedOriginMemberExists(msgCol, userCol string) string {
	return `EXISTS(SELECT 1 FROM messages_groups og
	                  INNER JOIN memberships mem ON mem.groupid = og.groupid AND mem.userid = ` + userCol + `
	                    AND mem.collection = 'Approved' AND mem.added < og.arrival AND mem.rippled = 0
	                  WHERE og.msgid = ` + msgCol + ` AND og.rippled_in = 0 AND og.deleted = 0)`
}

var attributionSchemaOnce sync.Once
var attributionSchemaWide bool

// AttributionSchemaReady reports whether rippling_reply_attribution has the graded-attribution
// columns (migration 2026_07_07_000002). The reply capture and the sysadmin metrics both need
// to work against a production DB that may not have been migrated yet, so each picks its wide
// or legacy variant off this. Checked once per process (schema changes need a restart to be
// noticed - deploys restart the API anyway).
func AttributionSchemaReady(db *gorm.DB) bool {
	attributionSchemaOnce.Do(func() {
		var n int64
		db.Table("information_schema.COLUMNS").
			Where("TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rippling_reply_attribution' AND COLUMN_NAME = 'attribution'").
			Count(&n)
		attributionSchemaWide = n > 0
	})
	return attributionSchemaWide
}

// DeriveAttribution runs the attribution ladder over the evidence bits captured at reply time
// and returns the channel. Precedence, and why:
//
//  1. home - an established origin-group member, by a membership rippling did not create. Wins
//     over all ripple evidence (conservative: when exposure is ambiguous we do NOT credit
//     rippling). See EstablishedOriginMemberExists for why the provenance qualifier matters.
//  2. ripple_notified - we sent this user the ripple mail for this post. Direct delivery evidence.
//  3. ripple_group - they were already a member of a group the post rippled into, so they saw it
//     in their own group's feed/digest because of the ripple.
//  4. ripple_join - they are a member of an ORIGIN group, but only because an earlier ripple (of
//     their own post) auto-joined them there. Same shape of evidence as 3 - membership-level
//     exposure that exists because of a ripple - so it is ranked with it, above the location
//     rungs. Needs no postHadRippled guard: the ripple that earns the credit already happened,
//     to a different post, and left the membership behind as its record.
//  5. organic_local - non-member inside an origin group's catchment: they'd plausibly have seen
//     the post in Browse regardless of rippling. Deliberately ranked ABOVE ripple_reach because
//     the reach polygon always covers the origin area - without this ordering every local
//     non-member would mis-classify as reach.
//  6. ripple_reach - outside the origin catchment but inside the reach polygon: their Browse
//     exposure existed only because the ripple extended there (the nearby feed is reach-fed).
//  7. unknown - nothing resolvable (search / deep link / share, or no location on file).
//
// The hard guard - a post that never rippled can never be ripple-attributed - is structural:
// with no ripple there are no notified-ledger rows and no rippled-in copies (so 2-3 can't fire),
// and 6 additionally requires postHadRippled. Rung 4 is deliberately outside the guard, being
// evidence about a PREVIOUS ripple rather than this post's.
//
// inOriginCatchment/inReach are pointers because location evidence can be unavailable
// (replier has no location on file): nil = unknown, distinct from a definite 0.
func DeriveAttribution(wasHomeMember, wasNotified, wasRippleGroupMember, wasRippleJoinMember,
	postHadRippled int, inOriginCatchment, inReach *int) string {
	switch {
	case wasHomeMember == 1:
		return AttributionHome
	case wasNotified == 1:
		return AttributionRippleNotified
	case wasRippleGroupMember == 1:
		return AttributionRippleGroup
	case wasRippleJoinMember == 1:
		return AttributionRippleJoin
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

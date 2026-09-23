package utils

// authorCapMiles resolves a post author's OUTBOUND distance cap in miles, or SQL NULL when they
// have not set one. It is the SQL twin of the batch's
// App\Services\Ripple\DistancePreferenceFilter::authorMaxDistanceMiles - the two must agree or the
// browse feed and the digest would disagree about the same post.
//
// Two keys, in priority order:
//
//	settings.myPostsMaxDistance  the member's own answer to "how far away can people see my posts",
//	                             written only when they separate it from what they see.
//	settings.browseMaxDistance   what they chose for what THEY see. Consulted as the fallback
//	                             because the two used to be one control: a member who has never
//	                             separated them gets exactly the behaviour they had before, and
//	                             separating them is what stops this key applying outbound.
//
// settings.browseReachMaxDistance is deliberately NOT consulted. That is the INBOUND-ONLY density
// band default (browse:backfill-max-distance): it says how far this member will travel to collect,
// which is a different question from how far their giveaway should travel to find a taker. Applying
// it outbound would stop a city member's posts leaving their ~4.8-mile band radius and undo the
// reason the ripple grows to the ceiling at all.
//
// "Not set" has four spellings and all of them must resolve to NULL here, because all four are
// reachable in production:
//
//	absent            JSON_EXTRACT gives SQL NULL -> CAST NULL -> GREATEST NULL -> NULLIF NULL.
//	JSON null         the normal result of re-linking the two axes, NOT a transient state: PATCH
//	                  /session replaces the settings blob wholesale, so the nulls the client sends
//	                  are stored as JSON null rather than removing the keys (verified on a live
//	                  row). NOTE JSON_EXTRACT(...) IS NULL is FALSE for this, and JSON_UNQUOTE turns
//	                  it into the *string* 'null', which CASTs to 0 - so it is the NULLIF(...,0)
//	                  that catches it, never an IS NULL test.
//	<= 0              a nonsense cap. GREATEST(...,0) folds negatives onto 0, NULLIF then drops it.
//	the sentinel      9007199254740991, meaning "no limit". This one resolves to itself rather than
//	                  to NULL, and needs no arm of its own: it is larger than any great-circle
//	                  distance on earth, so the caller's single comparison always passes it.
//
// DECIMAL(30,6), not (20,6): the sentinel is 16 integer digits and DECIMAL(20,6) holds only 14, so
// casting a stored sentinel to (20,6) saturates it at 99999999999999.999999. Under the caller's
// single comparison a saturated value still passes (it is far larger than any distance), so the
// outcome is unchanged either way - the width is here so a stored value survives the cast as
// itself rather than being silently mangled, and so a future `= sentinel` test cannot be written
// against a number that never arrives. It mattered more in an earlier draft of this clause, which
// had a dedicated `>= sentinel` arm; at (20,6) that arm could never fire.
const authorCapMiles = "COALESCE(" +
	"NULLIF(GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(au.settings, '$.myPostsMaxDistance')) AS DECIMAL(30,6)), 0), 0), " +
	"NULLIF(GREATEST(CAST(JSON_UNQUOTE(JSON_EXTRACT(au.settings, '$.browseMaxDistance')) AS DECIMAL(30,6)), 0), 0))"

// AuthorReachCapWhere is the OUTBOUND half of the distance preference: a post is only visible to
// viewers within the POST AUTHOR's own chosen distance of it ("how far away can people see your
// posts"), mirroring the inbound recipient-side cap in the Laravel digest. Applied in SQL (not Go)
// so every reader of the reach universe - the browse feed, its unread-count badge, and browse-scoped
// search - stays in lock-step by adding this same clause.
//
// Two arms:
//
//  1. FAST PATH - neither key present at all, the common case. A bare JSON_EXTRACT presence test
//     on both keys, before any casting, so the majority of rows never reach the resolution or the
//     trigonometry. This arm is an optimisation only: it can never wrongly exclude a post (it only
//     admits early), and every state it does not catch is decided correctly by arm 2. It earns its
//     place - measured over 60,000 rows shaped like production (70% no cap, 20% linked, 10% split),
//     dropping it costs 44ms against 26ms.
//  2. within the cap - one resolution, compared against the real great-circle distance from the
//     viewer to the post point. The sentinel is the COALESCE default, so "no cap set" and an
//     explicit "no limit" both fall out of this single comparison: the sentinel is larger than any
//     distance on earth, so it always passes. A separate sentinel arm would be redundant, and
//     resolving the keys a third time to ask the question measurably was - 54ms for the three-arm
//     form against 26ms for this one.
//
// Placeholders, in order: sentinel, viewerLat, viewerLng, viewerLat - unchanged from before the
// inbound/outbound split, so no call site had to be touched.
//
// Requires `au` (users, joined on messages.fromuser) and `ms` (messages_spatial, for the post point)
// to be in scope.
//
// Distance is real great-circle from the viewer to the post (like the ST_Contains reach gate), not
// the blurred distance the viewer-side slider uses. It is an inline Haversine (3959-mile earth
// radius, matching the Laravel filter) over ST_X/ST_Y of ms.point as plain lng/lat degrees, NOT
// ST_Distance_Sphere - the latter errors on the SRID-3857-tagged points Freegle stores (its degrees
// carry a projected SRID that ST_Distance_Sphere rejects). The LEAST/GREATEST pair clamps ACOS's
// argument into [-1,1]: overshoot at ~0 distance is the one that actually happens, and an
// out-of-domain ACOS returns NULL, which in this single-comparison arm would hide the post.
const AuthorReachCapWhere = "AND ((" +
	"JSON_EXTRACT(au.settings, '$.myPostsMaxDistance') IS NULL " +
	"AND JSON_EXTRACT(au.settings, '$.browseMaxDistance') IS NULL) " +
	"OR COALESCE(" + authorCapMiles + ", ?) " +
	">= (3959 * ACOS(GREATEST(-1.0, LEAST(1.0, " +
	"COS(RADIANS(?)) * COS(RADIANS(ST_Y(ms.point))) * COS(RADIANS(ST_X(ms.point)) - RADIANS(?)) " +
	"+ SIN(RADIANS(?)) * SIN(RADIANS(ST_Y(ms.point))))))) ) "

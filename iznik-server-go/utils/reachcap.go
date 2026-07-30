package utils

// AuthorReachCapWhere is the OUTBOUND half of the distance preference: a post is only visible to
// viewers within the POST AUTHOR's own settings.browseMaxDistance of it ("how far away can people
// see your posts"), mirroring the inbound recipient-side cap in the Laravel digest. Applied in SQL
// (not Go) so every reader of the reach universe - the browse feed, its unread-count badge, and
// browse-scoped search - stays in lock-step by adding this same clause. Absent settings / missing
// key / <= 0 / the sentinel all DISABLE the cap (the common case). Distance is real great-circle
// from the viewer to the post point (like the ST_Contains reach gate), not the blurred distance
// the viewer-side slider uses. Placeholders, in order: sentinel, viewerLat, viewerLng, viewerLat.
// Requires `au` (users, joined on messages.fromuser) and `ms` (messages_spatial, for the post
// point) to be in scope.
// Distance is an inline great-circle (Haversine, 3959-mile earth radius matching the Laravel
// filter) over ST_X/ST_Y of ms.point as plain lng/lat degrees, NOT ST_Distance_Sphere - the latter
// errors on the SRID-3857-tagged points Freegle stores (its degrees carry a projected SRID that
// ST_Distance_Sphere rejects). LEAST(1.0, ...) guards ACOS against float overshoot at ~0 distance.
const AuthorReachCapWhere = "AND (au.settings IS NULL " +
	"OR JSON_EXTRACT(au.settings, '$.browseMaxDistance') IS NULL " +
	"OR CAST(JSON_UNQUOTE(JSON_EXTRACT(au.settings, '$.browseMaxDistance')) AS DECIMAL(20,6)) <= 0 " +
	"OR CAST(JSON_UNQUOTE(JSON_EXTRACT(au.settings, '$.browseMaxDistance')) AS DECIMAL(20,6)) >= ? " +
	"OR (3959 * ACOS(LEAST(1.0, " +
	"COS(RADIANS(?)) * COS(RADIANS(ST_Y(ms.point))) * COS(RADIANS(ST_X(ms.point)) - RADIANS(?)) " +
	"+ SIN(RADIANS(?)) * SIN(RADIANS(ST_Y(ms.point)))))) " +
	"<= CAST(JSON_UNQUOTE(JSON_EXTRACT(au.settings, '$.browseMaxDistance')) AS DECIMAL(20,6))) "

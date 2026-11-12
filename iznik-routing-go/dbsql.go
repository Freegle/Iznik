package main

// Every SQL statement the service sends, in the form each engine takes.
//
// The statements are written with ? placeholders. On PostgreSQL they are rebound to $1, $2, ... by
// sqlFor, so a call site passes the same arguments on both engines. A statement that both engines
// read the same way goes through portableSQL. A statement that differs has a MySQL text and a
// PostgreSQL text, and the MySQL text is exactly what the service sent before the engine became
// configurable: dbsql_test.go holds each one to that.
//
// Where the engines differ, the differences are the ones the schema converter documents
// (docs/developers/reference/database-engines.md):
//
//   - Geometry columns are SRID 3857 on MySQL and 4326 on PostgreSQL, both holding degrees, so a
//     point or polygon built from a value is labelled with the column's own SRID.
//   - MySQL builds a point with POINT(x, y) or a WKT string; PostGIS uses ST_MakePoint or the WKT.
//   - ST_GeometryType gives POLYGON on MySQL and ST_Polygon on PostGIS.
//   - MySQL reads a box test from the spatial index with MBRContains; PostGIS uses && (the same
//     bounding-box test, answered by the GiST index).
//   - INTERVAL 90 DAY is INTERVAL '90 days'; IF() is CASE; INSERT IGNORE is ON CONFLICT DO NOTHING;
//     information_schema.statistics is pg_index.
//   - `groups`, `primary`, `key` and `value` are backticked on MySQL. PostgreSQL quotes "groups"
//     and "primary" and needs nothing for key and value.
//
// A statement that takes a time sends it in UTC (see utcArg).

import (
	"time"

	"github.com/freegle/iznik-routing-go/dbengine"
)

// sqlFor returns the statement for the configured engine.
func sqlFor(mysqlSQL, pgSQL string) string {
	if dbengine.Current() != dbengine.Postgres {
		return mysqlSQL
	}
	return dbengine.Postgres.Rebind(pgSQL)
}

// portableSQL is for a statement both engines read the same way.
func portableSQL(q string) string { return sqlFor(q, q) }

// fpArg sends a 64-bit partition fingerprint to the configured engine (see
// dbengine.Engine.FingerprintArg).
func fpArg(fp uint64) any { return dbengine.Current().FingerprintArg(fp) }

// utcArg sends a time as UTC. The MySQL driver converts to its configured location itself. On
// PostgreSQL the columns are timestamptz and pgx sends an absolute instant, so the zone of the
// value does not change what is compared; sending UTC keeps the two engines' arguments the same.
func utcArg(t time.Time) time.Time { return t.UTC() }

// pgSingleAsDouble reads a single-precision (real) column as the double a MySQL FLOAT column
// gives: 12.3 stays 12.3. pgx widens the 32-bit value, which would give 12.300000190734863.
func pgSingleAsDouble(col string) string {
	return "CAST(CAST(" + col + " AS text) AS double precision)"
}

// sqlSessionExists is the JWT session check (middleware.go).
func sqlSessionExists() string {
	return portableSQL("SELECT 1 FROM sessions INNER JOIN users ON users.id = sessions.userid WHERE sessions.id = ? AND users.id = ? LIMIT 1")
}

// sqlGroupActiveMembers counts a group's approved members seen in the last 90 days
// (group_actives.go). Args: groupid.
func sqlGroupActiveMembers() string {
	return sqlFor(
		`SELECT COUNT(*) FROM memberships m
		 INNER JOIN users u ON u.id = m.userid
		 WHERE m.groupid = ? AND m.collection = 'Approved'
		   AND u.lastaccess >= NOW() - INTERVAL 90 DAY`,
		`SELECT COUNT(*) FROM memberships m
		 INNER JOIN users u ON u.id = m.userid
		 WHERE m.groupid = ? AND m.collection = 'Approved'
		   AND u.lastaccess >= NOW() - INTERVAL '90 days'`)
}

// sqlNearbyGroups lists the groups around a point, the one that contains it first (groups.go).
// Args: lng, lat, lng, lat, boxLngMin, boxLngMax, boxLatMin, boxLatMax, lng, lat.
func sqlNearbyGroups() string {
	return sqlFor(`
			SELECT id, nameshort, ST_AsText(polyindex) AS wkt,
			       ST_Contains(polyindex,
			           ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)) AS contains_pt
			FROM `+"`groups`"+`
			WHERE publish = 1 AND listable = 1
			  AND polyindex IS NOT NULL
			  AND (
			    ST_Contains(polyindex,
			        ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857))
			    OR (
			      ST_X(ST_Centroid(polyindex)) BETWEEN ? AND ?
			      AND ST_Y(ST_Centroid(polyindex)) BETWEEN ? AND ?
			    )
			  )
			ORDER BY contains_pt DESC,
			         ST_Distance(polyindex,
			             ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)) ASC
			LIMIT 60
		`, `
			SELECT id, nameshort, ST_AsText(polyindex) AS wkt,
			       ST_Contains(polyindex, ST_SetSRID(ST_MakePoint(?, ?), 4326)) AS contains_pt
			FROM "groups"
			WHERE publish = 1 AND listable = 1
			  AND polyindex IS NOT NULL
			  AND (
			    ST_Contains(polyindex, ST_SetSRID(ST_MakePoint(?, ?), 4326))
			    OR (
			      ST_X(ST_Centroid(polyindex)) BETWEEN ? AND ?
			      AND ST_Y(ST_Centroid(polyindex)) BETWEEN ? AND ?
			    )
			  )
			ORDER BY contains_pt DESC,
			         ST_Distance(polyindex, ST_SetSRID(ST_MakePoint(?, ?), 4326)) ASC
			LIMIT 60
		`)
}

// sqlGroupBoundary reads one group's polygon and centroid (groups.go). Args: groupid.
func sqlGroupBoundary() string {
	return sqlFor("SELECT ST_AsText(polyindex), ST_Y(ST_Centroid(polyindex)), "+
		"ST_X(ST_Centroid(polyindex)) FROM `groups` WHERE id = ?",
		`SELECT ST_AsText(polyindex), ST_Y(ST_Centroid(polyindex)), `+
			`ST_X(ST_Centroid(polyindex)) FROM "groups" WHERE id = ?`)
}

// sqlGroupsList lists every publishable group with its centroid (groups.go).
func sqlGroupsList() string {
	return sqlFor("SELECT id, nameshort, "+
		"ST_Y(ST_Centroid(polyindex)) AS lat, ST_X(ST_Centroid(polyindex)) AS lng "+
		"FROM `groups` WHERE publish = 1 AND listable = 1 AND polyindex IS NOT NULL "+
		"ORDER BY nameshort",
		`SELECT id, nameshort, `+
			`ST_Y(ST_Centroid(polyindex)) AS lat, ST_X(ST_Centroid(polyindex)) AS lng `+
			`FROM "groups" WHERE publish = 1 AND listable = 1 AND polyindex IS NOT NULL `+
			`ORDER BY nameshort`)
}

// sqlDigestHomeGroups finds the groups whose polygon contains a point (digest_simulator.go).
// Args: the point as WKT, POINT(lng lat).
func sqlDigestHomeGroups() string {
	return sqlFor(`
			SELECT id, nameshort, ST_AsGeoJSON(polyindex)
			  FROM `+"`groups`"+`
			 WHERE ST_Contains(polyindex, ST_GeomFromText(?, 3857))
			 LIMIT 8
		`, `
			SELECT id, nameshort, ST_AsGeoJSON(polyindex)
			  FROM "groups"
			 WHERE ST_Contains(polyindex, ST_GeomFromText(?, 4326))
			 LIMIT 8
		`)
}

// sqlDigestPool reads the posts inside an isochrone with their engagement signals
// (digest_simulator.go). Args: window start, the isochrone as WKT, limit.
//
// messages.tnpostid is text, so the PostgreSQL default for it is '0': COALESCE will not mix text and
// an integer. The caller scans it into an integer either way.
func sqlDigestPool() string {
	return sqlFor(`
			SELECT ms.msgid,
			       ST_X(ms.point) AS lng,
			       ST_Y(ms.point) AS lat,
			       ms.msgtype,
			       ms.arrival,
			       ms.successful,
			       ms.promised,
			       COALESCE(ms.groupid, 0)            AS groupid,
			       COALESCE(g.nameshort, '')          AS groupname,
			       COALESCE(m.fromuser, 0)            AS fromuser,
			       COALESCE(m.subject, '')            AS subject,
			       (SELECT COALESCE(SUM(ml.count), 0)
			          FROM messages_likes ml
			         WHERE ml.msgid = ms.msgid
			           AND ml.type = 'View')          AS views,
			       (SELECT COUNT(*)
			          FROM chat_messages cm
			         WHERE cm.refmsgid = ms.msgid
			           AND cm.type = 'Interested'
			           AND cm.reviewrejected = 0
			           AND cm.reviewrequired = 0)     AS replies,
			       COALESCE(
			         (SELECT a.id FROM messages_attachments a
			           WHERE a.msgid = ms.msgid
			           ORDER BY a.`+"`primary`"+` DESC, a.id ASC LIMIT 1),
			         0)                               AS thumb_attachment_id,
			       COALESCE(
			         (SELECT a.externaluid FROM messages_attachments a
			           WHERE a.msgid = ms.msgid AND a.externaluid IS NOT NULL
			           ORDER BY a.`+"`primary`"+` DESC, a.id ASC LIMIT 1),
			         '')                              AS thumb_externaluid,
			       EXISTS(SELECT 1 FROM messages_outcomes mo
			               WHERE mo.msgid = ms.msgid)        AS has_outcome,
			       EXISTS(SELECT 1 FROM messages_outcomes mo
			               WHERE mo.msgid = ms.msgid
			                 AND mo.outcome IN ('Taken','Received')) AS has_success,
			       COALESCE(m.tnpostid, 0)            AS tnpostid,
			       COALESCE(m.locationid, 0)          AS locationid,
			       COALESCE(m.textbody, '')           AS textbody
			  FROM messages_spatial ms
			  LEFT JOIN messages m ON m.id = ms.msgid
			  LEFT JOIN `+"`groups`"+` g ON g.id = ms.groupid
			 WHERE ms.arrival >= ?
			   AND ms.msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 3857), ms.point)
			 ORDER BY ms.arrival DESC
			 LIMIT ?
		`, `
			SELECT ms.msgid,
			       ST_X(ms.point) AS lng,
			       ST_Y(ms.point) AS lat,
			       ms.msgtype,
			       ms.arrival,
			       ms.successful,
			       ms.promised,
			       COALESCE(ms.groupid, 0)            AS groupid,
			       COALESCE(g.nameshort, '')          AS groupname,
			       COALESCE(m.fromuser, 0)            AS fromuser,
			       COALESCE(m.subject, '')            AS subject,
			       (SELECT COALESCE(SUM(ml.count), 0)
			          FROM messages_likes ml
			         WHERE ml.msgid = ms.msgid
			           AND ml.type = 'View')          AS views,
			       (SELECT COUNT(*)
			          FROM chat_messages cm
			         WHERE cm.refmsgid = ms.msgid
			           AND cm.type = 'Interested'
			           AND cm.reviewrejected = 0
			           AND cm.reviewrequired = 0)     AS replies,
			       COALESCE(
			         (SELECT a.id FROM messages_attachments a
			           WHERE a.msgid = ms.msgid
			           ORDER BY a."primary" DESC, a.id ASC LIMIT 1),
			         0)                               AS thumb_attachment_id,
			       COALESCE(
			         (SELECT a.externaluid FROM messages_attachments a
			           WHERE a.msgid = ms.msgid AND a.externaluid IS NOT NULL
			           ORDER BY a."primary" DESC, a.id ASC LIMIT 1),
			         '')                              AS thumb_externaluid,
			       EXISTS(SELECT 1 FROM messages_outcomes mo
			               WHERE mo.msgid = ms.msgid)        AS has_outcome,
			       EXISTS(SELECT 1 FROM messages_outcomes mo
			               WHERE mo.msgid = ms.msgid
			                 AND mo.outcome IN ('Taken','Received')) AS has_success,
			       COALESCE(m.tnpostid, '0')          AS tnpostid,
			       COALESCE(m.locationid, 0)          AS locationid,
			       COALESCE(m.textbody, '')           AS textbody
			  FROM messages_spatial ms
			  LEFT JOIN messages m ON m.id = ms.msgid
			  LEFT JOIN "groups" g ON g.id = ms.groupid
			 WHERE ms.arrival >= ?
			   AND ms.msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 4326), ms.point)
			 ORDER BY ms.arrival DESC
			 LIMIT ?
		`)
}

// sqlPostsInIsochrone reads the posts inside an isochrone for one day (posts_for_member.go).
// Args: day start, day end, the isochrone as WKT, limit.
func sqlPostsInIsochrone() string {
	return sqlFor(`
			SELECT msgid,
			       ST_X(point) AS lng,
			       ST_Y(point) AS lat,
			       msgtype,
			       arrival,
			       successful,
			       promised,
			       COALESCE(groupid, 0)
			  FROM messages_spatial
			 WHERE arrival BETWEEN ? AND ?
			   AND msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 3857), point)
			 ORDER BY arrival DESC
			 LIMIT ?
		`, `
			SELECT msgid,
			       ST_X(point) AS lng,
			       ST_Y(point) AS lat,
			       msgtype,
			       arrival,
			       successful,
			       promised,
			       COALESCE(groupid, 0)
			  FROM messages_spatial
			 WHERE arrival BETWEEN ? AND ?
			   AND msgtype IN ('Offer','Wanted')
			   AND ST_Contains(ST_GeomFromText(?, 4326), point)
			 ORDER BY arrival DESC
			 LIMIT ?
		`)
}

// sqlActiveMembersInBox finds the active members inside a box, with the group each is in
// (reachable_groups.go). Args: the box as WKT.
//
// STRAIGHT_JOIN makes MySQL drive from the users_approxlocs SPATIAL index. PostgreSQL plans the join
// order itself, and the && test is answered by the GiST index on position.
func sqlActiveMembersInBox() string {
	return sqlFor(
		`SELECT STRAIGHT_JOIN DISTINCT m.groupid,
		        COALESCE(pl.lat, ual.lat), COALESCE(pl.lng, ual.lng)
		 FROM users_approxlocs ual
		 INNER JOIN memberships m ON m.userid = ual.userid AND m.collection = 'Approved'
		 INNER JOIN users u ON u.id = ual.userid AND u.lastaccess >= NOW() - INTERVAL 90 DAY
		 INNER JOIN `+"`groups`"+` g ON g.id = m.groupid
		     AND g.publish = 1 AND g.listable = 1
		     AND ST_GeometryType(g.polyindex) IN ('POLYGON', 'MULTIPOLYGON')
		 LEFT JOIN locations pl ON pl.id = u.lastlocation
		     AND pl.type = 'Postcode' AND NOT (pl.lat = 0 AND pl.lng = 0)
		 WHERE MBRContains(ST_GeomFromText(?, 3857), ual.position)
		   AND ST_Contains(g.polyindex,
		         ST_SRID(POINT(COALESCE(pl.lng, ual.lng), COALESCE(pl.lat, ual.lat)), 3857))`,
		`SELECT DISTINCT m.groupid,
		        COALESCE(pl.lat, ual.lat), COALESCE(pl.lng, ual.lng)
		 FROM users_approxlocs ual
		 INNER JOIN memberships m ON m.userid = ual.userid AND m.collection = 'Approved'
		 INNER JOIN users u ON u.id = ual.userid AND u.lastaccess >= NOW() - INTERVAL '90 days'
		 INNER JOIN "groups" g ON g.id = m.groupid
		     AND g.publish = 1 AND g.listable = 1
		     AND ST_GeometryType(g.polyindex) IN ('ST_Polygon', 'ST_MultiPolygon')
		 LEFT JOIN locations pl ON pl.id = u.lastlocation
		     AND pl.type = 'Postcode' AND NOT (pl.lat = 0 AND pl.lng = 0)
		 WHERE ual.position && ST_GeomFromText(?, 4326)
		   AND ST_Contains(g.polyindex,
		         ST_SetSRID(ST_MakePoint(COALESCE(pl.lng, ual.lng), COALESCE(pl.lat, ual.lat)), 4326))`)
}

// sqlNearestPostcode finds the postcode nearest a point, with its area name (geocode.go).
// Args: lat, lat, lng, lng, lng, lat.
func sqlNearestPostcode() string {
	return sqlFor(`
		SELECT p.name AS postcode, COALESCE(a.name,'') AS place
		FROM locations p LEFT JOIN locations a ON a.id = p.areaid
		WHERE p.type='Postcode' AND p.lat BETWEEN ?-0.1 AND ?+0.1 AND p.lng BETWEEN ?-0.15 AND ?+0.15
		ORDER BY ST_Distance_Sphere(POINT(p.lng,p.lat), POINT(?,?)) LIMIT 1
	`, `
		SELECT p.name AS postcode, COALESCE(a.name,'') AS place
		FROM locations p LEFT JOIN locations a ON a.id = p.areaid
		WHERE p.type='Postcode' AND p.lat BETWEEN ?::double precision-0.1 AND ?::double precision+0.1
		  AND p.lng BETWEEN ?::double precision-0.15 AND ?::double precision+0.15
		ORDER BY ST_DistanceSphere(ST_MakePoint(p.lng,p.lat), ST_MakePoint(?,?)) LIMIT 1
	`)
}

// sqlOriginGroup finds the first group a post arrived on (reach_union.go). Args: msgid.
func sqlOriginGroup() string {
	return portableSQL("SELECT mg.groupid FROM messages_groups mg WHERE mg.msgid = ? AND mg.deleted = 0 ORDER BY mg.arrival ASC LIMIT 1")
}

// sqlEvalRows reads the stored reach labels for a set of posts (reach_eval.go). placeholders is a
// comma separated list of ?, one per msgid. Args: the live partition fingerprint, then the msgids.
func sqlEvalRows(placeholders string) string {
	return sqlFor(
		"SELECT rr.msgid, COALESCE(IF(rr.reach_labels_next_fp = ?, rr.reach_labels_next, NULL), rr.reach_labels), "+
			"rr.tick, rr.max_drive_min, rr.schedule, rr.rejected_groups, rr.status, rr.origin_union_secs, "+
			"(SELECT mg.groupid FROM messages_groups mg WHERE mg.msgid = rr.msgid AND mg.deleted = 0 ORDER BY mg.arrival ASC LIMIT 1) "+
			"FROM rippling_reach rr WHERE rr.msgid IN ("+
			placeholders+")",
		"SELECT rr.msgid, COALESCE(CASE WHEN rr.reach_labels_next_fp = ? THEN rr.reach_labels_next END, rr.reach_labels), "+
			"rr.tick, "+pgSingleAsDouble("rr.max_drive_min")+", rr.schedule, rr.rejected_groups, rr.status, rr.origin_union_secs, "+
			"(SELECT mg.groupid FROM messages_groups mg WHERE mg.msgid = rr.msgid AND mg.deleted = 0 ORDER BY mg.arrival ASC LIMIT 1) "+
			"FROM rippling_reach rr WHERE rr.msgid IN ("+
			placeholders+")")
}

// sqlGroupAreaWKT reads a group's area as WKT unless it is a point (reach_eval.go). Args: groupid.
func sqlGroupAreaWKT() string {
	return sqlFor("SELECT ST_AsText(polyindex) FROM `groups` WHERE id = ? AND polyindex IS NOT NULL AND ST_GeometryType(polyindex) <> 'POINT'",
		`SELECT ST_AsText(polyindex) FROM "groups" WHERE id = ? AND polyindex IS NOT NULL AND ST_GeometryType(polyindex) <> 'ST_Point'`)
}

// sqlLeafPosts selects the posts whose stored leaves include one region (reach_eval.go). The
// caller appends sqlLeafFPFilter or sqlLeafFPFilter2 and the fingerprints as args. Args: leaf.
const sqlLeafPosts = "SELECT msgid FROM rippling_reach_leaves WHERE leaf = ?"

// sqlLeafFPFilter keeps leaf rows of one build, or of no build (arg: fingerprint).
const sqlLeafFPFilter = " AND (fp IS NULL OR fp = ?)"

// sqlLeafFPFilter2 keeps leaf rows of either of two builds (args: both fingerprints).
const sqlLeafFPFilter2 = " AND (fp IS NULL OR fp IN (?, ?))"

// sqlPartitionFingerprint reads the fingerprint recorded for the stored labels (reach_server.go).
// Args: the config key.
func sqlPartitionFingerprint() string {
	return sqlFor("SELECT `value` FROM config WHERE `key` = ?",
		"SELECT value FROM config WHERE key = ?")
}

// sqlLabelsUniqueKey counts the unique indexes of a table that are exactly (msgid, leaf, fp), in
// that order (reach_labels_apply.go). Args: table name.
func sqlLabelsUniqueKey() string {
	return sqlFor(
		"SELECT COUNT(*) FROM information_schema.statistics s "+
			"WHERE s.table_schema = DATABASE() AND s.table_name = ? AND s.non_unique = 0 "+
			"AND s.index_name <> 'PRIMARY' "+
			"AND (SELECT GROUP_CONCAT(column_name ORDER BY seq_in_index) FROM information_schema.statistics "+
			"     WHERE table_schema = s.table_schema AND table_name = s.table_name AND index_name = s.index_name) "+
			"    = 'msgid,leaf,fp'",
		// A partial index does not make the pair unique across the table, so it does not count.
		"SELECT COUNT(*) FROM pg_index i JOIN pg_class t ON t.oid = i.indrelid "+
			"WHERE t.relnamespace = current_schema()::regnamespace AND t.relname = ? "+
			"AND i.indisunique AND NOT i.indisprimary AND i.indpred IS NULL AND i.indexprs IS NULL "+
			"AND (SELECT string_agg(a.attname::text, ',' ORDER BY k.ord) "+
			"     FROM unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, ord) "+
			"     JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum) "+
			"    = 'msgid,leaf,fp'")
}

// sqlStagedPosts lists the posts already staged for a partition (reach_labels_apply.go). Args:
// the fingerprint.
func sqlStagedPosts() string {
	return portableSQL("SELECT msgid FROM rippling_reach WHERE reach_labels_next_fp = ?")
}

// sqlStagedPostsWithLeaves is sqlStagedPosts for posts that also have a leaf row for the partition
// in leavesTable. Args: the fingerprint twice.
func sqlStagedPostsWithLeaves(leavesTable string) string {
	return portableSQL("SELECT rr.msgid FROM rippling_reach rr WHERE rr.reach_labels_next_fp = ? " +
		"AND EXISTS (SELECT 1 FROM " + leavesTable + " l WHERE l.msgid = rr.msgid AND l.fp = ?)")
}

// sqlStageLabel writes one post's staged label (reach_labels_apply.go). Args: label, fingerprint,
// msgid, fingerprint.
func sqlStageLabel() string {
	return portableSQL("UPDATE rippling_reach SET reach_labels_next = ?, reach_labels_next_fp = ? " +
		"WHERE msgid = ? AND (reach_labels_next_fp IS NULL OR reach_labels_next_fp <> ?)")
}

// sqlPostExists is the check that a post still has a reach row (reach_labels_apply.go). Args: msgid.
func sqlPostExists() string {
	return portableSQL("SELECT 1 FROM rippling_reach WHERE msgid = ?")
}

// sqlInsertLeaves adds leaf rows, ignoring any that are already there (reach_labels_apply.go).
// values is "(?, ?, ?)" repeated and comma separated. Args: msgid, leaf, fingerprint per row.
func sqlInsertLeaves(table, values string) string {
	return sqlFor(
		"INSERT IGNORE INTO "+table+" (msgid, leaf, fp) VALUES "+values,
		"INSERT INTO "+table+" (msgid, leaf, fp) VALUES "+values+" ON CONFLICT DO NOTHING")
}

// sqlCountStaged counts posts staged for a partition (reach_labels_apply.go). Args: fingerprint.
func sqlCountStaged() string {
	return portableSQL("SELECT COUNT(*) FROM rippling_reach WHERE reach_labels_next_fp = ?")
}

// sqlCountLeaves counts a partition's leaf rows and the posts they belong to (reach_labels_apply.go).
// Args: fingerprint.
func sqlCountLeaves(table string) string {
	return portableSQL("SELECT COUNT(*), COUNT(DISTINCT msgid) FROM " + table + " WHERE fp = ?")
}

// sqlLabelExportRows reads the posts that have a drive budget (reach_labels_export.go).
func sqlLabelExportRows() string {
	return sqlFor(
		"SELECT msgid, lat, lng, max_drive_min FROM rippling_reach WHERE max_drive_min > 0 ORDER BY msgid",
		"SELECT msgid, lat, lng, "+pgSingleAsDouble("max_drive_min")+" FROM rippling_reach WHERE max_drive_min > 0 ORDER BY msgid")
}

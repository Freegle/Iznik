package main

import "github.com/freegle/iznik-routing-go/dbengine"

// The three statements, in the form each engine takes. They are written with ? placeholders and
// rebound for PostgreSQL. The MySQL text is what the tool has always sent.
//
// PostgreSQL has no DATE_FORMAT or IFNULL, so times go through to_char and nulls through COALESCE.
// A postcode compares case-insensitively on MySQL; on PostgreSQL the right-hand side is cast to
// citext so the comparison keeps that (REPLACE would otherwise give plain text).

func pick(mysqlSQL, pgSQL string) string {
	if dbengine.Current() == dbengine.Postgres {
		return dbengine.Postgres.Rebind(pgSQL)
	}
	return mysqlSQL
}

// sqlCandidates lists the posts since a cutoff that have at least one reply. Args: cutoff.
func sqlCandidates() string {
	q := `SELECT m.id
	         FROM messages m
	         INNER JOIN messages_spatial ms ON ms.msgid = m.id
	         WHERE m.arrival >= ?
	           AND m.type IN ('Offer', 'Wanted')
	           AND ms.point IS NOT NULL
	           AND EXISTS (
	               SELECT 1 FROM chat_messages cm
	                WHERE cm.refmsgid = m.id
	                  AND cm.type = 'Interested'
	                  AND cm.reviewrejected = 0
	                  AND cm.reviewrequired = 0
	           )`
	return pick(q, q)
}

// sqlPostMetadata reads a batch of posts with their outcome and postcode class. placeholders is a
// comma separated list of ?, one per id. Args: the ids.
func sqlPostMetadata(placeholders string) string {
	return pick(`SELECT m.id,
		              ST_Y(ms.point) AS lat,
		              ST_X(ms.point) AS lng,
		              DATE_FORMAT(m.arrival, '%Y-%m-%d %H:%i:%s') AS arrival,
		              m.type,
		              IFNULL(m.fromuser, 0),
		              mo.outcome,
		              DATE_FORMAT(mo.timestamp, '%Y-%m-%d %H:%i:%s') AS outcome_at,
		              tpc.ru_category
		         FROM messages m
		         INNER JOIN messages_spatial ms ON ms.msgid = m.id
		         LEFT JOIN messages_outcomes mo
		           ON mo.msgid = m.id
		          AND mo.outcome IN ('Taken','Promised','Withdrawn','Repost')
		         LEFT JOIN locations l ON l.id = m.locationid AND l.type = 'Postcode'
		         LEFT JOIN transport_postcode_classification tpc
		           ON tpc.postcode = REPLACE(l.name, ' ', '')
		         WHERE m.id IN (`+placeholders+`)`,
		`SELECT m.id,
		              ST_Y(ms.point) AS lat,
		              ST_X(ms.point) AS lng,
		              to_char(m.arrival, 'YYYY-MM-DD HH24:MI:SS') AS arrival,
		              m.type,
		              COALESCE(m.fromuser, 0),
		              mo.outcome,
		              to_char(mo.timestamp, 'YYYY-MM-DD HH24:MI:SS') AS outcome_at,
		              tpc.ru_category
		         FROM messages m
		         INNER JOIN messages_spatial ms ON ms.msgid = m.id
		         LEFT JOIN messages_outcomes mo
		           ON mo.msgid = m.id
		          AND mo.outcome IN ('Taken','Promised','Withdrawn','Repost')
		         LEFT JOIN locations l ON l.id = m.locationid AND l.type = 'Postcode'
		         LEFT JOIN transport_postcode_classification tpc
		           ON tpc.postcode = REPLACE(l.name, ' ', '')::citext
		         WHERE m.id IN (`+placeholders+`)`)
}

// sqlRepliers reads the repliers to a batch of posts, with their location and the email frequency
// on the post's group. placeholders is a comma separated list of ?, one per id. Args: the ids.
func sqlRepliers(placeholders string) string {
	return pick(`SELECT cm.refmsgid,
		              cm.userid,
		              DATE_FORMAT(cm.date, '%Y-%m-%d %H:%i:%s') AS reply_time,
		              ual.lat,
		              ual.lng,
		              m.emailfrequency
		         FROM chat_messages cm
		         LEFT JOIN users_approxlocs ual ON ual.userid = cm.userid
		         LEFT JOIN messages_groups mg ON mg.msgid = cm.refmsgid
		         LEFT JOIN memberships m
		           ON m.userid = cm.userid AND m.groupid = mg.groupid
		         WHERE cm.refmsgid IN (`+placeholders+`)
		           AND cm.type = 'Interested'
		           AND cm.reviewrejected = 0
		           AND cm.reviewrequired = 0`,
		`SELECT cm.refmsgid,
		              cm.userid,
		              to_char(cm.date, 'YYYY-MM-DD HH24:MI:SS') AS reply_time,
		              ual.lat,
		              ual.lng,
		              m.emailfrequency
		         FROM chat_messages cm
		         LEFT JOIN users_approxlocs ual ON ual.userid = cm.userid
		         LEFT JOIN messages_groups mg ON mg.msgid = cm.refmsgid
		         LEFT JOIN memberships m
		           ON m.userid = cm.userid AND m.groupid = mg.groupid
		         WHERE cm.refmsgid IN (`+placeholders+`)
		           AND cm.type = 'Interested'
		           AND cm.reviewrejected = 0
		           AND cm.reviewrequired = 0`)
}

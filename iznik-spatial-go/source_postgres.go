package main

import (
	"strconv"
	"strings"
)

// postgresDialect is PostgreSQL 18 with PostGIS, on the schema tools/pg-schema generates.
type postgresDialect struct{}

func (postgresDialect) engine() string { return enginePostgres }

// rebind numbers the ? placeholders $1, $2 and so on. A ? inside a single-quoted literal is
// left alone.
func (postgresDialect) rebind(query string) string {
	if !strings.Contains(query, "?") {
		return query
	}
	var b strings.Builder
	n := 0
	inLiteral := false
	for i := 0; i < len(query); i++ {
		c := query[i]
		switch {
		case c == '\'':
			inLiteral = !inLiteral
			b.WriteByte(c)
		case c == '?' && !inLiteral:
			n++
			b.WriteByte('$')
			b.WriteString(strconv.Itoa(n))
		default:
			b.WriteByte(c)
		}
	}
	return b.String()
}

func (postgresDialect) quote(name string) string { return `"` + name + `"` }

// ST_AsBinary returns plain OGC WKB. The column is SRID 4326 (relabelled, not reprojected), and
// the bytes carry no SRID, so the loaders read the same degrees they read from MySQL.
func (postgresDialect) asWKB(geomExpr string) string { return "ST_AsBinary(" + geomExpr + ")" }

func (postgresDialect) strpos(needle, haystack string) string {
	return "strpos(" + haystack + ", " + needle + ")"
}

// ST_X of an empty point is NULL, which the loader cannot scan into a float. MySQL has no empty
// geometries to meet here.
func (postgresDialect) notEmptyGuard(geomExpr string) string {
	return "\n\t\t  AND NOT ST_IsEmpty(" + geomExpr + ")"
}

func (postgresDialect) currentSchema() string { return "current_schema()" }

// to_char gives the same fixed form as MySQL's CAST of a DATETIME, whole seconds, whatever the
// column's precision. The connection's time zone is UTC (openSource sets it).
func (postgresDialect) maxSeenat() string {
	return `SELECT to_char(MAX(seenat), 'YYYY-MM-DD HH24:MI:SS') FROM jobs`
}

// jsonpath takes the same path strings as MySQL. #>> '{}' turns the matched JSON string into
// plain text, and a missing path gives NULL, as JSON_UNQUOTE(JSON_EXTRACT()) does.
func (postgresDialect) jsonText(col, placeholder string) string {
	return "jsonb_path_query_first(" + col + ", " + placeholder + "::text::jsonpath) #>> '{}'"
}

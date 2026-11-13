package database

import (
	"strings"
	"testing"
)

type fakeCatalog struct {
	cols map[string][]string
	uniq map[string][]string
}

func (f fakeCatalog) uniques(table string) [][]string {
	if u := f.uniq[table]; u != nil {
		return [][]string{u}
	}
	return nil
}

func (f fakeCatalog) columns(table string) []string { return f.cols[table] }
func (f fakeCatalog) unique_(table string) []string { return f.uniq[table] }

func (f fakeCatalog) unique(table string, cols []string) []string {
	u := f.unique_(table)
	if u == nil {
		return nil
	}
	have := map[string]bool{}
	for _, c := range cols {
		have[c] = true
	}
	for _, c := range u {
		if !have[c] {
			return nil
		}
	}
	return u
}

var testCatalog = fakeCatalog{
	cols: map[string][]string{
		"messages_by":    {"id", "msgid", "userid", "count"},
		"users_emails":   {"id", "userid", "email"},
		"items":          {"id", "name"},
		"rippling_reach": {"id", "max_polygon_cells"},
	},
	uniq: map[string][]string{
		"messages_by":  {"msgid", "userid"},
		"users_emails": {"email"},
		"items":        {"name"},
	},
}

func norm(s string) string { return strings.Join(strings.Fields(s), " ") }

func TestTranslatePostgres(t *testing.T) {
	cases := []struct{ name, in, want string }{
		{"backticks", "SELECT `groups`.id FROM `groups` WHERE `type` = 'a`b'", `SELECT "groups".id FROM "groups" WHERE "type" = 'a` + "`" + `b'`},
		{"ifnull", "SELECT IFNULL(a, 0) FROM t", "SELECT COALESCE(a, 0) FROM t"},
		{"date_sub literal", "WHERE x > DATE_SUB(NOW(), INTERVAL 10 MINUTE)", "WHERE x > (NOW() - INTERVAL '10 minute')"},
		{"date_add param", "WHERE x < DATE_ADD(NOW(), INTERVAL $1 DAY)", "WHERE x < (NOW() + (($1) * INTERVAL '1 day'))"},
		{"interval in sum", "SELECT NOW() - INTERVAL 31 DAY", "SELECT NOW() - INTERVAL '31 day'"},
		{"cast unsigned", "SELECT CAST(x AS UNSIGNED) FROM t", "SELECT CAST(x AS bigint) FROM t"},
		{"column unsigned", "ALTER TABLE rippling_reach ADD COLUMN z INT UNSIGNED NULL", "ALTER TABLE rippling_reach ADD COLUMN z INT NULL"},
		{"column exists", "ALTER TABLE rippling_reach ADD COLUMN max_polygon_cells MEDIUMBLOB NULL", "SELECT 1"},
		{"if", "SELECT IF(a > 1, 'x', 'y') FROM t", "SELECT (CASE WHEN a > 1 THEN 'x' ELSE 'y' END) FROM t"},
		{"nested if", "SELECT IF(a, IF(b, 1, 2), 3)", "SELECT (CASE WHEN a THEN (CASE WHEN b THEN 1 ELSE 2 END) ELSE 3 END)"},
		{"insert ignore", "INSERT IGNORE INTO items (name) VALUES ($1)", "INSERT INTO items (name) VALUES ($1) ON CONFLICT DO NOTHING"},
		{"upsert", "INSERT INTO messages_by (msgid, userid, count) VALUES ($1, $2, 1) ON DUPLICATE KEY UPDATE count = count + VALUES(count)",
			`INSERT INTO messages_by (msgid, userid, count) VALUES ($1, $2, 1) ON CONFLICT ("msgid","userid") DO UPDATE SET count = "messages_by"."count" + EXCLUDED.count`},
		{"upsert last insert id", "INSERT INTO items (name) VALUES ($1) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)",
			`INSERT INTO items (name) VALUES ($1) ON CONFLICT ("name") DO UPDATE SET id = "items".id`},
		{"gorm upsert without target", `INSERT INTO "messages_by" ("count","msgid","userid") VALUES (2,65,278) ON CONFLICT DO UPDATE SET "count"=count + VALUES(count)`,
			`INSERT INTO "messages_by" ("count","msgid","userid") VALUES (2,65,278) ON CONFLICT ("msgid","userid") DO UPDATE SET "count"="messages_by"."count" + EXCLUDED.count`},
		{"replace", "REPLACE INTO users_emails (userid, email) VALUES ($1, $2)",
			`INSERT INTO users_emails (userid, email) VALUES ($1, $2) ON CONFLICT ("email") DO UPDATE SET "userid" = EXCLUDED."userid"`},
		{"group_concat", "SELECT GROUP_CONCAT(DISTINCT a ORDER BY a SEPARATOR '; ') FROM t", "SELECT string_agg(DISTINCT (a)::text, '; ' ORDER BY a) FROM t"},
		{"limit offset", "SELECT 1 FROM t LIMIT 5, 10", "SELECT 1 FROM t LIMIT 10 OFFSET 5"},
		{"reserved", "INSERT INTO logs (type, user, byuser) VALUES (1,2,3)", `INSERT INTO logs (type, "user", byuser) VALUES (1,2,3)`},
		{"case end kept", "SELECT CASE WHEN a THEN 1 END FROM t", "SELECT CASE WHEN a THEN 1 END FROM t"},
		{"force index", "SELECT * FROM logs FORCE INDEX (timestamp_2) WHERE a = 1", "SELECT * FROM logs  WHERE a = 1"},
		{"spatial", "ST_Contains(g, ST_SRID(POINT($1, $2), $3))", "ST_Contains(g, ST_SetSRID(ST_MakePoint($1, $2), $3))"},
		{"mbr", "MBRIntersects(polyindex, ST_SRID(POLYGON(LINESTRING(POINT(1, 2), POINT(3, 4))), 4326))",
			"(polyindex && ST_SetSRID(ST_MakePolygon(ST_MakeLine(ARRAY[ST_MakePoint(1, 2), ST_MakePoint(3, 4)])), 4326))"},
		{"update join", "UPDATE memberships m2 JOIN memberships m1 ON m1.userid = 1 AND m1.groupid = m2.groupid SET `m2`.`added` = LEAST(m2.added, m1.added) WHERE m2.userid = 2",
			`UPDATE memberships m2 SET "added" = LEAST(m2.added, m1.added) FROM memberships m1 WHERE (m1.userid = 1 AND m1.groupid = m2.groupid) AND (m2.userid = 2)`},
		{"delete join", "DELETE r FROM ux_prompt_responses r INNER JOIN ux_prompts p ON p.id = r.prompt_id WHERE p.question LIKE 'x%'",
			`DELETE FROM ux_prompt_responses r USING ux_prompts p WHERE (p.id = r.prompt_id) AND (p.question LIKE 'x%')`},
		{"json object keys", "SELECT JSON_OBJECT($1, 2, 'k', $3)", "SELECT jsonb_build_object($1::text, 2, 'k', $3)"},
		{"json case", "CASE WHEN JSON_EXTRACT(s, '$.a') IS NULL THEN 1 ELSE JSON_EXTRACT(s, '$.a') END", "CASE WHEN JSON_EXTRACT(s, '$.a') IS NULL THEN to_jsonb(1) ELSE JSON_EXTRACT(s, '$.a') END"},
		{"mixed case column", "SELECT Payer, ud.grossamount FROM users_donations ud", `SELECT "Payer", ud."GrossAmount" FROM users_donations ud`},
		{"alias not touched", "SELECT x AS payer FROM t", "SELECT x AS payer FROM t"},
		{"already postgres", `SELECT "a" FROM "t" WHERE x = $1 ON CONFLICT ("a") DO NOTHING`, `SELECT "a" FROM "t" WHERE x = $1 ON CONFLICT ("a") DO NOTHING`},
		{"string with keywords untouched", "SELECT 'IFNULL(a) `x` INTERVAL 1 DAY'", "SELECT 'IFNULL(a) `x` INTERVAL 1 DAY'"},
		{"group by bare column", "SELECT MAX(t.a) AS a, t.b, t.c FROM t GROUP BY t.c ORDER BY t.b",
			`SELECT MAX(t.a) AS a, ANY_VALUE(t.b) AS "b", t.c FROM t GROUP BY t.c ORDER BY ANY_VALUE(t.b)`},
		{"group by already valid", "SELECT c, COUNT(*) AS n FROM t GROUP BY c ORDER BY n DESC", "SELECT c, COUNT(*) AS n FROM t GROUP BY c ORDER BY n DESC"},
		{"group by subquery", "SELECT x.id FROM (SELECT id, name FROM t GROUP BY name) x",
			`SELECT x.id FROM (SELECT ANY_VALUE(id) AS "id", name FROM t GROUP BY name) x`},
		{"distinct order by hidden", "SELECT DISTINCT a.id FROM a ORDER BY a.date DESC", "SELECT a.id FROM a GROUP BY a.id ORDER BY ANY_VALUE(a.date) DESC"},
		{"having alias without group", "SELECT id, f(x) AS hav FROM t WHERE y = 1 HAVING hav < 5 ORDER BY hav", "SELECT id, f(x) AS hav FROM t WHERE (y = 1) AND ((f(x)) < 5) ORDER BY hav"},
		{"json set pairs", "UPDATE u SET s = JSON_SET(s, '$.a', 'x', '$.b', 2)", "UPDATE u SET s = json_set(json_set(s, '$.a', 'x'::text), '$.b', 2)"},
		{"srid literal", "ST_GeomFromText('POINT(1 2)', 3857)", "ST_GeomFromText('POINT(1 2)', 4326)"},
		{"coalesce bool", "COALESCE(JSON_EXTRACT(s, '$.a') = 1, 0)", "COALESCE(JSON_EXTRACT(s, '$.a') = 1, false)"},
		{"update ignore", `UPDATE IGNORE "users_emails" SET "email"=$1 WHERE userid = $2`,
			`UPDATE "users_emails" SET "email"=$1 WHERE (userid = $2) AND NOT EXISTS (SELECT 1 FROM "users_emails" ig_x WHERE ig_x."email" = ($1))`},
		{"cast json", "SELECT CAST(5 AS JSON), CAST('{\"a\":1}' AS JSON)", "SELECT to_jsonb(5), ('{\"a\":1}')::jsonb"},
		{"regexp", "WHERE a REGEXP 'x' AND b NOT REGEXP 'y'", "WHERE a ~* 'x' AND b !~* 'y'"},
		{"exists item", "SELECT EXISTS(SELECT 1 FROM t) AS e, id FROM u", "SELECT (CASE WHEN EXISTS(SELECT 1 FROM t) THEN 1 ELSE 0 END) AS e, id FROM u"},
		{"json compare literal", "WHERE JSON_EXTRACT(d, '$.a') = 'start'", "WHERE JSON_EXTRACT(d, '$.a') = 'start'::text"},
		{"coalesce json negative", "COALESCE(JSON_EXTRACT(d, '$.a'), -1)", "COALESCE(JSON_EXTRACT(d, '$.a'), to_jsonb(-1))"},
		{"round", "SELECT ROUND(ST_Y(p), 2) FROM t", "SELECT ROUND((ST_Y(p))::numeric, 2) FROM t"},
		{"window kept", "SELECT a, row_number() OVER (ORDER BY b) AS rn FROM t GROUP BY a", "SELECT a, row_number() OVER (ORDER BY b) AS rn FROM t GROUP BY a"},
		{"do block untouched", "DO $$ BEGIN IF user THEN END IF; END $$", "DO $$ BEGIN IF user THEN END IF; END $$"},
		{"question marks", "SELECT 1 FROM t WHERE a = ? AND b = '?' AND c = ?", "SELECT 1 FROM t WHERE a = $1 AND b = '?' AND c = $2"},
		{"update limit", "UPDATE t SET a = 1 WHERE b = 2 ORDER BY id DESC LIMIT 1", "UPDATE t SET a = 1 WHERE ctid IN (SELECT ctid FROM t WHERE b = 2 ORDER BY id DESC LIMIT 1)"},
		{"delete limit", "DELETE FROM t WHERE b = 2 LIMIT 5", "DELETE FROM t WHERE ctid IN (SELECT ctid FROM t WHERE b = 2 LIMIT 5)"},
		{"upsert no index named", "INSERT INTO items (id2) VALUES ($1) ON DUPLICATE KEY UPDATE id2 = 1", "INSERT INTO items (id2) VALUES ($1)"},
		{"union comparison", "SELECT a > 1 AS x, b FROM t UNION SELECT c, d FROM u", "SELECT ((a > 1))::int AS x, b FROM t UNION SELECT c, d FROM u"},
		{"param is null", "SET h = CASE WHEN $3 IS NOT NULL THEN 1 ELSE 0 END", "SET h = CASE WHEN $3::text IS NOT NULL THEN 1 ELSE 0 END"},
		{"union scalar subquery", "SELECT (SELECT d FROM u WHERE u.id = t.id) AS x FROM t UNION SELECT 1 FROM v", "SELECT (SELECT d FROM u WHERE u.id = t.id) AS x FROM t UNION SELECT 1 FROM v"},
		{"geometry type", "WHERE ST_GeometryType(g) IN ('POLYGON')", "WHERE iznik_geometrytype(g) IN ('POLYGON')"},
		{"drop temporary", "DROP TEMPORARY TABLE IF EXISTS pc", "DROP  TABLE IF EXISTS pc"},
		{"rename table", "RENAME TABLE a TO b", "ALTER TABLE a RENAME TO b"},
		{"coalesce max json", "COALESCE(MAX(JSON_EXTRACT(d, '$.u')), -1)", "COALESCE(MAX(JSON_EXTRACT(d, '$.u')), to_jsonb(-1))"},
		{"database", "WHERE table_schema = DATABASE()", "WHERE table_schema = current_schema()"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			got := translatePostgres(c.in, testCatalog)
			if norm(got) != norm(c.want) {
				t.Errorf("\n in: %s\ngot: %s\nwant: %s", c.in, got, c.want)
			}
			// Idempotent: translating the result again changes nothing.
			if again := translatePostgres(got, testCatalog); norm(again) != norm(got) {
				t.Errorf("not idempotent:\n  1: %s\n  2: %s", got, again)
			}
		})
	}
}

func TestLexRoundTrips(t *testing.T) {
	for _, s := range []string{
		"SELECT 'it''s', \"q\", `b`, a -- c\n, /* x */ b FROM t WHERE a = ? AND b = $1",
		"",
		"x",
	} {
		if got := joinToks(lexSQL(s)); got != s {
			t.Errorf("lex round trip %q -> %q", s, got)
		}
	}
}

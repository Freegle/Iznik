package main

import (
	"crypto/sha256"
	"fmt"
	"regexp"
	"strings"
	"testing"
)

// mysqlStatements are the statements as they are sent on MySQL, called with fixed arguments for the
// ones that take a table or a placeholder list.
func mysqlStatements() map[string]string {
	return map[string]string{
		"SessionExists":         sqlSessionExists(),
		"GroupActiveMembers":    sqlGroupActiveMembers(),
		"NearbyGroups":          sqlNearbyGroups(),
		"GroupBoundary":         sqlGroupBoundary(),
		"GroupsList":            sqlGroupsList(),
		"DigestHomeGroups":      sqlDigestHomeGroups(),
		"DigestPool":            sqlDigestPool(),
		"PostsInIsochrone":      sqlPostsInIsochrone(),
		"ActiveMembersInBox":    sqlActiveMembersInBox(),
		"NearestPostcode":       sqlNearestPostcode(),
		"OriginGroup":           sqlOriginGroup(),
		"EvalRows":              sqlEvalRows("?,?,?"),
		"GroupAreaWKT":          sqlGroupAreaWKT(),
		"PartitionFingerprint":  sqlPartitionFingerprint(),
		"LabelsUniqueKey":       sqlLabelsUniqueKey(),
		"StagedPosts":           sqlStagedPosts(),
		"StagedPostsWithLeaves": sqlStagedPostsWithLeaves("tbl"),
		"StageLabel":            sqlStageLabel(),
		"PostExists":            sqlPostExists(),
		"InsertLeaves":          sqlInsertLeaves("tbl", "(?, ?, ?),(?, ?, ?)"),
		"CountStaged":           sqlCountStaged(),
		"CountLeaves":           sqlCountLeaves("tbl"),
		"LabelExportRows":       sqlLabelExportRows(),
	}
}

// mysqlGolden holds the SHA-256 of each statement as the service sent it before the engine became
// configurable, taken from the source at that commit. A change to a MySQL statement fails here, so
// production behaviour cannot move without someone saying so.
var mysqlGolden = map[string]string{
	"SessionExists":         "a5d355f9984f240331ccb0d9b2c0e708031e650ff85262a49e89501e4e019adc",
	"GroupActiveMembers":    "9fc45f6195ff1480f9221227870621da81567d3548e5e35246a97629740206cd",
	"NearbyGroups":          "a9e4320c705ba310f0f785fedcfd7f8255f8c8b433141b816d2567c3d369e489",
	"GroupBoundary":         "3589ade5781bc71d98decbb2fa510afc9185e376d1a21bc882f250b9930b8d0d",
	"GroupsList":            "9c122a3f554ad57f26d10f2d09c6adb18f6507780f3d64d894f516404821811c",
	"DigestHomeGroups":      "a188df57b8a15328baf116e7ef25ca9b4a40b76d9ef592fda94fdf9bf19aba47",
	"DigestPool":            "3562c1fad2adc47a04df842d4f637d18582d122813af85b486e7b7450284a50b",
	"PostsInIsochrone":      "23d9ec82b61f5cc6fcc4d866288c4fd11e6e656d43c2fafbd190248e997861d8",
	"ActiveMembersInBox":    "a7503dbb6257a18d166cbf155cc674a4a7ca4e0db20b181f8b0ddc9a7a03568b",
	"NearestPostcode":       "c41f7aec07437fdb040f6a19882eeba1b95fe9c899b96a4d302ae2262f4c131a",
	"OriginGroup":           "0fd3e04ad90129c33515abfb92bc56121e23fa93d72ad30330d796ede8bfb284",
	"EvalRows":              "b00004050a27f9a23878b476241324887c750b444be1404d102313bef7386ad1",
	"GroupAreaWKT":          "07afc9f1929bc24946501c6de083330d8882f6a1ee1916380ee96e41e16801c1",
	"PartitionFingerprint":  "d91b606f5cf734e4cc3731f7f650384ea1caab918f9f539728db935b6f839be3",
	"LabelsUniqueKey":       "ae8b7ad51efcf0a08a0021cee4fd536ebcd53767dc24a5d207d456546c202aac",
	"StagedPosts":           "d9e0cd406823168e4394b56a3101f8f2341be72ac0fc6e915018f9b2416803a6",
	"StagedPostsWithLeaves": "7948f263670f68c01c1e33082fea7e22ff1129a38b16df48b2925b8ab7af5237",
	"StageLabel":            "b3d337690fb37ef75e4edd935c9a0af4f519da08e4d76399c99bae91e0180e25",
	"PostExists":            "79bec2ee9a9de8b7fa7d0427d48e7de18185de6259b3d5e694d9b0188a5fdea4",
	"InsertLeaves":          "b2ae71294efb809a7f036b3ec9e47013823bd30aa6e2b878f21e854ce78a9585",
	"CountStaged":           "5cae189686f19a50a7187e80860eda8f77610261321e7bd0938d8d1e67ebbc6c",
	"CountLeaves":           "2221b25381b989bc3b6960555c07599ae99c071737cc83cd7dfef5d1f70d909d",
	"LabelExportRows":       "34651d11adf3a9e317d7a4cf0dcf64307eb855172b95628bfdb6b73fd4caa8c6",
}

func TestMySQLStatementsUnchanged(t *testing.T) {
	t.Setenv("DB_ENGINE", "")
	got := mysqlStatements()
	if len(got) != len(mysqlGolden) {
		t.Fatalf("%d statements, %d golden hashes", len(got), len(mysqlGolden))
	}
	for name, q := range got {
		want, ok := mysqlGolden[name]
		if !ok {
			t.Errorf("%s has no golden hash", name)
			continue
		}
		if h := fmt.Sprintf("%x", sha256.Sum256([]byte(q))); h != want {
			t.Errorf("MySQL statement %s changed (hash %s, want %s):\n%s", name, h, want, q)
		}
	}
	// The statements built from a fragment on the caller's side.
	if sqlLeafPosts+sqlLeafFPFilter+sqlLeafFPFilter2 !=
		"SELECT msgid FROM rippling_reach_leaves WHERE leaf = ?"+" AND (fp IS NULL OR fp = ?)"+" AND (fp IS NULL OR fp IN (?, ?))" {
		t.Error("leaf statement fragments changed")
	}
	// MySQL sends ? placeholders untouched.
	for name, q := range got {
		if strings.Contains(q, "$1") {
			t.Errorf("%s was rebound on MySQL", name)
		}
	}
}

var placeholderRe = regexp.MustCompile(`\$(\d+)`)

// maxPlaceholder is the highest $n in q, which is how many arguments PostgreSQL expects.
func maxPlaceholder(q string) int {
	max := 0
	for _, m := range placeholderRe.FindAllStringSubmatch(q, -1) {
		var n int
		fmt.Sscanf(m[1], "%d", &n)
		if n > max {
			max = n
		}
	}
	return max
}

// A call site passes the same arguments whichever engine is configured, so each PostgreSQL
// statement must take as many arguments as its MySQL twin, and use every one.
func TestPostgresStatementsTakeTheSameArguments(t *testing.T) {
	t.Setenv("DB_ENGINE", "")
	my := mysqlStatements()
	t.Setenv("DB_ENGINE", "postgres")
	pg := mysqlStatements() // the same constructors, now answering for PostgreSQL
	for name, mq := range my {
		pq := pg[name]
		if strings.Contains(pq, "?") {
			t.Errorf("%s: a ? is left in the PostgreSQL statement:\n%s", name, pq)
		}
		if want, got := strings.Count(mq, "?"), maxPlaceholder(pq); want != got {
			t.Errorf("%s: MySQL takes %d arguments, PostgreSQL %d", name, want, got)
		}
		// $1..$n each present once or more, none skipped.
		seen := map[int]bool{}
		for _, m := range placeholderRe.FindAllStringSubmatch(pq, -1) {
			var n int
			fmt.Sscanf(m[1], "%d", &n)
			seen[n] = true
		}
		for i := 1; i <= maxPlaceholder(pq); i++ {
			if !seen[i] {
				t.Errorf("%s: $%d is missing", name, i)
			}
		}
	}
}

// PostgreSQL must not be sent anything that only MySQL understands.
func TestPostgresStatementsHaveNoMySQLSyntax(t *testing.T) {
	t.Setenv("DB_ENGINE", "postgres")
	banned := []string{
		"`", "3857", "STRAIGHT_JOIN", "MBRContains", "IFNULL(", "DATE_FORMAT", "INTERVAL 90 DAY",
		"INSERT IGNORE", "information_schema", "ST_Distance_Sphere", "GROUP_CONCAT", "DATABASE()",
		"CONCAT('POINT(", "ST_SRID(", "'POINT'", "'POLYGON'", " IF(",
	}
	for name, q := range mysqlStatements() {
		for _, b := range banned {
			if strings.Contains(q, b) {
				t.Errorf("%s: PostgreSQL statement contains %q:\n%s", name, b, q)
			}
		}
	}
}

func TestSQLForUsesTheConfiguredEngine(t *testing.T) {
	t.Setenv("DB_ENGINE", "")
	if got := sqlFor("a ?", "b ?"); got != "a ?" {
		t.Errorf("MySQL: %q", got)
	}
	t.Setenv("DB_ENGINE", "postgres")
	if got := sqlFor("a ?", "b ?"); got != "b $1" {
		t.Errorf("PostgreSQL: %q", got)
	}
	// An engine name that does not parse leaves the default in place.
	t.Setenv("DB_ENGINE", "oracle")
	if got := sqlFor("a ?", "b ?"); got != "a ?" {
		t.Errorf("unknown engine: %q", got)
	}
}

func TestFingerprintAndTimeArguments(t *testing.T) {
	const big = uint64(0xcbf29ce484222325)
	t.Setenv("DB_ENGINE", "")
	if v, ok := fpArg(big).(uint64); !ok || v != big {
		t.Errorf("MySQL fingerprint %v", fpArg(big))
	}
	t.Setenv("DB_ENGINE", "postgres")
	if v, ok := fpArg(big).(int64); !ok || uint64(v) != big {
		t.Errorf("PostgreSQL fingerprint %v", fpArg(big))
	}
}

func TestGroupsDSNFollowsTheEngine(t *testing.T) {
	for _, k := range []string{"MYSQL_HOST", "MYSQL_PORT", "MYSQL_USER", "MYSQL_PASSWORD", "MYSQL_DBNAME",
		"PGSQL_HOST", "PGSQL_PORT", "PGSQL_USERNAME", "PGSQL_PASSWORD", "PGSQL_DATABASE", "PGSQL_SSLMODE"} {
		t.Setenv(k, "")
	}
	t.Setenv("DB_ENGINE", "")
	if groupsDSN() != "" {
		t.Error("no MYSQL_HOST must mean no database, as before")
	}
	t.Setenv("MYSQL_HOST", "percona")
	t.Setenv("MYSQL_PASSWORD", "pw")
	if got := groupsDSN(); got != "iznik:pw@tcp(percona:3306)/iznik?parseTime=true" {
		t.Errorf("MySQL DSN %q", got)
	}
	// PGSQL_HOST alone does not configure MySQL, and MYSQL_HOST alone does not configure PostgreSQL.
	t.Setenv("DB_ENGINE", "postgres")
	if groupsDSN() != "" {
		t.Error("MYSQL_HOST must not configure PostgreSQL")
	}
	t.Setenv("PGSQL_HOST", "postgres")
	t.Setenv("PGSQL_SSLMODE", "disable")
	if got := groupsDSN(); got != "postgres://root@postgres:5432/iznik?sslmode=disable&timezone=UTC" {
		t.Errorf("PostgreSQL DSN %q", got)
	}
	// A misspelt engine means no database, not the other engine.
	t.Setenv("DB_ENGINE", "postgress")
	if groupsDSN() != "" {
		t.Error("an unknown DB_ENGINE must not open any database")
	}
}

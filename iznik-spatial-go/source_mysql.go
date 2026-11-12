package main

// mysqlDialect is the production source. Its spellings are the ones the loaders have always
// sent; source_transcript_test.go fails if any statement changes.
type mysqlDialect struct{}

func (mysqlDialect) engine() string { return engineMySQL }

func (mysqlDialect) rebind(query string) string { return query }

func (mysqlDialect) quote(name string) string { return "`" + name + "`" }

func (mysqlDialect) asWKB(geomExpr string) string { return "ST_AsWKB(" + geomExpr + ")" }

func (mysqlDialect) strpos(needle, haystack string) string {
	return "LOCATE(" + needle + ", " + haystack + ")"
}

func (mysqlDialect) notEmptyGuard(string) string { return "" }

func (mysqlDialect) currentSchema() string { return "DATABASE()" }

func (mysqlDialect) maxSeenat() string { return `SELECT CAST(MAX(seenat) AS CHAR) FROM jobs` }

func (mysqlDialect) jsonText(col, placeholder string) string {
	return "JSON_UNQUOTE(JSON_EXTRACT(" + col + ", " + placeholder + "))"
}

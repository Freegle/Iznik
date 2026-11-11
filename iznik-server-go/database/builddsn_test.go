package database

import "testing"

func TestBuildDSN(t *testing.T) {
	t.Setenv("MYSQL_USER", "u")
	t.Setenv("MYSQL_PASSWORD", "p")
	t.Setenv("MYSQL_PROTOCOL", "tcp")
	t.Setenv("MYSQL_PORT", "3306")
	t.Setenv("MYSQL_DBNAME", "iznik")

	want := "u:p@tcp(dbhost:3306)/iznik?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true"
	if got := buildDSN("dbhost"); got != want {
		t.Errorf("buildDSN = %q, want %q", got, want)
	}

	// The write host and a read replica share everything but the host.
	if buildDSN("a") == buildDSN("b") {
		t.Error("different hosts must give different DSNs")
	}

	t.Setenv("MYSQL_USER", "")
	t.Setenv("MYSQL_PASSWORD", "")
	if got := buildDSN("h"); got != ":@tcp(h:3306)/iznik?charset=utf8mb4&parseTime=True&loc=Local&interpolateParams=true" {
		t.Errorf("empty credentials: %q", got)
	}
}

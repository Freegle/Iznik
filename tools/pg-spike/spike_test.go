package spike

import (
	"context"
	"fmt"
	"net/url"
	"os"
	"testing"
	"time"

	"gorm.io/driver/postgres"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"gorm.io/plugin/dbresolver"
)

// The spike needs a PostgreSQL server. It creates two databases on it, one standing for the
// source and one for the replica, with a different marker row in each, so a query's answer says
// which one dbresolver sent it to. Connection settings use the same variables as the compose
// service. The tests skip when the server cannot be reached.

func env(k, d string) string {
	if v := os.Getenv(k); v != "" {
		return v
	}
	return d
}

func baseURL(db string) string {
	u := url.URL{
		Scheme: "postgres",
		User:   url.UserPassword(env("PGSQL_USERNAME", "root"), env("PGSQL_PASSWORD", "iznik")),
		Host:   env("PGSQL_HOST", "postgres") + ":" + env("PGSQL_PORT", "5432"),
		Path:   "/" + db,
	}
	return u.String() + "?sslmode=disable"
}

type Item struct {
	ID   uint64 `gorm:"primaryKey"`
	Name string
}

func (Item) TableName() string { return "items" }

type rig struct {
	src, rep *gorm.DB // direct handles on the two databases
	db       *gorm.DB // the resolver handle under test
	srcName  string
	repName  string
}

func setup(t *testing.T) *rig {
	t.Helper()
	prefix := env("PGSPIKE_PREFIX", "pgspike")
	admin, err := gorm.Open(postgres.Open(baseURL("postgres")), &gorm.Config{})
	if err != nil {
		t.Skipf("no PostgreSQL reachable: %v", err)
	}
	r := &rig{srcName: prefix + "_primary", repName: prefix + "_replica"}
	for _, n := range []string{r.srcName, r.repName} {
		admin.Exec(fmt.Sprintf(`DROP DATABASE IF EXISTS %q WITH (FORCE)`, n))
		if err := admin.Exec(fmt.Sprintf(`CREATE DATABASE %q`, n)).Error; err != nil {
			t.Fatal(err)
		}
	}
	t.Cleanup(func() {
		sqlDB, _ := admin.DB()
		sqlDB.Close()
	})
	open := func(name, marker string) *gorm.DB {
		d, err := gorm.Open(postgres.Open(baseURL(name)), &gorm.Config{})
		if err != nil {
			t.Fatal(err)
		}
		for _, q := range []string{
			`CREATE TABLE whoami (role text)`,
			fmt.Sprintf(`INSERT INTO whoami VALUES ('%s')`, marker),
			`CREATE TABLE items (id bigserial PRIMARY KEY, name text)`,
		} {
			if err := d.Exec(q).Error; err != nil {
				t.Fatal(err)
			}
		}
		return d
	}
	r.src = open(r.srcName, "source")
	r.rep = open(r.repName, "replica")

	db, err := gorm.Open(postgres.Open(baseURL(r.srcName)), &gorm.Config{})
	if err != nil {
		t.Fatal(err)
	}
	if err := db.Use(dbresolver.Register(dbresolver.Config{
		Replicas: []gorm.Dialector{postgres.Open(baseURL(r.repName))},
		Policy:   dbresolver.RandomPolicy{},
	})); err != nil {
		t.Fatal(err)
	}
	r.db = db
	return r
}

func who(t *testing.T, tx *gorm.DB, q string) string {
	t.Helper()
	var role string
	if err := tx.Raw(q).Scan(&role).Error; err != nil {
		t.Fatalf("%s: %v", q, err)
	}
	return role
}

func count(t *testing.T, d *gorm.DB) int64 {
	t.Helper()
	var n int64
	if err := d.Model(&Item{}).Count(&n).Error; err != nil {
		t.Fatal(err)
	}
	return n
}

func TestRoutingOfReads(t *testing.T) {
	r := setup(t)
	cases := []struct {
		name, sql, want string
	}{
		{"plain select", `SELECT role FROM whoami`, "replica"},
		{"select with leading space and newline", "  \n SELECT role FROM whoami", "replica"},
		// The guess only looks at the first word, so a read-only CTE is sent to the source: safe,
		// but not offloaded.
		{"WITH ... SELECT", `WITH r AS (SELECT role FROM whoami) SELECT role FROM r`, "source"},
		{"leading comment", `/* x */ SELECT role FROM whoami`, "source"},
		{"parenthesised select", `(SELECT role FROM whoami)`, "source"},
		{"FOR UPDATE at the end", `SELECT role FROM whoami FOR UPDATE`, "source"},
		// Only the exact suffix "for update" is recognised. Other locking clauses on a raw SELECT
		// reach the replica. The same is true on MySQL.
		{"FOR UPDATE SKIP LOCKED", `SELECT role FROM whoami FOR UPDATE SKIP LOCKED`, "replica"},
		{"FOR SHARE", `SELECT role FROM whoami FOR SHARE`, "replica"},
		// A SELECT that has a side effect goes to the replica too.
		{"SELECT with a side effect", `SELECT CASE WHEN nextval('items_id_seq') > 0 THEN role END FROM whoami`, "replica"},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			if got := who(t, r.db, c.sql); got != c.want {
				t.Errorf("routed to %s, want %s", got, c.want)
			}
		})
	}

	t.Run("hints pin a statement", func(t *testing.T) {
		if got := who(t, r.db.Clauses(dbresolver.Write), `SELECT role FROM whoami`); got != "source" {
			t.Errorf("Write hint went to %s", got)
		}
		if got := who(t, r.db.Clauses(dbresolver.Read), `WITH r AS (SELECT role FROM whoami) SELECT role FROM r`); got != "replica" {
			t.Errorf("Read hint went to %s", got)
		}
	})

	t.Run("builder chain read goes to the replica", func(t *testing.T) {
		var w struct{ Role string }
		if err := r.db.Table("whoami").Take(&w).Error; err != nil {
			t.Fatal(err)
		}
		if w.Role != "replica" {
			t.Errorf("got %s", w.Role)
		}
	})

	t.Run("builder chain with a locking clause goes to the source", func(t *testing.T) {
		for _, c := range []clause.Locking{
			{Strength: "UPDATE"},
			{Strength: "UPDATE", Options: "SKIP LOCKED"},
			{Strength: "SHARE"},
			{Strength: "NO KEY UPDATE"},
		} {
			var w struct{ Role string }
			err := r.db.Transaction(func(tx *gorm.DB) error {
				return tx.Table("whoami").Clauses(c).Take(&w).Error
			})
			if err != nil {
				t.Fatal(err)
			}
			if w.Role != "source" {
				t.Errorf("%v went to %s", c, w.Role)
			}
			// Outside a transaction the lock is released at once, but the routing is the point.
			var w2 struct{ Role string }
			if err := r.db.Table("whoami").Clauses(c).Take(&w2).Error; err != nil {
				t.Fatal(err)
			}
			if w2.Role != "source" {
				t.Errorf("%v outside a transaction went to %s", c, w2.Role)
			}
		}
	})

	t.Run("reads inside a transaction go to the source", func(t *testing.T) {
		err := r.db.Transaction(func(tx *gorm.DB) error {
			if got := who(t, tx, `SELECT role FROM whoami`); got != "source" {
				t.Errorf("transaction read went to %s", got)
			}
			return nil
		})
		if err != nil {
			t.Fatal(err)
		}
	})
}

func TestRoutingOfWrites(t *testing.T) {
	r := setup(t)

	t.Run("GORM Create uses RETURNING on the source", func(t *testing.T) {
		it := Item{Name: "a"}
		if err := r.db.Create(&it).Error; err != nil {
			t.Fatal(err)
		}
		if it.ID == 0 {
			t.Error("the generated id was not read back")
		}
		if count(t, r.src) != 1 || count(t, r.rep) != 0 {
			t.Errorf("source %d replica %d", count(t, r.src), count(t, r.rep))
		}
	})

	t.Run("raw INSERT ... RETURNING goes to the source", func(t *testing.T) {
		var id uint64
		if err := r.db.Raw(`INSERT INTO items (name) VALUES ('b') RETURNING id`).Scan(&id).Error; err != nil {
			t.Fatal(err)
		}
		if id == 0 || count(t, r.src) != 2 || count(t, r.rep) != 0 {
			t.Errorf("id %d source %d replica %d", id, count(t, r.src), count(t, r.rep))
		}
	})

	t.Run("data-modifying CTE goes to the source", func(t *testing.T) {
		var name string
		if err := r.db.Raw(`WITH ins AS (INSERT INTO items (name) VALUES ('c') RETURNING name) SELECT name FROM ins`).Scan(&name).Error; err != nil {
			t.Fatal(err)
		}
		if name != "c" || count(t, r.src) != 3 || count(t, r.rep) != 0 {
			t.Errorf("name %q source %d replica %d", name, count(t, r.src), count(t, r.rep))
		}
	})

	t.Run("upsert with ON CONFLICT goes to the source", func(t *testing.T) {
		it := Item{ID: 1, Name: "renamed"}
		err := r.db.Clauses(clause.OnConflict{
			Columns:   []clause.Column{{Name: "id"}},
			DoUpdates: clause.AssignmentColumns([]string{"name"}),
		}).Create(&it).Error
		if err != nil {
			t.Fatal(err)
		}
		var n string
		r.src.Raw(`SELECT name FROM items WHERE id = 1`).Scan(&n)
		if n != "renamed" || count(t, r.rep) != 0 {
			t.Errorf("name %q replica rows %d", n, count(t, r.rep))
		}
	})

	t.Run("exec through the source pool reports no LastInsertId", func(t *testing.T) {
		sqlDB, err := r.db.DB()
		if err != nil {
			t.Fatal(err)
		}
		res, err := sqlDB.Exec(`INSERT INTO items (name) VALUES ('d')`)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := res.LastInsertId(); err == nil {
			t.Error("expected LastInsertId to be unsupported")
		} else {
			t.Logf("LastInsertId: %v", err)
		}
		if n, _ := res.RowsAffected(); n != 1 {
			t.Errorf("RowsAffected %d", n)
		}
	})
}

func TestFailoverToSourceWhenReplicaDown(t *testing.T) {
	r := setup(t)
	// A replica that nothing listens on.
	u, _ := url.Parse(baseURL(r.repName))
	u.Host = env("PGSQL_HOST", "postgres") + ":1"
	dialector, fc, err := newFailoverDialector(u.String(), baseURL(r.srcName))
	if err != nil {
		t.Fatal(err)
	}
	db, err := gorm.Open(postgres.Open(baseURL(r.srcName)), &gorm.Config{})
	if err != nil {
		t.Fatal(err)
	}
	if err := db.Use(dbresolver.Register(dbresolver.Config{
		Replicas: []gorm.Dialector{dialector},
		Policy:   dbresolver.RandomPolicy{},
	})); err != nil {
		t.Fatal(err)
	}
	start := time.Now()
	if got := who(t, db, `SELECT role FROM whoami`); got != "source" {
		t.Errorf("read went to %s, want the source after fallback", got)
	}
	if fc.fellBack.Load() == 0 {
		t.Error("the fallback was not used")
	}
	if d := time.Since(start); d > 5*time.Second {
		t.Errorf("fallback took %s", d)
	}
}

func TestFailoverPrefersHealthyReplica(t *testing.T) {
	r := setup(t)
	dialector, fc, err := newFailoverDialector(baseURL(r.repName), baseURL(r.srcName))
	if err != nil {
		t.Fatal(err)
	}
	db, _ := gorm.Open(postgres.Open(baseURL(r.srcName)), &gorm.Config{})
	if err := db.Use(dbresolver.Register(dbresolver.Config{Replicas: []gorm.Dialector{dialector}})); err != nil {
		t.Fatal(err)
	}
	if got := who(t, db, `SELECT role FROM whoami`); got != "replica" {
		t.Errorf("read went to %s", got)
	}
	if fc.fellBack.Load() != 0 {
		t.Error("fell back although the replica is up")
	}
}

func TestContextCancelStopsTheQuery(t *testing.T) {
	// iznik-server-go uses rocketlaunchr/mysql-go because database/sql cannot cancel a MySQL
	// query. pgx sends a cancel request, so a context deadline is enough.
	r := setup(t)
	ctx, cancel := context.WithTimeout(context.Background(), 300*time.Millisecond)
	defer cancel()
	start := time.Now()
	err := r.db.WithContext(ctx).Clauses(dbresolver.Write).Exec(`SELECT pg_sleep(10)`).Error
	if err == nil {
		t.Fatal("expected a cancellation error")
	}
	if d := time.Since(start); d > 3*time.Second {
		t.Errorf("returned after %s", d)
	}
	var running int
	time.Sleep(300 * time.Millisecond)
	r.src.Raw(`SELECT count(*) FROM pg_stat_activity WHERE query LIKE 'SELECT pg_sleep%' AND state = 'active' AND pid <> pg_backend_pid()`).Scan(&running)
	if running != 0 {
		t.Errorf("%d sleeping backends remain", running)
	}
}

func TestSimpleProtocolThroughResolver(t *testing.T) {
	// A pooler in transaction mode cannot take pgx's default prepared statements. The dialector
	// takes PreferSimpleProtocol for that case; routing must not change.
	r := setup(t)
	db, _ := gorm.Open(postgres.New(postgres.Config{DSN: baseURL(r.srcName), PreferSimpleProtocol: true}), &gorm.Config{})
	if err := db.Use(dbresolver.Register(dbresolver.Config{
		Replicas: []gorm.Dialector{postgres.New(postgres.Config{DSN: baseURL(r.repName), PreferSimpleProtocol: true})},
	})); err != nil {
		t.Fatal(err)
	}
	if got := who(t, db, `SELECT role FROM whoami`); got != "replica" {
		t.Errorf("read went to %s", got)
	}
	it := Item{Name: "s"}
	if err := db.Create(&it).Error; err != nil || it.ID == 0 {
		t.Errorf("create: %v id %d", err, it.ID)
	}
}

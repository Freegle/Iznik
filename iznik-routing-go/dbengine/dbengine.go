// Package dbengine chooses which database engine the routing service talks to, and builds the
// driver name and DSN for it. MySQL is the default. PostgreSQL is selected with DB_ENGINE=postgres.
//
// The service reads one database through database/sql. The engine is configuration, not code: the
// same binary runs on either, and the SQL that differs between them lives next to the call sites
// (see sql*.go in the main package).
//
// Settings come from the environment:
//
//	DB_ENGINE       mysql (default), postgres or postgresql
//	MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DBNAME     when the engine is MySQL
//	PGSQL_HOST, PGSQL_PORT, PGSQL_USERNAME, PGSQL_PASSWORD, PGSQL_DATABASE,
//	PGSQL_SSLMODE (default prefer)                                        when it is PostgreSQL
//
// The PGSQL names are the ones the Laravel pgsql connection reads. As on MySQL, an empty host means
// no database is configured and the service runs without one.
package dbengine

import (
	"fmt"
	"net"
	"net/url"
	"os"
	"strings"
	"time"

	// Registers the "mysql" driver.
	_ "github.com/go-sql-driver/mysql"
	// Registers the "pgx" driver.
	_ "github.com/jackc/pgx/v5/stdlib"
)

// Engine is a database engine the service can run on.
type Engine string

const (
	MySQL    Engine = "mysql"
	Postgres Engine = "postgres"
)

// EnvEngine is the variable that selects the engine.
const EnvEngine = "DB_ENGINE"

// Parse turns a configuration value into an Engine. An empty value is MySQL. Anything else that is
// not a known name is an error, so a misspelling cannot quietly select the other engine.
func Parse(s string) (Engine, error) {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "", "mysql":
		return MySQL, nil
	case "postgres", "postgresql":
		return Postgres, nil
	}
	return MySQL, fmt.Errorf("DB_ENGINE %q is not mysql or postgres", s)
}

// FromEnv reads DB_ENGINE.
func FromEnv() (Engine, error) {
	return Parse(os.Getenv(EnvEngine))
}

// Current is the engine the environment selects. A value that does not parse gives MySQL, the
// default; the connection code refuses to open anything in that case (see FromEnv), so no query
// runs against the wrong engine.
func Current() Engine {
	e, _ := FromEnv()
	return e
}

// Driver is the database/sql driver name.
func (e Engine) Driver() string {
	if e == Postgres {
		return "pgx"
	}
	return "mysql"
}

// Label is the engine's name as it reads in a log line.
func (e Engine) Label() string {
	if e == Postgres {
		return "PostgreSQL"
	}
	return "MySQL"
}

func (e Engine) defaultPort() string {
	if e == Postgres {
		return "5432"
	}
	return "3306"
}

// Settings are the connection settings for one engine.
type Settings struct {
	Host     string
	Port     string
	User     string
	Password string
	Name     string
}

// SettingsFromEnv reads the settings for engine e. Fields that are empty in the environment take
// the default: the engine's standard port, the database name iznik, and d's host and user (the user
// falls back to iznik on MySQL and root on PostgreSQL, the dev stack's users). d lets a command
// choose its own defaults; the service passes the zero value, so an empty host means no database.
func SettingsFromEnv(e Engine, d Settings) Settings {
	prefix, userVar, nameVar := "MYSQL_", "MYSQL_USER", "MYSQL_DBNAME"
	defUser := "iznik"
	if e == Postgres {
		prefix, userVar, nameVar = "PGSQL_", "PGSQL_USERNAME", "PGSQL_DATABASE"
		defUser = "root"
	}
	pick := func(key, fallback string) string {
		if v := os.Getenv(key); v != "" {
			return v
		}
		return fallback
	}
	if d.User != "" {
		defUser = d.User
	}
	defName := d.Name
	if defName == "" {
		defName = "iznik"
	}
	return Settings{
		Host:     pick(prefix+"HOST", d.Host),
		Port:     pick(prefix+"PORT", e.defaultPort()),
		User:     pick(userVar, defUser),
		Password: pick(prefix+"PASSWORD", d.Password),
		Name:     pick(nameVar, defName),
	}
}

// DSNOptions are the per-connection options. They apply to MySQL only: PostgreSQL always returns
// times as time.Time and has no read timeout setting in a DSN.
type DSNOptions struct {
	// ParseTime asks the MySQL driver for time.Time instead of []byte.
	ParseTime bool
	// ReadTimeout is the MySQL driver's I/O read timeout, in whole seconds. Zero leaves it unset.
	ReadTimeout time.Duration
}

// DSN builds the data source name for engine e.
func (e Engine) DSN(s Settings, o DSNOptions) string {
	if e == Postgres {
		u := url.URL{
			Scheme: "postgres",
			User:   url.User(s.User),
			Host:   net.JoinHostPort(s.Host, s.Port),
			Path:   "/" + s.Name,
		}
		if s.Password != "" {
			u.User = url.UserPassword(s.User, s.Password)
		}
		q := url.Values{}
		q.Set("sslmode", pickEnv("PGSQL_SSLMODE", "prefer"))
		// The schema stores timestamptz; a session in another zone would show other wall times.
		q.Set("timezone", "UTC")
		u.RawQuery = q.Encode()
		return u.String()
	}
	dsn := fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=%t", s.User, s.Password, s.Host, s.Port, s.Name, o.ParseTime)
	if o.ReadTimeout > 0 {
		dsn += fmt.Sprintf("&readTimeout=%ds", int(o.ReadTimeout.Seconds()))
	}
	return dsn
}

func pickEnv(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}

// Rebind rewrites ? placeholders to $1, $2, ... for PostgreSQL and returns the query unchanged for
// MySQL. A ? inside a single-quoted string, a double-quoted identifier or a -- comment is left
// alone. It does not understand dollar-quoted strings, so none of the service's queries use them.
func (e Engine) Rebind(q string) string {
	if e != Postgres || !strings.Contains(q, "?") {
		return q
	}
	var b strings.Builder
	b.Grow(len(q) + 8)
	n := 0
	var quote byte // the quote character we are inside, or 0
	inComment := false
	for i := 0; i < len(q); i++ {
		c := q[i]
		switch {
		case inComment:
			if c == '\n' {
				inComment = false
			}
			b.WriteByte(c)
		case quote != 0:
			if c == quote {
				quote = 0
			}
			b.WriteByte(c)
		case c == '\'' || c == '"':
			quote = c
			b.WriteByte(c)
		case c == '-' && i+1 < len(q) && q[i+1] == '-':
			inComment = true
			b.WriteByte(c)
		case c == '?':
			n++
			fmt.Fprintf(&b, "$%d", n)
		default:
			b.WriteByte(c)
		}
	}
	return b.String()
}

// FingerprintArg is how a 64-bit partition fingerprint is sent to the database. The fingerprint is
// a hash and uses all 64 bits. MySQL stores it in BIGINT UNSIGNED. PostgreSQL has only signed
// bigint, so the same bit pattern is stored as a signed value; every write and every comparison
// goes through this, so equal fingerprints stay equal.
func (e Engine) FingerprintArg(fp uint64) any {
	if e == Postgres {
		return int64(fp)
	}
	return fp
}

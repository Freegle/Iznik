package database

import (
	"errors"

	"github.com/go-sql-driver/mysql"
	"github.com/jackc/pgx/v5/pgconn"
)

// The two engines report the same conditions with different errors. These recognise either.

const (
	mysqlDuplicateEntry      = 1062
	mysqlQueryTimeout        = 3024
	pgUniqueViolation        = "23505"
	pgQueryCanceledOrTimeout = "57014"
)

// IsDuplicateKey reports a unique key violation: MySQL error 1062 or PostgreSQL 23505.
func IsDuplicateKey(err error) bool {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return me.Number == mysqlDuplicateEntry
	}
	var pe *pgconn.PgError
	return errors.As(err, &pe) && pe.Code == pgUniqueViolation
}

// IsDuplicateKeyOn reports a unique key violation on the named key. MySQL names the key in its message, the
// accepted names are what it may call it; PostgreSQL names the constraint.
func IsDuplicateKeyOn(err error, mysqlKeyMessages []string, pgConstraint string) bool {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		if me.Number != mysqlDuplicateEntry {
			return false
		}
		for _, m := range mysqlKeyMessages {
			if containsString(me.Message, m) {
				return true
			}
		}
		return false
	}
	var pe *pgconn.PgError
	return errors.As(err, &pe) && pe.Code == pgUniqueViolation && pe.ConstraintName == pgConstraint
}

// IsLockFailure reports a statement that lost a deadlock or gave up waiting for a lock, by the server's
// error code: MySQL 1213 and 1205, PostgreSQL 40P01 and 55P03. The wording of the message differs between
// servers and versions; the code does not.
func IsLockFailure(err error) bool {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return me.Number == 1213 || me.Number == 1205
	}
	var pe *pgconn.PgError
	return errors.As(err, &pe) && (pe.Code == "40P01" || pe.Code == "55P03")
}

// IsQueryTimeout reports a query stopped by its time limit: MySQL 3024 (MAX_EXECUTION_TIME) or PostgreSQL 57014.
func IsQueryTimeout(err error) bool {
	var me *mysql.MySQLError
	if errors.As(err, &me) {
		return me.Number == mysqlQueryTimeout
	}
	var pe *pgconn.PgError
	return errors.As(err, &pe) && pe.Code == pgQueryCanceledOrTimeout
}

func containsString(s, sub string) bool {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}

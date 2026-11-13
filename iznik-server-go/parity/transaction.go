package parity

import (
	"context"
	"database/sql"
	"errors"

	"gorm.io/gorm"
)

// AssertNoDefaultTransaction fails t when build's terminal call would open a transaction.
//
// GORM wraps a Create, Update or Delete in BEGIN and COMMIT unless the handle sets
// SkipDefaultTransaction, which production does not. A raw db.Exec sent one statement, so a
// converted write must keep sending one. Layer 1 cannot see the difference, because the dry-run
// handle skips the default transaction to render the statement. This check keeps it on and
// counts the BEGINs.
//
//	parity.AssertNoDefaultTransaction(t, func(tx *gorm.DB) *gorm.DB {
//		return stopDigestEmails(tx, 5)
//	})
func AssertNoDefaultTransaction(t TestingT, build Build) {
	t.Helper()

	db, err := dryRunDB(MySQL)
	if err != nil {
		t.Fatalf("parity: constructing the dry-run handle: %v", err)
		return
	}

	pool := &beginCounter{}
	session := db.Session(&gorm.Session{DryRun: true})
	session.Statement.ConnPool = pool
	tx := build(session)
	if tx == nil {
		t.Fatalf("parity: the build function returned a nil *gorm.DB")
		return
	}
	if pool.begins > 0 {
		t.Fatalf("parity: the statement opened %d transaction(s). Production sends one statement for a "+
			"raw Exec, so the chain must use db.Session(&gorm.Session{SkipDefaultTransaction: true})", pool.begins)
	}
}

// beginCounter is a connection pool that never connects and counts the transactions GORM asks for.
type beginCounter struct {
	begins int
}

var errNoConnection = errors.New("parity: no connection in a dry run")

func (c *beginCounter) BeginTx(context.Context, *sql.TxOptions) (*sql.Tx, error) {
	c.begins++
	return nil, errNoConnection
}

func (c *beginCounter) PrepareContext(context.Context, string) (*sql.Stmt, error) {
	return nil, errNoConnection
}

func (c *beginCounter) ExecContext(context.Context, string, ...interface{}) (sql.Result, error) {
	return nil, errNoConnection
}

func (c *beginCounter) QueryContext(context.Context, string, ...interface{}) (*sql.Rows, error) {
	return nil, errNoConnection
}

func (c *beginCounter) QueryRowContext(context.Context, string, ...interface{}) *sql.Row {
	return nil
}

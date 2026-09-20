package main

import (
	"database/sql"
	"fmt"
	"log"
	"os"
	"sync"
	"time"

	_ "github.com/go-sql-driver/mysql"
)

var groupsDB *sql.DB
var groupsDBMu sync.RWMutex

// configureGroupsPool sizes the shared pool so concurrent requests get pooled
// connections instead of contending. The server is GOMAXPROCS=NumCPU and
// handles requests on many goroutines, so it needs more than the default 2 idle conns.
func configureGroupsPool(db *sql.DB) {
	db.SetMaxOpenConns(32)
	db.SetMaxIdleConns(16)
	db.SetConnMaxLifetime(5 * time.Minute)
}

// groupsDSN builds the MySQL DSN from environment variables.
func groupsDSN() string {
	host := os.Getenv("MYSQL_HOST")
	if host == "" {
		return ""
	}
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	user := os.Getenv("MYSQL_USER")
	if user == "" {
		user = "iznik"
	}
	pass := os.Getenv("MYSQL_PASSWORD")
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	return fmt.Sprintf("%s:%s@tcp(%s:%s)/%s?parseTime=true", user, pass, host, port, dbname)
}

// initGroupsDB attempts to connect to MySQL at startup.
func initGroupsDB() {
	dsn := groupsDSN()
	if dsn == "" {
		return
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Printf("groups: MySQL open error: %v", err)
		return
	}
	if err := db.Ping(); err != nil {
		log.Printf("groups: MySQL ping error: %v", err)
		db.Close()
		return
	}
	configureGroupsPool(db)
	groupsDB = db
	host := os.Getenv("MYSQL_HOST")
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	log.Printf("groups: MySQL connected (%s:%s/%s)", host, port, dbname)
}

// ensureGroupsDB returns the shared DB connection, reconnecting lazily if
// the initial startup connection failed (e.g. the DB tunnel was not yet up).
func ensureGroupsDB() *sql.DB {
	// Hot path: shared RLock, no Ping. *sql.DB is a concurrency-safe pool that
	// transparently reopens broken connections on the next query, so pinging on every
	// call — while holding an EXCLUSIVE lock across a remote round trip — was both
	// unnecessary and serialized the whole (GOMAXPROCS=NumCPU) server to ~1 core.
	groupsDBMu.RLock()
	db := groupsDB
	groupsDBMu.RUnlock()
	if db != nil {
		return db
	}
	// Slow path: connect once under the exclusive lock (initGroupsDB failed at
	// startup, e.g. the DB tunnel wasn't up yet). The pool self-heals thereafter.
	groupsDBMu.Lock()
	defer groupsDBMu.Unlock()
	if groupsDB != nil {
		return groupsDB
	}
	dsn := groupsDSN()
	if dsn == "" {
		return nil
	}
	db, err := sql.Open("mysql", dsn)
	if err != nil {
		log.Printf("groups: MySQL reconnect open error: %v", err)
		return nil
	}
	if err := db.Ping(); err != nil {
		log.Printf("groups: MySQL reconnect ping error: %v", err)
		db.Close()
		return nil
	}
	configureGroupsPool(db)
	groupsDB = db
	host := os.Getenv("MYSQL_HOST")
	port := os.Getenv("MYSQL_PORT")
	if port == "" {
		port = "3306"
	}
	dbname := os.Getenv("MYSQL_DBNAME")
	if dbname == "" {
		dbname = "iznik"
	}
	log.Printf("groups: MySQL reconnected (%s:%s/%s)", host, port, dbname)
	return groupsDB
}

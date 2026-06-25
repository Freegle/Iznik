package database

import "gorm.io/gorm"

// ExecInsertGetID runs a raw INSERT on the primary write connection and returns the
// AUTO_INCREMENT id of the inserted row, taken from the write result itself.
//
// NEVER read a freshly-inserted row's id back with a separate
// "SELECT id ... ORDER BY id DESC LIMIT 1", "SELECT MAX(id)" or "SELECT LAST_INSERT_ID()":
// under the DB read/write split that SELECT can be routed to a Galera read replica which has
// not yet caught up, returning the WRONG row (a previous one) or 0. That is the Discourse 9832
// "mixed up offers" class of bug. LastInsertId() comes from the same write connection, so it is
// always correct, even under replication lag and parallel load.
//
// For INSERT IGNORE / INSERT ... ON DUPLICATE KEY where you also need the id on the
// "row already existed" path, add "ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)" to the query
// so MySQL reports the existing row's id via LastInsertId too (see CreateGroup / giftaid).
func ExecInsertGetID(db *gorm.DB, query string, args ...interface{}) (uint64, error) {
	sqlDB, err := db.DB()
	if err != nil {
		return 0, err
	}
	res, err := sqlDB.Exec(query, args...)
	if err != nil {
		return 0, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return 0, err
	}
	return uint64(id), nil
}

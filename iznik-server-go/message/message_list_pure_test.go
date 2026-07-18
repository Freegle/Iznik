package message

// Tests for pure (no-DB) functions in message_list.go.
// buildMTUnionAllMsgIDQuery is a pure SQL-builder: no database or fiber context needed.
// isQueryTimeoutErr is a pure error classifier: no database needed either.

import (
	"errors"
	"fmt"
	"strings"
	"testing"

	rawmysql "github.com/go-sql-driver/mysql"
	"github.com/stretchr/testify/assert"
)

// TestIsQueryTimeoutErr_DetectsERQueryTimeout verifies the MySQL error raised
// by the MAX_EXECUTION_TIME optimizer hint (error 3024, ER_QUERY_TIMEOUT) is
// recognised as a timeout, not treated as a genuine zero-rows result.
func TestIsQueryTimeoutErr_DetectsERQueryTimeout(t *testing.T) {
	err := &rawmysql.MySQLError{Number: 3024, Message: "Query execution was interrupted, maximum statement execution time exceeded"}
	assert.True(t, isQueryTimeoutErr(err))
}

// TestIsQueryTimeoutErr_WrappedError verifies detection still works when the
// MySQLError has been wrapped by another error (as GORM/database-sql do).
func TestIsQueryTimeoutErr_WrappedError(t *testing.T) {
	err := errors.New("wrapped: " + (&rawmysql.MySQLError{Number: 3024, Message: "timeout"}).Error())
	// A plain string-wrapped error (not errors.Wrap/fmt.Errorf %w) cannot be
	// unwrapped, so this must NOT be misdetected as a timeout — errors.As only
	// matches the concrete *MySQLError type through the error chain.
	assert.False(t, isQueryTimeoutErr(err))

	wrapped := fmt.Errorf("query failed: %w", &rawmysql.MySQLError{Number: 3024, Message: "timeout"})
	assert.True(t, isQueryTimeoutErr(wrapped))
}

// TestIsQueryTimeoutErr_IgnoresOtherErrors verifies unrelated errors (a
// different MySQL error number, a generic error, or nil) are not
// misclassified as a query timeout.
func TestIsQueryTimeoutErr_IgnoresOtherErrors(t *testing.T) {
	assert.False(t, isQueryTimeoutErr(nil))
	assert.False(t, isQueryTimeoutErr(errors.New("connection refused")))
	assert.False(t, isQueryTimeoutErr(&rawmysql.MySQLError{Number: 1146, Message: "Table doesn't exist"}))
	// gorm.ErrRecordNotFound-shaped generic error, still not a timeout.
	assert.False(t, isQueryTimeoutErr(errors.New("record not found")))
}

// branchSQL with a single %GID% placeholder, one extra arg, plus the trailing LIMIT arg.
const testBranchSQL = "SELECT mg.msgid, mg.arrival FROM messages_groups mg WHERE mg.groupid = %GID% AND mg.collection = ? ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"

// TestBuildMTUnionAllMsgIDQuery_SingleGroup verifies the output for a single group ID.
func TestBuildMTUnionAllMsgIDQuery_SingleGroup(t *testing.T) {
	branchArgs := []interface{}{"Pending"}
	groupIDs := []uint64{42}
	limit := 10

	sql, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	// Outer structure must wrap a UNION ALL in a subquery, with the
	// MAX_EXECUTION_TIME cap that stops a runaway member-name search hanging.
	assert.Contains(t, sql, "MAX_EXECUTION_TIME(20000)")
	assert.Contains(t, sql, "msgid FROM (")
	assert.Contains(t, sql, "GROUP BY msgid")
	assert.Contains(t, sql, "ORDER BY arrival DESC, msgid DESC LIMIT ?")

	// %GID% must be substituted with the actual group ID.
	assert.Contains(t, sql, "groupid = 42")
	assert.NotContains(t, sql, "%GID%")

	// Single group: no UNION ALL needed.
	assert.NotContains(t, sql, "UNION ALL")

	// Args: branchArgs (1) + limit per branch (1) + outer limit (1) = 3.
	assert.Len(t, args, 3)
	assert.Equal(t, "Pending", args[0])
	assert.Equal(t, limit, args[1])
	assert.Equal(t, limit, args[2])
}

// TestBuildMTUnionAllMsgIDQuery_TwoGroups verifies UNION ALL appears once for two groups.
func TestBuildMTUnionAllMsgIDQuery_TwoGroups(t *testing.T) {
	branchArgs := []interface{}{"Approved"}
	groupIDs := []uint64{10, 20}
	limit := 50

	sql, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	// Two groups → one UNION ALL.
	assert.Equal(t, 1, strings.Count(sql, "UNION ALL"))

	// Both group IDs must appear.
	assert.Contains(t, sql, "groupid = 10")
	assert.Contains(t, sql, "groupid = 20")

	// Args: 2 * (branchArgs(1) + limit(1)) + outer limit(1) = 5.
	assert.Len(t, args, 5)
	assert.Equal(t, limit, args[len(args)-1])
}

// TestBuildMTUnionAllMsgIDQuery_ThreeGroups verifies two UNION ALLs for three groups.
func TestBuildMTUnionAllMsgIDQuery_ThreeGroups(t *testing.T) {
	branchArgs := []interface{}{"Pending"}
	groupIDs := []uint64{1, 2, 3}
	limit := 25

	sql, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	assert.Equal(t, 2, strings.Count(sql, "UNION ALL"))
	assert.Contains(t, sql, "groupid = 1")
	assert.Contains(t, sql, "groupid = 2")
	assert.Contains(t, sql, "groupid = 3")

	// Args: 3 * (1 + 1) + 1 = 7.
	assert.Len(t, args, 7)
}

// TestBuildMTUnionAllMsgIDQuery_EmptyGroups returns a degenerate query that won't match anything.
func TestBuildMTUnionAllMsgIDQuery_EmptyGroups(t *testing.T) {
	branchArgs := []interface{}{"Approved"}
	groupIDs := []uint64{}
	limit := 10

	sql, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	// No branches → no UNION ALL, no groupid substitution.
	assert.NotContains(t, sql, "UNION ALL")
	assert.NotContains(t, sql, "%GID%")

	// Only the outer LIMIT arg is appended.
	assert.Len(t, args, 1)
	assert.Equal(t, limit, args[0])
}

// TestBuildMTUnionAllMsgIDQuery_MultipleBranchArgs verifies multiple per-branch args are replicated.
func TestBuildMTUnionAllMsgIDQuery_MultipleBranchArgs(t *testing.T) {
	// Branch SQL with two ? args (before the trailing LIMIT).
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = ? ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	branchArgs := []interface{}{"Pending", 0}
	groupIDs := []uint64{5, 6}
	limit := 20

	sql, args := buildMTUnionAllMsgIDQuery(branchSQL, branchArgs, groupIDs, limit)

	assert.Equal(t, 1, strings.Count(sql, "UNION ALL"))

	// Args: 2 * (2 + 1) + 1 = 7.
	assert.Len(t, args, 7)
	// First branch: "Pending", 0, limit
	assert.Equal(t, "Pending", args[0])
	assert.Equal(t, 0, args[1])
	assert.Equal(t, limit, args[2])
	// Second branch: same
	assert.Equal(t, "Pending", args[3])
	assert.Equal(t, 0, args[4])
	assert.Equal(t, limit, args[5])
	// Outer limit.
	assert.Equal(t, limit, args[6])
}

// TestBuildMTUnionAllMsgIDQuery_GIDSubstitutionIsExact verifies substitution is exact (not partial).
func TestBuildMTUnionAllMsgIDQuery_GIDSubstitutionIsExact(t *testing.T) {
	branchSQL := "SELECT mgid, arr FROM mg WHERE groupid = %GID% LIMIT ?"
	groupIDs := []uint64{999}
	limit := 5

	sql, _ := buildMTUnionAllMsgIDQuery(branchSQL, nil, groupIDs, limit)

	assert.Contains(t, sql, "groupid = 999")
	assert.NotContains(t, sql, "%GID%")
}

// TestBuildMTUnionAllMsgIDQuery_OuterQueryStructure checks the overall SQL wrapping.
func TestBuildMTUnionAllMsgIDQuery_OuterQueryStructure(t *testing.T) {
	branchArgs := []interface{}{}
	groupIDs := []uint64{7}
	limit := 30

	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg WHERE mg.groupid = %GID% ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	sql, _ := buildMTUnionAllMsgIDQuery(branchSQL, branchArgs, groupIDs, limit)

	// The outermost query selects msgid from the inner (with the execution-time cap).
	assert.True(t, strings.HasPrefix(sql, "SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM ("))
	// Final clause: ORDER BY arrival, then limit placeholder.
	assert.True(t, strings.HasSuffix(strings.TrimSpace(sql), "LIMIT ?"))
}

// TestBuildMTUnionAllMsgIDQuery_BranchesParenthesized verifies each branch is wrapped in ().
func TestBuildMTUnionAllMsgIDQuery_BranchesParenthesized(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg WHERE mg.groupid = %GID% ORDER BY mg.arrival DESC LIMIT ?"
	groupIDs := []uint64{1, 2}
	limit := 10

	sql, _ := buildMTUnionAllMsgIDQuery(branchSQL, nil, groupIDs, limit)

	// Both branches should be wrapped in parentheses.
	// Check that "( SELECT" or "(SELECT" appears at least twice.
	openParens := strings.Count(sql, "(SELECT") + strings.Count(sql, "( SELECT")
	assert.GreaterOrEqual(t, openParens, 2, "each branch must be parenthesized")
}

// TestBuildMTUnionAllMsgIDQuery_LimitIsLastArg verifies the outer LIMIT arg is the last element.
func TestBuildMTUnionAllMsgIDQuery_LimitIsLastArg(t *testing.T) {
	branchArgs := []interface{}{"x"}
	groupIDs := []uint64{100, 200}
	limit := 77

	_, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	assert.Equal(t, limit, args[len(args)-1], "outer LIMIT must be the last arg")
}

// TestBuildMTUnionAllMsgIDQuery_NilBranchArgs handles nil branchArgs gracefully.
func TestBuildMTUnionAllMsgIDQuery_NilBranchArgs(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM mg WHERE mg.groupid = %GID% ORDER BY mg.arrival DESC LIMIT ?"
	groupIDs := []uint64{42}
	limit := 5

	sql, args := buildMTUnionAllMsgIDQuery(branchSQL, nil, groupIDs, limit)

	assert.Contains(t, sql, "groupid = 42")
	// Args: nil branchArgs → 0 per branch, plus limit per branch (1) + outer limit (1) = 2.
	assert.Len(t, args, 2)
	assert.Equal(t, limit, args[0])
	assert.Equal(t, limit, args[1])
}

// TestBuildMTUnionAllMsgIDQuery_ZeroLimitPropagatesToBranches ensures limit=0 propagates correctly.
func TestBuildMTUnionAllMsgIDQuery_ZeroLimitPropagatesToBranches(t *testing.T) {
	branchArgs := []interface{}{}
	groupIDs := []uint64{1}
	limit := 0

	_, args := buildMTUnionAllMsgIDQuery(testBranchSQL, branchArgs, groupIDs, limit)

	// Per-branch limit arg + outer limit arg.
	for _, a := range args {
		assert.Equal(t, 0, a)
	}
}

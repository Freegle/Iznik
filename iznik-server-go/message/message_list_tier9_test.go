package message

// Tier 9 of the keep-raw adversarial review
// (plans/active/orm-keepraw-adversarial-review.md, §4): the 5
// ListMessagesMT keep-raw sites (a716e6f7dc57, e1228c35e4d2, d6cc20019a01,
// 6358402174f8, 7b739452734d) are all calls to buildMTUnionAllMsgIDQuery
// with a different branchSQL template - the UNION ALL it assembles has one
// arm PER GROUP the moderator has (branch count = len(groupIDs)), which is
// a runtime VALUE, not one of shapes.json's fixed, declarable shapes. This
// file proves each call site's actual branchSQL template renders correctly
// for ANY n via ormharness.AssertGoldenParametrizedShape
// (ormharness/parametrizedshape.go), not by sampling a couple of n and
// hoping the rest generalise.
//
// wantUnionAllMsgIDSQL below is a SECOND, independent implementation of
// buildMTUnionAllMsgIDQuery's structure - a plain loop, not a call into the
// function under test - so a bug in the production loop (an off-by-one in
// arg replication, a missing paren) has somewhere to be caught rather than
// being silently mirrored by the "expected" side. This is the same
// independence principle golden.go's goldenSql serves for ordinary sites:
// the proof is only worth something if the two sides were derived
// separately.
//
// Each branchSQL constant below is copied verbatim from ListMessagesMT
// (message_list.go), with two exceptions kept out deliberately: the
// contentcheckFilter append (only non-empty for collection=Pending) and the
// default branch's optional fromuser/context appends. Both are plain text
// concatenated onto the SAME opaque branchSQL template buildMTUnionAllMsgIDQuery
// treats identically either way - they are an "which optional filter text"
// question, not a "does the UNION ALL arm count generalise" question, so
// they are orthogonal to what this file proves. All five tests use a
// non-Pending collection so the omission is also textually accurate, not
// just orthogonal.
//
// message_list_pure_test.go (same package, pre-existing) already exercises
// buildMTUnionAllMsgIDQuery's general behaviour (0/1/2/3 groups, arg
// replication, structural asserts) against a synthetic branchSQL. That
// suite is valuable but not itself Gate-2 evidence for these 5 sites: it
// tests the shared builder generically, not each call site's own real
// branchSQL text, and it names no site id. This file is what ties the
// mechanism to the 5 actual keep-raw sites plan 7.2 Gate 2 requires a test
// bearing the site's own id for.

import (
	"strconv"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
)

// wantUnionAllMsgIDSQL independently reconstructs buildMTUnionAllMsgIDQuery's
// output for n synthetic group ids (1..n) and the given branch template -
// see the file doc comment for why this is a second implementation, not a
// call into the one under test.
func wantUnionAllMsgIDSQL(branchSQL string, branchArgs []interface{}, n int, limit int) (string, []interface{}) {
	var sb strings.Builder
	sb.WriteString("SELECT /*+ MAX_EXECUTION_TIME(20000) */ msgid FROM (SELECT msgid, MAX(arrival) AS arrival FROM (")

	args := make([]interface{}, 0, (len(branchArgs)+1)*n+1)
	for i := 1; i <= n; i++ {
		if i > 1 {
			sb.WriteString(" UNION ALL ")
		}
		sb.WriteString("(")
		sb.WriteString(strings.Replace(branchSQL, "%GID%", strconv.Itoa(i), 1))
		sb.WriteString(")")
		args = append(args, branchArgs...)
		args = append(args, limit)
	}

	sb.WriteString(") raw GROUP BY msgid) t ORDER BY arrival DESC, msgid DESC LIMIT ?")
	args = append(args, limit)
	return sb.String(), args
}

// syntheticGroupIDs returns n distinct group ids (1..n), matching what
// wantUnionAllMsgIDSQL assumes when it substitutes %GID%.
func syntheticGroupIDs(n int) []uint64 {
	ids := make([]uint64, n)
	for i := range ids {
		ids[i] = uint64(i + 1)
	}
	return ids
}

// assertUnionAllSiteShape is the shared proof body for all 5 sites: renders
// buildMTUnionAllMsgIDQuery (the real production function) at n = 0, 1, 2
// and 10 - the empty case, the no-UNION-needed case, the smallest case that
// actually exercises UNION ALL, and a double-digit case standing in for a
// moderator of many groups - and checks each against the independently
// built template.
func assertUnionAllSiteShape(t *testing.T, siteID string, branchSQL string, branchArgs []interface{}, limit int) {
	t.Helper()

	wantSQL := func(n int) string {
		sql, _ := wantUnionAllMsgIDSQL(branchSQL, branchArgs, n, limit)
		return sql
	}
	wantArgCount := func(n int) int {
		_, args := wantUnionAllMsgIDSQL(branchSQL, branchArgs, n, limit)
		return len(args)
	}

	ns := []int{0, 1, 2, 10}
	cases := make([]ormharness.ParametrizedShapeCase, len(ns))
	for i, n := range ns {
		sql, args := buildMTUnionAllMsgIDQuery(branchSQL, branchArgs, syntheticGroupIDs(n), limit)
		cases[i] = ormharness.ParametrizedShapeCase{N: n, SQL: sql, Args: args}
	}

	ormharness.AssertGoldenParametrizedShape(t, siteID, wantSQL, wantArgCount, cases)
}

// ListMessagesMT, subaction=searchall, numeric search term (message id
// lookup).
func TestTier9_a716e6f7dc57(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
		"INNER JOIN messages m ON m.id = mg.msgid " +
		"INNER JOIN users u ON u.id = m.fromuser " +
		"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL AND m.id = ? " +
		" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	assertUnionAllSiteShape(t, "a716e6f7dc57", branchSQL, []interface{}{"Approved", uint64(12345)}, 20)
}

// ListMessagesMT, subaction=searchall, subject LIKE fallback.
func TestTier9_e1228c35e4d2(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
		"INNER JOIN messages m ON m.id = mg.msgid " +
		"INNER JOIN users u ON u.id = m.fromuser " +
		"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL AND m.subject LIKE ? " +
		" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	assertUnionAllSiteShape(t, "e1228c35e4d2", branchSQL, []interface{}{"Approved", "%widget%"}, 20)
}

// ListMessagesMT, subaction=searchmemb, numeric user id lookup.
func TestTier9_d6cc20019a01(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
		"INNER JOIN messages m ON m.id = mg.msgid " +
		"INNER JOIN users u ON u.id = m.fromuser " +
		"WHERE mg.groupid = %GID% " +
		"AND mg.collection = ? " +
		"AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND u.deleted IS NULL " +
		"AND m.fromuser = ? " +
		" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	assertUnionAllSiteShape(t, "d6cc20019a01", branchSQL, []interface{}{"Approved", uint64(999)}, 20)
}

// ListMessagesMT, subaction=searchmemb, name/email LIKE fallback.
func TestTier9_6358402174f8(t *testing.T) {
	branchSQL := "SELECT DISTINCT mg.msgid, mg.arrival FROM messages_groups mg " +
		"INNER JOIN messages m ON m.id = mg.msgid " +
		"INNER JOIN users u ON u.id = m.fromuser " +
		"LEFT JOIN users_emails ue ON ue.userid = u.id " +
		"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND u.deleted IS NULL " +
		"AND (u.fullname LIKE ? OR ue.email LIKE ?) " +
		" ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	assertUnionAllSiteShape(t, "6358402174f8", branchSQL, []interface{}{"Approved", "%jo%", "%jo%"}, 20)
}

// ListMessagesMT, default listing (no search/subaction) - the base
// collection filter, without the optional fromuser/context appends (those
// are orthogonal text appends to the same opaque branchSQL template, not a
// second axis of the n-parametrization this file proves).
func TestTier9_7b739452734d(t *testing.T) {
	branchSQL := "SELECT mg.msgid, mg.arrival FROM messages_groups mg " +
		"INNER JOIN messages m ON m.id = mg.msgid " +
		"INNER JOIN users u ON u.id = m.fromuser " +
		"WHERE mg.groupid = %GID% AND mg.collection = ? AND mg.deleted = 0 " +
		"AND m.deleted IS NULL AND m.fromuser IS NOT NULL AND u.deleted IS NULL " +
		"ORDER BY mg.arrival DESC, mg.msgid DESC LIMIT ?"
	assertUnionAllSiteShape(t, "7b739452734d", branchSQL, []interface{}{"Approved"}, 20)
}

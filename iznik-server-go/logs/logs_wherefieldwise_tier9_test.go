package logs

// WHERE-fieldwise coverage (not exhaustive shapes) for GetLogs's query
// build (site 6cf1b5aded22), extracted as buildGetLogsQuery (logs.go) for
// this proof - a pure behaviour-preserving refactor, the actual SQL and
// db.Raw call are unchanged.
//
// This is the worked example for ormharness.AssertGoldenWhereFieldwise -
// see its package doc comment (ormharness/wherefieldwise.go) for the
// mechanism and the precondition it checks. The site was previously parked
// on "policy decision needed, well over 32 shapes" because
// AssertGoldenShapes' exhaustive model was the only tool available; a
// human read of the code (this file, and the "IN (?) native bind" note in
// logs.go) found no PatchSession-style interaction anywhere in it - the
// ~7 dimensions are genuinely independent - so the real number, 352
// reachable combinations, was a scale problem, not an independence
// problem, and this proves it with 11 cases instead.
//
// Factors:
//   - Modmailsonly, Date, Context, Userid: plain fields, each a fixed
//     fragment regardless of what else is present.
//   - Search: a plain field whose "alone" form includes BOTH the extra
//     LEFT JOIN users and the WHERE fragment together - they are added by
//     the same "if search != ''" block in the original code, so they are
//     one factor's contribution, not two.
//   - LogType (group, 2 forms): logtype drives BOTH the types/subtypes
//     selection (switch statement) and, only for logtype=="user", a
//     separate NOT(...) exclusion fragment appended earlier in the
//     original where-slice. "messages" and "memberships" render IDENTICAL
//     WHERE text after the IN (?) native-bind change (both contribute
//     "logs.type IN (?) AND logs.subtype IN (?)" - the native bind makes
//     the actual type/subtype VALUES invisible to a Layer 1 text
//     comparison), so they are ONE form, "TypeAndSubtypeFilter", not two;
//     "default" (types=nil, subtypes=nil, no NOT-fragment either) is the
//     baseline, contributing nothing, so it needs no form of its own.
//   - GroupScope (group, 2 forms): groupid>0 vs the mod-group IN-list
//     fallback vs neither (the baseline). The fallback is only reachable
//     when logtype != "user" - since fieldwise tests each factor ALONE
//     (every other factor at its absent/default state, which includes
//     LogType being absent, i.e. not "user"), that reachability
//     restriction is satisfied automatically and does not make LogType and
//     GroupScope interact for what this proof checks (see the package doc
//     comment on what the precondition can and cannot see).
//
// "all" combines LogType:TypeAndSubtypeFilter with GroupScope:GroupidExact
// (both reachable together, since GroupidExact does not depend on logtype
// at all) plus every plain field - a mutually-compatible combination
// chosen deliberately, not the only one that would render correctly.

import (
	"testing"

	"github.com/freegle/iznik-server-go/ormharness"
	"gorm.io/gorm"
)

func TestWhereFieldwiseGetLogs_6cf1b5aded22(t *testing.T) {
	build := func(logtype string, groupid, userid uint64, logsubtype, dateStr, search string, modmailsonly bool, contextID uint64, isAdmin bool, modGroupIDs []uint64) func(tx *gorm.DB) *gorm.DB {
		return func(tx *gorm.DB) *gorm.DB {
			sql, args := buildGetLogsQuery(logtype, groupid, userid, logsubtype, dateStr, search, modmailsonly, 20, contextID, isAdmin, modGroupIDs)
			return tx.Raw(sql, args...)
		}
	}

	cases := []ormharness.WhereFieldwiseCase{
		{Name: "base", Build: build("", 0, 0, "", "", "", false, 0, true, nil)},
		{Name: "all", Build: build("messages", 10, 5, "", "7", "john", true, 100, true, nil)},

		{Name: "Modmailsonly", Build: build("", 0, 0, "", "", "", true, 0, true, nil)},
		{Name: "Date", Build: build("", 0, 0, "", "7", "", false, 0, true, nil)},
		{Name: "Context", Build: build("", 0, 0, "", "", "", false, 100, true, nil)},
		{Name: "Userid", Build: build("", 0, 5, "", "", "", false, 0, true, nil)},
		{Name: "Search", Build: build("", 0, 0, "", "", "john", false, 0, true, nil)},

		{Name: "LogType:TypeAndSubtypeFilter", Build: build("messages", 0, 0, "", "", "", false, 0, true, nil)},
		{Name: "LogType:UserExclusion", Build: build("user", 0, 0, "", "", "", false, 0, true, nil)},

		{Name: "GroupScope:GroupidExact", Build: build("", 10, 0, "", "", "", false, 0, true, nil)},
		{Name: "GroupScope:ModGroupList", Build: build("", 0, 0, "", "", "", false, 0, false, []uint64{1, 2, 3})},
	}

	ormharness.AssertGoldenWhereFieldwise(t, "6cf1b5aded22", cases)
}

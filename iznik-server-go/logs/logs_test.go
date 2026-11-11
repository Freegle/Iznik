package logs

import (
	"regexp"
	"strings"
	"testing"

	"github.com/freegle/iznik-server-go/log"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

const (
	clType     = "logs.type IN (?)"
	clSubtype  = "logs.subtype IN (?)"
	clUserExcl = "NOT (logs.type = 'User' AND logs.subtype IN ('Created', 'Merged'))"
	clModmail  = "((logs.type = ? AND logs.subtype IN (?, ?, ?)) OR (logs.type = ? AND logs.subtype IN (?, ?, ?)))"
	clDate     = "logs.timestamp >= ?"
	clContext  = "logs.id < ?"
	clUser     = "(logs.user = ? OR logs.byuser = ?)"
	clSearch   = "(users.firstname LIKE ? OR users.lastname LIKE ? OR users.fullname LIKE ? OR CONCAT(users.firstname, ' ', users.lastname) LIKE ? OR messages.subject LIKE ?)"
	joinUsers  = "LEFT JOIN users ON users.id = logs.user"
	tail       = "ORDER BY logs.id DESC LIMIT ?"
)

var allClauses = []string{clType, clSubtype, clUserExcl, clModmail, clDate, clContext, clUser, clSearch, joinUsers}

var (
	defaultMessageSubtypes = []string{log.LOG_SUBTYPE_RECEIVED, log.LOG_SUBTYPE_APPROVED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED, log.LOG_SUBTYPE_AUTO_REPOSTED, log.LOG_SUBTYPE_AUTO_APPROVED, log.LOG_SUBTYPE_OUTCOME, log.LOG_SUBTYPE_HOLD, log.LOG_SUBTYPE_RELEASE}
	defaultMemberSubtypes  = []string{log.LOG_SUBTYPE_JOINED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_APPROVED, log.LOG_SUBTYPE_APPLIED, log.LOG_SUBTYPE_AUTO_APPROVED, log.LOG_SUBTYPE_LEFT}
	modmailArgs            = []interface{}{log.LOG_TYPE_MESSAGE, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED, log.LOG_SUBTYPE_REPLIED, log.LOG_TYPE_USER, log.LOG_SUBTYPE_MAILED, log.LOG_SUBTYPE_REJECTED, log.LOG_SUBTYPE_DELETED}
	dateRE                 = regexp.MustCompile(`^\d{4}-\d{2}-\d{2}$`)
)

// in builds the full input set; rows override only what they care about.
type in struct {
	logtype      string
	userid       uint64
	logsubtype   string
	dateStr      string
	search       string
	modmailsonly bool
	limit        int
	contextID    uint64
	isAdmin      bool
}

func TestBuildGetLogsQuery(t *testing.T) {
	// "date" in want marks the position of the timestamp arg, which depends on time.Now().
	const date = "<date>"

	tests := []struct {
		name    string
		in      in
		present []string
		args    []interface{}
	}{
		{"default logtype empty, nothing set", in{limit: 20}, nil, []interface{}{20}},
		{"unknown logtype logins", in{logtype: "logins", limit: 5}, nil, []interface{}{5}},
		{"unknown logtype ignores logsubtype", in{logtype: "logins", logsubtype: "Edit", limit: 5}, nil, []interface{}{5}},

		{"messages default subtypes", in{logtype: "messages", limit: 10},
			[]string{clType, clSubtype}, []interface{}{[]string{log.LOG_TYPE_MESSAGE}, defaultMessageSubtypes, 10}},
		{"messages explicit subtype", in{logtype: "messages", logsubtype: "Edit", limit: 10},
			[]string{clType, clSubtype}, []interface{}{[]string{log.LOG_TYPE_MESSAGE}, []string{"Edit"}, 10}},
		{"memberships default subtypes", in{logtype: "memberships", limit: 10},
			[]string{clType, clSubtype}, []interface{}{[]string{log.LOG_TYPE_USER}, defaultMemberSubtypes, 10}},
		{"memberships explicit subtype", in{logtype: "memberships", logsubtype: "Left", limit: 10},
			[]string{clType, clSubtype}, []interface{}{[]string{log.LOG_TYPE_USER}, []string{"Left"}, 10}},
		{"user excludes Created/Merged, no types", in{logtype: "user", limit: 10},
			[]string{clUserExcl}, []interface{}{10}},
		{"user ignores logsubtype", in{logtype: "user", logsubtype: "Edit", limit: 10},
			[]string{clUserExcl}, []interface{}{10}},

		{"modmailsonly", in{modmailsonly: true, limit: 3}, []string{clModmail}, append(append([]interface{}{}, modmailArgs...), 3)},
		{"modmailsonly false", in{modmailsonly: false, limit: 3}, nil, []interface{}{3}},

		{"date 7", in{dateStr: "7", limit: 1}, []string{clDate}, []interface{}{date, 1}},
		{"date 0", in{dateStr: "0", limit: 1}, []string{clDate}, []interface{}{date, 1}},
		{"date negative omitted", in{dateStr: "-1", limit: 1}, nil, []interface{}{1}},
		{"date non-numeric treated as 0", in{dateStr: "abc", limit: 1}, []string{clDate}, []interface{}{date, 1}},
		{"date empty omitted", in{dateStr: "", limit: 1}, nil, []interface{}{1}},

		{"context set", in{contextID: 99, limit: 1}, []string{clContext}, []interface{}{uint64(99), 1}},
		{"userid set", in{userid: 5, limit: 1}, []string{clUser}, []interface{}{uint64(5), uint64(5), 1}},

		{"search adds join and like clause", in{search: "bob", limit: 1}, []string{joinUsers, clSearch},
			[]interface{}{"%bob%", "%bob%", "%bob%", "%bob%", "%bob%", 1}},

		{"everything at once", in{logtype: "messages", userid: 5, logsubtype: "Edit", dateStr: "2", search: "x",
			modmailsonly: true, limit: 50, contextID: 100, isAdmin: false},
			[]string{clType, clSubtype, clModmail, clDate, clContext, clUser, clSearch, joinUsers},
			append(append([]interface{}{[]string{log.LOG_TYPE_MESSAGE}, []string{"Edit"}}, modmailArgs...),
				date, uint64(100), uint64(5), uint64(5), "%x%", "%x%", "%x%", "%x%", "%x%", 50)},
		{"user logtype with search and userid", in{logtype: "user", userid: 8, search: "a b", limit: 4},
			[]string{clUserExcl, clUser, clSearch, joinUsers},
			[]interface{}{uint64(8), uint64(8), "%a b%", "%a b%", "%a b%", "%a b%", "%a b%", 4}},
	}

	for _, tc := range tests {
		t.Run(tc.name, func(t *testing.T) {
			i := tc.in
			q, args := buildGetLogsQuery(i.logtype, i.userid, i.logsubtype, i.dateStr, i.search, i.modmailsonly, i.limit, i.contextID, i.isAdmin)

			// Structure that never varies.
			assert.True(t, strings.HasPrefix(q, "SELECT logs.*"))
			assert.Contains(t, q, "FROM logs LEFT JOIN messages ON messages.id = logs.msgid ")
			assert.Contains(t, q, "WHERE 1=1")
			assert.True(t, strings.HasSuffix(q, tail), q)

			for _, c := range allClauses {
				if contains(tc.present, c) {
					assert.Contains(t, q, c)
				} else {
					assert.NotContains(t, q, c)
				}
			}

			// One placeholder per bind arg (slices count once; gorm expands them).
			assert.Equal(t, strings.Count(q, "?"), len(args))

			require.Len(t, args, len(tc.args))
			for n, want := range tc.args {
				if want == date {
					s, ok := args[n].(string)
					require.True(t, ok, "arg %d should be a date string", n)
					assert.Regexp(t, dateRE, s)
					continue
				}
				assert.Equal(t, want, args[n], "arg %d", n)
			}
			assert.Equal(t, i.limit, args[len(args)-1])
		})
	}
}

func contains(list []string, s string) bool {
	for _, l := range list {
		if l == s {
			return true
		}
	}
	return false
}

func TestBuildGetLogsQueryDateValues(t *testing.T) {
	_, a0 := buildGetLogsQuery("", 0, "", "0", "", false, 1, 0, true)
	_, a7 := buildGetLogsQuery("", 0, "", "7", "", false, 1, 0, true)
	_, abc := buildGetLogsQuery("", 0, "", "abc", "", false, 1, 0, true)

	assert.Equal(t, a0[0], abc[0], "non-numeric behaves as 0 days")
	assert.NotEqual(t, a0[0], a7[0], "7 days back differs from today")
	assert.Less(t, a7[0].(string), a0[0].(string))
}

func TestBuildGetLogsQueryClauseOrder(t *testing.T) {
	q, _ := buildGetLogsQuery("messages", 5, "", "1", "x", true, 9, 100, false)
	last := -1
	for _, c := range []string{"1=1", clType, clSubtype, clModmail, clDate, clContext, clUser, clSearch} {
		idx := strings.Index(q, c)
		require.Greater(t, idx, last, c)
		last = idx
	}
	// The users join comes before WHERE.
	assert.Less(t, strings.Index(q, joinUsers), strings.Index(q, "WHERE 1=1"))
}

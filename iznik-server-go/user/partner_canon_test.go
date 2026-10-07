package user

import (
	"testing"

	"github.com/stretchr/testify/assert"
)

// tnCanonTable is the cross-stack TN canon table. The SAME pairs are asserted in
// PHP by iznik-batch/tests/Unit/Models/UserEmailTest.php::tnCanonTable against
// User::canonMail (and through it IncomingMailService::canonicalizeEmail), so a
// change to either stack that the other does not make fails a test. Keep the
// two tables identical.
var tnCanonTable = []struct {
	name  string
	email string
	canon string
}{
	{"suffixed alias", "alice-g123@user.trashnothing.com", "alice@usertrashnothingcom"},
	{"bare dotted", "tricia.hayes@user.trashnothing.com", "tricia.hayes@usertrashnothingcom"},
	{"suffixed dotted", "tricia.hayes-g298@user.trashnothing.com", "tricia.hayes@usertrashnothingcom"},
	{"bare hyphenated", "mary-jane@user.trashnothing.com", "mary-jane@usertrashnothingcom"},
	{"suffixed hyphenated", "mary-jane-g12@user.trashnothing.com", "mary-jane@usertrashnothingcom"},
	{"bare hyphen-g word", "mary-grace@user.trashnothing.com", "mary-grace@usertrashnothingcom"},
	{"bare short prefix", "bibiana@user.trashnothing.com", "bibiana@usertrashnothingcom"},
	{"suffixed longer name", "bibiana-gomes-g4840@user.trashnothing.com", "bibiana-gomes@usertrashnothingcom"},
	{"only the last suffix", "ann-g12-g34@user.trashnothing.com", "ann-g12@usertrashnothingcom"},
	{"mixed case", "Mary-Jane-G12@User.TrashNothing.com", "mary-jane@usertrashnothingcom"},
}

func TestCanonicalizePartnerEmail_CrossStackTNTable(t *testing.T) {
	for _, tc := range tnCanonTable {
		t.Run(tc.name, func(t *testing.T) {
			assert.Equal(t, tc.canon, CanonicalizePartnerEmail(tc.email))
		})
	}
}

func TestTNAliasIdentity(t *testing.T) {
	tests := []struct {
		email    string
		username string
		domain   string
		ok       bool
	}{
		// Suffixed aliases, any partner domain.
		{"alice-g123@user.trashnothing.com", "alice", "user.trashnothing.com", true},
		{"bibiana-gomes-g4840@user.trashnothing.com", "bibiana-gomes", "user.trashnothing.com", true},
		{"ann-g12-g34@user.trashnothing.com", "ann-g12", "user.trashnothing.com", true},
		{"prefix-g1@test.com", "prefix", "test.com", true},
		// Bare TN addresses are the same member as their aliases.
		{"mary-jane@user.trashnothing.com", "mary-jane", "user.trashnothing.com", true},
		{"mary-grace@user.trashnothing.com", "mary-grace", "user.trashnothing.com", true},
		{"Tricia.Hayes@USER.trashnothing.com", "tricia.hayes", "user.trashnothing.com", true},
		// A bare address on any other domain is just an address.
		{"plain@test.com", "", "", false},
		{"mary-jane@example.com", "", "", false},
		{"not-an-address", "", "", false},
	}

	for _, tc := range tests {
		t.Run(tc.email, func(t *testing.T) {
			username, domain, ok := TNAliasIdentity(tc.email)
			assert.Equal(t, tc.ok, ok)
			assert.Equal(t, tc.username, username)
			assert.Equal(t, tc.domain, domain)
		})
	}
}

// The display name comes from the username alone: only a -g<digits> immediately
// before the @ is dropped, never everything after the first "-g".
func TestPartnerDisplayName(t *testing.T) {
	tests := []struct {
		email string
		want  string
	}{
		{"tricia.hayes@user.trashnothing.com", "Tricia Hayes"},
		{"tricia.hayes-g298@user.trashnothing.com", "Tricia Hayes"},
		{"bibiana-gomes-g4840@user.trashnothing.com", "Bibiana-Gomes"},
		{"mary-grace@user.trashnothing.com", "Mary-Grace"},
		{"mary_jane-g12@user.trashnothing.com", "Mary Jane"},
		{"john.doe@example.com", "John Doe"},
		{"noatsign", "Noatsign"},
	}

	for _, tc := range tests {
		t.Run(tc.email, func(t *testing.T) {
			assert.Equal(t, tc.want, partnerDisplayName(tc.email))
		})
	}
}

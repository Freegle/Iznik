package user

import (
	"errors"
	"log"
	"regexp"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailhygiene"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// ValidatePartnerKey validates a partner API key and returns the partner's details.
// Returns partnerID, partnerName, domain, and any error.
func ValidatePartnerKey(db *gorm.DB, key string) (uint64, string, string, error) {
	if key == "" {
		return 0, "", "", errors.New("empty partner key")
	}

	var result struct {
		ID      uint64 `gorm:"column:id"`
		Partner string `gorm:"column:partner"`
		Domain  string `gorm:"column:domain"`
	}

	err := db.Table("partners_keys").Select("id, partner, `domain`").Where("`key` = ?", key).Scan(&result).Error
	if err != nil {
		return 0, "", "", err
	}

	if result.ID == 0 {
		return 0, "", "", errors.New("invalid partner key")
	}

	return result.ID, result.Partner, result.Domain, nil
}

// FindByTNIdOrEmail looks up a user by tnuserid first, then by email as a fallback.
// Returns the user ID, or 0 if not found.
func FindByTNIdOrEmail(db *gorm.DB, tnuserid uint64, email string) uint64 {
	candidates := FindTNCandidates(db, tnuserid, email)
	if len(candidates) == 0 {
		return 0
	}
	return candidates[0]
}

// FindTNCandidates returns every distinct user id the partner's identifiers
// resolve to - the tnuserid mapping first, then the email. They are USUALLY
// the same account, but a TN member can end up with two Freegle accounts:
// one carrying the tnuserid stamp and another owning the TN email (seen live
// 2026-08-06 - a Promise 403'd "Not your message" because the message
// belonged to the email twin while tnuserid-first resolution picked the
// other). Callers acting on a specific message should act as whichever
// candidate owns it.
func FindTNCandidates(db *gorm.DB, tnuserid uint64, email string) []uint64 {
	var out []uint64

	if tnuserid > 0 {
		var userid uint64
		db.Table("users").Select("id").Where("tnuserid = ? AND deleted IS NULL", tnuserid).Scan(&userid)
		if userid > 0 {
			out = append(out, userid)
		}
	}

	if email != "" {
		var userid uint64
		db.Table("users_emails").Select("users_emails.userid").
			Joins("INNER JOIN users ON users.id = users_emails.userid").
			Where("users_emails.email = ? AND users.deleted IS NULL", email).
			Scan(&userid)
		if userid > 0 && (len(out) == 0 || out[0] != userid) {
			out = append(out, userid)
		}
	}

	return out
}

// HealTNDivergence merges a TN member's twin accounts when the partner's
// identifiers resolved to two different users. TN is asserting both belong to
// the same member (and the caller has verified the email is in the partner's
// own domain), so the divergence is a data fault, not ambiguity - typically
// minted when a TN username rename changed the member's per-group email alias
// and the mail ingest created a fresh account for it. The email twin is
// merged INTO the tnuserid twin via the same transaction the moderator merge
// uses: the stamp stays put, the alias and every message move across, and
// future mail routes to the merged account - the sync stops the divergence
// instead of tolerating it forever.
//
// Returns the updated candidate set: the single surviving id on success, or
// the original candidates if the merge fails (per-message owner arbitration
// still copes with the split).
func HealTNDivergence(db *gorm.DB, candidates []uint64) []uint64 {
	if len(candidates) < 2 {
		return candidates
	}

	keep, discard := candidates[0], candidates[1]
	if err := MergeUsersTx(db, discard, keep, keep); err != nil {
		log.Printf("TN divergence heal: merging twin %d into %d failed: %v", discard, keep, err)
		return candidates
	}

	log.Printf("TN divergence heal: merged twin account %d into %d", discard, keep)
	return []uint64{keep}
}

// FindPartnerOwnerForMessage returns the fromuser of a message when its fromaddr
// is in the given partner domain, or 0 otherwise. This mirrors V1's
// getRolesForMessages, where a partner with a valid key acquires owner rights on
// a message whose fromaddr is in the partner domain and then acts as its fromuser.
// The host comparison is case-insensitive and exact (stricter than V1's loose
// substring match, which would also accept prefix/suffix domains).
func FindPartnerOwnerForMessage(db *gorm.DB, domain string, msgID uint64) uint64 {
	if domain == "" || msgID == 0 {
		return 0
	}

	var result struct {
		Fromuser uint64 `gorm:"column:fromuser"`
		Fromaddr string `gorm:"column:fromaddr"`
	}
	db.Table("messages").Select("fromuser, fromaddr").Where("id = ?", msgID).Scan(&result)
	if result.Fromuser == 0 || result.Fromaddr == "" {
		return 0
	}

	at := strings.LastIndex(result.Fromaddr, "@")
	if at < 0 {
		return 0
	}
	host := result.Fromaddr[at+1:]
	if strings.EqualFold(host, domain) {
		return result.Fromuser
	}

	return 0
}

// CreatePartnerUser creates a new user for a partner integration.
// It extracts a display name from the email prefix (before -g or @),
// sets the tnuserid, and adds the email to users_emails.
func CreatePartnerUser(db *gorm.DB, tnuserid uint64, email string) (uint64, error) {
	if email == "" {
		return 0, errors.New("email is required")
	}

	// Extract name from email prefix: take part before -g or @ (whichever comes first).
	prefix := email
	if atIdx := strings.Index(prefix, "@"); atIdx >= 0 {
		prefix = prefix[:atIdx]
	}
	if gIdx := strings.Index(prefix, "-g"); gIdx >= 0 {
		prefix = prefix[:gIdx]
	}

	// Replace dots/underscores with spaces and title-case.
	name := strings.ReplaceAll(prefix, ".", " ")
	name = strings.ReplaceAll(name, "_", " ")
	name = strings.Title(name) //nolint:staticcheck

	// Plain, isolated, literal single-row
	// INSERT; id read back via GORM's map-Create "@id" writeback (proven in
	// test/insertid_gorm_writeback_test.go, same pattern shipped for over a
	// dozen sibling
	// sites - the "undocumented and untested" concern this site's keep-raw rule
	// used to cite no longer applies).
	row := map[string]interface{}{
		"fullname": name,
		"added":    gorm.Expr("NOW()"),
	}
	if err := db.Table("users").Create(row).Error; err != nil {
		return 0, err
	}
	lastIDInt, _ := row["@id"].(int64)
	if lastIDInt == 0 {
		return 0, errors.New("failed to create user")
	}
	userid := uint64(lastIDInt)

	// Set tnuserid.
	// Plain single-table UPDATE, no id
	// readback involved.
	if tnuserid > 0 {
		db.Table("users").Where("id = ?", userid).Update("tnuserid", tnuserid)
	}

	// Add email.
	// Plain, isolated, literal single-row
	// INSERT; no id readback needed here.
	// Observe only. None of the 32 bad addresses on file belong to a partner
	// account, against partners being 35.7% of members - so this arm is expected
	// to stay silent, and will say so if that is wrong.
	emailhygiene.Report(email, "user.partnerCreate", userid)
	// canon is what a PHP canon lookup matches on; backwards is what a domain
	// search matches on, and those want DIFFERENT strings. iznik-batch's
	// User::addEmail stores canonMail() and strrev(strtolower(email)) respectively;
	// storing reverseString(canon) here put a value in backwards that no domain
	// prefix search expects. See .claude/rules/mail-and-data.md.
	db.Table("users_emails").Create(map[string]interface{}{
		"userid":    userid,
		"email":     email,
		"preferred": gorm.Expr("1"),
		"added":     gorm.Expr("NOW()"),
		"canon":     CanonicalizePartnerEmail(email),
		"backwards": reverseString(strings.ToLower(email)),
	})

	return userid, nil
}

// EnsurePartnerIdentifiers back-fills whichever of the partner's identifiers
// the resolved account is missing - the prevention half of stopping TN
// divergence. After a TN username rename the sync presents the member's NEW
// per-group email alias alongside the same tnuserid; attaching the alias here
// means the next inbound mail routes to this account instead of the mail
// ingest minting a twin. Symmetrically, an account found by email gets the
// tnuserid stamp if it has none (the stamp is UNIQUE, so an already-claimed
// stamp is left alone - that split is HealTNDivergence's job; the unique
// index backstops the check-then-update race).
func EnsurePartnerIdentifiers(db *gorm.DB, userid, tnuserid uint64, email string) {
	if userid == 0 {
		return
	}

	if tnuserid > 0 {
		var claimed int64
		db.Table("users").Where("tnuserid = ?", tnuserid).Count(&claimed)
		if claimed == 0 {
			db.Table("users").Where("id = ? AND tnuserid IS NULL", userid).Update("tnuserid", tnuserid)
		}
	}

	if email != "" {
		var count int64
		db.Table("users_emails").Where("userid = ? AND email = ?", userid, email).Count(&count)
		if count == 0 {
			// See CreatePartnerUser: canon and backwards hold different strings.
			db.Clauses(clause.Insert{Modifier: "IGNORE"}).Table("users_emails").Create(map[string]interface{}{
				"userid":    userid,
				"email":     email,
				"preferred": gorm.Expr("0"),
				"added":     gorm.Expr("NOW()"),
				"canon":     CanonicalizePartnerEmail(email),
				"backwards": reverseString(strings.ToLower(email)),
			})
		}
	}
}

// FindPartnerByName looks up a partner by name (case-insensitive LIKE match).
// Returns the partner ID, or 0 if not found.
func FindPartnerByName(name string) uint64 {
	db := database.DBConn
	var partnerID uint64
	db.Table("partners_keys").Select("id").Where("partner LIKE ?", "%"+name+"%").Limit(1).Scan(&partnerID)
	return partnerID
}

// tnPartnerDomain is the only domain whose per-group aliases iznik-batch's
// canonicalizeEmail strips a -gNNNN suffix from, so it is the only one where Go
// may do the same and still agree with it.
const tnPartnerDomain = "user.trashnothing.com"

// tnAliasRegexp matches a partner per-group alias, <username>-g<groupid>@<domain>.
// Trash Nothing mints one address per TN GROUP, so a member active in several TN
// groups presents several addresses differing only in the -g part. It mirrors
// iznik-batch's TNSyncCommand::tnUsernameFromAddress so both stacks decide
// "same member" by the same rule.
var tnAliasRegexp = regexp.MustCompile(`^(.+)-g\d+@(.+)$`)

// TNAliasIdentity splits a per-group alias into the TN username and the domain.
func TNAliasIdentity(email string) (string, string, bool) {
	m := tnAliasRegexp.FindStringSubmatch(strings.ToLower(strings.TrimSpace(email)))
	if m == nil {
		return "", "", false
	}
	return m[1], m[2], true
}

// likeEscape neutralises the LIKE wildcards in a value used as a literal prefix,
// using MySQL's default backslash escape character.
func likeEscape(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, "%", `\%`)
	s = strings.ReplaceAll(s, "_", `\_`)
	return s
}

// FindTNSiblings returns the other live accounts holding a per-group alias of the
// same TN member as the address given, excluding the account that address itself
// belongs to.
//
// A TN member can own more than one Freegle account. Until the alias back-fill
// landed (2026-08-11) a second TN group's alias arriving for the first time minted
// a fresh account, and 96 such pairs are still live. A partner call carries only
// ONE of the member's aliases, so resolving on that alias and the tnuserid alone
// can miss the account that actually owns the post being acted on - which is how a
// Promise came back 403 "Not your message" (TN post 47243586, 2026-09-19).
func FindTNSiblings(db *gorm.DB, email string) []uint64 {
	username, domain, ok := TNAliasIdentity(email)
	if !ok {
		return nil
	}

	// Indexed prefix scan on the address. The trailing % runs on past the end of
	// the username, so "bibiana-g%" also matches "bibiana-gomes-g4840@...", a
	// different member - the LIKE only narrows, the exact-identity test below
	// decides. iznik-batch merged two unrelated members on 2026-09-13 for want of
	// that test, so it is not theoretical.
	like := likeEscape(username) + "-g%@" + likeEscape(domain)

	var rows []struct {
		Userid uint64 `gorm:"column:userid"`
		Email  string `gorm:"column:email"`
	}
	db.Table("users_emails").
		Select("users_emails.userid, users_emails.email").
		Joins("INNER JOIN users ON users.id = users_emails.userid").
		Where("users_emails.email LIKE ? AND users.deleted IS NULL", like).
		Scan(&rows)

	self := uint64(0)
	for _, r := range rows {
		if strings.EqualFold(strings.TrimSpace(r.Email), strings.TrimSpace(email)) {
			self = r.Userid
			break
		}
	}

	var out []uint64
	seen := map[uint64]bool{}
	for _, r := range rows {
		u, d, ok := TNAliasIdentity(r.Email)
		if !ok || u != username || d != domain {
			continue
		}
		if r.Userid == 0 || r.Userid == self || seen[r.Userid] {
			continue
		}
		seen[r.Userid] = true
		out = append(out, r.Userid)
	}

	return out
}

// WithTNSiblings adds the member's other accounts to the partner candidate set.
//
// Discovery only. The candidates are used by actAsOwnerCandidate to act as
// whichever account owns the message; they are deliberately NOT passed to
// HealTNDivergence, because merging the live backlog is a reviewed, one-way
// operation belonging to tn:sync, not to an inbound partner request.
func WithTNSiblings(db *gorm.DB, candidates []uint64, email string) []uint64 {
	siblings := FindTNSiblings(db, email)
	if len(siblings) == 0 {
		return candidates
	}

	have := map[uint64]bool{}
	for _, c := range candidates {
		have[c] = true
	}
	for _, s := range siblings {
		if have[s] {
			continue
		}
		have[s] = true
		candidates = append(candidates, s)
	}

	return candidates
}

// CanonicalizePartnerEmail returns the canon to STORE for a partner address.
//
// For a Trash Nothing per-group alias it reproduces iznik-batch's
// canonicalizeEmail exactly - strip the -gNNNN suffix, strip the dots in the
// domain - so every alias of one member canonicalises to one value and a PHP canon
// lookup can find the row. Go's general CanonicalizeEmail does neither, so rows it
// wrote were invisible to that lookup, which is the backstop that stops a member's
// second address minting a second account.
//
// Scoped to the TN domain on purpose: donation matching and social auth also look
// up on canon, so widening the general function would change who those match.
func CanonicalizePartnerEmail(email string) string {
	username, domain, ok := TNAliasIdentity(email)
	if !ok || domain != tnPartnerDomain {
		return CanonicalizeEmail(email)
	}
	return username + "@" + strings.ReplaceAll(domain, ".", "")
}

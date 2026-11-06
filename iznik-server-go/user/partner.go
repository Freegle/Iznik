package user

import (
	"errors"
	"fmt"
	"log"
	"regexp"
	"strings"
	"sync"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/emailhygiene"
	"github.com/getsentry/sentry-go"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

var (
	tnAliasReportMu sync.RWMutex
	tnAliasReporter = reportTNAliasToSentry
)

// ErrTNIdHeld says a deleted account still holds the tnuserid. users.tnuserid is unique, so no new
// account can have it until that one is forgotten. TnUserProvisioner skips a post for the same reason.
var ErrTNIdHeld = errors.New("a deleted account holds this tnuserid")

func reportTNAliasToSentry(msg string) {
	log.Println(msg)
	sentry.WithScope(func(scope *sentry.Scope) {
		scope.SetLevel(sentry.LevelWarning)
		sentry.CaptureMessage(msg)
	})
}

// SetTNAliasReporter replaces where a moved TN alias is reported, for tests, and returns a function
// that puts the previous one back.
func SetTNAliasReporter(r func(msg string)) (restore func()) {
	tnAliasReportMu.Lock()
	prev := tnAliasReporter
	tnAliasReporter = r
	tnAliasReportMu.Unlock()

	return func() {
		tnAliasReportMu.Lock()
		tnAliasReporter = prev
		tnAliasReportMu.Unlock()
	}
}

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
//
// Only a TN id says who someone is. A call naming a tnuserid resolves to the account
// holding it and to nothing else, so if none does there are no candidates, whoever
// holds the address. The address is only matched alone for a partner with no such id.
func FindTNCandidates(db *gorm.DB, tnuserid uint64, email string) []uint64 {
	var out []uint64

	if tnuserid > 0 {
		var userid uint64
		db.Table("users").Select("id").Where("tnuserid = ? AND deleted IS NULL", tnuserid).Scan(&userid)
		if userid == 0 {
			return nil
		}
		out = append(out, userid)
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

// HealTNDivergence deals with the partner's identifiers resolving to two different
// users: the account holding the tnuserid and another holding the address. TN is
// asserting both belong to the same member (and the caller has verified the email
// is in the partner's own domain), so the address goes to that member.
//
// Only a TN id says who someone is. The other account is never merged, because
// nothing proves it is this member's, whatever usernames or addresses it holds. It
// just loses the address. Its posts stay on it, and actAsOwnerCandidate lets TN act
// on them.
//
// Returns the updated candidate set: the account holding the tnuserid on success, or
// the original candidates if it fails (per-message owner arbitration still copes
// with the split).
func HealTNDivergence(db *gorm.DB, candidates []uint64, email string) []uint64 {
	if len(candidates) < 2 {
		return candidates
	}

	keep, other := candidates[0], candidates[1]
	if err := moveTNAlias(db, email, other, keep); err != nil {
		log.Printf("TN divergence heal: moving an alias from %d to %d failed: %v", other, keep, err)
		return candidates
	}

	return []uint64{keep}
}

// tnUsernames returns the distinct TN usernames among an account's addresses on a partner's domain.
// A username only ever refuses something. It never says who someone is.
func tnUsernames(db *gorm.DB, userid uint64, domain string) []string {
	var emails []string
	db.Table("users_emails").Where("userid = ?", userid).Pluck("email", &emails)

	seen := map[string]bool{}
	var usernames []string
	for _, email := range emails {
		username, d, ok := TNIdentity(email)
		if ok && d == domain && !seen[username] {
			seen[username] = true
			usernames = append(usernames, username)
		}
	}

	return usernames
}

// TNIdOf returns the TN id an account holds, or 0 if it holds none.
func TNIdOf(db *gorm.DB, userid uint64) uint64 {
	var held *uint64
	db.Table("users").Select("tnuserid").Where("id = ?", userid).Scan(&held)
	if held == nil {
		return 0
	}

	return *held
}

// NeedsTNId says whether a partner call has to name a tnuserid. TN always has its member's id, so
// a TN address on its own is a call that has not said who it is about. Other partners have no such
// id and are matched on the address.
func NeedsTNId(domain string, tnuserid uint64, email string) bool {
	return strings.EqualFold(domain, tnPartnerDomain) && tnuserid == 0 && email != ""
}

// holderOf returns the account holding an address, or 0. A deleted account counts: it proves no
// more than a live one, and only keeps an address until it is forgotten.
func holderOf(db *gorm.DB, email string) uint64 {
	var holder uint64
	db.Table("users_emails").Select("userid").Where("email = ?", email).Scan(&holder)

	return holder
}

// ResolvePartnerActor resolves a member acting on a post through the partner API. A member TN names
// who we have not met, on an address we know, gets an account of their own and takes the address,
// so that TN can act for them. With neither known there is nobody to act as, and nor is there while
// a deleted account holds the id.
func ResolvePartnerActor(db *gorm.DB, tnuserid uint64, email string) ([]uint64, error) {
	candidates := FindTNCandidates(db, tnuserid, email)

	if len(candidates) == 0 && tnuserid > 0 && email != "" && holderOf(db, email) != 0 {
		userid, err := CreatePartnerUser(db, tnuserid, email)
		if errors.Is(err, ErrTNIdHeld) {
			return nil, nil
		}
		if err != nil {
			return nil, err
		}

		return []uint64{userid}, nil
	}

	return HealTNDivergence(db, candidates, email), nil
}

// moveTNAlias moves one address to the account of the member TN says owns it, and reports the
// account it came from. users_emails.email is unique, so it cannot be on both. Posts and chats
// already filed stay where they are: tn:split-shared-account is the way to move those.
func moveTNAlias(db *gorm.DB, email string, from, to uint64) error {
	err := db.Transaction(func(tx *gorm.DB) error {
		var wasPreferred bool
		if err := tx.Table("users_emails").Select("preferred").
			Where("email = ? AND userid = ?", email, from).Row().Scan(&wasPreferred); err != nil {
			return err
		}

		var preferredOnTo int64
		if err := tx.Table("users_emails").Where("userid = ? AND preferred = 1", to).Count(&preferredOnTo).Error; err != nil {
			return err
		}

		preferred := "0"
		if preferredOnTo == 0 {
			preferred = "1"
		}
		if err := tx.Table("users_emails").Where("email = ? AND userid = ?", email, from).
			Updates(map[string]interface{}{"userid": to, "preferred": gorm.Expr(preferred)}).Error; err != nil {
			return err
		}

		if !wasPreferred {
			return nil
		}

		// isTN() reads the preferred address, so the account it left must keep one.
		var nextID uint64
		tx.Table("users_emails").Select("id").Where("userid = ?", from).Order("id ASC").Limit(1).Scan(&nextID)
		if nextID == 0 {
			return nil
		}

		return tx.Table("users_emails").Where("id = ?", nextID).Update("preferred", gorm.Expr("1")).Error
	})
	if err != nil {
		return err
	}

	tnAliasReportMu.RLock()
	report := tnAliasReporter
	tnAliasReportMu.RUnlock()
	report(fmt.Sprintf("TN alias moved from user %d to user %d, the member Trash Nothing named", from, to))

	return nil
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
		Fromuser uint64  `gorm:"column:fromuser"`
		Fromaddr string  `gorm:"column:fromaddr"`
		Tnpostid *string `gorm:"column:tnpostid"`
	}
	db.Table("messages").Select("fromuser, COALESCE(fromaddr, '') AS fromaddr, tnpostid").Where("id = ?", msgID).Scan(&result)

	// TN's post id says it is TN's. A post from the posts API has no fromaddr at all.
	if result.Fromuser != 0 && strings.EqualFold(domain, tnPartnerDomain) && result.Tnpostid != nil && *result.Tnpostid != "" {
		return result.Fromuser
	}

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
//
// The address may already be on another account. Holding it does not make that account this
// member's, so TN's word stands and the address moves here.
//
// Callers come here when no live account holds the tnuserid, so an account that does is a deleted
// one: ErrTNIdHeld, and nothing is written. An account made anyway could not have the id, so the
// next call would not find it and would make another.
func CreatePartnerUser(db *gorm.DB, tnuserid uint64, email string) (uint64, error) {
	if tnuserid > 0 {
		var held int64
		db.Table("users").Where("tnuserid = ?", tnuserid).Count(&held)
		if held > 0 {
			log.Printf("TN user %d not added: a deleted account holds the id", tnuserid)

			return 0, ErrTNIdHeld
		}
	}

	userid, err := createPartnerAccount(db, tnuserid, email)
	if err != nil {
		return 0, err
	}

	if holder := holderOf(db, email); holder != 0 && tnuserid > 0 {
		if err := moveTNAlias(db, email, holder, userid); err != nil {
			return 0, err
		}

		return userid, nil
	}

	// Add email.
	// Plain, isolated, literal single-row
	// INSERT; no id readback needed here.
	// Observe only. None of the 32 bad addresses on file belong to a partner
	// account, against partners being 35.7% of members - so this arm is expected
	// to stay silent, and will say so if that is wrong.
	emailhygiene.Report(email, "user.partnerCreate", userid)
	// backwards is REVERSE(canon), not REVERSE(email). V1's User::addEmail writes
	// strrev(canonMail($email)) at both its insert sites, and canonMail strips the
	// dots out of the domain on purpose ("the format we have historically used"), so
	// the canon-derived value is the column's definition and the majority of the
	// table. Fixing the canon above therefore fixes this too.
	// See .claude/rules/mail-and-data.md.
	db.Table("users_emails").Create(map[string]interface{}{
		"userid":    userid,
		"email":     email,
		"preferred": gorm.Expr("1"),
		"added":     gorm.Expr("NOW()"),
		"canon":     CanonicalizePartnerEmail(email),
		"backwards": reverseString(CanonicalizePartnerEmail(email)),
	})

	return userid, nil
}

// createPartnerAccount creates the user row, named from the address, with its tnuserid.
func createPartnerAccount(db *gorm.DB, tnuserid uint64, email string) (uint64, error) {
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
	// In the insert, so that losing a race for the unique id leaves no account behind without it.
	if tnuserid > 0 {
		row["tnuserid"] = tnuserid
	}
	if err := db.Table("users").Create(row).Error; err != nil {
		return 0, err
	}
	lastIDInt, _ := row["@id"].(int64)
	if lastIDInt == 0 {
		return 0, errors.New("failed to create user")
	}

	return uint64(lastIDInt), nil
}

// EnsurePartnerIdentifiers attaches the partner's address to the resolved account -
// the prevention half of stopping TN divergence. After a TN username rename the
// sync presents the member's NEW per-group email alias alongside the same tnuserid;
// attaching the alias here means the next inbound mail routes to this account
// instead of the mail ingest minting a twin. HealTNDivergence has already moved an
// alias held by a live account, and one held by a deleted account moves here. An
// account is never given a tnuserid for holding an address: only a TN id says who
// someone is.
func EnsurePartnerIdentifiers(db *gorm.DB, userid uint64, email string) {
	if userid == 0 {
		return
	}

	if email != "" {
		holder := holderOf(db, email)
		if holder != 0 && holder != userid {
			if err := moveTNAlias(db, email, holder, userid); err != nil {
				log.Printf("Moving an alias from %d to %d failed: %v", holder, userid, err)
			}
		}
		if holder == 0 {
			// See CreatePartnerUser: backwards is REVERSE(canon).
			db.Clauses(clause.Insert{Modifier: "IGNORE"}).Table("users_emails").Create(map[string]interface{}{
				"userid":    userid,
				"email":     email,
				"preferred": gorm.Expr("0"),
				"added":     gorm.Expr("NOW()"),
				"canon":     CanonicalizePartnerEmail(email),
				"backwards": reverseString(CanonicalizePartnerEmail(email)),
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

// TNIdentity is TNAliasIdentity plus a bare <username>@user.trashnothing.com address, which
// TrashNothing also delivers to and which iznik-batch gives a member it creates. A bare
// address on any other domain is an ordinary address, not a TrashNothing identity.
func TNIdentity(email string) (string, string, bool) {
	if username, domain, ok := TNAliasIdentity(email); ok {
		return username, domain, true
	}

	e := strings.ToLower(strings.TrimSpace(email))
	at := strings.LastIndex(e, "@")
	if at <= 0 || e[at+1:] != tnPartnerDomain {
		return "", "", false
	}

	return e[:at], e[at+1:], true
}

// CanonicalizePartnerEmail returns the canon to STORE for a partner address.
//
// A Trash Nothing per-group alias, or a bare username@ address on Trash Nothing's domain, has the
// -gNNNN suffix stripped and the dots in the domain removed, as iznik-batch's canonMail does, so
// every address of one member canonicalises to one value and a PHP canon lookup can find the row,
// which is the backstop that stops a member's second address minting a second account.
//
// CanonicalizeEmail now does the same for these addresses. The one difference is that this does
// not strip a "+" suffix from the username, which canonMail does for any address. Nothing in this
// repository says whether a TN username can contain "+": one that does would get a different canon
// here and in PHP.
func CanonicalizePartnerEmail(email string) string {
	username, domain, ok := TNIdentity(email)
	if !ok || domain != tnPartnerDomain {
		return CanonicalizeEmail(email)
	}
	return username + "@" + strings.ReplaceAll(domain, ".", "")
}

package user

import (
	"errors"
	"strings"

	"github.com/freegle/iznik-server-go/database"
	"gorm.io/gorm"
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

	err := db.Raw("SELECT id, partner, `domain` FROM partners_keys WHERE `key` = ?", key).Scan(&result).Error
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
	var userid uint64

	if tnuserid > 0 {
		db.Raw("SELECT id FROM users WHERE tnuserid = ?", tnuserid).Scan(&userid)
		if userid > 0 {
			return userid
		}
	}

	if email != "" {
		db.Raw("SELECT userid FROM users_emails WHERE email = ?", email).Scan(&userid)
	}

	return userid
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
	db.Raw("SELECT fromuser, fromaddr FROM messages WHERE id = ?", msgID).Scan(&result)
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

	// Use the underlying sql.DB to get LastInsertId() directly from the MySQL protocol
	// response — never issue a separate SELECT LAST_INSERT_ID() as it's unsafe under
	// parallel load (GORM's connection pool may assign a different connection).
	sqlDB, err := db.DB()
	if err != nil {
		return 0, err
	}

	sqlResult, err := sqlDB.Exec("INSERT INTO users (fullname, added) VALUES (?, NOW())", name)
	if err != nil {
		return 0, err
	}

	lastID, err := sqlResult.LastInsertId()
	if err != nil || lastID == 0 {
		return 0, errors.New("failed to create user")
	}
	userid := uint64(lastID)

	// Set tnuserid.
	if tnuserid > 0 {
		db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", tnuserid, userid)
	}

	// Add email.
	canon := CanonicalizeEmail(email)
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added, canon, backwards) VALUES (?, ?, 1, NOW(), ?, ?)",
		userid, email, canon, reverseString(canon))

	return userid, nil
}

// FindPartnerByName looks up a partner by name (case-insensitive LIKE match).
// Returns the partner ID, or 0 if not found.
func FindPartnerByName(name string) uint64 {
	db := database.DBConn
	var partnerID uint64
	db.Raw("SELECT id FROM partners_keys WHERE partner LIKE ? LIMIT 1", "%"+name+"%").Scan(&partnerID)
	return partnerID
}

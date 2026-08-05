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
	var userid uint64

	if tnuserid > 0 {
		db.Table("users").Select("id").Where("tnuserid = ?", tnuserid).Scan(&userid)
		if userid > 0 {
			return userid
		}
	}

	if email != "" {
		db.Table("users_emails").Select("userid").Where("email = ?", email).Scan(&userid)
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
	// test/orm_insertid_test.go, same pattern shipped for over a dozen sibling
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
	canon := CanonicalizeEmail(email)
	db.Table("users_emails").Create(map[string]interface{}{
		"userid":    userid,
		"email":     email,
		"preferred": gorm.Expr("1"),
		"added":     gorm.Expr("NOW()"),
		"canon":     canon,
		"backwards": reverseString(canon),
	})

	return userid, nil
}

// FindPartnerByName looks up a partner by name (case-insensitive LIKE match).
// Returns the partner ID, or 0 if not found.
func FindPartnerByName(name string) uint64 {
	db := database.DBConn
	var partnerID uint64
	db.Table("partners_keys").Select("id").Where("partner LIKE ?", "%"+name+"%").Limit(1).Scan(&partnerID)
	return partnerID
}

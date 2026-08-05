package user

import (
	"fmt"
	"regexp"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type UserEmail struct {
	ID        uint64     `json:"id" gorm:"primary_key"`
	Added     time.Time  `json:"added"`
	Bounced   *time.Time `json:"bounced"`
	Ourdomain int        `json:"ourdomain"`
	Preferred int        `json:"preferred"`
	Email     string     `json:"email"`
}

func getEmails(id uint64) []UserEmail {
	db := database.DBConn

	var emails []UserEmail

	db.Table("users_emails").Select("id, added, bounced, preferred, email").
		Where("userid = ?", id).Order("preferred DESC, email ASC").Scan(&emails)

	for ix, e := range emails {
		emails[ix].Ourdomain = utils.OurDomain(e.Email)
	}

	return emails
}

// GetOrCreateInternalEmail returns the @users.ilovefreegle.org email for a user,
// creating and storing one if none exists with the correct user ID embedded.
// This is the V1 inventEmail() equivalent used by partner integrations (e.g. Trash Nothing)
// to identify users without exposing their real email addresses.
func GetOrCreateInternalEmail(db *gorm.DB, id uint64) string {
	domain := utils.USER_DOMAIN

	// Look for an existing internal email that encodes this specific user ID
	// (format: {local}-{id}@users.ilovefreegle.org).
	var email string
	db.Table("users_emails").Select("email").Where("userid = ? AND email LIKE ?",
		id, fmt.Sprintf("%%-%d@%s", id, domain)).Order("preferred DESC").Limit(1).Scan(&email)

	// Validate the returned email actually ends with -{id}@domain (not a merged user's ID).
	suffix := fmt.Sprintf("-%d@%s", id, domain)
	if email != "" && strings.HasSuffix(email, suffix) {
		return email
	}

	// None found with the correct ID — generate and persist one.
	var displayname string
	db.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", id).Scan(&displayname)

	local := SanitiseEmailLocal(displayname)
	if local == "" {
		local = fmt.Sprintf("freegler%d", id)
	}
	email = fmt.Sprintf("%s-%d@%s", local, id, domain)

	db.Table("users_emails").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"userid":       id,
		"email":        email,
		"preferred":    gorm.Expr("0"),
		"added":        gorm.Expr("NOW()"),
		"validatetime": gorm.Expr("NOW()"),
	})

	return email
}

var nonAlphaNum = regexp.MustCompile(`[^a-zA-Z0-9]`)

// SanitiseEmailLocal strips non-alphanumeric characters, lowercases, and truncates to 16 chars.
func SanitiseEmailLocal(name string) string {
	result := strings.ToLower(nonAlphaNum.ReplaceAllString(name, ""))
	if len(result) > 16 {
		result = result[:16]
	}
	return result
}

package test

import (
	"testing"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"github.com/stretchr/testify/assert"
)

func TestValidatePartnerKeyValid(t *testing.T) {
	prefix := uniquePrefix("partner_valid")
	db := database.DBConn

	db.Exec("INSERT INTO partners_keys (partner, `key`, domain) VALUES (?, ?, ?)",
		prefix+"_partner", prefix+"_key", "test.com")

	partnerID, partnerName, domain, err := user.ValidatePartnerKey(db, prefix+"_key")
	assert.NoError(t, err)
	assert.Greater(t, partnerID, uint64(0))
	assert.Equal(t, prefix+"_partner", partnerName)
	assert.Equal(t, "test.com", domain)
}

func TestValidatePartnerKeyInvalid(t *testing.T) {
	db := database.DBConn

	_, _, _, err := user.ValidatePartnerKey(db, "nonexistent_key_xyz")
	assert.Error(t, err)
}

func TestFindByTNIdOrEmailByTNId(t *testing.T) {
	prefix := uniquePrefix("partner_findtn")
	db := database.DBConn

	userID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("UPDATE users SET tnuserid = ? WHERE id = ?", 77777, userID)

	found := user.FindByTNIdOrEmail(db, 77777, "")
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailByEmail(t *testing.T) {
	prefix := uniquePrefix("partner_findem")
	db := database.DBConn

	email := prefix + "@test.com"
	userID := CreateTestUser(t, prefix+"_user", "User")
	db.Exec("INSERT INTO users_emails (userid, email, preferred, added) VALUES (?, ?, 1, NOW())", userID, email)

	found := user.FindByTNIdOrEmail(db, 0, email)
	assert.Equal(t, userID, found)
}

func TestFindByTNIdOrEmailNotFound(t *testing.T) {
	db := database.DBConn

	found := user.FindByTNIdOrEmail(db, 0, "nonexistent_999@test.com")
	assert.Equal(t, uint64(0), found)
}

func TestCreatePartnerUser(t *testing.T) {
	prefix := uniquePrefix("partner_create")
	db := database.DBConn

	email := prefix + "-gtest@example.com"
	userID, err := user.CreatePartnerUser(db, 88888, email)
	assert.NoError(t, err)
	assert.Greater(t, userID, uint64(0))

	// Verify tnuserid was set.
	var tnuserid uint64
	db.Raw("SELECT COALESCE(tnuserid, 0) FROM users WHERE id = ?", userID).Scan(&tnuserid)
	assert.Equal(t, uint64(88888), tnuserid)

	// Verify email was added.
	var emailCount int64
	db.Raw("SELECT COUNT(*) FROM users_emails WHERE userid = ? AND email = ?", userID, email).Scan(&emailCount)
	assert.Equal(t, int64(1), emailCount)

	// Verify name was extracted from email prefix (before -g).
	// The name extraction replaces underscores with spaces and title-cases.
	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", userID).Scan(&fullname)
	assert.NotEmpty(t, fullname, "Name should be extracted from email")
}

func TestCreatePartnerUserNameFromAtSign(t *testing.T) {
	db := database.DBConn

	email := "john.doe@example.com"
	userID, err := user.CreatePartnerUser(db, 0, email)
	assert.NoError(t, err)
	assert.Greater(t, userID, uint64(0))

	var fullname string
	db.Raw("SELECT fullname FROM users WHERE id = ?", userID).Scan(&fullname)
	assert.Equal(t, "John Doe", fullname)
}

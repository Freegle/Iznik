package session

import (
	"fmt"
	stdlog "log"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
)

// saveProfileImage inserts a profile picture URL for a user.
// GetProfileRecord uses ORDER BY id DESC LIMIT 1, so the latest INSERT is always shown.
// Only called when a real (non-silhouette) picture URL is available.
func saveProfileImage(userID uint64, pictureURL string) {
	if pictureURL == "" {
		return
	}
	database.DBConn.Exec(
		"INSERT INTO users_images (userid, url, `default`, contenttype) VALUES (?, ?, 0, 'image/jpeg')",
		userID, pictureURL,
	)
}

// socialMatchOrCreate finds an existing user by email or social login UID,
// creates a new one if needed, and returns the user ID.
// loginType should be utils.LOGIN_TYPE_GOOGLE or utils.LOGIN_TYPE_FACEBOOK.
func socialMatchOrCreate(loginType, uid, email, firstname, lastname, fullname string) (uint64, error) {
	db := database.DBConn

	var emailUserID uint64
	var loginUserID uint64

	// Find existing user by email.
	if email != "" {
		db.Raw("SELECT u.id FROM users u "+
			"JOIN users_emails ue ON ue.userid = u.id "+
			"WHERE ue.email = ? "+
			"LIMIT 1", email).Scan(&emailUserID)
	}

	// Find existing user by social login UID.
	db.Raw("SELECT userid FROM users_logins WHERE type = ? AND uid = ? LIMIT 1",
		loginType, uid).Scan(&loginUserID)

	// If both found and different, log it but pick the email user (PHP parity).
	if emailUserID > 0 && loginUserID > 0 && emailUserID != loginUserID {
		stdlog.Printf("Social login conflict: %s uid=%s matches user %d but email %s matches user %d; using email user",
			loginType, uid, loginUserID, email, emailUserID)
	}

	// Pick the user ID: prefer email match, then login match.
	userID := emailUserID
	if userID == 0 {
		userID = loginUserID
	}

	// If we found a user by email but not by social login, check for TN user.
	if userID > 0 && loginUserID == 0 {
		var tnUserID *uint64
		db.Raw("SELECT tnuserid FROM users WHERE id = ?", userID).Scan(&tnUserID)
		if tnUserID != nil && *tnUserID > 0 {
			return 0, fmt.Errorf("user %d is a TN user and cannot use %s login", userID, loginType)
		}
	}

	if userID == 0 {
		// Create new user.  Use the underlying sql.DB to get LastInsertId()
		// directly from the MySQL protocol response — never issue a separate
		// SELECT LAST_INSERT_ID() as it's unsafe under parallel load (GORM's
		// connection pool may assign a different connection).
		sqlDB, err := db.DB()
		if err != nil {
			return 0, fmt.Errorf("failed to get sql.DB: %w", err)
		}

		sqlResult, err := sqlDB.Exec("INSERT INTO users (fullname, firstname, lastname, added) VALUES (?, ?, ?, NOW())",
			fullname, firstname, lastname)
		if err != nil {
			return 0, fmt.Errorf("failed to create user: %w", err)
		}

		lastID, err := sqlResult.LastInsertId()
		if err != nil || lastID == 0 {
			return 0, fmt.Errorf("failed to get new user ID")
		}
		userID = uint64(lastID)

		// Add email if provided.
		if email != "" {
			canon := user.CanonicalizeEmail(email)
			db.Exec("INSERT INTO users_emails (userid, email, preferred, validated, canon, backwards) VALUES (?, ?, 0, NOW(), ?, ?)",
				userID, email, canon, user.ReverseString(canon))
		}

		// Add social login record.
		db.Exec("INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?, ?, ?)",
			userID, loginType, uid)
	} else {
		// User exists. Ensure they have the email and social login records.
		if email != "" && emailUserID == 0 {
			// They logged in via social UID but we don't have this email yet.
			canon := user.CanonicalizeEmail(email)
			db.Exec("INSERT IGNORE INTO users_emails (userid, email, preferred, validated, canon, backwards) VALUES (?, ?, 0, NOW(), ?, ?)",
				userID, email, canon, user.ReverseString(canon))
		}

		if loginUserID == 0 {
			// They were found by email but don't have a social login record yet.
			db.Exec("INSERT IGNORE INTO users_logins (userid, type, uid) VALUES (?, ?, ?)",
				userID, loginType, uid)
		}
	}

	// Update last access on the social login record.
	db.Exec("UPDATE users_logins SET lastaccess = NOW() WHERE userid = ? AND type = ?",
		userID, loginType)

	// Update name if missing.
	if fullname != "" {
		db.Exec("UPDATE users SET firstname = ?, lastname = ?, fullname = ? WHERE id = ? AND (fullname IS NULL OR fullname = '')",
			firstname, lastname, fullname, userID)
	}

	return userID, nil
}

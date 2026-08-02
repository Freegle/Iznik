package session

import (
	"fmt"
	stdlog "log"

	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/user"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

// saveProfileImage inserts a profile picture URL for a user.
// GetProfileRecord uses ORDER BY id DESC LIMIT 1, so the latest INSERT is always shown.
// Only called when a real (non-silhouette) picture URL is available.
//
// ORM migration site 9ccb23bbcdaf (wave 2). Golden column order (userid, url,
// default, contenttype) is not alphabetical, but normaliseColumnOrder sorts
// both sides' columns together with their values before comparing
// (ormharness/normalise_test.go TestNormaliseColumnOrder_Insert); the two
// literal values (0, 'image/jpeg') go through gorm.Expr so they render inline
// rather than as binds.
func saveProfileImage(userID uint64, pictureURL string) {
	if pictureURL == "" {
		return
	}
	database.DBConn.Table("users_images").Create(map[string]interface{}{
		"userid":      userID,
		"url":         pictureURL,
		"default":     gorm.Expr("0"),
		"contenttype": gorm.Expr("'image/jpeg'"),
	})
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
		// ORM migration site 242735a48039 (wave 4).
		db.Table("users u").
			Select("u.id").
			Joins("JOIN users_emails ue ON ue.userid = u.id").
			Where("ue.email = ?", email).
			Limit(1).
			Scan(&emailUserID)
	}

	// Find existing user by social login UID.
	// ORM migration site 304e1cc5f2e5 (wave 1).
	db.Table("users_logins").Select("userid").Where("type = ? AND uid = ?", loginType, uid).
		Limit(1).Scan(&loginUserID)

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
		// ORM migration site 64602d672727 (wave 1).
		db.Table("users").Select("tnuserid").Where("id = ?", userID).Scan(&tnUserID)
		if tnUserID != nil && *tnUserID > 0 {
			return 0, fmt.Errorf("user %d is a TN user and cannot use %s login", userID, loginType)
		}
	}

	if userID == 0 {
		// Create new user.  Use the underlying sql.DB to get LastInsertId()
		// directly from the MySQL protocol response — never issue a separate
		// SELECT LAST_INSERT_ID() as it's unsafe under parallel load (GORM's
		// connection pool may assign a different connection).
		//
		// ORM migration site bbbc465b075c (insertid-conv). Table()+map
		// Create reads the generated id back from the SAME sql.Result the
		// INSERT returned (gorm.io/gorm/callbacks/create.go), writing it
		// into the map under "@id" - no separate connection-scoped query,
		// so no connection to lose. Proven against the real database in
		// test/orm_insertid_test.go. Column order in the map doesn't matter
		// either way: normaliseColumnOrder (ormharness) pairs each column
		// with its value before comparing against the golden.
		row := map[string]interface{}{
			"fullname":  fullname,
			"firstname": firstname,
			"lastname":  lastname,
			"added":     gorm.Expr("NOW()"),
		}
		if err := db.Table("users").Create(row).Error; err != nil {
			return 0, fmt.Errorf("failed to create user: %w", err)
		}

		lastID, _ := row["@id"].(int64)
		if lastID == 0 {
			return 0, fmt.Errorf("failed to get new user ID")
		}
		userID = uint64(lastID)

		// Add email if provided.
		// ORM migration site f6bd87f2df8e (wave 2). Golden column order not
		// alphabetical, but normaliseColumnOrder handles the map-Create
		// reorder; see TestNormaliseColumnOrder_Insert.
		if email != "" {
			canon := user.CanonicalizeEmail(email)
			db.Table("users_emails").Create(map[string]interface{}{
				"userid":    userID,
				"email":     email,
				"preferred": gorm.Expr("0"),
				"validated": gorm.Expr("NOW()"),
				"canon":     canon,
				"backwards": user.ReverseString(canon),
			})
		}

		// Add social login record.
		// ORM migration site c86a6d9efeb3 (wave 3).
		db.Table("users_logins").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"userid": userID,
			"type":   loginType,
			"uid":    uid,
		})
	} else {
		// User exists. Ensure they have the email and social login records.
		if email != "" && emailUserID == 0 {
			// They logged in via social UID but we don't have this email yet.
			canon := user.CanonicalizeEmail(email)
			// ORM migration site 1013ca206ab1 (wave 3).
			db.Table("users_emails").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
				"userid":    userID,
				"email":     email,
				"preferred": gorm.Expr("0"),
				"validated": gorm.Expr("NOW()"),
				"canon":     canon,
				"backwards": user.ReverseString(canon),
			})
		}

		if loginUserID == 0 {
			// They were found by email but don't have a social login record yet.
			// ORM migration site 9cb4eab12012 (wave 3).
			db.Table("users_logins").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
				"userid": userID,
				"type":   loginType,
				"uid":    uid,
			})
		}
	}

	// Update last access on the social login record.
	// ORM migration site cdcc4a38c65d (wave 2).
	db.Table("users_logins").Where("userid = ? AND type = ?", userID, loginType).
		Update("lastaccess", gorm.Expr("NOW()"))

	// Update name if missing.
	// ORM migration site 994ffacdcb47 (wave 2). None of these three assignments
	// reference another assigned column (all plain binds), so the SET order is
	// not load-bearing and GORM's alphabetical Updates(map) order is safe; see
	// check-set-order.sh / setOrderIsLoadBearing.
	if fullname != "" {
		db.Table("users").Where("id = ? AND (fullname IS NULL OR fullname = '')", userID).
			Updates(map[string]interface{}{
				"firstname": firstname,
				"lastname":  lastname,
				"fullname":  fullname,
			})
	}

	return userID, nil
}

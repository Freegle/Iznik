package user

import "gorm.io/gorm"

// SaveAboutMe records a member's "about me" text. users_aboutme keeps a history,
// one row per change, and readers take the latest. The settings forms send
// aboutme on every save whether or not it changed, so a save whose text matches
// the current entry adds nothing.
func SaveAboutMe(db *gorm.DB, userid uint64, text string) {
	var latest []struct {
		Text *string
	}
	db.Table("users_aboutme").Select("text").Where("userid = ?", userid).
		Order("timestamp DESC, id DESC").Limit(1).Scan(&latest)
	if len(latest) > 0 && latest[0].Text != nil && *latest[0].Text == text {
		return
	}

	db.Table("users_aboutme").Create(map[string]interface{}{
		"userid":    userid,
		"text":      text,
		"timestamp": gorm.Expr("NOW()"),
	})
}

package user

import (
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/utils"
	"sync"
	"time"
)

type Ratings struct {
	Up   uint64
	Down uint64
	Mine string
}

type Publiclocation struct {
	Display   string `json:"display"`
	Groupid   uint64 `json:"groupid"`
	Groupname string `json:"groupname"`
	Location  string `json:"location"`
}

type PrivatePosition struct {
	Lat  float32 `json:"lat"`
	Lng  float32 `json:"lng"`
	Name string  `json:"name,omitempty"`
	Loc  string  `json:"loc,omitempty"`
}

type UserInfo struct {
	Replies         uint64          `json:"replies"`
	Repliesoffer    uint64          `json:"repliesoffer"`
	Replieswanted   uint64          `json:"replieswanted"`
	Taken           uint64          `json:"taken"`
	Reneged         uint64          `json:"reneged"`
	Collected       uint64          `json:"collected"`
	Offers          uint64          `json:"offers"`
	Wanteds         uint64          `json:"wanteds"`
	Openoffers      uint64          `json:"openoffers"`
	Openwanteds     uint64          `json:"openwanteds"`
	Expectedreply   uint64          `json:"expectedreply"`
	Expectedreplies uint64          `json:"expectedreplies"`
	Openage         uint64          `json:"openage"`
	Replytime       uint64          `json:"replytime"`
	Ratings         Ratings         `json:"ratings" gorm:"-"`
	Publiclocation  *Publiclocation `json:"publiclocation,omitempty" gorm:"-"`
}

func GetUserInfo(id uint64, myid uint64) UserInfo {
	db := database.DBConn

	var info UserInfo
	var mu sync.Mutex

	info.Replies = 0
	info.Reneged = 0
	info.Collected = 0
	info.Openage = utils.OPEN_AGE

	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	var wg sync.WaitGroup

	wg.Add(1)
	go func() {
		defer wg.Done()
		// Count replies, split by message type (Offer vs Wanted).
		type replyCount struct {
			Count   uint64
			Msgtype string
		}
		var counts []replyCount
		// ORM migration site 48b377098859 (wave 4).
		db.Table("chat_messages cm").
			Select("COUNT(DISTINCT cm.refmsgid) AS count, m.type AS msgtype").
			Joins("INNER JOIN messages m ON m.id = cm.refmsgid").
			Where("cm.userid = ? AND cm.date > ? AND cm.refmsgid IS NOT NULL AND cm.type = ?", id, start, utils.CHAT_MESSAGE_INTERESTED).
			Group("m.type").
			Scan(&counts)
		mu.Lock()
		defer mu.Unlock()
		for _, c := range counts {
			info.Replies += c.Count
			switch c.Msgtype {
			case utils.OFFER:
				info.Repliesoffer = c.Count
			case utils.WANTED:
				info.Replieswanted = c.Count
			}
		}
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		// ORM migration site 9088f1449d32 (wave 1).
		res := db.Table("messages_reneged").Select("COUNT(DISTINCT(messages_reneged.msgid)) AS reneged").Where("userid = ? AND timestamp > ?", id, start)
		var info2 UserInfo
		res.Scan(&info2)
		mu.Lock()
		defer mu.Unlock()
		info.Reneged = info2.Reneged
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		// ORM migration site fe953b2b8ed1 (wave 4).
		res := db.Table("messages_by").
			Select("COUNT(DISTINCT messages_by.msgid) AS collected").
			Joins("INNER JOIN messages ON messages.id = messages_by.msgid").
			Joins("INNER JOIN chat_messages ON chat_messages.refmsgid = messages.id AND messages.type = ? AND chat_messages.type = ?",
				utils.OFFER, utils.CHAT_MESSAGE_INTERESTED).
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Where("chat_messages.userid = ? AND messages_by.userid = ? AND messages_by.userid != messages.fromuser AND messages_groups.arrival >= ?",
				id, id, start)
		var info2 UserInfo
		res.Scan(&info2)
		mu.Lock()
		defer mu.Unlock()
		info.Collected = info2.Collected
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()

		// COUNT(DISTINCT messages.id), not COUNT(*): rippling-out adds a messages_groups
		// row (rippled_in = 1) per group a post ripples into, and genuine cross-posting
		// adds one origin row (rippled_in = 0) per group posted to directly - either way
		// the join fans out to multiple rows per message. Without the DISTINCT a single
		// post reaching N groups inflated the Offers/Wanteds (and Openoffers/Openwanteds)
		// counts by a factor of N. Same rippling pattern as the dashboard Popular Posts
		// and mygroups counts (0e639acdf, 9fda94a29).
		// ORM migration site ae4e50c81157 (wave 4).
		rows, _ := db.Table("messages").
			Select("COUNT(DISTINCT messages.id) AS count, messages.type, messages_outcomes.outcome").
			Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
			Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
			Where("fromuser = ? AND messages.arrival > ? AND collection = ? AND messages_groups.deleted = 0", id, start, utils.COLLECTION_APPROVED).
			Group("messages.type, messages_outcomes.outcome").
			Rows()

		if rows != nil {
			defer rows.Close()

			for rows.Next() {
				type countRow struct {
					Count   uint64
					Type    string
					Outcome string
				}

				var cr countRow

				db.ScanRows(rows, &cr)

				mu.Lock()

				switch cr.Type {
				case utils.OFFER:
					info.Offers += cr.Count

					if len(cr.Outcome) == 0 {
						info.Openoffers += cr.Count
					}
				case utils.WANTED:
					info.Wanteds += cr.Count

					if len(cr.Outcome) == 0 {
						info.Openwanteds += cr.Count
					}
				}

				mu.Unlock()
			}
		}
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		// No need to check on the chat room type as we can only get messages of type Interested in a User2User chat.
		// ORM migration site 7c2a4a892c17 (wave 1).
		res := db.Table("users_replytime").Select("replytime").Where("userid = ?", id)
		var info2 UserInfo
		res.Scan(&info2)
		mu.Lock()
		defer mu.Unlock()
		info.Replytime = info2.Replytime
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		// No need to check on the chat room type as we can only get messages of type Interested in a User2User chat.
		start := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")

		// ORM migration site cd31abc88595 (wave 5).
		res := db.Table("users_expected").
			Select("COUNT(*) AS expectedreply").
			Joins("INNER JOIN users ON users.id = users_expected.expectee").
			Joins("INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid").
			Where("expectee = ? AND chat_messages.date >= ? AND replyexpected = 1 AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ?",
				id, start, utils.CHAT_REPLY_GRACE)
		var info2 UserInfo
		res.Scan(&info2)
		mu.Lock()
		defer mu.Unlock()
		info.Expectedreply = info2.Expectedreply
		info.Expectedreplies = info2.Expectedreply
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()

		// We show visible ratings, ones we have made ourselves, or those from TN.
		type Count struct {
			Count  uint64
			Rating string
		}

		var counts []Count

		start := time.Now().AddDate(0, 0, -utils.RATINGS_PERIOD).Format("2006-01-02")
		// ORM migration site 7069b88d6489 (wave 1).
		res := db.Table("ratings").Select("COUNT(*) AS count, rating").
			Where("ratee = ? AND timestamp >= ? AND (tn_rating_id IS NOT NULL OR rater = ? OR visible = 1)", id, start, myid).
			Group("rating")
		res.Scan(&counts)

		mu.Lock()
		defer mu.Unlock()

		for _, count := range counts {
			if count.Rating == utils.RATING_UP {
				info.Ratings.Up = count.Count
			} else if count.Rating == utils.RATING_DOWN {
				info.Ratings.Down = count.Count
			}
		}
	}()

	if myid > 0 {
		wg.Add(1)
		go func() {
			defer wg.Done()

			// We show visible ratings, ones we have made ourselves, or those from TN.
			type Count struct {
				Count  uint64
				Rating string
			}

			var counts []Count

			start := time.Now().AddDate(0, 0, -utils.RATINGS_PERIOD).Format("2006-01-02")
			// ORM migration site 3c5d23f03c54 (wave 1).
			res := db.Table("ratings").Select("rating").Where("rater = ? AND ratee = ? AND timestamp >= ?", myid, id, start)
			res.Scan(&counts)

			mu.Lock()
			defer mu.Unlock()

			for _, count := range counts {
				info.Ratings.Mine = count.Rating
			}
		}()
	}

	wg.Wait()

	return info
}

// GetPublicLocationForUser returns the public location for a user, derived from their
// lastlocation or most recent group membership.
func GetPublicLocationForUser(userid uint64) *Publiclocation {
	db := database.DBConn

	// Use settings.mylocation.area.name first for the public location display.
	var areaName *string
	// ORM migration site f66d831e859e (wave 1).
	db.Table("users").Select("JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.area'), '$.name'))").
		Where("id = ? AND settings IS NOT NULL", userid).Scan(&areaName)

	if areaName != nil && *areaName != "" && *areaName != "null" {
		return &Publiclocation{
			Display:  *areaName,
			Location: *areaName,
		}
	}

	// Fall back to lastlocation area name (find the parent area of the postcode).
	var locName string
	// ORM migration site 60fa78a45347 (wave 4).
	db.Table("users u").
		Select("l2.name").
		Joins("INNER JOIN locations l1 ON l1.id = u.lastlocation").
		Joins("INNER JOIN locations l2 ON l2.id = l1.areaid").
		Where("u.id = ? AND u.lastlocation IS NOT NULL", userid).
		Limit(1).
		Scan(&locName)

	if locName != "" {
		return &Publiclocation{
			Display:  locName,
			Location: locName,
		}
	}

	// Fall back to most recent group membership.
	var groupLoc struct {
		Groupid   uint64
		Groupname string
	}
	// ORM migration site 1056062f1f8f (wave 4).
	db.Table("memberships m").
		Select("m.groupid, COALESCE(g.namefull, g.nameshort) AS groupname").
		Joins("INNER JOIN `groups` g ON g.id = m.groupid").
		Where("m.userid = ? AND m.collection = ?", userid, utils.COLLECTION_APPROVED).
		Order("m.added DESC").
		Limit(1).
		Scan(&groupLoc)

	if groupLoc.Groupid > 0 {
		return &Publiclocation{
			Display:   groupLoc.Groupname,
			Location:  groupLoc.Groupname,
			Groupid:   groupLoc.Groupid,
			Groupname: groupLoc.Groupname,
		}
	}

	return nil
}

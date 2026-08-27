package user

import (
	"crypto/rand"
	"crypto/sha1"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"log"
	"math"
	"math/big"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/location"
	log2 "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
)

type Aboutme struct {
	Text      string    `json:"text"`
	Timestamp time.Time `json:"timestamp"`
}

type User struct {
	ID              uint64      `json:"id" gorm:"primary_key"`
	Firstname       *string     `json:"firstname"`
	Lastname        *string     `json:"lastname"`
	Fullname        *string     `json:"fullname"`
	Displayname     string      `json:"displayname" gorm:"-"`
	Profile         UserProfile `json:"profile" gorm:"-"`
	Lastaccess      time.Time   `json:"lastaccess"`
	Info            UserInfo    `json:"info" gorm:"-"`
	Supporter       bool        `json:"supporter" gorm:"-"`
	Donated         *time.Time  `json:"donated" gorm:"-"`
	DonatedType     *string     `json:"donatedtype" gorm:"-"`
	Comments        []Comment   `json:"comments,omitempty" gorm:"-"`
	Spammer         interface{} `json:"spammer" gorm:"-"`
	Showmod         bool        `json:"showmod" gorm:"-"`
	Lat             float32     `json:"lat" gorm:"-"` // Exact for logged in user, approx for others.
	Lng             float32     `json:"lng" gorm:"-"`
	Aboutme         Aboutme     `json:"aboutme" gorm:"-"`
	Added           time.Time   `json:"added"`
	ExpectedReplies int         `json:"expectedreplies" gorm:"-"`
	ExpectedChats   []uint64    `json:"expectedchats" gorm:"-"`
	// No gorm:"->" — user/auth.go GetLoveJunkUser writes this column via
	// db.Create(&ljuser) when first creating an LJ-linked user; making it
	// read-only would silently drop the value and cause a fresh user to be
	// created on every LJ call (TestCreateChatMessageLoveJunk regression).
	Ljuserid        *uint64          `json:"ljuserid,omitempty"`
	Deleted         *time.Time       `json:"deleted"`
	Forgotten       *time.Time       `json:"forgotten"`
	Lastlocation    *uint64          `json:"lastlocation"`
	Privateposition *PrivatePosition `json:"privateposition,omitempty" gorm:"-"`

	// Only returned for logged-in user.
	Email              string               `json:"email" gorm:"-"`
	Emails             []UserEmail          `json:"emails" gorm:"-"`
	Memberships        []Membership         `json:"memberships" gorm:"-"`
	MessageHistory     []UserMessageHistory `json:"messagehistory,omitempty" gorm:"-"`
	Systemrole         string               `json:"systemrole"`
	Settings           json.RawMessage      `json:"settings"` // This is JSON stored in the DB as a string.
	Relevantallowed    bool                 `json:"relevantallowed"`
	Newslettersallowed bool                 `json:"newslettersallowed"`
	Bouncing           bool                 `json:"bouncing"`
	Bouncereason       *string              `json:"bouncereason,omitempty" gorm:"-"`
	Bounceat           *string              `json:"bounceat,omitempty" gorm:"-"`
	Trustlevel         *string              `json:"trustlevel"`
	Marketingconsent   bool                 `json:"marketingconsent"`
	Source             *string              `json:"source"`
	Modmails           uint64               `json:"modmails" gorm:"-"`
	Suspectreason      *string              `json:"suspectreason,omitempty" gorm:"-"`
	Activedistance     *float64             `json:"activedistance" gorm:"-"`
	Locationchanges    *int                 `json:"locationchanges,omitempty" gorm:"-"`
	Chatmodstatus      *string              `json:"chatmodstatus,omitempty" gorm:"->"`
	Newsfeedmodstatus  *string              `json:"newsfeedmodstatus,omitempty" gorm:"->"`
	Tnuserid           *uint64              `json:"tnuserid,omitempty" gorm:"->"`
	Lastpush           *time.Time           `json:"lastpush,omitempty" gorm:"-"`
	Donations          []UserDonation       `json:"donations,omitempty" gorm:"-"`
	Giftaid            *UserGiftAid         `json:"giftaid,omitempty" gorm:"-"`
	Loginlink          string               `json:"loginlink,omitempty" gorm:"-"`
	Engagement         *string              `json:"engagement" gorm:"->"`
}

type UserGiftAid struct {
	ID        uint64    `json:"id"`
	Userid    uint64    `json:"userid"`
	Timestamp time.Time `json:"timestamp"`
	Period    string    `json:"period"`
}

type UserDonation struct {
	ID              uint64    `json:"id"`
	Userid          *uint64   `json:"userid"`
	Timestamp       time.Time `json:"timestamp"`
	GrossAmount     float64   `json:"GrossAmount"`
	Source          string    `json:"source"`
	TransactionType *string   `json:"TransactionType"`
	Giftaidconsent  bool      `json:"giftaidconsent"`
}

type Tabler interface {
	TableName() string
}

func (UserProfileRecord) TableName() string {
	return "users_images"
}

type UserProfileRecord struct {
	ID           uint64 `json:"id" gorm:"primary_key"`
	Profileid    uint64
	Url          string
	Archived     int
	Useprofile   bool            `json:"-"`
	Externaluid  string          `json:"externaluid"`
	Ouruid       string          `json:"ouruid"`
	Externalmods json.RawMessage `json:"externalmods"`
}

// This corresponds to the DB table.
func (MembershipTable) TableName() string {
	return "memberships"
}

type MembershipTable struct {
	ID                  uint64    `json:"id" gorm:"primary_key"`
	Groupid             uint64    `json:"groupid"`
	Userid              uint64    `json:"userid"`
	Added               time.Time `json:"added"`
	Collection          string    `json:"collection"`
	Emailfrequency      int       `json:"emailfrequency"`
	Eventsallowed       int       `json:"eventsallowed"`
	Volunteeringallowed int       `json:"volunteeringallowed"`
	Role                string    `json:"role"`
	OurPostingStatus    *string   `json:"ourpostingstatus,omitempty" gorm:"column:ourPostingStatus"`
}

// This is the membership we return to the client.  It includes some information not stored in the DB.
type Membership struct {
	MembershipTable
	Nameshort                string `json:"nameshort"`
	Namefull                 string `json:"namefull"`
	Namedisplay              string `json:"namedisplay"`
	Type                     string `json:"type"`
	Bbox                     string `json:"bbox"`
	Microvolunteeringallowed int    `json:"microvolunteeringallowed"`
}

type UserMessageHistory struct {
	ID         uint64    `json:"id"`
	Subject    string    `json:"subject"`
	Type       string    `json:"type"`
	Arrival    time.Time `json:"arrival"`
	Postdate   time.Time `json:"postdate"` // V1-parity: frontend reads msg.postdate for $recentwanted
	Groupid    uint64    `json:"groupid"`
	Collection string    `json:"collection"`
	Daysago    int       `json:"daysago"`
	Outcome    *string   `json:"outcome"`
}

func (MembershipHistory) TableName() string {
	return "memberships_history"
}

type MembershipHistory struct {
	ID                 uint64    `json:"id" gorm:"primary_key"`
	Groupid            uint64    `json:"groupid"`
	Userid             uint64    `json:"userid"`
	Added              time.Time `json:"added"`
	Collection         string    `json:"collection"`
	Processingrequired bool      `json:"processingrequired"`
}

type Search struct {
	ID         uint64    `json:"id" gorm:"primary_key"`
	Date       time.Time `json:"date"`
	Userid     uint64    `json:"userid"`
	Term       string    `json:"term"`
	Maxmsg     uint64    `json:"maxmsg"`
	Locationid uint64    `json:"locationid"`
}

func hideSensitiveFields(user *User, myid uint64) {
	// Hide sensitive fields for non-logged in user or different user.
	// Systemrole is not hidden — it's public information (mod/admin status)
	// and is needed by the frontend for crown icons in mod logs.
	if myid != user.ID {
		user.Relevantallowed = false
		user.Newslettersallowed = false
		user.Bouncing = false
		user.Marketingconsent = false
		user.Source = nil
		// Mod-only fields: only visible to mods of a shared group.
		if !IsModOfUser(myid, user.ID) {
			user.Settings = nil
			user.Chatmodstatus = nil
			user.Newsfeedmodstatus = nil
			user.Tnuserid = nil
			user.Ljuserid = nil
		}
	}
}

func GetUserByEmail(c *fiber.Ctx) error {
	email := c.Params("email")

	if email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Email parameter required")
	}

	// Looking up a user by email
	db := database.DBConn
	var userId uint64

	// Join with users table to ensure the user exists and isn't deleted
	err := db.Table("users").
		Select("users.id").
		Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
		Where("users_emails.email = ? AND users.deleted IS NULL", email).
		Limit(1).
		Scan(&userId).Error

	if err != nil || userId == 0 {
		return c.JSON(fiber.Map{
			"exists": false,
		})
	}

	return c.JSON(fiber.Map{
		"exists": true,
	})
}

func GetUser(c *fiber.Ctx) error {
	modtools := c.Query("modtools") == "true"

	if c.Params("id") != "" {
		// Check if this is a comma-separated list of IDs (batch request).
		idsParam := c.Params("id")
		if strings.Contains(idsParam, ",") {
			// Batch request for multiple users.
			ids := strings.Split(idsParam, ",")
			myid := WhoAmI(c)

			if len(ids) > 30 {
				return fiber.NewError(fiber.StatusBadRequest, "Too many users requested")
			}

			users := GetUsersByIds(ids, myid, modtools)
			return c.JSON(users)
		}

		// Looking for a specific user.
		id, err := strconv.ParseUint(idsParam, 10, 64)

		if err == nil {
			myid := WhoAmI(c)

			user := GetUserById(id, myid)

			if user.ID != id {
				return fiber.NewError(fiber.StatusNotFound, "User not found")
			}

			// Capture tnuserid/ljuserid before hideSensitiveFields strips them;
			// partners and mod-or-above callers need them to match records to
			// Freegle users.
			tnuserid := user.Tnuserid
			ljuserid := user.Ljuserid

			hideSensitiveFields(&user, myid)
			enrichUserForModtools(&user, id, myid, modtools)

			// Mod-or-above callers (Moderator/Support/Admin systemrole) get
			// tnuserid/ljuserid restored even when not a mod of a shared group
			// with the target. authMiddleware sets c.Locals("userRole") only
			// AFTER c.Next() (so it can overlap the auth query with the handler
			// via goroutine), so we have to check the caller's role here.
			// Skip the systemrole lookup when there's nothing to restore or
			// when this is a self-fetch (hideSensitiveFields didn't strip).
			if (tnuserid != nil || ljuserid != nil) && myid > 0 && myid != id && auth.IsSystemMod(myid) {
				user.Tnuserid = tnuserid
				user.Ljuserid = ljuserid
			}

			// Partners (e.g. Trash Nothing) can see the internal @users.ilovefreegle.org
			// email for a user so they can match their records to Freegle users.
			// External emails are not returned to protect user privacy.
			// GetOrCreateInternalEmail ensures a correctly-formatted address exists
			// even for users whose only stored internal email has the wrong user ID
			// (e.g. after a merge), and creates one if none exists at all.
			if partnerKey := c.Query("partner"); partnerKey != "" {
				if _, _, _, err := ValidatePartnerKey(database.DBConn, partnerKey); err == nil {
					user.Email = GetOrCreateInternalEmail(database.DBConn, id)
					user.Tnuserid = tnuserid
					user.Ljuserid = ljuserid
				}
			}

			return c.JSON(user)
		}

		return fiber.NewError(fiber.StatusBadRequest, "Invalid user ID")
	} else {
		// Looking for the currently logged-in user as authenticated by the Authorization header JWT (if present).
		id := WhoAmI(c)

		if id > 0 {
			// We want to get information in parallel.
			var wg sync.WaitGroup
			var memberships []Membership
			var user User
			var latlng utils.LatLng
			var emails []UserEmail

			wg.Add(1)
			go func() {
				defer wg.Done()
				user = GetUserById(id, id)
			}()

			wg.Add(1)
			go func() {
				defer wg.Done()
				memberships = GetMemberships(id)
			}()

			wg.Add(1)
			go func() {
				defer wg.Done()
				latlng = GetLatLng(id)
			}()

			wg.Add(1)
			go func() {
				defer wg.Done()
				emails = getEmails(id)
			}()

			// Now wait for these parallel requests to complete.
			wg.Wait()
			user.Memberships = memberships
			user.Lat = latlng.Lat
			user.Lng = latlng.Lng
			user.Emails = emails

			if len(emails) > 0 {
				// First email is preferred (by construction) or best guess.
				//
				// Find first email that is not ourDomain
				for _, email := range emails {
					if user.Email == "" && utils.OurDomain(email.Email) == 0 {
						user.Email = email.Email
					}
				}
			}

			if user.Settings == nil {
				user.Settings = json.RawMessage("{}")
			}

			if user.ID == id {
				return c.JSON(user)
			}
		}

		return fiber.NewError(fiber.StatusNotFound, "Not logged in")
	}
}

func GetExpectedReplies(id uint64) []uint64 {
	var expectedReplies []uint64

	db := database.DBConn

	start := time.Now().AddDate(0, 0, -utils.CHAT_ACTIVE_LIMIT).Format("2006-01-02")
	db.Table("users_expected").
		Select("DISTINCT(chatid)").
		Joins("INNER JOIN users ON users.id = users_expected.expectee").
		Joins("INNER JOIN chat_messages ON chat_messages.id = users_expected.chatmsgid").
		Where("expectee = ? AND chat_messages.date >= ? AND replyexpected = 1 AND replyreceived = 0 AND TIMESTAMPDIFF(MINUTE, chat_messages.date, users.lastaccess) >= ?",
			id, start, utils.CHAT_REPLY_GRACE).
		Pluck("chatid", &expectedReplies)

	return expectedReplies
}

func GetMemberships(id uint64) []Membership {
	db := database.DBConn

	var memberships []Membership
	db.Table("memberships").
		Select("memberships.id, added, role, groupid, emailfrequency, eventsallowed, volunteeringallowed, ourPostingStatus, microvolunteering AS microvolunteeringallowed, nameshort, namefull, groups.type, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
		Joins("INNER JOIN `groups` ON groups.id = memberships.groupid").
		Where("userid = ? AND collection = ?", id, "Approved").
		Scan(&memberships)

	for ix, r := range memberships {
		if len(r.Namefull) > 0 {
			memberships[ix].Namedisplay = r.Namefull
		} else {
			memberships[ix].Namedisplay = r.Nameshort
		}
	}

	return memberships
}

// GetActiveModGroupIDs returns group IDs where the user is an active moderator/owner.
// A moderator is "active" unless their membership settings JSON has active=0.
// A moderator is "active" unless their membership settings JSON has active=0.
// inventNameAttempts bounds the retries when the generated name is one we
// cannot keep. Each attempt is independent, so a handful is plenty.
const inventNameAttempts = 10

// usableInventedName reports whether a candidate is one we can store as a
// user's fullname.
//
// The isSuspiciousName check is the important one, and it is not paranoia:
// SanitizeDisplayName rewrites a suspicious name back to "A freegler" on
// every read, but InventName is only reached when the stored fullname *is*
// "A freegler". So storing a suspicious name is a one-way trap — the user
// displays as "A freegler" forever and we never invent again.
//
// Both sources can produce one. Role addresses (info@, support@, admin@) are
// common, and a trigram-generated word lands on an authority word like "team"
// or "update" often enough to have failed CI.
func usableInventedName(name string) bool {
	return name != "" && name != "A freegler" && !isSuspiciousName(name)
}

// InventName derives a display name from the user's email address and stores it
// as the user's fullname. Returns the invented name, or "A freegler"
// if no usable email is found.
func InventName(db *gorm.DB, id uint64) string {
	// Try the email local part first (V1 parity: use real email when it's clean).
	var email string
	db.Table("users_emails").Select("email").Where("userid = ?", id).Order("preferred DESC, id ASC").Limit(1).Scan(&email)

	var name string
	if at := strings.Index(email, "@"); at > 0 {
		name = utils.TidyName(email[:at])
	}

	// Fall back to a trigram-generated name when the email local part is
	// unusable, retrying until we get one we can actually keep.
	if !usableInventedName(name) {
		name = ""
		for i := 0; i < inventNameAttempts; i++ {
			if candidate := utils.GenerateName(); usableInventedName(candidate) {
				name = candidate
				break
			}
		}
	}

	if name == "" {
		return "A freegler"
	}

	// Store so subsequent reads return the correct name.
	// Also overwrites legacy bad names: "A freegler", FBUser*, and 32-char Yahoo hex strings.
	db.Table("users").Where("id = ? AND (fullname IS NULL OR fullname = '' OR fullname = 'A freegler' OR fullname LIKE 'FBUser%' OR (CHAR_LENGTH(fullname) = 32 AND fullname REGEXP '[A-Za-z].*[0-9]|[0-9].*[A-Za-z]'))", id).
		Updates(map[string]interface{}{"fullname": name, "inventedname": gorm.Expr("1")})

	return name
}

func GetActiveModGroupIDs(userid uint64) []uint64 {
	db := database.DBConn
	var groupIDs []uint64
	result := db.Table("memberships").
		Where("userid = ? AND role IN (?, ?) AND collection = ? "+
			"AND (settings IS NULL OR JSON_EXTRACT(settings, '$.active') IS NULL OR JSON_EXTRACT(settings, '$.active') != 0)",
			userid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).
		Pluck("groupid", &groupIDs)
	if result.Error != nil {
		log.Printf("Failed to get active mod group IDs for user %d: %v", userid, result.Error)
	}
	return groupIDs
}

// HasWiderReview checks if a user participates in wider chat review, i.e. they are an active
// moderator on at least one group that has widerchatreview=1 in its settings.
// Checks if any of their active groups has widerchatreview=1 in settings.
func HasWiderReview(userid uint64) bool {
	db := database.DBConn
	activeGroupIDs := GetActiveModGroupIDs(userid)
	if len(activeGroupIDs) == 0 {
		return false
	}
	var count int64
	db.Table("groups").Where("id IN ? AND JSON_EXTRACT(settings, '$.widerchatreview') = 1",
		activeGroupIDs).Count(&count)
	return count > 0
}

func GetUserMessageHistory(userid uint64) []UserMessageHistory {
	db := database.DBConn

	var history []UserMessageHistory
	// Use a correlated subquery to get the most recent posting date for each
	// (message, group) pair instead of LEFT JOIN messages_postings.  The JOIN
	// approach fans out: N messages_postings rows per message produce N result
	// rows, causing duplicated entries in the posting-history modal when a message
	// has been reposted (Discourse #9672).  The subquery filters by groupid so
	// postings for one group never contaminate the arrival date of another group.
	db.Table("messages m").
		Select("m.id, m.subject, m.type, "+
			"COALESCE("+
			"(SELECT MAX(mp.date) FROM messages_postings mp WHERE mp.msgid = m.id AND mp.groupid = mg.groupid), "+
			"m.arrival) AS arrival, "+
			"mg.groupid, mg.collection, "+
			"(SELECT outcome FROM messages_outcomes WHERE messages_outcomes.msgid = m.id ORDER BY timestamp DESC LIMIT 1) AS outcome").
		Joins("INNER JOIN messages_groups mg ON m.id = mg.msgid").
		// rippled_in = 0: a post rippled OUT gets an extra messages_groups row
		// (rippled_in = 1) per receiving group. Without this filter the join fans
		// out to one history entry per group, so a post reaching N groups showed
		// N identical rows (Discourse #9851 / the 23x Posting History). Restricting
		// to origin rows shows the post once (still per group for genuine
		// cross-posts, matching pre-rippling behaviour).
		Where("m.fromuser = ? AND mg.deleted = 0 AND mg.rippled_in = 0 AND m.deleted IS NULL AND mg.collection IN (?, ?)",
			userid, utils.COLLECTION_APPROVED, utils.COLLECTION_PENDING).
		Order("arrival DESC").
		Scan(&history)

	now := time.Now()
	for ix, h := range history {
		history[ix].Daysago = int(now.Sub(h.Arrival).Hours() / 24)
		history[ix].Postdate = h.Arrival
	}

	return history
}

// ApplySettingsDefaultsToJSON applies V1-parity defaults to a raw settings JSON
// blob and returns the updated blob.  It is safe to call on any GET response path
// because it never persists to the database.  Callers outside this package (e.g.
// GetSession) use this to avoid the V1/V2 parity gap where absent keys render as
// undefined in the frontend rather than their implicit true/4/12 defaults.
func ApplySettingsDefaultsToJSON(settings json.RawMessage, systemrole string) json.RawMessage {
	if len(settings) == 0 {
		return settings
	}

	var m map[string]interface{}
	if err := json.Unmarshal(settings, &m); err != nil {
		return settings
	}

	changed := false

	if _, ok := m["notificationmails"]; !ok {
		m["notificationmails"] = true
		changed = true
	}
	if _, ok := m["engagement"]; !ok {
		m["engagement"] = true
		changed = true
	}

	// settings.notifications is a nested object; create it when absent.
	// Inject per-key defaults for any key absent from that sub-object.
	{
		var notifs map[string]interface{}
		if raw, ok := m["notifications"]; ok {
			notifs, _ = raw.(map[string]interface{})
		}
		if notifs == nil {
			notifs = make(map[string]interface{})
		}
		notifChanged := false
		if _, ok := notifs["dailypostspush"]; !ok {
			notifs["dailypostspush"] = true
			notifChanged = true
		}
		if notifChanged {
			m["notifications"] = notifs
			changed = true
		}
	}

	// Mod-specific defaults only for users with a moderator+ systemrole.
	// Injecting these for regular users caused settings contamination when
	// the frontend echoed the full settings blob back via PATCH.
	isMod := systemrole == utils.SYSTEMROLE_MODERATOR ||
		systemrole == utils.SYSTEMROLE_SUPPORT ||
		systemrole == utils.SYSTEMROLE_ADMIN
	if isMod {
		if _, ok := m["modnotifs"]; !ok {
			m["modnotifs"] = 4
			changed = true
		}
		if _, ok := m["backupmodnotifs"]; !ok {
			m["backupmodnotifs"] = 12
			changed = true
		}
	}

	if changed {
		if b, err := json.Marshal(m); err == nil {
			return b
		}
	}
	return settings
}

// applySettingsDefaults applies V1-parity defaults for settings fields that may
// be absent from the JSON stored in the database. V1 (User.php) applies these
// defaults on read: notificationmails=true, engagement=true, modnotifs=4,
// backupmodnotifs=12.
func applySettingsDefaults(user *User) {
	user.Settings = ApplySettingsDefaultsToJSON(user.Settings, user.Systemrole)
}

func GetUserById(id uint64, myid uint64) User {
	db := database.DBConn

	var user User
	var info UserInfo
	var aboutme Aboutme
	var profileRecord UserProfileRecord
	var expectedReplies []uint64

	isMod := len(GetActiveModGroupIDs(myid)) > 0
	// V1 getPublicSpammer checks systemrole directly for mod-visibility of spam details,
	// not group-mod status — keep this separate from isMod used for settings inclusion.
	isSystemMod := auth.IsSystemMod(myid)

	type spamRow struct {
		ID         uint64    `gorm:"column:id"`
		Userid     uint64    `gorm:"column:userid"`
		Byuserid   *uint64   `gorm:"column:byuserid"`
		Collection string    `gorm:"column:collection"`
		Reason     *string   `gorm:"column:reason"`
		Added      time.Time `gorm:"column:added"`
	}
	var spam spamRow
	var spamFound bool

	var wg sync.WaitGroup

	wg.Add(1)
	go func() {
		defer wg.Done()

		// Settings are needed for modtools toggles (notificationmails etc.).
		// Return for self, or for mods viewing other users.
		//
		// Whether
		// "settings, " is included is the only toggle - 2 possible rendered
		// forms, both proven by the retired ormharness (shapes.json /
		// TestTier3Shapes_e0558c2c039d, removed in d22ba1d6c).
		selectCols := "users.id, firstname, lastname, fullname, lastaccess, users.added, systemrole, relevantallowed, newslettersallowed, marketingconsent, trustlevel, bouncing, deleted, forgotten, source, engagement, " +
			"chatmodstatus, newsfeedmodstatus, tnuserid, ljuserid, "
		if id == myid || isMod {
			selectCols += "settings, "
		}
		selectCols += "CASE WHEN systemrole IN (?, ?, ?) AND JSON_EXTRACT(users.settings, '$.showmod') IS NULL THEN 1 ELSE JSON_EXTRACT(users.settings, '$.showmod') END AS showmod"

		// Find, not First: First unconditionally adds an implicit "ORDER
		// BY <primary key>" + LIMIT 1 and raises ErrRecordNotFound, but
		// this is a Table()-only query with no registered Model, so
		// Schema stays nil and resolving that ORDER BY's primary key
		// column fails outright with "model value required" (gorm's
		// statement.go, the clause.Column PrimaryKey case). See
		// group/group.go's GetGroup (site 2811b4d3acf7) for the
		// established fix: Find() never adds those clauses, so the
		// caller checks RowsAffected instead of comparing the error to
		// ErrRecordNotFound.
		tx := db.Table("users").
			Select(selectCols, utils.SYSTEMROLE_MODERATOR, utils.SYSTEMROLE_SUPPORT, utils.SYSTEMROLE_ADMIN).
			Where("users.id = ?", id).
			Find(&user)

		if tx.RowsAffected > 0 {
			if user.Deleted == nil || isMod {
				// Show real name for active users, and also for deleted
				// users when viewed by a moderator.
				if user.Fullname != nil {
					user.Displayname = *user.Fullname
				} else {
					user.Displayname = ""

					if user.Firstname != nil {
						user.Displayname += *user.Firstname

						if user.Lastname != nil {
							user.Displayname += " " + *user.Lastname
						}
					} else if user.Lastname != nil {
						user.Displayname = *user.Lastname
					}
				}

				user.Displayname = utils.TidyName(user.Displayname)

				// if display name resolved to "A freegler" (empty or
				// explicitly set), invent a name from the user's email address and
				// store it so subsequent reads return a real name.
				if user.Displayname == "A freegler" {
					user.Displayname = InventName(db, id)
				}

				// Rewrite misleading/fraudulent names for non-mods on display
				// (Discourse #9587). Stored fullname is untouched.
				isGroupMod := false
				var modCount int64
				db.Table("memberships").Where("userid = ? AND role IN (?, ?)",
					id, utils.ROLE_OWNER, utils.ROLE_MODERATOR).Count(&modCount)
				if modCount > 0 {
					isGroupMod = true
				}
				isExempt := IsExemptBySystemroleAndMod(user.Systemrole, isGroupMod)
				user.Displayname = SanitizeDisplayName(user.Displayname, isExempt)
			} else {
				// Censor name for deleted user when viewed by non-mod.
				user.Displayname = "Deleted User #" + strconv.FormatUint(id, 10)
				user.Firstname = nil
				user.Lastname = nil
			}
		}
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		profileRecord = GetProfileRecord(id)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		info = GetUserInfo(id, myid)
	}()

	// We return the approximate location of the user.
	var lat, lng float64

	wg.Add(1)
	go func() {
		defer wg.Done()
		latlng := GetLatLng(id)

		if (latlng.Lat != 0) || (latlng.Lng != 0) {
			lat, lng = utils.Blur((float64)(latlng.Lat), (float64)(latlng.Lng), utils.BLUR_USER)
		}
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Table("users_aboutme").Where("userid = ?", id).Order("timestamp DESC").Limit(1).Scan(&aboutme)
	}()

	var supporter struct {
		Supporter   bool       `json:"supporter"`
		Donated     *time.Time `json:"donated"`
		DonatedType *string    `json:"donatedtype"`
	}

	wg.Add(1)
	go func() {
		// Get whether they are a supporter - a mod, someone who has donated, or someone who has volunteered.
		// Also get whether they have ever donated - that's used for our own user.
		defer wg.Done()
		start := time.Now().AddDate(0, 0, -utils.SUPPORTER_PERIOD).Format("2006-01-02")

		db.Table("users").
			Select("(CASE WHEN "+
				"((users.systemrole != ? OR "+
				"EXISTS(SELECT id FROM users_donations WHERE userid = ? AND users_donations.timestamp >= ?) OR "+
				"EXISTS(SELECT id FROM microactions WHERE userid = ? AND microactions.timestamp >= ?)) AND "+
				"(CASE WHEN JSON_EXTRACT(users.settings, '$.hidesupporter') IS NULL THEN 0 ELSE JSON_EXTRACT(users.settings, '$.hidesupporter') END) = 0) "+
				"THEN 1 ELSE 0 END) "+
				"AS supporter, "+
				"(SELECT MAX(timestamp) FROM users_donations WHERE userid = ?) AS donated, "+
				"(SELECT type FROM users_donations WHERE userid = ? ORDER BY timestamp DESC LIMIT 1) AS donatedtype",
				utils.SYSTEMROLE_USER, id, start, id, start, id, id).
			Where("users.id = ?", id).
			Scan(&supporter)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		expectedReplies = GetExpectedReplies(id)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		var rows []spamRow
		db.Table("spam_users").Select("id, userid, byuserid, collection, reason, added").
			Where("userid = ?", id).Order("id ASC").Limit(1).Scan(&rows)
		if len(rows) > 0 {
			spam = rows[0]
			spamFound = true
		}
	}()

	wg.Wait()

	if user.Deleted == nil && profileRecord.Useprofile {
		ProfileSetPath(profileRecord.Profileid, profileRecord.Url, profileRecord.Externaluid, profileRecord.Externalmods, profileRecord.Archived, &user.Profile)
	}

	user.Lat = (float32)(lat)
	user.Lng = (float32)(lng)

	user.Info = info

	if user.Deleted == nil {
		user.Aboutme = aboutme
	}

	user.Supporter = supporter.Supporter

	// V1 parity (User::getPublicSpammer): mods see rich spam_users object so ModSpammer.vue
	// can show "Unconfirmed Spammer" etc. Non-mods see bool TRUE only for confirmed Spammer
	// collection — PendingAdd must not leak to regular users.
	if spamFound {
		if isSystemMod {
			obj := map[string]interface{}{
				"id":         spam.ID,
				"userid":     spam.Userid,
				"byuserid":   spam.Byuserid,
				"collection": spam.Collection,
				"reason":     spam.Reason,
				"added":      spam.Added,
			}
			user.Spammer = obj
		} else if spam.Collection == utils.SPAM_COLLECTION_SPAMMER {
			user.Spammer = true
		} else {
			user.Spammer = false
		}
	} else {
		user.Spammer = false
	}

	// Apply V1-parity defaults for settings fields that may be absent from the JSON.
	applySettingsDefaults(&user)

	if id == myid {
		// We can see our own donor status.
		user.Donated = supporter.Donated
		user.DonatedType = supporter.DonatedType
	}

	if user.Deleted == nil {
		user.ExpectedReplies = len(expectedReplies)
		user.ExpectedChats = expectedReplies
	}

	// Fetch most recent bounce reason for bouncing users.
	if user.Bouncing {
		type BounceInfo struct {
			Reason string `gorm:"column:reason"`
			Date   string `gorm:"column:date"`
		}
		var bi BounceInfo
		db.Table("bounces_emails be").
			Select("be.reason, be.date").
			Joins("INNER JOIN users_emails ue ON ue.id = be.emailid").
			Where("ue.userid = ?", id).
			Order("be.id DESC").
			Limit(1).
			Scan(&bi)
		if bi.Date != "" {
			user.Bouncereason = &bi.Reason
			user.Bounceat = &bi.Date
		}
	}

	return user
}

// GetUsersByIds fetches multiple users in parallel by their IDs.
func GetUsersByIds(ids []string, myid uint64, modtools bool) []User {
	var mu sync.Mutex
	users := []User{}

	var wg sync.WaitGroup
	wg.Add(len(ids))

	for _, idStr := range ids {
		go func(idStr string) {
			defer wg.Done()

			id, err := strconv.ParseUint(idStr, 10, 64)
			if err != nil {
				return
			}

			user := GetUserById(id, myid)
			hideSensitiveFields(&user, myid)

			if user.ID == id {
				mu.Lock()
				users = append(users, user)
				mu.Unlock()
			}
		}(idStr)
	}

	wg.Wait()

	// Enrich each user with modtools data (memberships, emails, etc.)
	// and fetch comments in a single batch.
	if modtools && myid > 0 && len(users) > 0 {
		for i := range users {
			enrichUserForModtools(&users[i], users[i].ID, myid, modtools)
		}

		userids := make([]uint64, len(users))
		for i, u := range users {
			userids[i] = u.ID
		}
		comments := GetComments(userids, myid)
		for i := range users {
			if c, ok := comments[users[i].ID]; ok {
				users[i].Comments = c
			}
		}
	}

	return users
}

func GetProfileRecord(id uint64) UserProfileRecord {
	db := database.DBConn
	var profile UserProfileRecord

	db.Table("users_images ui").
		Select("ui.id AS profileid, ui.url AS url, ui.archived, ui.externaluid, ui.externalmods, CASE WHEN JSON_EXTRACT(settings, '$.useprofile') IS NULL THEN 1 ELSE JSON_EXTRACT(settings, '$.useprofile') END AS useprofile").
		Joins("INNER JOIN users ON users.id = ui.userid").
		Where("userid = ?", id).
		Order("ui.id DESC").
		Limit(1).
		Scan(&profile)

	return profile
}

func GetLatLng(id uint64) utils.LatLng {
	var ret utils.LatLng

	ret.Lat = 0
	ret.Lng = 0

	db := database.DBConn

	type userLoc struct {
		ID      uint64 `gorm:"primary_key"`
		Mylat   float32
		Mylng   float32
		Lastlat float32
		Lastlng float32
	}

	var ul, ulmsg, ulgroups userLoc

	// We look for the location in the following descending order:
	// - mylocation in settings, which we need to decode
	// - lastlocation in user
	// - last messages posted on a group with a location
	// - most recently joined group
	//
	// Tests show that the first query is fast to fetch, whereas the others are less so.  The first will handle
	// a user with a known location, so it's a good mainline case to keep fast.
	// If it doesn't give us what we need them , then fetch the others in parallel.
	db.Table("users").
		Select("users.id, locations.lat AS lastlat, locations.lng as lastlng, "+
			"CAST(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.lat') AS DECIMAL(10,6)) AS mylat,"+
			"CAST(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.lng') AS DECIMAL(10,6)) as mylng").
		Joins("LEFT JOIN locations ON locations.id = users.lastlocation").
		Joins("LEFT JOIN spam_users ON spam_users.userid = users.id").
		Where("users.id = ?", id).
		Scan(&ul)

	if ul.Mylng != 0 || ul.Mylat != 0 {
		ret.Lat = ul.Mylat
		ret.Lng = ul.Mylng
	} else {
		var wg sync.WaitGroup

		wg.Add(1)
		go func() {
			defer wg.Done()
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()
			db.Table("locations").
				Select("messages.fromuser AS id, locations.lat AS lastlat, locations.lng AS lastlng").
				Joins("INNER JOIN messages ON messages.locationid = locations.id").
				Where("messages.fromuser = ?", id).
				Order("arrival DESC").
				Limit(1).
				Scan(&ulmsg)
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()
			db.Table("`groups`").
				Select("groups.id, groups.lat AS lastlat, groups.lng AS lastlng").
				Joins("INNER JOIN memberships ON groups.id = memberships.groupid").
				Where("memberships.userid = ?", id).
				Order("added DESC").
				Limit(1).
				Scan(&ulgroups)
		}()

		wg.Wait()

		if ul.Lastlat != 0 || ul.Lastlng != 0 {
			ret.Lat = ul.Lastlat
			ret.Lng = ul.Lastlng
		} else if ulmsg.Lastlat != 0 || ulmsg.Lastlng != 0 {
			ret.Lat = ulmsg.Lastlat
			ret.Lng = ulmsg.Lastlng
		} else if ulgroups.Lastlat != 0 || ulgroups.Lastlng != 0 {
			ret.Lat = ulgroups.Lastlat
			ret.Lng = ulgroups.Lastlng
		}
	}

	return ret
}

func GetSearchesForUser(c *fiber.Ctx) error {
	db := database.DBConn
	myid := WhoAmI(c)

	if c.Params("id") != "" {
		id, err := strconv.ParseUint(c.Params("id"), 10, 64)

		if err == nil && id == myid {
			var searches []Search

			// Show the last few.  Slightly hacky search to make sure we show the most recent searches.
			// The derived table goes
			// through .Table(expr, args...): Table() takes the raw-expression
			// path whenever the name contains a space or backtick (chainable_api.go),
			// so a parenthesised subquery with its own bind renders verbatim rather
			// than being (mis)quoted as an identifier.
			db.Table("(SELECT * FROM users_searches WHERE userid = ? AND deleted = 0 ORDER BY id desc LIMIT 100) t", id).
				Select("*").
				Group("t.term").
				Order("t.id DESC").
				Limit(10).
				Find(&searches)

			if searches == nil {
				searches = make([]Search, 0)
			}

			return c.JSON(searches)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "User not found")
}

// DeleteUserSearch soft-deletes a user search by setting deleted=1.
// The user can only delete their own searches, or admin/support can delete any.
//
// @Summary Delete a user search
// @Tags usersearch
// @Produce json
// @Param id query integer true "Search ID"
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /api/usersearch [delete]
func DeleteUserSearch(c *fiber.Ctx) error {
	myid := WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	// Try JSON body first (frontend sends DELETE with JSON body), fall back to query param.
	var id uint64
	var req struct {
		ID uint64 `json:"id"`
	}
	if err := c.BodyParser(&req); err == nil && req.ID > 0 {
		id = req.ID
	} else {
		parsed, err := strconv.ParseUint(c.Query("id"), 10, 64)
		if err != nil || parsed == 0 {
			return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 2, "status": "Invalid id"})
		}
		id = parsed
	}

	db := database.DBConn

	// Check ownership.
	var search Search
	if err := db.Table("users_searches").Where("id = ?", id).Scan(&search).Error; err != nil || search.ID == 0 {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	if search.Userid != myid {
		// Check if admin/support.
		if !auth.IsAdminOrSupport(myid) {
			return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
		}
	}

	// Soft-delete: mark all searches with the same userid and term as deleted.
	db.Table("users_searches").Where("userid = ? AND term = ?", search.Userid, search.Term).Update("deleted", gorm.Expr("1"))

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func GetPublicLocation(c *fiber.Ctx) error {
	var ret Publiclocation
	var groupname string
	var groupid uint64
	var loc string

	if c.Params("id") != "" {
		id, err := strconv.ParseUint(c.Params("id"), 10, 64)

		if err == nil {
			var wg sync.WaitGroup

			latlng := GetLatLng(id)

			wg.Add(1)
			go func() {
				defer wg.Done()
				// Get a public area based on this.
				l := location.ClosestPostcode(latlng.Lat, latlng.Lng)
				loc = l.Areaname
			}()

			wg.Add(1)
			go func() {
				defer wg.Done()

				// Get the closest group.
				group := location.ClosestSingleGroup(float64(latlng.Lat), float64(latlng.Lng), utils.NEARBY)

				if group != nil {
					groupname = group.Namedisplay
					groupid = group.ID
				}
			}()

			wg.Wait()
		}
	}

	if len(loc) > 0 {
		ret.Location = loc
		ret.Groupname = groupname
		ret.Groupid = groupid

		ret.Display = ret.Location

		if len(ret.Groupname) > 0 {
			ret.Display = ret.Location + ", " + ret.Groupname
		}
	}

	return c.JSON(ret)
}

// SearchUsers searches across users by name, email, ID, yahooid, or login UID.
// Requires Admin or Support role.
func SearchUsers(c *fiber.Ctx) error {
	myid := WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	if !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not authorized")
	}

	q := c.Query("q")
	if q == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Search term required")
	}

	numericID, _ := strconv.ParseUint(q, 10, 64)

	// If query is purely numeric, do a fast direct ID lookup first.
	if numericID > 0 {
		var exists uint64
		db.Table("users").Select("id").Where("id = ?", numericID).Scan(&exists)
		if exists > 0 {
			// Found by ID — skip the slow LIKE searches.
			return c.JSON(fiber.Map{"users": []uint64{exists}})
		}
	}

	// Use prefix match (term%) for canon/fullname/yahooid/uid,
	// and reversed prefix match for backwards column. Substring match (%term%)
	// on email only. This is faster (uses indexes) and more precise.
	prefixTerm := q + "%"
	emailLikeTerm := "%" + q + "%"
	reversed := reverseString(q)
	backwardsTerm := reversed + "%"

	var userIDs []uint64
	// Same .Table(expr, args...)
	// derived-table mechanism as GetSearchesForUser (06d02e94fa4a): the whole
	// parenthesised UNION is the "table", so it renders verbatim rather than
	// being quoted as an identifier.
	db.Table("("+
		"(SELECT userid FROM users_emails WHERE email LIKE ? OR canon LIKE ? OR backwards LIKE ?) "+
		"UNION "+
		"(SELECT id AS userid FROM users WHERE fullname LIKE ?) "+
		"UNION "+
		"(SELECT id AS userid FROM users WHERE yahooid LIKE ?) "+
		"UNION "+
		"(SELECT id AS userid FROM users WHERE id = ?) "+
		"UNION "+
		"(SELECT userid FROM users_logins WHERE uid LIKE ?) "+
		") t",
		emailLikeTerm, prefixTerm, backwardsTerm, prefixTerm, prefixTerm, numericID, prefixTerm).
		Select("DISTINCT userid").
		Order("userid ASC").
		Limit(100).
		Pluck("userid", &userIDs)

	return c.JSON(fiber.Map{"users": userIDs})
}

func generateRandomKey(length int) string {
	const chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789"
	b := make([]byte, length)
	for i := range b {
		n, _ := rand.Int(rand.Reader, big.NewInt(int64(len(chars))))
		b[i] = chars[n.Int64()]
	}
	return string(b)
}

func ReverseString(s string) string {
	return reverseString(s)
}

func reverseString(s string) string {
	runes := []rune(s)
	for i, j := 0, len(runes)-1; i < j; i, j = i+1, j-1 {
		runes[i], runes[j] = runes[j], runes[i]
	}
	return string(runes)
}

// enrichUserForModtools adds modtools-specific data to a user when modtools=true.
// enrichUserForModtools adds modtools-specific data to a user when modtools=true.
// This includes memberships, emails, messagehistory, location, comments, donations, etc.
func enrichUserForModtools(u *User, id uint64, myid uint64, modtools bool) {
	db := database.DBConn

	// SECURITY: gate the two genuinely sensitive modtools-only fields - a user's posting history
	// (including pending posts) and their precise/private location - on the caller being a platform
	// moderator (system role) or the user themselves. `modtools` is a client-supplied query flag and
	// myid may be 0 (anonymous), so without this an anonymous/ordinary caller could pass
	// ?modtools=true and read anyone's posting history and precise location (this data is not shown
	// on the public Freegle site, only in ModTools). Other modtools fields keep their own gates
	// (giftaid = PERM_GIFTAID, modmails = the caller's mod-group filter, public location = public by
	// design), so we do NOT disable modtools wholesale.
	modDataAuthz := myid > 0 && (auth.IsSystemMod(myid) || myid == id)

	var memberships []Membership
	var emails []UserEmail
	var messageHistory []UserMessageHistory
	var privatePos utils.LatLng
	var publicLoc *Publiclocation
	var modmails uint64
	var wg sync.WaitGroup

	// Fetch memberships for authenticated requests only.
	if myid > 0 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			memberships = GetMemberships(id)
		}()
	}

	// Emails: visible to the user themselves, mods of the user, or admin/support.
	if myid > 0 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if IsModOfUser(myid, id) || id == myid || auth.IsAdminOrSupport(myid) {
				emails = getEmails(id)
			}
		}()
	}

	// Sensitive: posting history and precise/private location - mod (system role) or self only.
	if modtools && modDataAuthz {
		wg.Add(1)
		go func() {
			defer wg.Done()
			messageHistory = GetUserMessageHistory(id)
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()
			privatePos = GetLatLng(id)
		}()
	}

	// Public location and the mod-group-filtered modmail count keep their own scoping.
	if modtools {
		wg.Add(1)
		go func() {
			defer wg.Done()
			publicLoc = GetPublicLocationForUser(id)
		}()

		wg.Add(1)
		go func() {
			defer wg.Done()
			modGroupIDs := GetActiveModGroupIDs(myid)
			if len(modGroupIDs) > 0 {
				// modmails is uint64
				// (not int64), so this stays Select+Scan rather than Count,
				// which only accepts *int64.
				db.Table("users_modmails").Select("COUNT(*)").
					Where("userid = ? AND groupid IN ?", id, modGroupIDs).Scan(&modmails)
			}
		}()
	}

	var lastpush *time.Time
	if modtools {
		wg.Add(1)
		go func() {
			defer wg.Done()
			var lastpushStr *string
			db.Table("users_push_notifications").Select("MAX(lastsent)").Where("userid = ?", id).Scan(&lastpushStr)
			if lastpushStr != nil {
				if parsed, err := time.Parse("2006-01-02 15:04:05", *lastpushStr); err == nil && !parsed.IsZero() {
					lastpush = &parsed
				}
			}
		}()
	}

	var activedistance *float64
	callerIsMod := false
	if modtools && myid > 0 {
		wg.Add(1)
		go func() {
			defer wg.Done()
			callerIsMod = IsModOfUser(myid, id)
		}()
	}

	if modtools {
		wg.Add(1)
		go func() {
			defer wg.Done()
			type groupLatLng struct {
				Lat float64
				Lng float64
			}
			var locs []groupLatLng
			// mh.rippled = 0: exclude memberships created by rippling auto-join (Rippling Out,
			// ExpandService::addPosterMembershipToRippledGroups). Those follow a post's reach, not
			// a choice by the member, so counting them flags/bans innocent freeglers for spread they
			// never caused (Discourse 10064/1).
			db.Table("memberships_history mh").
				Select("DISTINCT g.lat, g.lng").
				Joins("INNER JOIN `groups` g ON mh.groupid = g.id").
				Where("mh.userid = ? AND mh.rippled = 0 AND DATEDIFF(NOW(), mh.added) <= 31 AND g.publish = 1 AND g.onmap = 1 AND g.lat != 0 AND g.lng != 0", id).
				Scan(&locs)
			if len(locs) >= 2 {
				var swlat, swlng, nelat, nelng float64
				swlat, swlng = locs[0].Lat, locs[0].Lng
				nelat, nelng = locs[0].Lat, locs[0].Lng
				for _, loc := range locs[1:] {
					if loc.Lat < swlat {
						swlat = loc.Lat
					}
					if loc.Lng < swlng {
						swlng = loc.Lng
					}
					if loc.Lat > nelat {
						nelat = loc.Lat
					}
					if loc.Lng > nelng {
						nelng = loc.Lng
					}
				}
				dist := utils.Haversine(swlat, swlng, nelat, nelng)
				rounded := math.Round(dist)
				activedistance = &rounded
			}
		}()
	}

	// Under rippling-out a post's reach follows the poster's declared location, so a member who keeps
	// changing location is the spam vector that group-spread (activedistance) used to be. Surface the
	// count of distinct postcodes they have set in the last 90 days so mods reviewing a flagged member
	// can see the hopping directly. We expose the count (cleanly available from the PostcodeChange log)
	// rather than a geographic spread, which would need historical lat/lng we do not retain.
	var locationchanges *int
	if modtools {
		wg.Add(1)
		go func() {
			defer wg.Done()
			var n int
			db.Table("logs").Select("COUNT(DISTINCT text)").
				Where("user = ? AND type = ? AND subtype = ? AND timestamp >= NOW() - INTERVAL 90 DAY",
					id, log2.LOG_TYPE_USER, log2.LOG_SUBTYPE_POSTCODECHANGE).Scan(&n)
			if n > 0 {
				locationchanges = &n
			}
		}()
	}

	wg.Wait()

	// Resolve NULL ourPostingStatus → MODERATED.
	// DEFAULT stays as DEFAULT — it's an explicit status meaning "follow group default".
	if modtools {
		for i := range memberships {
			m := &memberships[i]
			if m.OurPostingStatus == nil || *m.OurPostingStatus == "" {
				v := utils.POSTING_STATUS_MODERATED
				m.OurPostingStatus = &v
			}
		}
	} else {
		// Non-modtools: strip posting status (mod-only field).
		for i := range memberships {
			memberships[i].OurPostingStatus = nil
		}
	}

	u.Memberships = memberships
	u.MessageHistory = messageHistory
	u.Modmails = modmails

	if callerIsMod || myid == id || auth.IsAdminOrSupport(myid) {
		u.Activedistance = activedistance
		u.Locationchanges = locationchanges
		u.Lastpush = lastpush
	}

	if modtools {
		if privatePos.Lat != 0 || privatePos.Lng != 0 {
			var locNamePtr *string
			db.Table("users").Select("JSON_UNQUOTE(JSON_EXTRACT(JSON_EXTRACT(settings, '$.mylocation'), '$.name'))").
				Where("id = ? AND settings IS NOT NULL", id).Scan(&locNamePtr)

			locName := ""
			if locNamePtr != nil && *locNamePtr != "null" {
				locName = *locNamePtr
			}

			if locName == "" {
				locName = ""
				if u.Lastlocation != nil && *u.Lastlocation > 0 {
					db.Table("locations").Select("name").Where("id = ?", *u.Lastlocation).Scan(&locName)
				}
			}

			if locName == "" && (privatePos.Lat != 0 || privatePos.Lng != 0) {
				// Nearest postcode via the spatial sidecar's KNN index rather than an
				// unindexed bounding-box + distance-sort scan of `locations` (see user.go:1077
				// for the same helper used for an analogous need). The KNN has no distance
				// cap, so keep the replaced query's ~0.1-degree bounding box as a guard: a
				// position with no postcode that close used to show nothing, not a postcode
				// from miles away.
				//
				// Site 753270f0ca22 (formerly wave 1) is retired, not converted-and-kept:
				// master replaced the SQL statement entirely with this KNN sidecar call,
				// so there is no query left for the manifest to describe.
				closest := location.ClosestPostcode(privatePos.Lat, privatePos.Lng)
				if closest.Name != "" &&
					math.Abs(float64(closest.Lat-privatePos.Lat)) <= 0.1 &&
					math.Abs(float64(closest.Lng-privatePos.Lng)) <= 0.1 {
					locName = closest.Name
				}
			}

			u.Privateposition = &PrivatePosition{
				Lat:  privatePos.Lat,
				Lng:  privatePos.Lng,
				Name: locName,
				Loc:  locName,
			}
		}
		if publicLoc != nil {
			u.Info.Publiclocation = publicLoc
		}
	}

	if len(emails) > 0 {
		u.Emails = emails
		// Prefer a non-internal email; fall back to an internal one if no external address exists.
		for _, email := range emails {
			if u.Email == "" && utils.OurDomain(email.Email) == 0 {
				u.Email = email.Email
			}
		}
		if u.Email == "" {
			u.Email = emails[0].Email
		}
	}

	if modtools && myid > 0 {
		comments := GetComments([]uint64{id}, myid)
		if c, ok := comments[id]; ok {
			u.Comments = c
		}

		if auth.HasPermission(myid, auth.PERM_GIFTAID) {
			var donations []UserDonation
			db.Table("users_donations").Select("id, userid, timestamp, GrossAmount, source, TransactionType, giftaidconsent").
				Where("userid = ?", id).Order("timestamp DESC").Scan(&donations)
			if len(donations) > 0 {
				u.Donations = donations
			}

			var giftaid UserGiftAid
			result := db.Table("giftaid").Select("id, userid, timestamp, period").
				Where("userid = ? AND deleted IS NULL", id).Limit(1).Scan(&giftaid)
			if result.RowsAffected > 0 {
				u.Giftaid = &giftaid
			}
		}

		if auth.IsAdminOrSupport(myid) {
			// Generate login link for impersonation.
			// Admin can impersonate anyone, support can impersonate non-mods.
			isAdmin := auth.IsAdmin(myid)
			canImpersonate := isAdmin || !auth.IsSystemMod(id)
			// id 0 is a ghost reference (e.g. a purged user still linked from
			// a chat): creating a Link credential for it fails the users FK
			// (Error 1452, steadily since at least May) and the u=0 login
			// link it would emit is dead anyway.
			if canImpersonate && id > 0 {
				var key string
				db.Table("users_logins").Select("credentials").Where("userid = ? AND type = 'Link'", id).Limit(1).Scan(&key)
				if key == "" {
					key = generateRandomKey(32)
					db.Table("users_logins").Create(map[string]interface{}{
						"userid":      id,
						"type":        gorm.Expr("'Link'"),
						"credentials": key,
					})
				}
				if key != "" {
					userSite := os.Getenv("USER_SITE")
					if userSite == "" {
						userSite = "www.ilovefreegle.org"
					}
					u.Loginlink = fmt.Sprintf("https://%s/?u=%d&k=%s", userSite, id, key)
				}
			}
		}
	}
}

func AddMembership(userid uint64, groupid uint64, role string, collection string, emailfrequency int, eventsallowed int, volunteeringallowed int, reason string) bool {
	db := database.DBConn

	ret := false

	// See if we're already a member, and whether we're banned.
	var wg = sync.WaitGroup{}
	var membership MembershipTable
	var banned uint64

	wg.Add(1)
	go func() {
		defer wg.Done()
		db.Where("userid = ? AND groupid = ?", userid, groupid).Limit(1).Find(&membership)
	}()

	wg.Add(1)
	go func() {
		defer wg.Done()
		// Note: the chained Limit(1) on the pre-conversion db.Raw(...) call was a
		// no-op — GORM's query builder only applies clauses when Statement.SQL is
		// still empty, and Raw() sets it immediately, so the real query never
		// carried a LIMIT. Preserved here as-is (no Limit) to match the recorded
		// golden SQL and actual prior behaviour exactly.
		db.Table("users_banned").Select("userid").Where("userid = ? AND groupid = ?", userid, groupid).Find(&banned)
	}()

	wg.Wait()

	if banned == 0 {
		ret = true

		if membership.ID == 0 {
			ret = false

			membership.Userid = userid
			membership.Groupid = groupid
			membership.Added = time.Now()
			membership.Role = role
			membership.Collection = collection
			membership.Emailfrequency = emailfrequency
			membership.Eventsallowed = eventsallowed
			membership.Volunteeringallowed = volunteeringallowed

			// Two concurrent joins (double-click, retry) both pass the
			// membership check above and race the insert; the loser used to
			// 1062 on memberships.userid_groupid (a few times a day, logged as
			// an error). DoNothing renders a no-op ON DUPLICATE KEY UPDATE, so
			// the loser keeps ID == 0 and takes exactly the path it always
			// took - just without the error.
			db.Clauses(clause.OnConflict{DoNothing: true}).Create(&membership)

			if membership.ID > 0 {
				ret = true

				var wg2 = sync.WaitGroup{}

				wg2.Add(1)
				go func() {
					defer wg2.Done()

					// Add to membership history for abuse detection.
					var history MembershipHistory

					history.Userid = userid
					history.Groupid = groupid
					history.Added = membership.Added
					history.Collection = collection

					// Set processingrequired for background processing (welcome email, spam check, etc).
					history.Processingrequired = true

					db.Create(&history)
				}()

				wg2.Add(1)
				go func() {
					// Log the membership.
					defer wg2.Done()
					log2.Log(log2.LogEntry{
						Type:    log2.LOG_TYPE_GROUP,
						Subtype: log2.LOG_SUBTYPE_JOINED,
						User:    &userid,
						Byuser:  &userid,
						Groupid: &groupid,
						Text:    &reason,
					})
				}()

				wg2.Wait()

				// At the moment we only add members from the FD client, so we don't need to change the system role.

				// Welcome email, spam check, and member review are handled by the
				// background cron (memberships_processing) which picks up rows
				// with processingrequired=1 in memberships_history.
			}
		}
	}

	return ret
}

type UserPostRequest struct {
	Action   string           `json:"action"`
	Engageid utils.FlexUint64 `json:"engageid"`
	Ratee    uint64           `json:"ratee"`
	Rating   *string          `json:"rating"`
	Reason   *string          `json:"reason"`
	Text     *string          `json:"text"`
	Ratingid uint64           `json:"ratingid"`
	ID       uint64           `json:"id"`
	Email    string           `json:"email"`
	Primary  *bool            `json:"primary"`
	ID1      utils.FlexUint64 `json:"id1"`
	ID2      utils.FlexUint64 `json:"id2"`
	Email1   string           `json:"email1"`
	Email2   string           `json:"email2"`
}

func PostUser(c *fiber.Ctx) error {
	myid := WhoAmI(c)

	var req UserPostRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Engaged doesn't require login.
	if req.Engageid > 0 {
		return handleEngaged(c, db, uint64(req.Engageid))
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	switch req.Action {
	case "Rate":
		return handleRate(c, db, myid, req)
	case "RatingReviewed":
		return handleRatingReviewed(c, db, myid, req)
	case "AddEmail":
		return handleAddEmail(c, db, myid, req)
	case "RemoveEmail":
		return handleRemoveEmail(c, db, myid, req)
	case "Unbounce":
		return handleUnbounce(c, myid, req)
	case "Unsubscribe":
		return handleUserUnsubscribe(c, myid, req)
	case "Merge":
		return handleMerge(c, myid, req)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
	}
}

func handleEngaged(c *fiber.Ctx, db *gorm.DB, engageid uint64) error {
	// Record engagement success.
	var mailid uint64
	db.Table("engage").Select("mailid").Where("id = ?", engageid).Scan(&mailid)

	if mailid > 0 {
		db.Table("engage").Where("id = ?", engageid).Update("succeeded", gorm.Expr("NOW()"))
		db.Table("engage_mails").Where("id = ?", mailid).
			Updates(map[string]interface{}{"action": gorm.Expr("action + 1"), "rate": gorm.Expr("COALESCE(100 * action / shown, 0)")})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func handleRate(c *fiber.Ctx, db *gorm.DB, myid uint64, req UserPostRequest) error {
	if req.Ratee == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "ratee is required")
	}

	// Validate rating value.
	if req.Rating != nil && *req.Rating != utils.RATING_UP && *req.Rating != utils.RATING_DOWN {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid rating value")
	}

	// Can't rate yourself.
	if req.Ratee == myid {
		return fiber.NewError(fiber.StatusBadRequest, "Cannot rate yourself")
	}

	// Determine if review is required (down-vote with reason and text).
	reviewRequired := false
	if req.Rating != nil && *req.Rating == utils.RATING_DOWN && req.Reason != nil && req.Text != nil {
		reviewRequired = true
	}

	db.Table("ratings").Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(map[string]interface{}{
			"rater": myid, "ratee": req.Ratee, "rating": req.Rating, "reason": req.Reason,
			"text": req.Text, "timestamp": gorm.Expr("NOW()"), "reviewrequired": reviewRequired,
		})

	// Update lastupdated for both users.
	db.Table("users").Where("id IN (?, ?)", myid, req.Ratee).Update("lastupdated", gorm.Expr("NOW()"))

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func handleRatingReviewed(c *fiber.Ctx, db *gorm.DB, myid uint64, req UserPostRequest) error {
	if req.Ratingid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "ratingid is required")
	}

	// Verify the caller is admin/support or a mod of a group the ratee belongs to.
	if !auth.IsAdminOrSupport(myid) {
		var count int64
		db.Table("ratings r").
			Select("COUNT(*)").
			Joins("JOIN memberships m1 ON m1.userid = r.ratee").
			Joins("JOIN memberships m2 ON m2.groupid = m1.groupid AND m2.userid = ?", myid).
			Where("r.id = ? AND m2.role IN (?, ?)", req.Ratingid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
			Scan(&count)
		if count == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Not authorized to review this rating")
		}
	}

	db.Table("ratings").Where("id = ?", req.Ratingid).Update("reviewrequired", gorm.Expr("0"))

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func handleAddEmail(c *fiber.Ctx, db *gorm.DB, myid uint64, req UserPostRequest) error {
	if req.Email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "email is required")
	}

	email := strings.TrimSpace(req.Email)
	targetID := req.ID
	if targetID == 0 {
		targetID = myid
	}

	// Only allow if admin/support or own account.
	if targetID != myid {
		if !auth.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusForbidden, "You cannot administer those users")
		}
	}

	// Check if email already exists in the table.
	var existingUID *uint64
	var existingID uint64
	row := db.Table("users_emails").Select("id, userid").Where("email = ?", email).Limit(1).Row()
	if row != nil {
		row.Scan(&existingID, &existingUID)
	}

	canon := CanonicalizeEmail(email)
	isPrimary := true
	if req.Primary != nil {
		isPrimary = *req.Primary
	}
	var primaryVal int
	if isPrimary {
		primaryVal = 1
	}

	if existingID > 0 {
		if existingUID != nil && *existingUID == targetID {
			// Already on this user — update preferred if needed.
			if isPrimary {
				db.Table("users_emails").Where("id = ?", existingID).Update("preferred", primaryVal)
				db.Table("users_emails").Where("userid = ? AND id != ?", targetID, existingID).Update("preferred", gorm.Expr("0"))
			}
			return c.JSON(fiber.Map{"ret": 0, "status": "Success", "emailid": existingID})
		}

		if existingUID != nil && *existingUID != targetID {
			// Email is used by a different user.
			if !auth.IsAdminOrSupport(myid) {
				return c.Status(fiber.StatusConflict).JSON(fiber.Map{"ret": 3, "status": "Email already used"})
			}
		}

		// Orphaned row (userid IS NULL) or admin reassigning: update the existing row.
		// None of these five
		// assignments reference another assigned column, so the SET order is
		// not load-bearing and GORM's alphabetical Updates(map) order is safe;
		// see the retired check-set-order.sh / setOrderIsLoadBearing (removed
		// in d22ba1d6c).
		db.Table("users_emails").Where("id = ?", existingID).Updates(map[string]interface{}{
			"userid":    targetID,
			"preferred": primaryVal,
			"validated": gorm.Expr("NOW()"),
			"canon":     canon,
			"backwards": reverseString(canon),
		})

		if isPrimary {
			db.Table("users_emails").Where("userid = ? AND id != ?", targetID, existingID).Update("preferred", gorm.Expr("0"))
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success", "emailid": existingID})
	}

	// Email doesn't exist at all — insert new row. Table()+map Create reads the
	// generated id back from the same sql.Result the INSERT returned, under
	// the map key "@id" (see test/insertid_gorm_writeback_test.go) - the
	// write connection, same as ExecInsertGetID, so still immune to the read/write
	// split's Discourse-9832-class staleness.
	// Named newRow, not row:
	// "row" is already declared above (line ~1809) as the *sql.Row from the
	// existing-email lookup, and this map has an incompatible type - reusing
	// the name here made := illegal (no new variable on the left) and, worse,
	// silently type-mismatched had Go allowed it.
	newRow := map[string]interface{}{
		"userid":    targetID,
		"email":     email,
		"preferred": primaryVal,
		"validated": gorm.Expr("NOW()"),
		"canon":     canon,
		"backwards": reverseString(canon),
	}
	if err := db.Table("users_emails").Create(newRow).Error; err != nil {
		return c.Status(fiber.StatusInternalServerError).JSON(fiber.Map{"ret": 4, "status": "Email add failed"})
	}
	emailIDInt, _ := newRow["@id"].(int64)
	emailID := uint64(emailIDInt)

	if isPrimary {
		db.Table("users_emails").Where("userid = ? AND email != ?", targetID, email).Update("preferred", gorm.Expr("0"))
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "emailid": emailID})
}

func handleRemoveEmail(c *fiber.Ctx, db *gorm.DB, myid uint64, req UserPostRequest) error {
	if req.Email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "email is required")
	}

	targetID := req.ID
	if targetID == 0 {
		targetID = myid
	}

	// Only allow if admin/support or own account.
	if targetID != myid {
		if !auth.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusForbidden, "You cannot administer those users")
		}
	}

	// Verify email belongs to this user.
	var emailUserid uint64
	db.Table("users_emails").Select("userid").Where("email = ? AND userid = ?", req.Email, targetID).Scan(&emailUserid)

	if emailUserid == 0 {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{"ret": 3, "status": "Not on same user"})
	}

	db.Table("users_emails").Where("email = ? AND userid = ?", req.Email, targetID).Delete(nil)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// CanonicalizeEmail returns a canonical form of the email for deduplication.
func CanonicalizeEmail(email string) string {
	email = strings.ToLower(strings.TrimSpace(email))
	parts := strings.SplitN(email, "@", 2)
	if len(parts) != 2 {
		return email
	}
	// Remove dots and plus-addressing from local part for Gmail-style canonicalization.
	local := strings.ReplaceAll(parts[0], ".", "")
	if idx := strings.Index(local, "+"); idx >= 0 {
		local = local[:idx]
	}
	return local + "@" + parts[1]
}

// UserPutRequest is the body for PUT /user (signup).
type UserPutRequest struct {
	Email       string `json:"email"`
	Password    string `json:"password"`
	Firstname   string `json:"firstname"`
	Lastname    string `json:"lastname"`
	Displayname string `json:"displayname"`
	GroupID     uint64 `json:"groupid"`
}

// UserPatchRequest is the body for PATCH /user (profile update).
type UserPatchRequest struct {
	ID                 uint64           `json:"id"`
	Displayname        *string          `json:"displayname,omitempty"`
	Settings           *json.RawMessage `json:"settings,omitempty"`
	Onholidaytill      *string          `json:"onholidaytill,omitempty"`
	Relevantallowed    *utils.FlexInt   `json:"relevantallowed,omitempty"`
	Newslettersallowed *utils.FlexInt   `json:"newslettersallowed,omitempty"`
	Aboutme            *string          `json:"aboutme,omitempty"`
	Newsfeedmodstatus  *string          `json:"newsfeedmodstatus,omitempty"`
	Email              *string          `json:"email,omitempty"`
	Source             *string          `json:"source,omitempty"`
	Password           *string          `json:"password,omitempty"`
	Trustlevel         *string          `json:"trustlevel,omitempty"`
}

// UserDeleteRequest is the body for DELETE /user.
type UserDeleteRequest struct {
	ID uint64 `json:"id"`
}

// PutUser creates a new user (signup).
//
// @Summary Create/signup a new user
// @Tags user
// @Accept json
// @Produce json
// @Param body body UserPutRequest true "Signup details"
// @Success 200 {object} map[string]interface{}
// @Router /user [put]
func PutUser(c *fiber.Ctx) error {
	var req UserPutRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Email == "" {
		return fiber.NewError(fiber.StatusBadRequest, "email is required")
	}

	email := strings.TrimSpace(req.Email)
	db := database.DBConn

	// Check if email already exists.
	var existingUID uint64
	db.Table("users_emails").Select("userid").Where("email = ?", email).Limit(1).Scan(&existingUID)

	if existingUID > 0 {
		// Authenticated callers (e.g. moderators using Add Member in ModTools) get the existing
		// user's ID back — idempotent, mirrors PHP v1 behaviour for mods.
		myid := WhoAmI(c)
		if myid > 0 {
			return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": existingUID})
		}

		// If they provided a correct password, treat signup as login — avoids
		// forcing users to switch to the login screen and re-enter credentials.
		if req.Password != "" && auth.VerifyPassword(existingUID, req.Password) {
			persistent, jwtString, err := auth.CreateSessionAndJWT(existingUID)
			if err != nil {
				return fiber.NewError(fiber.StatusInternalServerError, "Failed to create session")
			}
			return c.JSON(fiber.Map{
				"ret":        0,
				"status":     "Success",
				"id":         existingUID,
				"persistent": persistent,
				"jwt":        jwtString,
			})
		}

		return c.Status(fiber.StatusConflict).JSON(fiber.Map{
			"ret":    2,
			"status": "That email is already in use",
		})
	}

	// Build display name from parts.
	fullname := strings.TrimSpace(req.Displayname)
	if fullname == "" {
		parts := []string{}
		if req.Firstname != "" {
			parts = append(parts, req.Firstname)
		}
		if req.Lastname != "" {
			parts = append(parts, req.Lastname)
		}
		fullname = strings.Join(parts, " ")
	}

	var firstname *string
	var lastname *string
	if req.Firstname != "" {
		firstname = &req.Firstname
	}
	if req.Lastname != "" {
		lastname = &req.Lastname
	}

	// Create user. Table()+map Create reads the generated id back from the
	// same sql.Result the INSERT returned (gorm.io/gorm/callbacks/create.go),
	// under the map key "@id" - no separate connection-scoped
	// SELECT LAST_INSERT_ID() query, so no connection-pool race. See
	// test/insertid_gorm_writeback_test.go, which proves this against the
	// real database.
	row := map[string]interface{}{
		"fullname":  fullname,
		"firstname": firstname,
		"lastname":  lastname,
		"added":     gorm.Expr("NOW()"),
	}
	if err := db.Table("users").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
	}

	newUserIDInt, _ := row["@id"].(int64)
	if newUserIDInt == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to get new user ID")
	}
	newUserID := uint64(newUserIDInt)

	// Add email.
	canon := CanonicalizeEmail(email)
	db.Table("users_emails").Create(map[string]interface{}{
		"userid":    newUserID,
		"email":     email,
		"preferred": gorm.Expr("1"),
		"validated": gorm.Expr("NOW()"),
		"canon":     canon,
		"backwards": reverseString(canon),
	})

	// Generate random password if none provided (for email-only signup).
	// The client shows this to the user in the welcome modal.
	password := req.Password
	if password == "" {
		password = utils.RandomHex(4) // 8 char random hex password
	}

	// Hash with sha1+salt and store.
	salt := os.Getenv("PASSWORD_SALT")
	if salt == "" {
		salt = "zzzz"
	}
	h := sha1.New()
	h.Write([]byte(password + salt))
	hashed := hex.EncodeToString(h.Sum(nil))
	db.Table("users_logins").Create(map[string]interface{}{
		"userid":      newUserID,
		"type":        utils.LOGIN_TYPE_NATIVE,
		"uid":         newUserID,
		"credentials": hashed,
		"salt":        salt,
	})

	// If groupid provided, add membership.
	if req.GroupID > 0 {
		result := db.Table("memberships").Create(map[string]interface{}{
			"userid":     newUserID,
			"groupid":    req.GroupID,
			"role":       utils.ROLE_MEMBER,
			"collection": utils.COLLECTION_APPROVED,
		})
		if result.RowsAffected > 0 {
			db.Table("logs").Create(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"type":      log2.LOG_TYPE_GROUP,
				"subtype":   log2.LOG_SUBTYPE_JOINED,
				"groupid":   req.GroupID,
				"user":      newUserID,
				"byuser":    newUserID,
			})

			// V1 parity (User::addMembership, User.php:911-916): record the join in
			// memberships_history with processingrequired=1 so the background
			// member-review / welcome / abuse-detection consumer (memberships:process)
			// treats this as a brand-new joiner. AddMembership() writes this row, but
			// the website-signup path inserts the membership inline and never calls it,
			// so without this the new member bypasses new-joiner scrutiny.
			db.Table("memberships_history").Create(map[string]interface{}{
				"userid":             newUserID,
				"groupid":            req.GroupID,
				"collection":         utils.COLLECTION_APPROVED,
				"processingrequired": gorm.Expr("1"),
				"added":              gorm.Expr("NOW()"),
			})
		}
	}

	// Create a session. Series is a random numeric value (bigint unsigned);
	// token is a random hex string. Previously passed userID for series,
	// which collided across every session for the same user.
	series := utils.RandomUint64()
	token := utils.RandomHex(16)
	// Use the INSERT's LastInsertId (write connection) rather than a
	// "SELECT ... WHERE userid ORDER BY id DESC LIMIT 1", which the read/write
	// split routes to a replica that can return the user's PREVIOUS session under
	// Galera's cross-node apply window - putting the wrong session id in the JWT.
	var sessionID uint64
	sessionRow := map[string]interface{}{
		"userid":     newUserID,
		"series":     series,
		"token":      token,
		"lastactive": gorm.Expr("NOW()"),
	}
	if db.Table("sessions").Create(sessionRow).Error == nil {
		if lastID, ok := sessionRow["@id"].(int64); ok && lastID > 0 {
			sessionID = uint64(lastID)
		}
	}

	// Generate JWT.
	jwtToken := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        fmt.Sprint(newUserID),
		"sessionid": fmt.Sprint(sessionID),
		"exp":       time.Now().Unix() + 30*24*60*60, // 30 days
	})

	jwtString, err := jwtToken.SignedString([]byte(os.Getenv("JWT_SECRET")))
	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to generate JWT")
	}

	// If an authenticated user (e.g. a moderator using Add Member) created this account,
	// don't return auth tokens — returning them would overwrite the caller's own session.
	// Auth tokens are only needed for self-signup where the caller becomes the new user.
	callerID := WhoAmI(c)
	if callerID > 0 {
		resp := fiber.Map{
			"ret":    0,
			"status": "Success",
			"id":     newUserID,
		}
		if req.Password == "" {
			resp["password"] = password
		}
		return c.JSON(resp)
	}

	resp := fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     newUserID,
		"persistent": fiber.Map{
			"id":     sessionID,
			"series": series,
			"token":  token,
			"userid": newUserID,
		},
		"jwt": jwtString,
	}

	// Return the generated password so the client can show it in the welcome modal.
	if req.Password == "" {
		resp["password"] = password
	}

	return c.JSON(resp)
}

// ProcessSettingsUpdate applies V1-parity side effects when settings are saved:
//   - If mylocation changes to a new Postcode, update lastlocation, clear isochrones, log PostcodeChange.
//   - Prune groupsnear from mylocation before saving (keeps the DB row smaller).
//
// Returns the pruned settings JSON ready for storage, and appends any SET clauses needed for the
// users table (e.g. lastlocation) to the provided slices. Callers must include those clauses in
// their UPDATE statement.
// RapidLocationChangeThreshold is the number of DISTINCT postcodes a user may set within 24 hours
// before they are flagged for moderator review. Rippling-out makes a post's reach follow the
// poster's declared location (not their group memberships), so rapidly hopping location is the new
// way to push posts into unrelated areas. This catches that pattern; it is the one spam signal
// rippling newly requires (see plans/rippling-out-rollout/spam-checks-review-2026-06-18.md).
//
// Relaxed 4 -> 8 alongside the rippling go-live (this PR): under rippling, legitimate members
// refine/correct their declared location more than before (it now drives what they see and where
// their posts reach), so a few changes in a day is expected. 8 distinct postcodes in 24h is still
// clearly abnormal and keeps the guard against genuine reach-hopping while cutting the false
// positives the tighter value produced. Like PR #818's SEEN_THRESHOLD relax, this WEAKENS a spam
// guard and is only safe once rippling is live. Tune the constant if it proves too tight/loose.
const RapidLocationChangeThreshold = 8

// CheckLocationChangeVelocity flags a user for moderator review when they have set too many distinct
// postcodes in the last 24 hours. It is NON-DESTRUCTIVE: it sets the existing member-review flag
// that mods already act on (memberships.reviewrequestedat), NOT a block, post-suppression, or
// auto-ban, so a genuine mover/traveller is reviewed rather than punished. Moderators are never
// flagged. Called right after a PostcodeChange has been logged.
func CheckLocationChangeVelocity(db *gorm.DB, myid uint64) {
	if RapidLocationChangeThreshold <= 0 {
		return
	}

	var distinct int
	db.Table("logs").Select("COUNT(DISTINCT text)").
		Where("user = ? AND type = ? AND subtype = ? AND timestamp >= NOW() - INTERVAL 24 HOUR",
			myid, log2.LOG_TYPE_USER, log2.LOG_SUBTYPE_POSTCODECHANGE).Scan(&distinct)

	if distinct < RapidLocationChangeThreshold {
		return
	}

	// Never flag moderators/owners.
	var modCount int64
	db.Table("memberships").Where("userid = ? AND role IN (?, ?)",
		myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Count(&modCount)
	if modCount > 0 {
		return
	}

	reason := fmt.Sprintf("Changed location %d times in 24h (rippling-out reach-hopping signal)", distinct)

	// Flag the user's memberships for review, but don't re-flag rows already pending (only a fresh
	// request, or one whose previous request has already been actioned).
	// Neither assignment references
	// the other assigned column, so the SET order is not load-bearing.
	db.Table("memberships").Where("userid = ? "+
		"AND (reviewrequestedat IS NULL OR (reviewedat IS NOT NULL AND reviewedat >= reviewrequestedat))", myid).
		Updates(map[string]interface{}{
			"reviewrequestedat": gorm.Expr("NOW()"),
			"reviewreason":      reason,
		})

	log2.Log(log2.LogEntry{
		Type:    log2.LOG_TYPE_USER,
		Subtype: log2.LOG_SUBTYPE_SUSPECT,
		User:    &myid,
		Byuser:  &myid,
		Text:    &reason,
	})
}

func ProcessSettingsUpdate(settingsJSON []byte, myid uint64, setClauses *[]string, setArgs *[]interface{}) []byte {
	db := database.DBConn

	// Detect postcode change.
	var newSettings struct {
		Mylocation *struct {
			ID   *uint64 `json:"id"`
			Name *string `json:"name"`
			Type *string `json:"type"`
		} `json:"mylocation"`
	}
	if jsonErr := json.Unmarshal(settingsJSON, &newSettings); jsonErr == nil &&
		newSettings.Mylocation != nil &&
		newSettings.Mylocation.Type != nil && *newSettings.Mylocation.Type == "Postcode" &&
		newSettings.Mylocation.ID != nil {

		var oldLastlocation *uint64
		db.Table("users").Select("lastlocation").Where("id = ?", myid).Scan(&oldLastlocation)

		newLocID := *newSettings.Mylocation.ID
		if oldLastlocation == nil || *oldLastlocation != newLocID {
			*setClauses = append(*setClauses, "lastlocation = ?")
			*setArgs = append(*setArgs, newLocID)
			db.Table("isochrones_users").Where("userid = ?", myid).Delete(nil)

			var textPtr *string
			if newSettings.Mylocation.Name != nil {
				textPtr = newSettings.Mylocation.Name
			}
			log2.Log(log2.LogEntry{
				Type:    log2.LOG_TYPE_USER,
				Subtype: log2.LOG_SUBTYPE_POSTCODECHANGE,
				User:    &myid,
				Byuser:  &myid,
				Text:    textPtr,
			})

			// Rippling-out: reach now follows declared location, so flag rapid location-hopping
			// for moderator review (non-destructive).
			CheckLocationChangeVelocity(db, myid)
		}
	}

	// Prune groupsnear from mylocation.
	var rawMap map[string]interface{}
	if jsonErr := json.Unmarshal(settingsJSON, &rawMap); jsonErr == nil {
		if myloc, ok := rawMap["mylocation"].(map[string]interface{}); ok {
			delete(myloc, "groupsnear")
		}
		if pruned, jsonErr := json.Marshal(rawMap); jsonErr == nil {
			settingsJSON = pruned
		}
	}

	return settingsJSON
}

// PatchUser updates user profile fields.
//
// @Summary Update user profile
// @Tags user
// @Accept json
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /user [patch]
func PatchUser(c *fiber.Ctx) error {
	myid := WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req UserPatchRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	db := database.DBConn

	// Handle newsfeedmodstatus for another user (mod action).
	if req.Newsfeedmodstatus != nil && req.ID > 0 && req.ID != myid {
		// Verify caller is admin/support or mod of a shared group.
		if !auth.IsAdminOrSupport(myid) {
			// Check if they share a group where the caller is a mod.
			var sharedModGroup int64
			// Converted together with its
			// identical twin below (18a18b50e638): a half-converted pair renumbers
			// the survivor's site ID, so gate (h) refuses the split state.
			db.Table("memberships m1").
				Select("COUNT(*)").
				Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
				Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?)", myid, req.ID, utils.ROLE_OWNER, utils.ROLE_MODERATOR).
				Scan(&sharedModGroup)

			if sharedModGroup == 0 {
				return fiber.NewError(fiber.StatusForbidden, "Not authorized to moderate this user")
			}
		}

		db.Table("users").Where("id = ?", req.ID).Update("newsfeedmodstatus", *req.Newsfeedmodstatus)
		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	// Determine which user to update. Settings, relevantallowed, and
	// newslettersallowed can be updated by a mod acting on a member.
	// Other fields (displayname, aboutme, email, etc.) are self-only.
	targetID := myid
	if req.ID > 0 && req.ID != myid {
		if auth.IsAdminOrSupport(myid) {
			targetID = req.ID
		} else {
			var sharedModGroup int64
			// Twin of 4ccf389828b7 above.
			db.Table("memberships m1").
				Select("COUNT(*)").
				Joins("INNER JOIN memberships m2 ON m1.groupid = m2.groupid").
				Where("m1.userid = ? AND m2.userid = ? AND m1.role IN (?, ?)", myid, req.ID, utils.ROLE_OWNER, utils.ROLE_MODERATOR).
				Scan(&sharedModGroup)

			if sharedModGroup > 0 {
				targetID = req.ID
			}
		}
	}

	// Note: when a caller targets another user they aren't authorised to moderate,
	// targetID intentionally falls back to their own id - the mod-editable fields
	// then apply to self and the other user is left untouched (see
	// TestPatchUserSettingsNonModCannotUpdateOther). This is a deliberate safe
	// default, not the cause of the 9923 "settings won't stick" report (that was a
	// frontend value-reading bug, fixed separately).

	// Self-only updates always target the logged-in user.
	if req.Displayname != nil {
		// None of these three
		// assignments reference another assigned column.
		db.Table("users").Where("id = ?", myid).Updates(map[string]interface{}{
			"fullname":  *req.Displayname,
			"firstname": gorm.Expr("NULL"),
			"lastname":  gorm.Expr("NULL"),
		})
	}

	if req.Settings != nil {
		if settingsJSON, err := json.Marshal(req.Settings); err == nil {
			var setClauses []string
			var setArgs []interface{}
			settingsJSON = ProcessSettingsUpdate(settingsJSON, targetID, &setClauses, &setArgs)
			// ProcessSettingsUpdate appends at most ONE extra clause
			// ("lastlocation = ?", on a postcode change) - a genuine 2-shape
			// site, not the N-independent-fields kind PatchModConfig/
			// PatchSession are. Both shapes were proven by the retired
			// ormharness (shapes.json / TestTier1BatchShapes_941509171a6e,
			// removed in d22ba1d6c). Left setClauses/setArgs
			// untouched (session.go's PatchSession shares
			// ProcessSettingsUpdate and stays raw/string-based on purpose -
			// see f85b0b8ed693 - so its signature isn't changed here).
			assignments := clause.Set{}
			if len(setClauses) > 0 {
				assignments = append(assignments, clause.Assignment{
					Column: clause.Column{Name: "lastlocation"},
					Value:  setArgs[0],
				})
			}
			// Merge incoming settings into existing rather than replacing,
			// so partial updates don't wipe unrelated fields.
			assignments = append(assignments, clause.Assignment{
				Column: clause.Column{Name: "settings"},
				Value:  gorm.Expr("JSON_MERGE_PATCH(COALESCE(settings, '{}'), CAST(? AS JSON))", string(settingsJSON)),
			})
			// Surface a failed write rather than swallowing it and returning 200
			// (a silent no-op is how "settings won't stick" bugs hide).
			if res := db.Table("users").Clauses(assignments).Where("id = ?", targetID).Updates(map[string]interface{}{}); res.Error != nil {
				return fiber.NewError(fiber.StatusInternalServerError, "Failed to update settings")
			}
		}
	}

	if req.Onholidaytill != nil {
		if *req.Onholidaytill == "" {
			db.Table("users").Where("id = ?", myid).Update("onholidaytill", gorm.Expr("NULL"))
		} else {
			db.Table("users").Where("id = ?", myid).Update("onholidaytill", *req.Onholidaytill)
		}
	}

	if req.Relevantallowed != nil {
		db.Table("users").Where("id = ?", targetID).Update("relevantallowed", int(*req.Relevantallowed))
	}

	if req.Newslettersallowed != nil {
		db.Table("users").Where("id = ?", targetID).Update("newslettersallowed", int(*req.Newslettersallowed))
	}

	if req.Aboutme != nil {
		// Insert a new aboutme entry. The most recent is fetched via ORDER BY timestamp DESC LIMIT 1.
		db.Table("users_aboutme").Create(map[string]interface{}{
			"userid":    myid,
			"text":      *req.Aboutme,
			"timestamp": gorm.Expr("NOW()"),
		})
	}

	if req.Newsfeedmodstatus != nil {
		// Self-update (no req.ID or req.ID == myid).
		db.Table("users").Where("id = ?", myid).Update("newsfeedmodstatus", *req.Newsfeedmodstatus)
	}

	if req.Email != nil && *req.Email != "" {
		// Queue email verification rather than adding directly.
		// New addresses must be verified before being linked to the account.
		if err := queue.QueueTask(queue.TaskEmailVerify, map[string]interface{}{
			"user_id": myid,
			"email":   strings.TrimSpace(*req.Email),
		}); err != nil {
			// Log but don't fail the whole request.
			fmt.Printf("Failed to queue email verify for user %d: %v\n", myid, err)
		}
	}

	if req.Source != nil {
		db.Table("users").Where("id = ?", myid).Update("source", *req.Source)
	}

	if req.Password != nil && *req.Password != "" {
		targetID := myid
		if req.ID > 0 && req.ID != myid {
			// Setting password for another user requires admin/support.
			if !auth.IsAdminOrSupport(myid) {
				return fiber.NewError(fiber.StatusForbidden, "Not authorized to set password for another user")
			}
			targetID = req.ID
		}

		salt := auth.GetPasswordSalt()
		hashed := auth.HashPassword(*req.Password, salt)
		uid := strconv.FormatUint(targetID, 10)
		db.Table("users_logins").Clauses(clause.OnConflict{
			DoUpdates: clause.Assignments(map[string]interface{}{
				"credentials": hashed,
				"salt":        salt,
			}),
		}).Create(map[string]interface{}{
			"userid":      targetID,
			"type":        utils.LOGIN_TYPE_NATIVE,
			"uid":         uid,
			"credentials": hashed,
			"salt":        salt,
		})
	}

	// Trustlevel mirrors the legacy V1 PHP user API endpoint.
	// A moderator (systemrole Moderator/Support/Admin) can set any trust level
	// on any user. A regular user can only self-set Basic or Declined, or
	// clear it by sending an empty string. Attempts that don't match either
	// rule must be rejected — silent-drop was the NUXT3 bug that lost
	// microvolunteering "Decline" clicks.
	if req.Trustlevel != nil {
		trustTarget := req.ID
		if trustTarget == 0 {
			trustTarget = myid
		}

		// A moderator acting on self or someone else — full permission.
		isMod := auth.IsAdminOrSupport(myid)
		if !isMod {
			var systemrole string
			db.Table("users").Select("systemrole").Where("id = ?", myid).Scan(&systemrole)
			if systemrole == utils.SYSTEMROLE_MODERATOR {
				isMod = true
			}
		}

		if isMod {
			if *req.Trustlevel == "" {
				db.Table("users").Where("id = ?", trustTarget).Update("trustlevel", gorm.Expr("NULL"))
			} else {
				db.Table("users").Where("id = ?", trustTarget).Update("trustlevel", *req.Trustlevel)
			}
		} else {
			// Non-moderator: self only, Basic/Declined/empty only.
			if trustTarget != myid {
				return fiber.NewError(fiber.StatusForbidden, "Not authorized to set another user's trust level")
			}
			tl := *req.Trustlevel
			if tl == "" {
				db.Table("users").Where("id = ?", myid).Update("trustlevel", gorm.Expr("NULL"))
			} else if tl == "Basic" || tl == "Declined" {
				db.Table("users").Where("id = ?", myid).Update("trustlevel", tl)
			} else {
				return fiber.NewError(fiber.StatusForbidden, "Only moderators can set elevated trust levels")
			}
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// LimboUser soft-deletes a user — they can recover by logging back in within ~14 days.
// After the grace period, a background job calls handleForget to do GDPR erasure.
//
// @Summary Soft-delete (limbo) a user
// @Tags user
// @Produce json
// @Security BearerAuth
// @Success 200 {object} map[string]interface{}
// @Router /user [delete]
func LimboUser(c *fiber.Ctx) error {
	myid := WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Parse the target user ID from body or query.
	var req UserDeleteRequest
	_ = c.BodyParser(&req) // Ignore parse errors - body is optional, query param fallback below.

	if req.ID == 0 {
		// Try query parameter.
		if idStr := c.Query("id"); idStr != "" {
			fmt.Sscanf(idStr, "%d", &req.ID)
		}
	}

	targetID := req.ID
	if targetID == 0 {
		// Self-delete.
		targetID = myid
	}

	if targetID != myid {
		// Admin/support purging another user: queue an immediate GDPR forget.
		// Laravel's forgetUser() wipes all personal data and sets forgotten = NOW().
		// This matches V1 PHP behaviour where support DELETE was a hard purge, not a soft limbo.
		if !auth.IsAdminOrSupport(myid) {
			return fiber.NewError(fiber.StatusForbidden, "Only admin/support can delete other users")
		}

		// Cannot delete moderators/owners — they must demote themselves first.
		var targetModRole string
		db.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", targetID, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Limit(1).Scan(&targetModRole)

		if targetModRole != "" {
			return fiber.NewError(fiber.StatusForbidden, "Cannot delete a moderator/owner — they must demote first")
		}

		if err := queue.QueueTask(queue.TaskUserForget, map[string]interface{}{
			"user_id": targetID,
			"reason":  "Support purge",
			"by_user": myid,
		}); err != nil {
			log.Printf("LimboUser: failed to queue user_forget for user %d: %v", targetID, err)
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to queue purge")
		}

		return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
	}

	// Self-delete: put the user into limbo so they can recover within ~14 days.
	// A background job (users:cleanup) will call forgetUser() after the grace period.
	var modRole string
	db.Table("memberships").Select("role").Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Limit(1).Scan(&modRole)

	if modRole != "" {
		return c.Status(fiber.StatusBadRequest).JSON(fiber.Map{
			"ret":    2,
			"status": "Please demote yourself to a member first",
		})
	}

	var spammerCount int64
	db.Table("spam_users").Where("userid = ? AND collection IN (?, ?)", myid, utils.SPAM_COLLECTION_SPAMMER, utils.SPAM_COLLECTION_PENDING_ADD).Count(&spammerCount)

	if spammerCount > 0 {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{
			"ret":    3,
			"status": "We can't do this.",
		})
	}

	// Soft, recoverable limbo (shared with the Unsubscribe action).
	softLimboUser(db, targetID, myid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleUnbounce clears the bouncing flag on a user. A regular user may unbounce
// only themselves (the self-service "Try again" button in their own Settings —
// AccountSection.vue); admin/support may unbounce anyone. This mirrors the
// self-or-admin gate on handleUserUnsubscribe.
func handleUnbounce(c *fiber.Ctx, myid uint64, req UserPostRequest) error {
	db := database.DBConn

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	if req.ID != myid && !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Only admin/support can unbounce other users")
	}

	// Clear both the user-level bouncing flag and the per-email bounced
	// timestamps, matching the canonical reset (UnbounceDomainCommand). Leaving
	// users_emails.bounced set would let processBouncedEmails re-mark the address
	// invalid and re-flag the user as bouncing.
	db.Table("users").Where("id = ?", req.ID).Update("bouncing", gorm.Expr("0"))
	db.Table("users_emails").Where("userid = ?", req.ID).Update("bounced", gorm.Expr("NULL"))

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// LogGroupLeftForApprovedMemberships writes a per-group (Group, Left) audit log
// for every Approved membership the user currently holds. V1 emits one such log
// per group when the user actually leaves: at grace-period expiry the
// processForgets cron calls User::forget(), which iterates the memberships and
// calls User::removeMembership() per group, each writing a Left log
// (User.php:1087-1095). V2 instead bulk-deletes approved memberships eagerly at
// delete time, so by the time the cleanup cron runs there is nothing left to
// iterate and the Left logs would never be written — at any point. We therefore
// emit them here, immediately before the bulk delete. byUser is the actor recorded
// in the log; pass 0 to record byuser as NULL (e.g. the partner flow, which has no
// acting Freegle user).
func LogGroupLeftForApprovedMemberships(db *gorm.DB, targetID uint64, byUser uint64) {
	var groupids []uint64
	db.Table("memberships").Select("groupid").Where("userid = ? AND collection = ?",
		targetID, utils.COLLECTION_APPROVED).Scan(&groupids)
	for _, groupid := range groupids {
		if byUser == 0 {
			db.Table("logs").Create(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"type":      log2.LOG_TYPE_GROUP,
				"subtype":   log2.LOG_SUBTYPE_LEFT,
				"user":      targetID,
				"byuser":    gorm.Expr("NULL"),
				"groupid":   groupid,
			})
		} else {
			db.Table("logs").Create(map[string]interface{}{
				"timestamp": gorm.Expr("NOW()"),
				"type":      log2.LOG_TYPE_GROUP,
				"subtype":   log2.LOG_SUBTYPE_LEFT,
				"user":      targetID,
				"byuser":    byUser,
				"groupid":   groupid,
			})
		}
	}
}

// softLimboUser puts a user into a recoverable "limbo": it removes their approved
// memberships (so they drop out of group member lists), marks the account deleted
// (a 14-day grace period before users:cleanup runs forgetUser), and logs a
// User/Deleted entry. The user can recover by logging back in within the grace
// period. Shared by self-delete (DELETE /user) and the Support-tools Unsubscribe
// action (POST /user action=Unsubscribe) so both behave identically. byUser is the
// actor recorded in the log (the user themselves, or the support volunteer).
func softLimboUser(db *gorm.DB, targetID uint64, byUser uint64) {
	// V1 parity: record a per-group (Group, Left) audit log before the eager bulk
	// delete drops the memberships (see LogGroupLeftForApprovedMemberships).
	LogGroupLeftForApprovedMemberships(db, targetID, byUser)
	db.Table("memberships").Where("userid = ? AND collection = ?", targetID, utils.COLLECTION_APPROVED).Delete(nil)
	db.Table("users").Where("id = ?", targetID).Update("deleted", gorm.Expr("NOW()"))
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log2.LOG_TYPE_USER,
		"subtype":   log2.LOG_SUBTYPE_DELETED,
		"user":      targetID,
		"byuser":    byUser,
	})
}

// handleUserUnsubscribe puts a target user into a recoverable limbo (soft-delete)
// via the Support-tools "Unsubscribe" action. V1 parity: POST /user action=Unsubscribe
// maps to a recoverable removal (User::limbo) — NOT the hard GDPR purge that
// DELETE /user performs for support-on-another-user — so a user who unsubscribed
// by mistake (e.g. one-click email unsubscribe) can recover by logging back in.
// A regular user may unsubscribe only themselves; admin/support may unsubscribe
// anyone. (Discourse #9738.)
func handleUserUnsubscribe(c *fiber.Ctx, myid uint64, req UserPostRequest) error {
	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	targetID := req.ID

	if targetID != myid && !auth.IsAdminOrSupport(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Only admin/support can unsubscribe other users")
	}

	softLimboUser(database.DBConn, targetID, myid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleMerge merges user id1 (discard) into user id2 (keep).
// UI text: "merge FROM the first user INTO the second user" → id1=discard, id2=keep.
// Admin/Support can always merge. Moderators can merge if they moderate both users (V1 parity).
func handleMerge(c *fiber.Ctx, myid uint64, req UserPostRequest) error {
	// Early gate: must be at least a moderator of some group (or admin/support) to call this
	// endpoint. This prevents unauthenticated/regular users from probing email existence.
	if !auth.IsAdminOrSupport(myid) && !auth.IsModOfAnyGroup(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Only moderators or admin/support can merge users")
	}

	db := database.DBConn

	// If email addresses were provided instead of user IDs, resolve them first.
	// Join to users to exclude deleted accounts (same pattern as GetUserByEmail).
	if req.ID1 == 0 && req.Email1 != "" {
		// Converted together with its
		// identical twin below (15c4586ac31e): a half-converted pair renumbers
		// the survivor's site ID, so gate (h) refuses the split state.
		result := db.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ? AND users.deleted IS NULL", req.Email1).
			Limit(1).
			Scan((*uint64)(&req.ID1))
		if result.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error looking up email1")
		}
		if req.ID1 == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "No user found for email1")
		}
	}
	if req.ID2 == 0 && req.Email2 != "" {
		// Twin of cfcce3676000 above.
		result := db.Table("users").
			Select("users.id").
			Joins("INNER JOIN users_emails ON users_emails.userid = users.id").
			Where("users_emails.email = ? AND users.deleted IS NULL", req.Email2).
			Limit(1).
			Scan((*uint64)(&req.ID2))
		if result.Error != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Database error looking up email2")
		}
		if req.ID2 == 0 {
			return fiber.NewError(fiber.StatusBadRequest, "No user found for email2")
		}
	}

	if req.ID1 == 0 || req.ID2 == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id1 and id2 are required")
	}

	if req.ID1 == req.ID2 {
		return fiber.NewError(fiber.StatusBadRequest, "Cannot merge a user with themselves")
	}

	// Full permission check: admin/support can always merge; moderators must moderate both users.
	if !auth.IsAdminOrSupport(myid) && !(IsModOfUser(myid, uint64(req.ID1)) && IsModOfUser(myid, uint64(req.ID2))) {
		return fiber.NewError(fiber.StatusForbidden, "You cannot administer those users")
	}

	if err := MergeUsersTx(db, uint64(req.ID1), uint64(req.ID2), myid); err != nil {
		return err
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// MergeUsersTx merges id1 (discarded) into id2 (kept): every table's rows
// move from id1 to id2 inside one transaction, mirroring V1's User::merge,
// then id1's users row is hard-deleted after commit. byuser is recorded in
// the merge log entries. Extracted from handleMerge so the TN divergence
// heal (see FindTNCandidates) can merge a member's twin accounts through
// exactly the moderator-merge code path.
func MergeUsersTx(db *gorm.DB, id1, id2, byuser uint64) error {
	// All merge operations run inside a single transaction (V1 parity).
	// id1 = DISCARD (source), id2 = KEEP (destination). All data moves FROM id1 TO id2.
	tx := db.Begin()
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to start transaction")
	}

	committed := false
	defer func() {
		if !committed {
			tx.Rollback()
		}
	}()

	// ── SECTION A: emails, memberships ──────────────────────────────────────────

	// Email merge: move id1's emails to id2.
	// If id2 already has a preferred email, demote id1's preferred before moving.
	var id2HasPreferred int64
	tx.Table("users_emails").Where("userid = ? AND preferred = 1", id2).Count(&id2HasPreferred)
	if id2HasPreferred > 0 {
		if err := tx.Table("users_emails").Where("userid = ? AND preferred = 1", id1).Update("preferred", gorm.Expr("0")).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to demote id1 preferred email")
		}
	}
	if err := tx.Table("users_emails").Where("userid = ?", id1).Update("userid", id2).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to merge emails")
	}

	// Membership merge: V1 parity (max role, older date, non-null attrs from id1).
	roleWeight := map[string]int{"Non-member": 0, "Member": 1, "Moderator": 2, "Owner": 3}

	type MembershipRow struct {
		ID       uint64
		Groupid  uint64
		Role     string
		Added    string
		Configid *uint64
		Settings *string
		Heldby   *uint64
	}

	var id1Membs []MembershipRow
	tx.Table("memberships").Select("id, groupid, role, added, configid, settings, heldby").
		Where("userid = ?", id1).Scan(&id1Membs)

	for _, m1 := range id1Membs {
		var id2Memb MembershipRow
		tx.Table("memberships").Select("id, groupid, role, added, configid, settings, heldby").
			Where("userid = ? AND groupid = ?", id2, m1.Groupid).Scan(&id2Memb)

		if id2Memb.ID == 0 {
			// id2 not in this group — just reassign.
			if err := tx.Table("memberships").Where("id = ?", m1.ID).Update("userid", id2).Error; err != nil {
				return fiber.NewError(fiber.StatusInternalServerError, "Failed to transfer membership")
			}
		} else {
			// Both are members — take max role.
			newRole := id2Memb.Role
			if roleWeight[m1.Role] > roleWeight[id2Memb.Role] {
				newRole = m1.Role
			}
			tx.Table("memberships").Where("userid = ? AND groupid = ?", id2, m1.Groupid).Update("role", newRole)
			// Take older added date (SQL JOIN to avoid Go datetime string formatting).
			// Genuine multi-table UPDATE...JOIN: Table()-verbatim JOIN text +
			// explicit clause.Set, same mechanism as session/merge.go's
			// mergeChatRooms conversion. Proven by the retired ormharness's
			// updatejoin_replace_test.go TestUpdateJoin_SelfJoinWithLeastExpr
			// (removed in d22ba1d6c).
			tx.Table("memberships m2 JOIN memberships m1 ON m1.userid = ? AND m1.groupid = m2.groupid", id1).
				Clauses(clause.Set{
					{Column: clause.Column{Table: "m2", Name: "added"}, Value: gorm.Expr("LEAST(m2.added, m1.added)")},
				}).
				Where("m2.userid = ? AND m2.groupid = ?", id2, m1.Groupid).
				Updates(map[string]interface{}{})
			// Take non-null attrs from id1 if id2 doesn't have them.
			if m1.Configid != nil {
				tx.Table("memberships").Where("userid = ? AND groupid = ?", id2, m1.Groupid).
					Update("configid", gorm.Expr("COALESCE(configid, ?)", *m1.Configid))
			}
			if m1.Settings != nil {
				tx.Table("memberships").Where("userid = ? AND groupid = ?", id2, m1.Groupid).
					Update("settings", gorm.Expr("COALESCE(settings, ?)", *m1.Settings))
			}
			if m1.Heldby != nil {
				tx.Table("memberships").Where("userid = ? AND groupid = ?", id2, m1.Groupid).
					Update("heldby", gorm.Expr("COALESCE(heldby, ?)", *m1.Heldby))
			}
			// Delete the now-redundant id1 row.
			tx.Table("memberships").Where("id = ?", m1.ID).Delete(nil)
		}
	}
	// Clean up any remaining id1 memberships.
	tx.Table("memberships").Where("userid = ?", id1).Delete(nil)

	// ── SECTION B: messages, history, chat, sessions, logins ────────────────────

	// Messages.
	if err := tx.Table("messages").Where("fromuser = ?", id1).Update("fromuser", id2).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to merge messages")
	}
	// History tables.
	tx.Table("messages_history").Where("fromuser = ?", id1).Update("fromuser", id2)
	tx.Table("memberships_history").Where("userid = ?", id1).Update("userid", id2)
	// Log references.
	tx.Table("logs").Where("user = ?", id1).Update("user", id2)
	tx.Table("logs").Where("byuser = ?", id1).Update("byuser", id2)

	// Chat room merge with deduplication (V1 parity).
	type ChatRoomRow struct {
		ID            uint64
		Chattype      string
		User1         uint64
		User2         *uint64
		Groupid       *uint64
		Latestmessage *string
	}
	var id1Rooms []ChatRoomRow
	tx.Table("chat_rooms").Select("id, chattype, user1, user2, groupid, latestmessage").
		Where("(user1 = ? OR user2 = ?) AND chattype IN ('User2User','User2Mod')", id1, id1).Scan(&id1Rooms)
	for _, room := range id1Rooms {
		var existingID uint64
		if room.Chattype == "User2Mod" {
			tx.Table("chat_rooms").Select("id").
				Where("user1 = ? AND groupid = ? AND chattype = 'User2Mod'", id2, room.Groupid).Scan(&existingID)
		} else {
			var otherUserID uint64
			if room.User1 == id1 {
				if room.User2 != nil {
					otherUserID = *room.User2
				}
			} else {
				otherUserID = room.User1
			}
			if otherUserID > 0 {
				tx.Table("chat_rooms").Select("id").
					Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)",
						id2, otherUserID, otherUserID, id2).Scan(&existingID)
			}
		}
		if existingID > 0 {
			// Duplicate room — move messages into surviving room and delete duplicate.
			tx.Table("chat_messages").Where("chatid = ?", room.ID).Update("chatid", existingID)
			if room.Latestmessage != nil {
				tx.Table("chat_rooms").Where("id = ?", existingID).
					Update("latestmessage", gorm.Expr("GREATEST(latestmessage, ?)", *room.Latestmessage))
			}
			tx.Table("chat_rooms").Where("id = ?", room.ID).Delete(nil)
		} else {
			if room.User1 == id1 {
				tx.Table("chat_rooms").Where("id = ?", room.ID).Update("user1", id2)
			} else {
				tx.Table("chat_rooms").Where("id = ?", room.ID).Update("user2", id2)
			}
		}
	}
	tx.Table("chat_messages").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("chat_roster").Where("userid = ?", id1).Update("userid", id2)

	// Sessions and logins.
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("sessions").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("users_logins").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_logins").Where("userid = ? AND type = 'Native'", id2).Update("uid", id2)

	// ── SECTION C: user attributes, simple tables, bans, giftaid, log entries ───

	// User attributes: take id1's values if id2 doesn't have them.
	type UserAttrs struct {
		Fullname   *string
		Firstname  *string
		Lastname   *string
		Yahooid    *string
		Systemrole string
		Added      string
		Tnuserid   *uint64
	}
	var u1Attrs, u2Attrs UserAttrs
	tx.Table("users").Select("fullname, firstname, lastname, yahooid, systemrole, added, tnuserid").
		Where("id = ?", id1).Scan(&u1Attrs)
	tx.Table("users").Select("fullname, firstname, lastname, yahooid, systemrole, added, tnuserid").
		Where("id = ?", id2).Scan(&u2Attrs)

	// fullname: take id1's if id2 is NULL, skip FBUser/-owner placeholder names.
	if u1Attrs.Fullname != nil && u2Attrs.Fullname == nil {
		fn := *u1Attrs.Fullname
		isBad := strings.HasPrefix(strings.ToLower(fn), "fbuser") || strings.HasSuffix(fn, "-owner")
		if !isBad {
			tx.Table("users").Where("id = ?", id2).Update("fullname", fn)
		}
	}
	// firstname, lastname, yahooid: take id1's if id2 is NULL.
	if u1Attrs.Firstname != nil && u2Attrs.Firstname == nil {
		tx.Table("users").Where("id = ?", id2).Update("firstname", *u1Attrs.Firstname)
	}
	if u1Attrs.Lastname != nil && u2Attrs.Lastname == nil {
		tx.Table("users").Where("id = ?", id2).Update("lastname", *u1Attrs.Lastname)
	}
	if u1Attrs.Yahooid != nil && u2Attrs.Yahooid == nil {
		tx.Table("users").Where("id = ?", id2).Update("yahooid", *u1Attrs.Yahooid)
	}

	// systemrole: take the max (User < Moderator < Support < Admin).
	sysRoleOrder := map[string]int{"User": 0, "Moderator": 1, "Support": 2, "Admin": 3}
	if sysRoleOrder[u1Attrs.Systemrole] > sysRoleOrder[u2Attrs.Systemrole] {
		tx.Table("users").Where("id = ?", id2).Update("systemrole", u1Attrs.Systemrole)
	}

	// added: take the older date — read id1's added timestamp and pass it directly.
	// Use SQL DATE comparison within MySQL to avoid driver string-format issues.
	// Same
	// mechanism as the memberships LEAST() conversion above; proven by the
	// retired ormharness's updatejoin_replace_test.go
	// TestUpdateJoin_SelfJoinWithLeastExpr (removed in d22ba1d6c).
	tx.Table("users u2 JOIN users u1 ON u1.id = ?", id1).
		Clauses(clause.Set{
			{Column: clause.Column{Table: "u2", Name: "added"}, Value: gorm.Expr("LEAST(u2.added, u1.added)")},
		}).
		Where("u2.id = ?", id2).
		Updates(map[string]interface{}{})

	// lastupdated.
	tx.Table("users").Where("id = ?", id2).Update("lastupdated", gorm.Expr("NOW()"))

	// tnuserid: transfer if id2 doesn't have one.
	if u1Attrs.Tnuserid != nil && u2Attrs.Tnuserid == nil {
		tx.Table("users").Where("id = ?", id1).Update("tnuserid", gorm.Expr("NULL"))
		tx.Table("users").Where("id = ?", id2).Update("tnuserid", *u1Attrs.Tnuserid)
	}

	// Simple UPDATE IGNORE tables (V1 parity — ~25 reference tables).
	//
	// volunteering has no FK on userid (communityevents does — ON DELETE SET NULL).
	// Without this rewrite, merging id1 leaves their volunteering opportunities pointing
	// at a userid that vanishes when id1 is deleted at the end of the merge.
	//
	// This was
	// never actually runtime-varying: every one of these 41 statements runs
	// unconditionally on every call (nothing here is caller-selected), so
	// there was no dynamic SQL to begin with - only a Go slice+loop shape the
	// extractor's static analysis couldn't trace u.sql through. Unrolled into
	// 41 individual GORM calls, each a plain single-table UPDATE[ IGNORE],
	// exactly the established wave 2 idiom already used a few lines below
	// (users_banned, line ~3189). Every one of the 41 was proven (as its own
	// shape for this one site id) by the retired ormharness (shapes.json /
	// TestTier2_d5ecca066b1b, removed in d22ba1d6c).
	tx.Table("locations_excluded").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("spam_users").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("spam_users").Where("byuserid = ?", id1).Update("byuserid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_addresses").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("users_comments").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("users_comments").Where("byuserid = ?", id1).Update("byuserid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_donations").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_images").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_invitations").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_nearby").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_notifications").Where("fromuser = ?", id1).Update("fromuser", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_notifications").Where("touser = ?", id1).Update("touser", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_nudges").Where("fromuser = ?", id1).Update("fromuser", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_nudges").Where("touser = ?", id1).Update("touser", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_push_notifications").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_requests").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_requests").Where("completedby = ?", id1).Update("completedby", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_searches").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("newsfeed").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("messages_reneged").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_stories").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_stories_likes").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_stories_requested").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_thanks").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("modnotifs").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("teams_members").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_aboutme").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("ratings").Where("rater = ?", id1).Update("rater", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("ratings").Where("ratee = ?", id1).Update("ratee", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_replytime").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("messages_promises").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("messages_by").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("trysts").Where("user1 = ?", id1).Update("user1", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("trysts").Where("user2 = ?", id1).Update("user2", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("isochrones_users").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("microactions").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("volunteering").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("volunteering").Where("deletedby = ?", id1).Update("deletedby", id2)
	tx.Table("volunteering").Where("heldby = ?", id1).Update("heldby", id2)
	tx.Table("communityevents").Where("userid = ?", id1).Update("userid", id2)
	tx.Table("communityevents").Where("heldby = ?", id1).Update("heldby", id2)

	// Bans: move id1's bans to id2, then delete memberships for groups id2 is now banned from.
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_banned").Where("userid = ?", id1).Update("userid", id2)
	tx.Clauses(clause.Update{Modifier: "IGNORE"}).Table("users_banned").Where("byuser = ?", id1).Update("byuser", id2)

	type MergeBanRow struct{ Groupid uint64 }
	var mergeBans []MergeBanRow
	tx.Table("users_banned").Select("groupid").Where("userid = ?", id2).Scan(&mergeBans)
	for _, ban := range mergeBans {
		tx.Table("memberships").Where("userid = ? AND groupid = ?", id2, ban.Groupid).Delete(nil)
	}

	// Giftaid: keep the most favourable declaration (V1 parity).
	giftaidWeight := map[string]int{
		"Past4YearsAndFuture": 0,
		"Since":               1,
		"Future":              2,
		"This":                3,
		"Declined":            4,
	}
	type MergeGiftaidRow struct {
		ID     uint64
		Period string
	}
	var giftaids []MergeGiftaidRow
	tx.Table("giftaid").Select("id, period").Where("userid IN (?, ?)", id1, id2).Scan(&giftaids)
	if len(giftaids) > 0 {
		best := giftaids[0]
		for _, g := range giftaids[1:] {
			gw, ok := giftaidWeight[g.Period]
			if !ok {
				gw = 99
			}
			bw, ok2 := giftaidWeight[best.Period]
			if !ok2 {
				bw = 99
			}
			if gw < bw {
				best = g
			}
		}
		for _, g := range giftaids {
			if g.ID != best.ID {
				tx.Table("giftaid").Where("id = ?", g.ID).Delete(nil)
			}
		}
		tx.Table("giftaid").Where("id = ?", best.ID).Update("userid", id2)
	}

	// Merge log entries (two entries, one per user — V1 parity).
	logText := fmt.Sprintf("Merged %d into %d", id1, id2)
	tx.Table("logs").Create(map[string]interface{}{
		"user":      id1,
		"byuser":    byuser,
		"type":      log2.LOG_TYPE_USER,
		"subtype":   log2.LOG_SUBTYPE_MERGED,
		"text":      logText,
		"timestamp": gorm.Expr("NOW()"),
	})
	tx.Table("logs").Create(map[string]interface{}{
		"user":      id2,
		"byuser":    byuser,
		"type":      log2.LOG_TYPE_USER,
		"subtype":   log2.LOG_SUBTYPE_MERGED,
		"text":      logText,
		"timestamp": gorm.Expr("NOW()"),
	})

	// ── Commit ──────────────────────────────────────────────────────────────────
	if err := tx.Commit().Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to commit merge")
	}
	committed = true

	// Hard-delete id1 AFTER commit (V1 parity: DELETE FROM users WHERE id = ?).
	// Table()+Delete(nil) carries no
	// model, so User.Deleted (a plain *time.Time, not gorm.DeletedAt) can never
	// turn this into a soft-delete UPDATE.
	db.Table("users").Where("id = ?", id1).Delete(nil)

	return nil
}

// All endpoints in this file are mod-only: the caller must be a moderator of
// a group the target user belongs to (or Admin/Support).  Each returns a flat
// array — no nested enrichment.

func requireModOfUser(c *fiber.Ctx) (myid, targetid uint64, err error) {
	myid = WhoAmI(c)
	if myid == 0 {
		return 0, 0, fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}
	targetid, parseErr := strconv.ParseUint(c.Params("id"), 10, 64)
	if parseErr != nil || targetid == 0 {
		return 0, 0, fiber.NewError(fiber.StatusBadRequest, "Invalid user ID")
	}
	if !IsModOfUser(myid, targetid) {
		return 0, 0, fiber.NewError(fiber.StatusForbidden, "Not a moderator for this user")
	}
	return myid, targetid, nil
}

// GetUserChatrooms returns chat rooms for a target user.
//
// @Summary Get chat rooms for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/chatrooms [get]
func GetUserChatrooms(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type ChatroomRow struct {
		ID       uint64     `json:"id"`
		Chattype string     `json:"chattype"`
		User1    uint64     `json:"user1"`
		User2    uint64     `json:"user2"`
		Groupid  uint64     `json:"groupid"`
		Lastdate *time.Time `json:"lastdate"`
	}

	var rooms []ChatroomRow
	db.Table("chat_rooms").Select("id, chattype, user1, user2, COALESCE(groupid, 0) AS groupid, latestmessage AS lastdate").
		Where("(user1 = ? OR user2 = ?)", targetid, targetid).Order("latestmessage DESC").Scan(&rooms)

	if rooms == nil {
		rooms = []ChatroomRow{}
	}

	return c.JSON(rooms)
}

// GetUserEmailHistory returns recent emails sent to a user.
//
// @Summary Get email history for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/emailhistory [get]
func GetUserEmailHistory(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type EmailHistoryRow struct {
		ID        uint64     `json:"id"`
		Timestamp *time.Time `json:"timestamp"`
		Eximid    *string    `json:"eximid"`
		From      *string    `json:"from"`
		To        *string    `json:"to"`
		Subject   *string    `json:"subject"`
		Status    *string    `json:"status"`
	}

	var emails []EmailHistoryRow
	db.Table("logs_emails").Select("id, timestamp, eximid, `from`, `to`, subject, status").
		Where("userid = ?", targetid).Order("id DESC").Limit(100).Scan(&emails)

	if emails == nil {
		emails = []EmailHistoryRow{}
	}

	return c.JSON(emails)
}

// GetUserBans returns ban records for a user.
//
// @Summary Get bans for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/bans [get]
func GetUserBans(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type BanRow struct {
		Groupid uint64     `json:"groupid"`
		Group   string     `json:"group"`
		Date    *time.Time `json:"date"`
		Byuser  *uint64    `json:"byuser"`
		Byemail *string    `json:"byemail"`
	}

	var bans []BanRow
	db.Table("users_banned ub").
		Select("ub.groupid, "+
			"COALESCE(g.namefull, g.nameshort) AS `group`, "+
			"ub.date, ub.byuser, "+
			"(SELECT ue.email FROM users_emails ue WHERE ue.userid = ub.byuser AND ue.preferred = 1 LIMIT 1) AS byemail").
		Joins("LEFT JOIN `groups` g ON g.id = ub.groupid").
		Where("ub.userid = ?", targetid).
		Order("ub.date DESC").
		Scan(&bans)

	if bans == nil {
		bans = []BanRow{}
	}

	return c.JSON(bans)
}

// GetUserNewsfeed returns ChitChat posts by a user.
//
// @Summary Get newsfeed posts for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/newsfeed [get]
func GetUserNewsfeed(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type NewsfeedRow struct {
		ID        uint64     `json:"id"`
		Timestamp *time.Time `json:"timestamp"`
		Message   *string    `json:"message"`
		Hidden    *time.Time `json:"hidden"`
		Hiddenby  *uint64    `json:"hiddenby"`
		Deleted   *time.Time `json:"deleted"`
		Deletedby *uint64    `json:"deletedby"`
	}

	var posts []NewsfeedRow
	db.Table("newsfeed").Select("id, timestamp, message, hidden, hiddenby, deleted, deletedby").
		Where("userid = ?", targetid).Order("id DESC").Scan(&posts)

	if posts == nil {
		posts = []NewsfeedRow{}
	}

	return c.JSON(posts)
}

// GetUserApplied returns recent group applications (last 31 days).
//
// @Summary Get recent group applications for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/applied [get]
func GetUserApplied(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type AppliedRow struct {
		Groupid     uint64     `json:"groupid"`
		Nameshort   string     `json:"nameshort"`
		Namefull    string     `json:"namefull"`
		Namedisplay string     `json:"namedisplay" gorm:"column:namedisplay"`
		Added       *time.Time `json:"added"`
	}

	var applied []AppliedRow
	db.Table("memberships_history mh").
		Select("mh.groupid, g.nameshort, COALESCE(g.namefull, '') AS namefull, COALESCE(g.namefull, g.nameshort) AS namedisplay, mh.added").
		Joins("INNER JOIN `groups` g ON g.id = mh.groupid").
		Where("mh.userid = ? AND DATEDIFF(NOW(), mh.added) <= 31 AND g.publish = 1 AND g.onmap = 1", targetid).
		Order("mh.added DESC").
		Scan(&applied)

	if applied == nil {
		applied = []AppliedRow{}
	}

	return c.JSON(applied)
}

// GetUserReplies returns messages the user has replied to (expressed interest in).
//
// @Summary Get messages a user replied to (mod-only)
// @Tags user
// @Router /api/user/{id}/replies [get]
func GetUserReplies(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	msgtype := c.Query("type")

	db := database.DBConn
	start := time.Now().AddDate(0, 0, -utils.OPEN_AGE).Format("2006-01-02")

	type ReplyRow struct {
		ID      uint64  `json:"id"`
		Subject string  `json:"subject"`
		Type    string  `json:"type"`
		Arrival string  `json:"arrival"`
		Outcome *string `json:"outcome"`
	}

	// msgtype!="" is
	// the only toggle - 2 possible rendered forms, both proven by the retired
	// ormharness (shapes.json / TestTier3Shapes_395499023142, removed in
	// d22ba1d6c).
	whereSQL := "cm.userid = ? AND cm.date > ? AND cm.refmsgid IS NOT NULL AND cm.type = ?"
	whereArgs := []interface{}{targetid, start, utils.CHAT_MESSAGE_INTERESTED}
	if msgtype != "" {
		whereSQL += " AND m.type = ?"
		whereArgs = append(whereArgs, msgtype)
	}

	// One row per post, matching the replies badge beside the modal, which counts
	// COUNT(DISTINCT cm.refmsgid). Both joins below fan out, and SELECT DISTINCT cannot
	// collapse them because the fanned-out columns are themselves selected:
	//
	//   - messages_groups holds a row per group the post reached, and rippling adds one
	//     (rippled_in = 1) per receiving group with its own arrival. A post rippling
	//     outwards over a day therefore yielded one row per distinct ripple time.
	//     MIN(mg.arrival) is the origin arrival, i.e. when the post was actually made.
	//     Grouping rather than filtering on rippled_in = 0 because a few posts carry no
	//     origin row at all and filtering would drop them from the list entirely.
	//   - messages_outcomes holds a row per outcome, so a post Withdrawn and then Taken
	//     doubled again. The subquery takes the most recent, as GetUserMessageHistory does.
	tx := db.Table("chat_messages cm").
		Select("m.id, m.subject, m.type, MIN(mg.arrival) AS arrival, "+
			"(SELECT mo.outcome FROM messages_outcomes mo WHERE mo.msgid = m.id "+
			"ORDER BY mo.timestamp DESC LIMIT 1) AS outcome").
		Joins("INNER JOIN messages m ON m.id = cm.refmsgid").
		Joins("INNER JOIN messages_groups mg ON mg.msgid = m.id").
		Where(whereSQL, whereArgs...).
		Group("m.id, m.subject, m.type")

	var replies []ReplyRow
	tx.Order("arrival DESC").Limit(100).Scan(&replies)

	if replies == nil {
		replies = []ReplyRow{}
	}

	return c.JSON(replies)
}

// GetUserMembershipHistory returns full membership history.
//
// @Summary Get membership history for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/membershiphistory [get]
func GetUserMembershipHistory(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type MembershipHistoryRow struct {
		Timestamp   *time.Time `json:"timestamp"`
		Type        string     `json:"type"`
		Groupid     uint64     `json:"groupid"`
		Nameshort   string     `json:"nameshort"`
		Namefull    string     `json:"namefull"`
		Namedisplay string     `json:"namedisplay" gorm:"column:namedisplay"`
		Text        string     `json:"text"`
	}

	var history []MembershipHistoryRow
	db.Table("logs l").
		Select("l.timestamp, l.subtype AS type, l.groupid, g.nameshort, COALESCE(g.namefull, '') AS namefull, COALESCE(g.namefull, g.nameshort) AS namedisplay, COALESCE(l.text,'') AS text").
		Joins("INNER JOIN `groups` g ON g.id = l.groupid").
		Where("l.user = ? AND l.type = 'Group' AND l.subtype IN ('Joined','Approved','Rejected','Applied','Left')", targetid).
		Order("l.id DESC").
		Scan(&history)

	if history == nil {
		history = []MembershipHistoryRow{}
	}

	return c.JSON(history)
}

// GetUserLogins returns login history for a user.
//
// @Summary Get login history for a user (mod-only)
// @Tags user
// @Router /api/user/{id}/logins [get]
func GetUserLogins(c *fiber.Ctx) error {
	_, targetid, err := requireModOfUser(c)
	if err != nil {
		return err
	}

	db := database.DBConn

	type LoginRow struct {
		ID         uint64     `json:"id"`
		Userid     uint64     `json:"userid"`
		Type       string     `json:"type"`
		Added      *time.Time `json:"added"`
		Lastaccess *time.Time `json:"lastaccess"`
	}

	var logins []LoginRow
	db.Table("users_logins").Select("id, userid, type, added, lastaccess").
		Where("userid = ?", targetid).Order("lastaccess DESC").Limit(50).Scan(&logins)

	if logins == nil {
		logins = []LoginRow{}
	}

	return c.JSON(logins)
}

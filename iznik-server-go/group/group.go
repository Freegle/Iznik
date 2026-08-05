package group

import (
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
)

const FREEGLE = utils.GROUP_TYPE_FREEGLE

func (Group) TableName() string { return "groups" }

// Full group details.
type Group struct {
	ID                       uint64           `json:"id" gorm:"primary_key"`
	Nameshort                string           `json:"nameshort"`
	Namefull                 string           `json:"namefull"`
	Namedisplay              string           `json:"namedisplay" gorm:"-"`
	Settings                 json.RawMessage  `json:"settings"` // This is JSON stored in the DB as a string.
	Rules                    json.RawMessage  `json:"rules"`    // Group rules, nullable JSON.
	Region                   string           `json:"region"`
	Logo                     string           `json:"logo"`
	Publish                  int              `json:"publish"`
	Ontn                     int              `json:"ontn"`
	Membercount              int              `json:"membercount"`
	Modcount                 int              `json:"modcount"`
	Lat                      float32          `json:"lat"`
	Lng                      float32          `json:"lng"`
	Altlat                   float32          `json:"altlat"`
	Altlng                   float32          `json:"altlng"`
	GroupProfile             GroupProfile     `gorm:"-" json:"-"`
	Profile                  uint64           `gorm:"column:profile" json:"-"`
	GroupProfileStr          string           `json:"profile" gorm:"-"`
	Onmap                    int              `json:"onmap"`
	Tagline                  string           `json:"tagline"`
	Description              string           `json:"description"`
	Contactmail              string           `json:"-"`
	Modsemail                string           `json:"modsemail"`
	Fundingtarget            int              `json:"fundingtarget"`
	Affiliationconfirmed     *time.Time       `json:"affiliationconfirmed,omitempty"`
	Founded                  *time.Time       `json:"founded,omitempty"`
	GroupSponsors            []GroupSponsor   `gorm:"ForeignKey:groupid" json:"sponsors"`
	GroupVolunteers          []GroupVolunteer `gorm:"-" json:"showmods"`
	Showjoin                 int              `json:"showjoin"`
	Bbox                     string           `json:"bbox,omitempty" gorm:"column:bbox"`
	Type                     string           `json:"type"`
	Overridemoderation       string           `json:"overridemoderation"`
	Autofunctionoverride     int              `json:"autofunctionoverride"`
	Microvolunteering        int              `json:"microvolunteering"`
	Microvolunteeringoptions json.RawMessage  `json:"microvolunteeringoptions"`
	Mentored                 int              `json:"mentored" gorm:"column:mentored"`
	Onhere                   int              `json:"onhere" gorm:"column:onhere"`
	Onlovejunk               int              `json:"onlovejunk" gorm:"column:onlovejunk"`
	Welcomemail              string           `json:"welcomemail,omitempty"`
	Myrole                   string           `json:"myrole,omitempty" gorm:"-"`

	// Polygon fields (only populated when polygon=true query param)
	Poly         *string `json:"poly,omitempty" gorm:"-"`
	Polyofficial *string `json:"polyofficial,omitempty" gorm:"-"`
	Cga          *string `json:"cga,omitempty" gorm:"-"`
	Dpa          *string `json:"dpa,omitempty" gorm:"-"`

	// TN key fields (only populated when tnkey=true and user is moderator)
	Tnkey *TnKeyInfo `json:"tnkey,omitempty" gorm:"-"`
}

// TnKeyInfo holds the TrashNothing settings URL, matching the V1 API shape.
// The TN API returns {"url":"...","expires":"..."} — we nest it under "key"
// in the group response to match what the V1 PHP API returned.
type TnKeyInfo struct {
	Url     string `json:"url"`
	Expires string `json:"expires,omitempty"`
}

// Summary group details.
type GroupEntry struct {
	ID          uint64  `json:"id" gorm:"primary_key"`
	Nameshort   string  `json:"nameshort"`
	Namefull    string  `json:"namefull"`
	Namedisplay string  `json:"namedisplay"`
	Lat         float32 `json:"lat"`
	Lng         float32 `json:"lng"`
	Altlat      float32 `json:"altlat"`
	Altlng      float32 `json:"altlng"`
	Publish     int     `json:"publish"`
	Onmap       int     `json:"onmap"`
	Onhere      int     `json:"onhere" gorm:"column:onhere"`
	Ontn        int     `json:"ontn" gorm:"column:ontn"`
	Onlovejunk  int     `json:"onlovejunk" gorm:"column:onlovejunk"`
	Region      string  `json:"region"`
	Contactmail string  `json:"-"`
	Modsemail   string  `json:"modsemail"`
	Showjoin    int     `json:"showjoin"`
	Mentored    int     `json:"mentored" gorm:"column:mentored"`

	// Support-only fields (only populated when support=true and user is Admin/Support)
	Founded                *time.Time `json:"founded,omitempty" gorm:"column:founded"`
	Lastmoderated          *time.Time `json:"lastmoderated,omitempty" gorm:"column:lastmoderated"`
	Lastmodactive          *time.Time `json:"lastmodactive,omitempty" gorm:"column:lastmodactive"`
	Lastautoapprove        *time.Time `json:"lastautoapprove,omitempty" gorm:"column:lastautoapprove"`
	Activeownercount       *int       `json:"activeownercount,omitempty" gorm:"column:activeownercount"`
	Activemodcount         *int       `json:"activemodcount,omitempty" gorm:"column:activemodcount"`
	Backupmodsactive       *int       `json:"backupmodsactive,omitempty" gorm:"column:backupmodsactive"`
	Backupownersactive     *int       `json:"backupownersactive,omitempty" gorm:"column:backupownersactive"`
	Affiliationconfirmed   *time.Time `json:"affiliationconfirmed,omitempty" gorm:"column:affiliationconfirmed"`
	Affiliationconfirmedby *uint64    `json:"affiliationconfirmedby,omitempty" gorm:"column:affiliationconfirmedby"`
	Recentautoapproves     *int       `json:"recentautoapproves,omitempty" gorm:"-"`
	Recentmanualapproves   *int       `json:"recentmanualapproves,omitempty" gorm:"-"`
	Recentautoapprovespct  *float64   `json:"recentautoapprovespercent,omitempty" gorm:"-"`
	Recentmoderated        *int       `json:"recentmoderated,omitempty" gorm:"-"`
	Recentmoderatedpct     *float64   `json:"recentmoderatedpercent,omitempty" gorm:"-"`

	// Polygon fields (only populated when polygon=true query param)
	Poly         *string `json:"poly,omitempty" gorm:"-"`
	Polyofficial *string `json:"polyofficial,omitempty" gorm:"-"`
	Cga          *string `json:"cga,omitempty" gorm:"-"`
	Dpa          *string `json:"dpa,omitempty" gorm:"-"`
}

type RepostSettings struct {
	Offer    int `json:"offer"`
	Wanted   int `json:"wanted"`
	Max      int `json:"max"`
	Chaseups int `json:"chaseups"`
}

// DefaultRepostSettings is what a group with no `reposts` entry in its settings
// falls back to. Mirrors V1's Message::getPublic()/canRepost() default of
// ['offer' => 3, 'wanted' => 7, 'max' => 5, 'chaseups' => 5].
func DefaultRepostSettings() RepostSettings {
	return RepostSettings{Offer: 3, Wanted: 7, Max: 5, Chaseups: 5}
}

func GetGroup(c *fiber.Ctx) error {
	idParam := c.Params("id")

	// Support comma-separated IDs for batch fetching (e.g. /group/1,2,3).
	if strings.Contains(idParam, ",") {
		return getMultipleGroups(c, idParam)
	}

	id, err := strconv.ParseUint(idParam, 10, 64)

	if err != nil {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}

	// showmods and sponsors params control whether to include those fields.
	// Default behavior (no params) loads both for backward compatibility.
	showmodsParam := c.Query("showmods")
	sponsorsParam := c.Query("sponsors")

	wantShowmods := showmodsParam != "false"
	wantSponsors := sponsorsParam != "false"
	wantFilteredSponsors := sponsorsParam == "true"

	db := database.DBConn
	var group Group
	var volunteers []GroupVolunteer
	var filteredSponsors []GroupSponsor
	found := false

	// Get group, volunteers, and sponsors info in parallel for speed.
	var wg sync.WaitGroup

	if wantShowmods {
		wg.Add(1)

		go func() {
			defer wg.Done()
			volunteers = GetGroupVolunteers(id)
		}()
	}

	if wantFilteredSponsors {
		wg.Add(1)

		go func() {
			defer wg.Done()
			// ORM migration site 21406c23a191 (wave 1).
			db.Table("groups_sponsorship").
				Where("groupid = ? AND startdate <= NOW() AND enddate >= DATE(NOW()) AND visible = 1", id).
				Order("amount DESC").
				Scan(&filteredSponsors)
		}()
	}

	wg.Add(1)

	go func() {
		defer wg.Done()

		// Return the group even if publish = 0 or onhere = 0 because they have the actual id, so they must really
		// want it.  This can happen if a user has a message on a group that is then set to publish = 0, for example.
		q := db.Session(&gorm.Session{})

		if !wantFilteredSponsors && wantSponsors {
			// Load all sponsors via GORM Preload (no date/visible filtering) - backward compatible default.
			q = q.Preload("GroupSponsors")
		}

		// ORM migration site 2811b4d3acf7 (tier6). Converted from
		// Raw(...).First(&group) to Table()/Select()/Where().Find(&group).
		// First() unconditionally adds an ORDER BY + LIMIT 1 clause and sets
		// RaiseErrorOnNotFound - but on a Raw()-based statement those clauses
		// were silently dropped (BuildQuerySQL skips clause-building entirely
		// once Statement.SQL is already populated by Raw()), so the golden's
		// lack of ORDER BY/LIMIT was always the real executed SQL. A straight
		// swap to Table()+First() would have started emitting a real
		// "ORDER BY id LIMIT 1", since that short-circuit no longer applies -
		// a genuine behaviour change, not a harmless rewrite. Find() never
		// adds those clauses and never raises ErrRecordNotFound, so the
		// caller now checks RowsAffected directly instead of comparing the
		// returned error against ErrRecordNotFound - which is also a small
		// correctness improvement: the old check treated ANY error other
		// than "not found" (e.g. a genuine connection failure) as found=true.
		tx := q.Table("groups").
			Select("`groups`.*, CAST(JSON_EXTRACT(`groups`.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
			Where("id = ?", id).
			Find(&group)
		found = tx.RowsAffected > 0

		if found {
			if group.Profile > 0 {
				db.Where("id = ?", group.Profile).First(&group.GroupProfile)
				if group.GroupProfile.ID > 0 {
					group.GroupProfileStr = "https://" + os.Getenv("IMAGE_DOMAIN") + "/gimg_" + strconv.FormatUint(group.GroupProfile.ID, 10) + ".jpg"
				}
			}

			if len(group.Namefull) > 0 {
				group.Namedisplay = group.Namefull
			} else {
				group.Namedisplay = group.Nameshort
			}

			if len(group.Contactmail) > 0 {
				group.Modsemail = group.Contactmail
			} else {
				group.Modsemail = group.Nameshort + "-volunteers@" + os.Getenv("GROUP_DOMAIN")
			}
		}
	}()

	wg.Wait()

	if found {
		if wantShowmods {
			group.GroupVolunteers = volunteers
		}

		if wantFilteredSponsors {
			group.GroupSponsors = filteredSponsors
		}

		// Run independent queries in parallel to reduce latency.
		myid := user.WhoAmI(c)
		wantPolygon := c.Query("polygon") == "true"
		wantTnkey := c.Query("tnkey") == "true" && myid > 0 && auth.IsModOfGroup(myid, id)

		type PolyResult struct {
			Poly         *string `gorm:"column:poly"`
			Polyofficial *string `gorm:"column:polyofficial"`
		}
		var polyResult PolyResult
		var myrole string
		var email string

		var wg2 sync.WaitGroup

		if wantPolygon {
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				// ORM migration site 7c5c81bc5dc0 (wave 1).
				db.Table("groups").Select("poly, polyofficial").Where("id = ?", id).Scan(&polyResult)
			}()
		}

		if myid > 0 {
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				// ORM migration site 06597ffa764d (wave 1).
				db.Table("memberships").Select("role").
					Where("userid = ? AND groupid = ? AND collection = ?", myid, id, utils.COLLECTION_APPROVED).
					Scan(&myrole)
			}()
		}

		if wantTnkey {
			wg2.Add(1)
			go func() {
				defer wg2.Done()
				// ORM migration site 01adb146166c (wave 1).
				db.Table("users_emails").Select("email").
					Where("userid = ?", myid).
					Order("preferred DESC, id ASC").
					Limit(1).
					Scan(&email)
			}()
		}

		wg2.Wait()

		// Apply polygon results.
		if wantPolygon {
			group.Poly = polyResult.Poly
			group.Polyofficial = polyResult.Polyofficial
			group.Cga = polyResult.Polyofficial
			group.Dpa = polyResult.Poly
		}

		// Apply myrole.
		if myid > 0 && myrole != "" {
			group.Myrole = myrole
		} else {
			group.Myrole = "Non-member"
		}

		// Fetch TN key using the email we already retrieved in parallel.
		if wantTnkey && email != "" {
			tnkey := os.Getenv("TNKEY")
			if tnkey != "" {
				tnURL := fmt.Sprintf("https://trashnothing.com/modtools/api/group-settings-url?key=%s&moderator_email=%s&group_id=%s",
					url.QueryEscape(tnkey),
					url.QueryEscape(email),
					url.QueryEscape(group.Nameshort))

				client := &http.Client{Timeout: 10 * time.Second}
				resp, err := client.Get(tnURL)
				if err == nil {
					defer resp.Body.Close()
					body, err := io.ReadAll(resp.Body)
					if err == nil {
						var tnResult TnKeyInfo
						if json.Unmarshal(body, &tnResult) == nil && tnResult.Url != "" {
							group.Tnkey = &tnResult
						}
					}
				}
			}
		}

		// Strip modtools-only fields when not requested via modtools flag.
		if c.Query("modtools") != "true" {
			group.Welcomemail = ""
		}

		// Default nil JSON fields to empty objects so the frontend never sees null,
		// which would crash group.settings.X access and trigger an infinite store
		// re-fetch loop (the store uses !group.settings as "not yet loaded"). Mirrors
		// the same nil-guard in user/user.go.
		if group.Settings == nil {
			group.Settings = json.RawMessage("{}")
		}
		if group.Microvolunteeringoptions == nil {
			group.Microvolunteeringoptions = json.RawMessage("{}")
		}

		return c.JSON(group)
	} else {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}
}

// getMultipleGroups handles batch group fetching for comma-separated IDs.
// Returns an array of groups, fetched in parallel. Skips IDs that don't exist.
func getMultipleGroups(c *fiber.Ctx, idParam string) error {
	parts := strings.Split(idParam, ",")
	if len(parts) > 50 {
		return fiber.NewError(fiber.StatusBadRequest, "Too many IDs (max 50)")
	}

	var ids []uint64
	for _, p := range parts {
		id, err := strconv.ParseUint(strings.TrimSpace(p), 10, 64)
		if err == nil && id > 0 {
			ids = append(ids, id)
		}
	}

	if len(ids) == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "No valid IDs")
	}

	db := database.DBConn
	myid := user.WhoAmI(c)
	modtools := c.Query("modtools") == "true"

	type result struct {
		idx   int
		group *Group
	}

	results := make([]result, 0, len(ids))
	var mu sync.Mutex
	var wg sync.WaitGroup

	for i, id := range ids {
		wg.Add(1)
		go func(idx int, gid uint64) {
			defer wg.Done()

			// ORM migration site 547458a591ae (tier6). Same First()->Find()
			// conversion as GetGroup (2811b4d3acf7) above, for the same
			// reason: see that site's comment.
			var g Group
			tx := db.Preload("GroupSponsors").
				Table("groups").
				Select("`groups`.*, CAST(JSON_EXTRACT(`groups`.settings, '$.showjoin') AS UNSIGNED) AS showjoin, ST_AsText(ST_ENVELOPE(polyindex)) AS bbox").
				Where("id = ?", gid).
				Find(&g)

			if tx.Error != nil || tx.RowsAffected == 0 {
				return
			}

			if g.Profile > 0 {
				db.Where("id = ?", g.Profile).First(&g.GroupProfile)
				if g.GroupProfile.ID > 0 {
					g.GroupProfileStr = "https://" + os.Getenv("IMAGE_DOMAIN") + "/gimg_" + strconv.FormatUint(g.GroupProfile.ID, 10) + ".jpg"
				}
			}

			if len(g.Namefull) > 0 {
				g.Namedisplay = g.Namefull
			} else {
				g.Namedisplay = g.Nameshort
			}

			if len(g.Contactmail) > 0 {
				g.Modsemail = g.Contactmail
			} else {
				g.Modsemail = g.Nameshort + "-volunteers@" + os.Getenv("GROUP_DOMAIN")
			}

			if myid > 0 {
				var myrole string
				// ORM migration site 3f55e9081ae4 (wave 1).
				db.Table("memberships").Select("role").
					Where("userid = ? AND groupid = ? AND collection = ?", myid, gid, utils.COLLECTION_APPROVED).
					Scan(&myrole)
				if myrole != "" {
					g.Myrole = myrole
				} else {
					g.Myrole = "Non-member"
				}
			}

			if !modtools {
				g.Welcomemail = ""
			}

			// Default nil JSON fields to empty objects (same nil-guard as single
			// GetGroup path and user/user.go) so the frontend never receives null.
			if g.Settings == nil {
				g.Settings = json.RawMessage("{}")
			}
			if g.Microvolunteeringoptions == nil {
				g.Microvolunteeringoptions = json.RawMessage("{}")
			}

			mu.Lock()
			results = append(results, result{idx: idx, group: &g})
			mu.Unlock()
		}(i, id)
	}

	wg.Wait()

	// Sort by original request order.
	sort.Slice(results, func(i, j int) bool {
		return results[i].idx < results[j].idx
	})

	groups := make([]Group, 0, len(results))
	for _, r := range results {
		groups = append(groups, *r.group)
	}

	// Fetch polygon data if requested.
	if c.Query("polygon") == "true" && len(groups) > 0 {
		type PolyRow struct {
			ID           uint64  `gorm:"column:id"`
			Poly         *string `gorm:"column:poly"`
			Polyofficial *string `gorm:"column:polyofficial"`
		}

		polyIDs := make([]uint64, len(groups))
		for i, g := range groups {
			polyIDs[i] = g.ID
		}

		var polyRows []PolyRow
		// ORM migration site 9494e3480fa0 (wave 1).
		db.Table("groups").Select("id, poly, polyofficial").Where("id IN ?", polyIDs).Scan(&polyRows)

		polyMap := make(map[uint64]*PolyRow, len(polyRows))
		for i := range polyRows {
			polyMap[polyRows[i].ID] = &polyRows[i]
		}

		for ix := range groups {
			if pr, ok := polyMap[groups[ix].ID]; ok {
				groups[ix].Poly = pr.Poly
				groups[ix].Polyofficial = pr.Polyofficial
				groups[ix].Cga = pr.Polyofficial
				groups[ix].Dpa = pr.Poly
			}
		}
	}

	return c.JSON(groups)
}

func ListGroups(c *fiber.Ctx) error {
	db := database.DBConn

	support := c.Query("support") == "true"

	// Check if user is Admin or Support when support=true is requested.
	isAdminOrSupport := false
	if support {
		myid := user.WhoAmI(c)
		if myid > 0 {
			isAdminOrSupport = auth.IsAdminOrSupport(myid)
		}
	}

	var groups []GroupEntry

	if isAdminOrSupport {
		// Support mode: return all groups (not just published/onhere) with extra fields.
		// ORM migration site 1a4bd532caa4 (wave 1).
		db.Table("groups").
			Select("id, nameshort, namefull, lat, lng, altlat, altlng, onmap, onhere, ontn, onlovejunk, publish, region, contactmail, mentored, "+
				"CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin, "+
				"founded, lastmoderated, lastmodactive, lastautoapprove, activeownercount, activemodcount, "+
				"backupmodsactive, backupownersactive, affiliationconfirmed, affiliationconfirmedby").
			Where("type = ?", FREEGLE).
			Scan(&groups)
	} else {
		// ORM migration site d7629b3fa332 (wave 1).
		db.Table("groups").
			Select("id, nameshort, namefull, lat, lng, onmap, onhere, ontn, onlovejunk, publish, region, contactmail, mentored, CAST(JSON_EXTRACT(groups.settings, '$.showjoin') AS UNSIGNED) AS showjoin").
			Where("publish = 1 AND onhere = 1 AND type = ?", FREEGLE).
			Scan(&groups)
	}

	// For support mode, fetch recent auto-approve, manual-approve, and moderation counts in parallel.
	type approveCount struct {
		Groupid uint64 `gorm:"column:groupid"`
		Count   int    `gorm:"column:count"`
	}
	type moderatedCount struct {
		Groupid        uint64 `gorm:"column:groupid"`
		ModeratedCount int    `gorm:"column:moderated_count"`
		TotalCount     int    `gorm:"column:total_count"`
	}

	var autoApproves []approveCount
	var manualApproves []approveCount
	var moderatedCounts []moderatedCount

	if isAdminOrSupport {
		start31 := time.Now().AddDate(0, 0, -31).Format("2006-01-02")
		start30 := time.Now().AddDate(0, 0, -30).Format("2006-01-02")

		var wg sync.WaitGroup
		wg.Add(3)

		go func() {
			defer wg.Done()
			// ORM migration site f207e41516c4 (wave 1).
			db.Table("logs").Select("COUNT(*) AS count, groupid").
				Where("timestamp >= ? AND type = ? AND subtype = ?", start31, "Message", "Autoapproved").
				Group("groupid").
				Scan(&autoApproves)
		}()

		go func() {
			defer wg.Done()
			// ORM migration site a1d28f99a959 (wave 1).
			db.Table("logs").Select("COUNT(*) AS count, groupid").
				Where("timestamp >= ? AND type = ? AND subtype = ?", start31, "Message", "Approved").
				Group("groupid").
				Scan(&manualApproves)
		}()

		go func() {
			defer wg.Done()
			// Count messages where a moderator manually approved (approvedby IS NOT NULL)
			// vs total messages arriving in the past 30 days, grouped by community.
			// Uses arrival rather than approvedat so the denominator is consistent.
			// ORM migration site 9ab327a70a09 (wave 1).
			db.Table("messages_groups").
				Select("groupid, SUM(approvedby IS NOT NULL) AS moderated_count, COUNT(*) AS total_count").
				Where("arrival >= ?", start30).
				Group("groupid").
				Scan(&moderatedCounts)
		}()

		wg.Wait()

		// Build lookup maps for O(1) access.
		autoMap := make(map[uint64]int, len(autoApproves))
		for _, a := range autoApproves {
			autoMap[a.Groupid] = a.Count
		}
		manualMap := make(map[uint64]int, len(manualApproves))
		for _, a := range manualApproves {
			manualMap[a.Groupid] = a.Count
		}
		modMap := make(map[uint64]moderatedCount, len(moderatedCounts))
		for _, m := range moderatedCounts {
			modMap[m.Groupid] = m
		}

		for ix := range groups {
			autoCount := autoMap[groups[ix].ID]
			// Manual approves includes auto-approves (they have both Approved and Autoapproved logs),
			// so subtract auto-approves to get the true manual count.
			manualCount := manualMap[groups[ix].ID] - autoCount
			if manualCount < 0 {
				manualCount = 0
			}

			groups[ix].Recentautoapproves = &autoCount
			groups[ix].Recentmanualapproves = &manualCount

			var pct float64
			total := autoCount + manualCount
			if total > 0 {
				pct = float64(100*autoCount) / float64(total)
			}
			groups[ix].Recentautoapprovespct = &pct

			mc := modMap[groups[ix].ID]
			modCount := mc.ModeratedCount
			groups[ix].Recentmoderated = &modCount
			var modPct float64
			if mc.TotalCount > 0 {
				modPct = float64(100*mc.ModeratedCount) / float64(mc.TotalCount)
			}
			groups[ix].Recentmoderatedpct = &modPct
		}
	}

	// Fetch polygon data if requested.
	if c.Query("polygon") == "true" && len(groups) > 0 {
		type PolyRow struct {
			ID           uint64  `gorm:"column:id"`
			Poly         *string `gorm:"column:poly"`
			Polyofficial *string `gorm:"column:polyofficial"`
		}

		ids := make([]uint64, len(groups))
		for i, g := range groups {
			ids[i] = g.ID
		}

		var polyRows []PolyRow
		// ORM migration site a7496f46878c (wave 1). Converted together with its
		// identical sibling above: leaving one of two textually identical
		// statements raw is the configuration that renumbers site IDs.
		db.Table("groups").Select("id, poly, polyofficial").Where("id IN ?", ids).Scan(&polyRows)

		polyMap := make(map[uint64]*PolyRow, len(polyRows))
		for i := range polyRows {
			polyMap[polyRows[i].ID] = &polyRows[i]
		}

		for ix := range groups {
			if pr, ok := polyMap[groups[ix].ID]; ok {
				groups[ix].Poly = pr.Poly
				groups[ix].Polyofficial = pr.Polyofficial
				groups[ix].Cga = pr.Polyofficial
				groups[ix].Dpa = pr.Poly
			}
		}
	}

	for ix, group := range groups {
		if len(group.Namefull) > 0 {
			groups[ix].Namedisplay = group.Namefull
		} else {
			groups[ix].Namedisplay = group.Nameshort
		}

		if len(group.Contactmail) > 0 {
			groups[ix].Modsemail = group.Contactmail
		} else {
			groups[ix].Modsemail = group.Nameshort + "-volunteers@" + os.Getenv("GROUP_DOMAIN")
		}
	}

	if len(groups) == 0 {
		// Force [] rather than null to be returned.
		return c.JSON(make([]string, 0))
	} else {
		return c.JSON(groups)
	}
}

// =============================================================================
// Merged from group/group_write.go
// =============================================================================

// validateGeometry checks if a WKT geometry string is valid using MySQL's ST_IsValid.
// Returns true if valid, false if invalid or unparseable.
func validateGeometry(wkt string) bool {
	db := database.DBConn

	var valid *int
	// ORM migration site 6d0982e798b5 (Tier 2 keep-raw review). Bare scalar
	// SELECT with no FROM at all - same BuildClauses={"SELECT"} mechanism as
	// amp.go's bare-EXISTS conversions (see the comment there and
	// ormharness/bareexists_test.go). .Table(...) is still required even
	// though it never renders: without it GORM's schema-parse-failure branch
	// rejects the statement for having no table set. "groups" is used purely
	// to satisfy that check - it never appears in the rendered SQL, since
	// FROM is excluded from BuildClauses.
	tx := db.Table("groups").Select("ST_IsValid(ST_GeomFromText(?))", wkt)
	tx.Statement.BuildClauses = []string{"SELECT"}
	result := tx.Scan(&valid)

	if result.Error != nil || valid == nil {
		return false
	}

	return *valid == 1
}

// logGroupEdit inserts an audit log entry for group edit operations.
func logGroupEdit(groupid uint64, byuser uint64, text string) {
	db := database.DBConn
	// ORM migration site cbad92a90c0d (wave 2).
	db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"),
		"type":      log.LOG_TYPE_GROUP,
		"subtype":   log.LOG_SUBTYPE_EDIT,
		"groupid":   groupid,
		"byuser":    byuser,
		"text":      text,
	})
}

type PatchGroupRequest struct {
	ID                       uint64           `json:"id"`
	Tagline                  *string          `json:"tagline"`
	Namefull                 *string          `json:"namefull"`
	Welcomemail              *string          `json:"welcomemail"`
	Description              *string          `json:"description"`
	Region                   *string          `json:"region"`
	AffiliationConfirmed     *string          `json:"affiliationconfirmed"`
	Onhere                   *int             `json:"onhere"`
	Publish                  *int             `json:"publish"`
	Microvolunteering        *int             `json:"microvolunteering"`
	Microvolunteeringoptions *json.RawMessage `json:"microvolunteeringoptions"`
	Mentored                 *int             `json:"mentored"`
	Ontn                     *int             `json:"ontn"`
	Onlovejunk               *int             `json:"onlovejunk"`
	Profile                  *uint64          `json:"profile"`
	Settings                 *json.RawMessage `json:"settings"`
	Rules                    *json.RawMessage `json:"rules"`
	// Admin/Support only fields
	Lat             *float64 `json:"lat"`
	Lng             *float64 `json:"lng"`
	Altlat          *float64 `json:"altlat"`
	Altlng          *float64 `json:"altlng"`
	Nameshort       *string  `json:"nameshort"`
	Licenserequired *int     `json:"licenserequired"`
	Poly            *string  `json:"poly"`
	Polyofficial    *string  `json:"polyofficial"`
	Showonyahoo     *int     `json:"showonyahoo"`
}

func PatchGroup(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchGroupRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Verify group exists
	var groupCount int64
	// ORM migration site 88ec4f8b3364 (wave 1).
	db.Table("groups").Where("id = ?", req.ID).Count(&groupCount)
	if groupCount == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Group not found")
	}

	// Check authorization: must be mod/owner of the group OR admin/support
	if !auth.IsModOfGroup(myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	isAdmin := auth.IsAdminOrSupport(myid)

	// Apply mod/owner settable fields
	if req.Tagline != nil {
		// ORM migration site b1c25f5b67a9 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("tagline", *req.Tagline)
	}
	if req.Namefull != nil {
		// ORM migration site bc7de11031d3 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("namefull", *req.Namefull)
	}
	if req.Welcomemail != nil {
		// ORM migration site 7a9e12196036 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("welcomemail", *req.Welcomemail)
	}
	if req.Description != nil {
		// ORM migration site fb4e48c03bee (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("description", *req.Description)
	}
	if req.Region != nil {
		// ORM migration site 4fd65f2418a6 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("region", *req.Region)
	}
	if req.AffiliationConfirmed != nil {
		affConfirmed := *req.AffiliationConfirmed
		for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05", "2006-01-02 15:04:05"} {
			if t, err := time.Parse(layout, affConfirmed); err == nil {
				affConfirmed = t.UTC().Format("2006-01-02 15:04:05")
				break
			}
		}
		// ORM migration site b7a7c29b7611 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"affiliationconfirmed": affConfirmed, "affiliationconfirmedby": myid})
	}
	if req.Onhere != nil {
		// ORM migration site 0510fa1a8a85 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("onhere", *req.Onhere)
	}
	if req.Publish != nil {
		// ORM migration site fd0917d06441 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("publish", *req.Publish)
	}
	if req.Microvolunteering != nil {
		// ORM migration site 2e011a3ca233 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("microvolunteering", *req.Microvolunteering)
	}
	if req.Microvolunteeringoptions != nil {
		// ORM migration site 11cd46e11f5a (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("microvolunteeringoptions", string(*req.Microvolunteeringoptions))
	}
	if req.Mentored != nil {
		// ORM migration site dbd2165e28c7 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("mentored", *req.Mentored)
	}
	if req.Ontn != nil {
		// ORM migration site 5a1f3cd17397 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("ontn", *req.Ontn)
	}
	if req.Onlovejunk != nil {
		// ORM migration site 1e4d0a106c72 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("onlovejunk", *req.Onlovejunk)
	}
	if req.Profile != nil {
		// ORM migration site 23cf0e34c542 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("profile", *req.Profile)
		logGroupEdit(req.ID, myid, "Profile")
	}
	if req.Settings != nil {
		// ORM migration site 585a51354a68 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("settings", string(*req.Settings))
		logGroupEdit(req.ID, myid, "Settings")
	}
	if req.Rules != nil {
		// ORM migration site 6de535f15717 (wave 2).
		db.Table("groups").Where("id = ?", req.ID).Update("rules", string(*req.Rules))
		logGroupEdit(req.ID, myid, "Rules")
	}

	// Admin/Support only fields
	if isAdmin {
		if req.Lat != nil {
			// ORM migration site cf14153e46ac (wave 2). Converted together with
			// its identical twin in CreateGroup: a half-converted pair renumbers
			// the survivor's site ID, so gate (h) refuses the split state.
			db.Table("groups").Where("id = ?", req.ID).Update("lat", *req.Lat)
		}
		if req.Lng != nil {
			// ORM migration site 6a4f5b776c87 (wave 2). Converted together with
			// its identical twin in CreateGroup (adbbb9dadd0c): a half-converted
			// pair renumbers the survivor's site ID, so gate (h) refuses the
			// split state.
			db.Table("groups").Where("id = ?", req.ID).Update("lng", *req.Lng)
		}
		if req.Altlat != nil {
			// ORM migration site 0e9905e6f0ce (wave 2).
			db.Table("groups").Where("id = ?", req.ID).Update("altlat", *req.Altlat)
		}
		if req.Altlng != nil {
			// ORM migration site 22727b8ed343 (wave 2).
			db.Table("groups").Where("id = ?", req.ID).Update("altlng", *req.Altlng)
		}
		if req.Nameshort != nil {
			// ORM migration site e5fa7f0e05bd (wave 2).
			db.Table("groups").Where("id = ?", req.ID).Update("nameshort", *req.Nameshort)
		}
		if req.Licenserequired != nil {
			// ORM migration site dd57ad0485df (wave 2).
			db.Table("groups").Where("id = ?", req.ID).Update("licenserequired", *req.Licenserequired)
		}
		// poly (DPA) / polyofficial (CGA). An empty string means "clear this area" - it must be
		// allowed (a moderator removing the DPA), so skip geometry validation and store NULL rather
		// than feeding "" to validateGeometry (which returns 400). Matches V1 PHP Group::setPrivate.
		polyChanged := false
		if req.Poly != nil {
			if *req.Poly == "" {
				// ORM migration site 7993248ef4e6 (wave 2).
				db.Table("groups").Where("id = ?", req.ID).Update("poly", gorm.Expr("NULL"))
			} else {
				if !validateGeometry(*req.Poly) {
					return fiber.NewError(fiber.StatusBadRequest, "Invalid poly geometry")
				}
				// ORM migration site 450c5b5fca94 (wave 2).
				db.Table("groups").Where("id = ?", req.ID).Update("poly", *req.Poly)
			}
			polyChanged = true
		}
		if req.Polyofficial != nil {
			if *req.Polyofficial == "" {
				// ORM migration site cff1d8adacf1 (wave 2).
				db.Table("groups").Where("id = ?", req.ID).Update("polyofficial", gorm.Expr("NULL"))
			} else {
				if !validateGeometry(*req.Polyofficial) {
					return fiber.NewError(fiber.StatusBadRequest, "Invalid polyofficial geometry")
				}
				// ORM migration site e4f0bcf9f2eb (wave 2).
				db.Table("groups").Where("id = ?", req.ID).Update("polyofficial", *req.Polyofficial)
			}
			polyChanged = true
		}
		if polyChanged {
			// Recompute the spatial index so the poly/polyofficial change takes effect. When the DPA
			// (poly) is cleared the group falls back to the CGA (polyofficial), then to POINT(0 0).
			// ORM migration site 548090e97d00 (Tier 1 spatial review, round 3).
			// SRID is folded into the gorm.Expr string via fmt.Sprintf, the
			// same shipped idiom location.go's locations_spatial REPLACE
			// sites use (25b7b92e33fd/6f1d6543e5c0).
			db.Table("groups").
				Where("id = ?", req.ID).
				Update("polyindex", gorm.Expr(fmt.Sprintf("ST_GeomFromText(COALESCE(poly, polyofficial, 'POINT(0 0)'), %d)", utils.SRID)))
		}
		if req.Showonyahoo != nil {
			// ORM migration site 34c2c6e9128b (wave 2).
			db.Table("groups").Where("id = ?", req.ID).Update("showonyahoo", *req.Showonyahoo)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

type CreateGroupRequest struct {
	Name      string   `json:"name"`
	GroupType string   `json:"grouptype"`
	Lat       *float64 `json:"lat,omitempty"`
	Lng       *float64 `json:"lng,omitempty"`
}

// CreateGroup creates a new group. Requires moderator/owner on any group, or admin/support.
// @Summary Create a new group
// @Tags group
// @Accept json
// @Produce json
// @Security BearerAuth
// @Router /group [post]
func CreateGroup(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req CreateGroupRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Name == "" {
		return fiber.NewError(fiber.StatusBadRequest, "name is required")
	}

	if req.GroupType == "" {
		req.GroupType = "Freegle"
	}

	db := database.DBConn

	// Check authorization: admin/support OR moderator/owner of any group.
	isAdmin := auth.IsAdminOrSupport(myid)

	if !isAdmin {
		var modCount int64
		// ORM migration site fcf7a3fd9364 (wave 1).
		db.Table("memberships").Where("userid = ? AND role IN (?, ?)", myid, utils.ROLE_OWNER, utils.ROLE_MODERATOR).Count(&modCount)
		if modCount == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Must be a moderator to create groups")
		}
	}

	// ORM migration site 8cbeeeb7e32f (Tier 1 batch review). GORM's map-Create
	// reads the id back from the same sql.Result the INSERT returned (under
	// the map key "@id"), the same write-connection guarantee the old
	// sqlDB.Exec()+LastInsertId() call had. SRID folded into the gorm.Expr
	// string via fmt.Sprintf, the shipped idiom this file's PatchGroup
	// (site 548090e97d00) and location.go's locations_spatial REPLACE sites
	// already use.
	row := map[string]interface{}{
		"nameshort": req.Name,
		"namefull":  req.Name,
		"type":      req.GroupType,
		"publish":   gorm.Expr("1"),
		"onhere":    gorm.Expr("1"),
		"polyindex": gorm.Expr(fmt.Sprintf("ST_GeomFromText('POINT(0 0)', %d)", utils.SRID)),
	}
	if err := db.Table("groups").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create group")
	}

	var newID uint64
	if idInt64, ok := row["@id"].(int64); ok && idInt64 > 0 {
		newID = uint64(idInt64)
	}

	// Admin/support can set lat/lng.
	if isAdmin {
		if req.Lat != nil {
			// ORM migration site 194062f24f48 (wave 2).
			db.Table("groups").Where("id = ?", newID).Update("lat", *req.Lat)
		}
		if req.Lng != nil {
			// ORM migration site adbbb9dadd0c (wave 2). Converted together with
			// its identical twin in PatchGroup (6a4f5b776c87): a half-converted
			// pair renumbers the survivor's site ID, so gate (h) refuses the
			// split state.
			db.Table("groups").Where("id = ?", newID).Update("lng", *req.Lng)
		}
	}

	// Creator becomes Owner.
	// ORM migration site ea603dbc3fe0 (wave 2).
	db.Table("memberships").Create(map[string]interface{}{
		"userid":     myid,
		"groupid":    newID,
		"role":       utils.ROLE_OWNER,
		"collection": utils.COLLECTION_APPROVED,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "id": newID})
}

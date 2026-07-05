package message

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"sort"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/freegle/iznik-server-go/aiimage"
	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/group"
	"github.com/freegle/iznik-server-go/item"
	"github.com/freegle/iznik-server-go/location"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/spatial"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
	"golang.org/x/net/html"
	"gorm.io/gorm"
	"gorm.io/gorm/clause"
	"gorm.io/plugin/dbresolver"
)

// Pre-compiled regexps to avoid recompiling on every message fetch.
var emailRegexp = regexp.MustCompile(utils.EMAIL_REGEXP)
var phoneRegexp = regexp.MustCompile(utils.PHONE_REGEXP)
var tnRegexp = regexp.MustCompile(utils.TN_REGEXP)

// tnPicPageURLRegexp finds each TN "pics" page link embedded in a textbody.
var tnPicPageURLRegexp = regexp.MustCompile(`(?m)https://trashnothing\.com/pics/\S+`)

// tnPicHeaderRegexp strips the "Check out the pictures…" intro line.
var tnPicHeaderRegexp = regexp.MustCompile(`(?m)^Check out the pictures[^\n]*\n?`)

// tnPicURLLineRegexp strips individual trashnothing.com/pics/ URL lines.
var tnPicURLLineRegexp = regexp.MustCompile(`(?m)^https://trashnothing\.com/pics/[^\n]*\n?`)

// TNPageFetcher fetches a TN /pics/ page and returns direct image URLs.
// Swappable in tests.
var TNPageFetcher = extractTNImageURLsFromPage

// TNImageFetcher downloads a TN image and returns (data, mime, error).
// Swappable in tests.
var TNImageFetcher = downloadTNImage

func downloadTNImage(imageURL string) ([]byte, string, error) {
	client := &http.Client{Timeout: 60 * time.Second}
	resp, err := client.Get(imageURL)
	if err != nil {
		return nil, "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, "", fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, "", err
	}
	mime := resp.Header.Get("Content-Type")
	if mime == "" {
		mime = "image/jpeg"
	}
	return data, mime, nil
}

// isTNImageURL returns true for direct TN image URLs (not the /pics/ page links).
func isTNImageURL(u string) bool {
	return strings.Contains(u, "trashnothing.com/img/") ||
		strings.Contains(u, "img.trashnothing.com") ||
		strings.Contains(u, "/tn-photos/") ||
		strings.Contains(u, "photos.trashnothing.com")
}

// extractTNImageURLsFromPage fetches a TN /pics/ page and returns direct image URLs.
func extractTNImageURLsFromPage(pageURL string) []string {
	client := &http.Client{Timeout: 30 * time.Second}
	resp, err := client.Get(pageURL)
	if err != nil || resp.StatusCode < 200 || resp.StatusCode >= 300 {
		if err == nil {
			resp.Body.Close()
		}
		return nil
	}
	defer resp.Body.Close()

	doc, err := html.Parse(resp.Body)
	if err != nil {
		return nil
	}

	var found []string
	var walk func(*html.Node)
	walk = func(n *html.Node) {
		if n.Type == html.ElementNode && n.Data == "a" {
			for _, attr := range n.Attr {
				if attr.Key == "href" && isTNImageURL(attr.Val) {
					found = append(found, attr.Val)
					break
				}
			}
		}
		for c := n.FirstChild; c != nil; c = c.NextSibling {
			walk(c)
		}
	}
	walk(doc)

	// Fall back to img src if no anchor hrefs found.
	if len(found) == 0 {
		var walkImgs func(*html.Node)
		walkImgs = func(n *html.Node) {
			if n.Type == html.ElementNode && n.Data == "img" {
				for _, attr := range n.Attr {
					if attr.Key == "src" && isTNImageURL(attr.Val) {
						found = append(found, attr.Val)
						break
					}
				}
			}
			for c := n.FirstChild; c != nil; c = c.NextSibling {
				walkImgs(c)
			}
		}
		walkImgs(doc)
	}

	return found
}

// TNPhotoScrapeRunner scrapes TN photos and stores them as attachments.  It runs
// SYNCHRONOUSLY so the photos are fully in place before the caller writes the edit's
// change signal (the messages_edits row).  TN polls /api/changes — which reports a
// message as "Edited" via messages_edits — and then fetches the message to read its
// attachments; if the signal were visible before the photos landed, TN would get a
// partial photo set.  Swappable in tests.
var TNPhotoScrapeRunner = func(db *gorm.DB, msgID uint64, picPageURLs []string) {
	scrapeTNPhotosToAttachments(db, msgID, picPageURLs)
}

// scrapeTNPhotosToAttachments downloads TN images from pic-page URLs and inserts
// them as messages_attachments rows.  Errors are logged only.
// Exported as ScrapeTNPhotosSync for test use.
func scrapeTNPhotosToAttachments(db *gorm.DB, msgID uint64, picPageURLs []string) {
	isPrimary := true
	seen := map[string]bool{}
	for _, pageURL := range picPageURLs {
		imageURLs := TNPageFetcher(pageURL)
		for _, imageURL := range imageURLs {
			if seen[imageURL] {
				continue
			}
			seen[imageURL] = true

			data, mime, err := TNImageFetcher(imageURL)
			if err != nil {
				log.Printf("scrapeTNPhotos: failed to download %s: %v", imageURL, err)
				continue
			}

			externaluid, err := aiimage.ImageUploader(data, mime)
			if err != nil {
				log.Printf("scrapeTNPhotos: TUS upload failed for %s: %v", imageURL, err)
				continue
			}

			primary := 0
			if isPrimary {
				primary = 1
				isPrimary = false
			}
			db.Table("messages_attachments").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
				"msgid":       msgID,
				"externaluid": externaluid,
				"primary":     primary,
			})
		}
	}
}

// ScrapeTNPhotosSync is the synchronous variant of scrapeTNPhotosToAttachments, exposed for tests.
var ScrapeTNPhotosSync = scrapeTNPhotosToAttachments

// Declaring the table name seems to help with a race seen in testing.
func (Message) TableName() string {
	return "messages"
}

// Message represents a posting (offer or wanted)
// swagger:model Message
type Message struct {
	ID      uint64    `json:"id" gorm:"primary_key"`
	Arrival time.Time `json:"arrival"`
	// VisibleSince is the earliest this post could have been seen: the oldest arrival across
	// the groups it is live on. The feed orders by it and the card dates by it, so the list
	// cannot contradict the dates printed on it.
	//
	// Arrival above is messages.arrival - when it was first written - which is NOT the same
	// thing once a post has been reposted or has rippled: this browse view was ordering by
	// Arrival while the card showed a group arrival, so a 20-day-old post displaying "5 days"
	// sat above a 3-hour-old one.
	VisibleSince       time.Time           `json:"visibleSince"`
	Date               time.Time           `json:"date"`
	Fromuser           uint64              `json:"fromuser"`
	Subject            string              `json:"subject"`
	Type               string              `json:"type"`
	Textbody           string              `json:"textbody"`
	Lat                float64             `json:"lat"`
	Lng                float64             `json:"lng"`
	Unseen             bool                `json:"unseen"`
	Availablenow       uint                `json:"availablenow"`
	Availableinitially uint                `json:"availableinitially"`
	MessageGroups      []MessageGroup      `gorm:"-" json:"groups"`
	MessageAttachments []MessageAttachment `gorm:"-" json:"attachments"`
	MessageOutcomes    []MessageOutcome    `gorm:"-" json:"outcomes"`
	MessagePromises    []MessagePromise    `gorm:"-" json:"promises"`
	Promisecount       int                 `json:"promisecount"`
	Promised           bool                `json:"promised"`
	PromisedToYou      bool                `json:"promisedtoyou"`
	MessageReply       []MessageReply      `gorm:"ForeignKey:refmsgid" json:"replies"`
	Replycount         int                 `json:"replycount"`
	MessageURL         string              `json:"url"`
	Successful         bool                `json:"successful"`
	Refchatids         []uint64            `json:"refchatids" gorm:"-"`
	Locationid         uint64              `json:"-"`
	Location           *location.Location  `json:"location,omitempty" gorm:"-"`
	Item               *item.Item          `json:"item" gorm:"-"`
	// DEPRECATED, for bundled app clients only. A hold belongs to a (message, group)
	// pair (messages_groups.heldby, exposed as groups[].heldby); there is no correct
	// message-wide value for a post that reached several groups, and supplying one
	// leaks one group's hold onto the others (Discourse 9970/2). Up-to-date clients
	// read the per-group row for the group they are acting on — see MessageGroup.Heldby.
	//
	// It stays in the payload because the ModTools app bundles its web build, so
	// installed apps render held state from this field and lost holds entirely when it
	// was removed (Discourse 9481/636). Computed per viewer by effectiveHeldby; there is
	// no messages.heldby column behind it any more. Remove once the app floor has moved
	// past the per-group frontend.
	Heldby           *uint64          `json:"heldby"`
	Source           *string          `json:"source"`
	Sourceheader     *string          `json:"sourceheader"`
	Fromaddr         *string          `json:"fromaddr"`
	Fromip           *string          `json:"fromip"`
	Fromcountry      *string          `json:"fromcountry"`
	Repostat         *time.Time       `json:"repostat"`
	Canrepost        bool             `json:"canrepost"`
	Deliverypossible bool             `json:"deliverypossible"`
	Deadline         *time.Time       `json:"deadline"`
	Edits            []MessageEdit    `json:"edits,omitempty" gorm:"-"`
	RawMessage       *string          `json:"message,omitempty" gorm:"column:message"`
	Worry            []WorryMatch     `json:"worry,omitempty" gorm:"-"`
	Postings         []MessagePosting `json:"postings,omitempty" gorm:"-"`
	Tnpostid         *string          `json:"tnpostid"`
	Expiresat        *time.Time       `json:"expiresat,omitempty" gorm:"-"`
	// ReplyEligible: rippling-out (#2). nil/omitted = eligible (the post isn't rippling,
	// i.e. has no rippling_reach row, or eligibility wasn't computed). false = the post
	// has rippled out but not yet to the viewer's location, so the UI shows it view-only.
	ReplyEligible *bool `json:"replyeligible,omitempty" gorm:"-"`
	// ReachesYouAt: when this post's rippling reach is expected to arrive at the
	// viewer, for a post they can see but which has not rippled to them yet. Set only
	// alongside ReplyEligible=false and only for the reach reason - a viewer blocked
	// by a ban is not waiting for the ripple, so it stays nil there.
	//
	// ReachesYouFully says which question was answered. True: a tick of the post's own
	// schedule grows far enough to include them, and this is when. False: no tick ever
	// does, so this is instead when the reach stops expanding - the point their held
	// reply is passed on regardless. Both are real answers; the second is the common
	// one, and it is an upper bound rather than a prediction, because a reach also
	// finishes early when the post gathers enough repliers or is taken.
	ReachesYouAt    *time.Time `json:"reachesyouat,omitempty" gorm:"-"`
	ReachesYouFully *bool      `json:"reachesyoufully,omitempty" gorm:"-"`
	// BulkItems is the structured catalogue for a bulk offer ("clearance"). Nil
	// (and omitted) for ordinary single-item posts. Bulkcount is len(BulkItems),
	// exposed so list/summary views can flag a bulk offer cheaply.
	BulkItems []BulkItem `json:"bulkitems,omitempty" gorm:"-"`
	Bulkcount int        `json:"bulkcount,omitempty" gorm:"-"`
	// Bulkslots are the offerer-defined collection windows a replier picks from.
	Bulkslots []string `json:"bulkslots,omitempty" gorm:"-"`
	// Accessinstructions is the offerer's private note (address / gate code /
	// intercom). Only returned to the offerer or a moderator — never to general
	// viewers — and sent to a replier only once they're promised an item.
	Accessinstructions *string `json:"accessinstructions,omitempty" gorm:"-"`
}

// MessagePosting represents a posting history record from messages_postings.
type MessagePosting struct {
	Msgid       uint64 `json:"msgid"`
	Groupid     uint64 `json:"groupid"`
	Date        string `json:"date"`
	Repost      bool   `json:"repost"`
	Autorepost  bool   `json:"autorepost"`
	Namedisplay string `json:"namedisplay"`
}

// WorryMatch represents a concern keyword found in a message's subject or body.
type WorryMatch struct {
	Word      string    `json:"word"`
	Worryword WorryWord `json:"worryword"`
}

// WorryWord represents a concern keyword used for message checking.
type WorryWord struct {
	ID      uint64 `json:"id"`
	Keyword string `json:"keyword"`
	Type    string `json:"type"`
}

type MessageEdit struct {
	ID             uint64     `json:"id"`
	Oldsubject     *string    `json:"oldsubject"`
	Newsubject     *string    `json:"newsubject"`
	Oldtext        *string    `json:"oldtext"`
	Newtext        *string    `json:"newtext"`
	Reviewrequired int        `json:"reviewrequired"`
	Timestamp      *time.Time `json:"timestamp"`
}

// computeExpiresat calculates when a message expires based on group settings.
// It checks maxagetoshow and repost settings for each group the message is on,
// and returns the latest (most generous) expiry time.
func computeExpiresat(db *gorm.DB, msgType string, messageGroups []MessageGroup) *time.Time {
	if len(messageGroups) == 0 {
		return nil
	}

	groupIDs := make([]uint64, len(messageGroups))
	arrivalByGroup := make(map[uint64]time.Time)
	for i, mg := range messageGroups {
		groupIDs[i] = mg.Groupid
		arrivalByGroup[mg.Groupid] = mg.Arrival
	}

	type groupSettings struct {
		ID       uint64 `gorm:"column:id"`
		Settings string `gorm:"column:settings"`
	}
	var groups []groupSettings
	// Converted together with its
	// identical sibling in applyExpiry below: leaving one of two textually
	// identical statements raw is the configuration that renumbers the
	// survivor's site ID (ratchet gate h).
	db.Table("groups").Select("id, settings").Where("id IN ?", groupIDs).Scan(&groups)

	var latest *time.Time

	for _, g := range groups {
		arrival, ok := arrivalByGroup[g.ID]
		if !ok {
			continue
		}

		// Mirror the legacy V1 PHP Message::getPublic() behaviour:
		//   $maxagetoshow = $g->getSetting('maxagetoshow', 90);
		//   $reposts      = $g->getSetting('reposts', ['offer'=>3,'wanted'=>14,'max'=>10,...]);
		//   $repost       = $type == Offer ? $reposts['offer'] : $reposts['wanted'];
		//   $expiretime   = max($repost * ($reposts['max'] + 1), $maxagetoshow);
		// V1 getSetting honours explicit 0, so we mustn't fall back to the default
		// when maxagetoshow is set to 0 — Hertford and others use 0 deliberately.
		maxAgeDays := 90
		repostDays := 14
		if msgType == "Offer" {
			repostDays = 3
		}
		maxRepost := 10

		if g.Settings != "" {
			var s map[string]interface{}
			if err := json.Unmarshal([]byte(g.Settings), &s); err == nil {
				if v, exists := s["maxagetoshow"]; exists {
					if fv, ok := v.(float64); ok {
						maxAgeDays = int(fv)
					}
				}

				if reposts, exists := s["reposts"]; exists {
					if rMap, ok := reposts.(map[string]interface{}); ok {
						typeKey := "wanted"
						if msgType == "Offer" {
							typeKey = "offer"
						}
						if rd, ok := rMap[typeKey].(float64); ok {
							repostDays = int(rd)
						}
						if mx, ok := rMap["max"].(float64); ok {
							maxRepost = int(mx)
						}
					}
				}
			}
		}

		repostLifetime := repostDays * (maxRepost + 1)
		if repostLifetime > maxAgeDays {
			maxAgeDays = repostLifetime
		}

		expires := arrival.Add(time.Duration(maxAgeDays) * 24 * time.Hour)
		if latest == nil || expires.After(*latest) {
			latest = &expires
		}
	}

	return latest
}

func GetMessages(c *fiber.Ctx) error {
	ids := strings.Split(c.Params("ids"), ",")
	myid := user.WhoAmI(c)
	isPartner := false
	if key := c.Query("partner"); key != "" {
		if _, _, _, err := user.ValidatePartnerKey(database.DBConn, key); err == nil {
			isPartner = true
		}
	}

	if len(ids) < 20 {
		messages := GetMessagesByIds(myid, ids, isPartner)

		if len(ids) == 1 {
			if len(messages) == 1 {
				return c.JSON(messages[0])
			} else {
				return fiber.NewError(fiber.StatusNotFound, "Message not found")
			}
		} else {
			return c.JSON(messages)
		}
	} else {
		return fiber.NewError(fiber.StatusBadRequest, "Steady on")
	}
}

// rippleEnabled reports whether the rippling-out feature is switched on. Mirrors the Laravel
// config('freegle.ripple.enabled') / RIPPLE_ENABLED env so the whole feature ships dark and is
// flipped on with one env var (default off). While off, the reach/reply-eligibility path below is
// skipped entirely, so the API is byte-for-byte identical to pre-rippling.
func rippleEnabled() bool {
	v := os.Getenv("RIPPLE_ENABLED")
	return v == "true" || v == "1"
}

func GetMessagesByIds(myid uint64, ids []string, isPartner bool) []Message {
	db := database.DBConn
	archiveDomain := os.Getenv("IMAGE_ARCHIVED_DOMAIN")
	imageDomain := os.Getenv("IMAGE_DOMAIN")

	// This can be used to fetch one or more messages.  Fetch them in parallel.  Empirically this is faster than
	// fetching the information in parallel for multiple messages.
	var mu sync.Mutex
	messages := []Message{}
	er := emailRegexp
	ep := phoneRegexp

	var wgOuter sync.WaitGroup

	wgOuter.Add(len(ids))

	for _, id := range ids {
		go func(id string) {
			defer wgOuter.Done()

			var message Message
			found := false

			// We have lots to load here.  db.preload is tempting, but loads in series - so if we use go routines we can
			// load in parallel and reduce latency.
			var wg sync.WaitGroup
			isMod := auth.IsSystemMod(myid)

			wg.Add(1)
			go func() {
				defer wg.Done()
				// isMod
				// is the only toggle (it drives the deleted-sender filter and
				// the raw message field together) - 2 possible rendered forms,
				// both proven by the retired ormharness (shapes.json /
				// TestTier3Shapes_08bb471351a0, removed in d22ba1d6c).
				selectCols := "messages.id, messages.arrival, messages.date, messages.fromuser, " +
					// Oldest live-group arrival: when this first became available to anyone. A repost
					// bumps that row, so this follows it, which is what makes a repost lift the post.
					"COALESCE((SELECT MIN(mgv.arrival) FROM messages_groups mgv WHERE mgv.msgid = messages.id AND mgv.deleted = 0), messages.arrival) AS visible_since, " +
					"messages.subject, messages.type, textbody, lat, lng, availablenow, availableinitially, locationid, " +
					"deliverypossible, deadline, heldby, messages.source, messages.sourceheader, messages.fromaddr, messages.fromip, messages.fromcountry, messages.tnpostid, "
				if isMod {
					selectCols += "messages.message, "
				}
				selectCols += "CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen"

				whereSQL := "messages.id = ? AND messages.deleted IS NULL"
				whereArgs := []interface{}{id}
				if !isMod {
					whereSQL += " AND users.deleted IS NULL"
				}

				// Find, not First: First unconditionally adds an implicit
				// "ORDER BY <primary key>" + LIMIT 1 and raises
				// ErrRecordNotFound, but this is a Table()-only query with
				// no registered Model, so Schema stays nil and resolving
				// that ORDER BY's primary key column fails outright with
				// "model value required" (gorm's statement.go, the
				// clause.Column PrimaryKey case). See group/group.go's
				// GetGroup (site 2811b4d3acf7) for the established fix:
				// Find() never adds those clauses, so the caller checks
				// RowsAffected instead of comparing the error to
				// ErrRecordNotFound.
				tx := db.Table("messages").
					Select(selectCols).
					Joins("LEFT JOIN users ON users.id = messages.fromuser").
					Joins("LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?", myid, utils.MESSAGE_LIKES_VIEW).
					Where(whereSQL, whereArgs...).
					Find(&message)
				found = tx.RowsAffected > 0
			}()

			var messageGroups []MessageGroup
			wg.Add(1)
			go func() {
				defer wg.Done()

				// Get messages_groups entries for this message.
				// Messages must have at least one entry in messages_groups to be publicly accessible.
				// This prevents internal messages (like chat messages received by email) from being
				// exposed on the public web.
				//
				// Both APPROVED and PENDING messages are visible to all users. This is not a privacy
				// issue because these messages were posted with the intention of being public. It also
				// allows shared links to work even before moderation approval.
				db.Table("messages_groups").
					Select("groupid, msgid, arrival, collection, autoreposts, approvedby, heldby, spamtype, spamreason, contentcheck_checked_at, contentcheck_reasons, rippled_in").
					Where("msgid = ? AND deleted = 0", id).Scan(&messageGroups)

				// Moderator-only "quicker to get to" P/Q note, kept in its own rippling_proximity
				// table (off the hot messages_groups path). Best-effort: only for mods, and a
				// missing table / query error just means no note — so apiv2 can ship before the
				// table exists without affecting message loading.
				if isMod && len(messageGroups) > 0 {
					var notes []struct {
						Groupid uint64
						P       string
						Q       string
					}
					if err := db.Table("rippling_proximity").Select("groupid, p, q").Where("msgid = ?", id).Scan(&notes).Error; err == nil {
						for _, nt := range notes {
							for i := range messageGroups {
								if messageGroups[i].Groupid == nt.Groupid {
									p, q := nt.P, nt.Q
									messageGroups[i].RippleProximityP = &p
									messageGroups[i].RippleProximityQ = &q
								}
							}
						}
					}
				}
			}()

			var messageAttachments []MessageAttachment

			wg.Add(1)
			go func() {
				defer wg.Done()
				// Mask rejected/regenerating AI images: if the externaluid matches an ai_image
				// that is no longer active, return an empty externaluid so the frontend shows
				// a placeholder instead of the rejected illustration.
				db.Table("messages_attachments ma").
					Select("ma.id, ma.msgid, bia.bulkitemid, ma.archived, "+
						"CASE WHEN ai.id IS NOT NULL THEN '' ELSE COALESCE(ma.externaluid, '') END AS externaluid, "+
						"ma.externalmods").
					Joins("LEFT JOIN ai_images ai ON ai.externaluid = ma.externaluid AND ai.status IN ('rejected', 'regenerating', 'suppressed')").
					Joins("LEFT JOIN messages_bulk_item_attachments bia ON bia.attachmentid = ma.id").
					Where("ma.msgid = ?", id).
					Order("ma.`primary` DESC, ma.id ASC").
					Scan(&messageAttachments)
			}()

			var messageReply []MessageReply
			wg.Add(1)
			go func() {
				defer wg.Done()

				// There is some strange case where people can end up replying to themselves.  Don't show such
				// replies.
				//
				// If someone has replied multiple times, we only want to return one of them, so group by userid.
				//
				// Check that the reply isn't too long ago compared to the most recent post of it.  That can happen
				// very occasionally if someone posts, an item for a long time, and there is a reply
				//
				// Gate rippling held replies: an email/TN reply from outside the post's current reach is
				// held (rippling_held_replies, status <> 'released') so it doesn't reach the poster before
				// the post ripples to the replier. Every delivery channel honours this - the in-app chat
				// list/count and message fetch (chat/chatmessage.go), the poster-notification email and
				// push, and the chat-list badge/snippet/roster (PR #927). This own-posts reply list feeds
				// the "My Posts" replies + replycount, so it must gate too or the poster sees a held reply
				// there (name + snippet + count) while it's still hidden everywhere else. Unconditional
				// (no mod exemption), matching the #927 count-surface gates.
				db.Table("chat_messages").
					Select("DISTINCT chat_messages.id, refmsgid, chat_messages.date, userid, fromuser, "+
						"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname").
					Joins("INNER JOIN messages ON messages.id = chat_messages.refmsgid").
					Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
					Joins("INNER JOIN users ON users.id = chat_messages.userid").
					Where("refmsgid = ? AND chat_messages.type = ? AND (messages.fromuser != ? OR chat_messages.userid != ?) "+
						"AND reviewrequired = 0 AND reviewrejected = 0 "+
						"AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = chat_messages.id AND rhr.status <> 'released') "+
						"AND DATEDIFF(chat_messages.date, messages_groups.arrival) < ?",
						id, utils.MESSAGE_INTERESTED, myid, myid, utils.OPEN_AGE).
					Group("userid").
					Scan(&messageReply)

				tnre := tnRegexp

				for i, r := range messageReply {
					if r.Fromuser != myid {
						// Not our message so we shouldn't see who replied.
						messageReply[i].Userid = 0
						messageReply[i].Displayname = ""
					} else {
						messageReply[i].Displayname = tnre.ReplaceAllString(messageReply[i].Displayname, "$1")
					}
				}
			}()

			var messageOutcomes []MessageOutcome
			wg.Add(1)
			go func() {
				defer wg.Done()
				db.Where("msgid = ?", id).Find(&messageOutcomes)
			}()

			var messagePromises []MessagePromise
			wg.Add(1)
			go func() {
				defer wg.Done()
				db.Where("msgid = ?", id).Find(&messagePromises)
			}()

			var refchatids []uint64
			wg.Add(1)
			go func() {
				defer wg.Done()
				db.Table("chat_messages").Select("DISTINCT(chatid)").Where("refmsgid = ?", id).Pluck("chatid", &refchatids)
			}()

			// Fetch pending edits (mod-only, for edit review page).
			var messageEdits []MessageEdit
			if isMod {
				wg.Add(1)
				go func() {
					defer wg.Done()
					db.Table("messages_edits").
						Select("id, oldsubject, newsubject, oldtext, newtext, reviewrequired, `timestamp` AS `timestamp`").
						Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", id).
						Order("id DESC").Scan(&messageEdits)
				}()
			}

			wg.Wait()

			// isGroupMod is used for edit access and location disclosure.
			isGroupMod := isMod
			if !isGroupMod {
				idNum, _ := strconv.ParseUint(id, 10, 64)
				isGroupMod = isModForMessage(db, myid, idNum)
			}

			// Postings (history of which groups this message was on) are public information,
			// returned to all callers — matching V1 behaviour.
			var messagePostings []MessagePosting
			db.Table("messages_postings mp").
				Select("mp.msgid, mp.groupid, mp.date, mp.repost, mp.autorepost, COALESCE(g.namefull, g.nameshort) AS namedisplay").
				Joins("INNER JOIN `groups` g ON mp.groupid = g.id").
				Where("mp.msgid = ?", id).
				Order("mp.date ASC").
				Scan(&messagePostings)

			message.MessageGroups = messageGroups

			// Holds are carried per-group on messageGroups (groups[].heldby); that is the
			// truth, and what up-to-date clients read. The message-level Heldby below is a
			// compatibility value for bundled app clients that predate the per-group change
			// — see effectiveHeldby. Resolve it to a hold on a group the viewer actually
			// moderates; non-mods see none.
			message.Heldby = nil
			if isGroupMod {
				var myModGroups []uint64
				db.Table("memberships").Select("groupid").
					Where("userid = ? AND role IN (?, ?) AND collection = ?", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).
					Scan(&myModGroups)
				if len(myModGroups) > 0 {
					viewer := make(map[uint64]bool, len(myModGroups))
					for _, g := range myModGroups {
						viewer[g] = true
					}
					message.Heldby = effectiveHeldby(messageGroups, viewer)
				}
			}
			message.Expiresat = computeExpiresat(db, message.Type, messageGroups)
			message.MessageAttachments = messageAttachments
			message.MessageReply = messageReply
			message.MessageOutcomes = messageOutcomes
			message.MessagePromises = messagePromises
			if isMod && len(messageEdits) > 0 {
				message.Edits = messageEdits
			}
			if len(messagePostings) > 0 {
				message.Postings = messagePostings
			}

			if found && (len(messageGroups) > 0 || isMod) {
				message.Replycount = len(message.MessageReply)
				message.MessageURL = "https://" + os.Getenv("USER_SITE") + "/message/" + strconv.FormatUint(message.ID, 10)

				// Populate location with precise coords and nearby groups (mod-only).
				// The top-level lat/lng are blurred below for privacy; the location
				// field contains precise data and must only be returned to mods.
				if isMod {
					if message.Locationid > 0 {
						loc := location.FetchSingle(message.Locationid)
						if loc != nil {
							if message.Lat != 0 && message.Lng != 0 {
								loc.GroupsNear = location.ClosestGroups(float64(message.Lat), float64(message.Lng), location.NEARBY, 10)
							}
							message.Location = loc
						}
					} else if message.Lat != 0 && message.Lng != 0 {
						l := location.ClosestPostcode(float32(message.Lat), float32(message.Lng))
						l.GroupsNear = location.ClosestGroups(float64(message.Lat), float64(message.Lng), location.NEARBY, 10)
						message.Location = &l
					}
				}

				// Protect anonymity of poster a bit.
				message.Lat, message.Lng = utils.Blur(message.Lat, message.Lng, utils.BLUR_USER)

				// source/fromip/fromcountry are mod-only fields.
				if !isMod {
					message.Source = nil
					message.Sourceheader = nil
					message.Fromaddr = nil
					message.Fromip = nil
					message.Fromcountry = nil

					// Why a post was held is moderator information: it names the
					// keyword that flagged it, which tells a spammer exactly what
					// to avoid next time. The row is selected for everyone because
					// collection/arrival are public, so strip the check fields here.
					for i := range message.MessageGroups {
						message.MessageGroups[i].ContentcheckReasons = nil
						message.MessageGroups[i].ContentcheckCheckedAt = nil
					}
				}

				// Convert 2-letter country code to full name for frontend display.
				if message.Fromcountry != nil && len(*message.Fromcountry) == 2 {
					if name, ok := utils.CountryName(*message.Fromcountry); ok {
						message.Fromcountry = &name
					}
				}

				// Strip potential phone numbers and email addresses for anonymous
				// callers. Skip when authenticated by a valid partner key — partners
				// are trusted integrations (e.g. Trash Nothing) that need the full
				// body to round-trip messages between platforms.
				if myid == 0 && !isPartner {
					// Remove confidential info.
					message.Textbody = er.ReplaceAllString(message.Textbody, "***@***.com")
					message.Textbody = ep.ReplaceAllString(message.Textbody, "***")
				}

				// Get the paths and compute AI field.
				for i, a := range message.MessageAttachments {
					message.MessageAttachments[i].ComputeAI()
					if a.Externaluid != "" {
						message.MessageAttachments[i].Ouruid = a.Externaluid
						message.MessageAttachments[i].Externalmods = a.Externalmods
						message.MessageAttachments[i].Path = misc.GetImageDeliveryUrl(a.Externaluid, string(a.Externalmods))
						message.MessageAttachments[i].Paththumb = misc.GetImageDeliveryUrl(a.Externaluid, string(a.Externalmods))
					} else if a.Archived > 0 {
						message.MessageAttachments[i].Path = "https://" + archiveDomain + "/img_" + strconv.FormatUint(a.ID, 10) + ".jpg"
						message.MessageAttachments[i].Paththumb = "https://" + archiveDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
					} else {
						message.MessageAttachments[i].Path = "https://" + imageDomain + "/img_" + strconv.FormatUint(a.ID, 10) + ".jpg"
						message.MessageAttachments[i].Paththumb = "https://" + imageDomain + "/timg_" + strconv.FormatUint(a.ID, 10) + ".jpg"
					}
				}

				message.Promisecount = len(message.MessagePromises)
				message.Promised = message.Promisecount > 0

				for _, o := range message.MessageOutcomes {
					if o.Outcome == utils.OUTCOME_TAKEN || o.Outcome == utils.OUTCOME_RECEIVED {
						message.Successful = true
					}
				}

				if message.Fromuser != myid {
					// Privacy: a non-owner viewer shouldn't see *other people's*
					// promise rows. But they CAN see their own row if they're a
					// promisee — that row records terms they are themselves a
					// party to (e.g. agreement details they're being asked to
					// accept), so hiding it from them is unhelpful.
					//
					// Filter the slice in place: keep only rows where
					// Userid == myid, drop the rest. PromisedToYou is set as a
					// side effect for clients that don't read the slice.
					filtered := message.MessagePromises[:0]
					for _, p := range message.MessagePromises {
						if p.Userid == myid {
							filtered = append(filtered, p)
							message.PromisedToYou = true
						}
					}
					message.MessagePromises = filtered
				} else {
					message.Refchatids = refchatids
				}

				// Fetch item, location, and repost info in parallel.
				// Item is always public and lives in messages_items, so it
				// must be fetched regardless of locationid — a rejected
				// message can have a valid item but no locationid.
				// Location is for mods and the message owner only: prefer
				// the precise postcode from locationid, else fall back to
				// lat/lng (mirrors the mod path above).
				// Repost eligibility needs the message's group settings,
				// not location.
				var wgExtra sync.WaitGroup

				var loc *location.Location
				var i *item.Item
				var repostAt *time.Time
				var canRepost bool

				wgExtra.Add(1)
				go func() {
					defer wgExtra.Done()
					i = item.FetchForMessage(message.ID)
				}()

				if message.Locationid > 0 {
					wgExtra.Add(1)
					go func() {
						defer wgExtra.Done()
						loc = location.FetchSingle(message.Locationid)
					}()
				} else if message.Lat != 0 && message.Lng != 0 {
					wgExtra.Add(1)
					go func() {
						defer wgExtra.Done()
						l := location.ClosestPostcode(float32(message.Lat), float32(message.Lng))
						l.GroupsNear = location.ClosestGroups(float64(message.Lat), float64(message.Lng), location.NEARBY, 10)
						loc = &l
					}()
				}

				wgExtra.Add(1)
				go func() {
					defer wgExtra.Done()

					// Reposting is per-group: each group has its own arrival and
					// its own repost settings. Fetch the settings keyed by group
					// so we can pair each group's interval with that group's
					// arrival rather than collapsing onto the first group.
					type repostRow struct {
						Groupid uint64
						Reposts *string
					}
					var rows []repostRow
					db.Table("`groups`").
						Select("messages_groups.groupid AS groupid, JSON_EXTRACT(settings, '$.reposts') AS reposts").
						Joins("INNER JOIN messages_groups ON messages_groups.groupid = groups.id").
						Where("msgid = ? AND messages_groups.deleted = 0", message.ID).
						Scan(&rows)

					settingsByGroup := make(map[uint64]group.RepostSettings, len(rows))
					for _, r := range rows {
						// A group with no `reposts` setting gets V1's defaults.
						// Keeping the fallback in Go rather than in the SQL
						// matters: the old CASE WHEN emitted a PHP hash literal
						// that didn't parse as JSON, silently yielding interval 0
						// (always eligible).
						rs := group.DefaultRepostSettings()
						if r.Reposts != nil {
							if err := json.Unmarshal([]byte(*r.Reposts), &rs); err != nil {
								rs = group.DefaultRepostSettings()
							}
						}
						settingsByGroup[r.Groupid] = rs
					}

					// The message is repostable as soon as ANY group it's on has
					// passed that group's own repost interval, measured from that
					// group's own arrival. This matches V1 (Message::canRepost),
					// which ORs across groups, and matches how reposting actually
					// works: AutoRepostService bumps each group's arrival
					// independently, so eligibility is a per-group property.
					//
					// Requiring EVERY group to be eligible is wrong for rippled
					// posts. Each ripple expansion inserts a messages_groups row
					// with a fresh arrival, which under an AND rule pushes the
					// gate back every time the post reaches somewhere new, so a
					// widely-rippling post can stay un-repostable indefinitely
					// even though its home group passed the interval days ago.
					//
					// repostAt is the EARLIEST per-group repost time: the point at
					// which the message first becomes repostable, and the point at
					// which auto-repost next fires on some group.
					canRepost = false
					for _, mg := range message.MessageGroups {
						rs, ok := settingsByGroup[mg.Groupid]
						if !ok {
							rs = group.DefaultRepostSettings()
						}

						interval := rs.Wanted
						if message.Type == utils.OFFER {
							interval = rs.Offer
						}

						if interval >= 365 {
							// Some groups set a very high value as a way of
							// turning reposting off. That switches it off for
							// this group only; it doesn't block the others.
							continue
						}

						ra := mg.Arrival.AddDate(0, 0, interval)
						if repostAt == nil || ra.Before(*repostAt) {
							raCopy := ra
							repostAt = &raCopy
						}
						if ra.Before(time.Now()) {
							canRepost = true
						}
					}
				}()

				wgExtra.Wait()

				message.Item = i
				message.Repostat = repostAt
				message.Canrepost = canRepost

				// Precise location only for mods and message owner.
				// Other viewers get blurred lat/lng (handled elsewhere).
				if message.Fromuser == myid || isModForMessage(db, myid, message.ID) {
					message.Location = loc
				}

				// Bulk-offer catalogue: group the (now path-resolved) attachments by
				// item and attach per-item interest. The full per-user interest list
				// is only visible to the offerer or a moderator.
				canSeeInterest := message.Fromuser == myid || isGroupMod
				message.BulkItems = LoadBulkItems(db, message.ID, myid, canSeeInterest, message.MessageAttachments)
				message.Bulkcount = len(message.BulkItems)
				if message.Bulkcount > 0 {
					message.Bulkslots = LoadBulkSlots(db, message.ID)
					// Access instructions are private — only the offerer/mod sees them.
					if canSeeInterest {
						ai := loadAccessInstructions(db, message.ID)
						if ai != "" {
							message.Accessinstructions = &ai
						}
					}
				}

				mu.Lock()
				messages = append(messages, message)
				mu.Unlock()
			}
		}(id)
	}

	wgOuter.Wait()

	// Check worry words for moderators.
	// Any group-level mod sees worry words, not just system mods.
	if myid > 0 && len(messages) > 0 {
		var modCount int64
		db.Table("memberships").Where("userid = ? AND role IN (?, ?) AND collection = ?", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).Limit(1).Count(&modCount)
		if modCount > 0 || auth.IsAdminOrSupport(myid) {
			checkWorryWords(db, messages)
		}
	}

	// Reply-eligibility (#2): a post is view-only (replyeligible=false) when the viewer
	// cannot reply to it yet. Two reasons:
	//   - rippling-out: the post has rippled out (has a rippling_reach row) but not yet to
	//     the viewer's location; or
	//   - the viewer is banned from every group the post is on, so they must not interact
	//     with it (mirrors the digest ban exclusion, but for the location-based reach path).
	// Posts with no reach row and no ban stay eligible (the field is omitted). The queries
	// only run when there's something to find (a known location / an actual ban), keeping
	// them off the hot path for the common case.
	//
	// Activation is DATA-DRIVEN, mirroring the write-path reach gate in
	// chat.CreateChatMessage: the section runs when EITHER the RIPPLE_ENABLED master switch
	// is on, OR at least one fetched post is actually rippling (it has a rippling_reach row).
	// The per-group trial (RIPPLE_WITHIN_GROUPS) ripples posts WITHOUT the master switch, and
	// the write gate is not switch-gated either — so keying the read path on the master switch
	// alone left the UI offering a Reply button on trial posts that the write path then
	// rejected with 403 not_in_reach (Discourse: dejavu / msg 120820564). The EXISTS probe
	// runs only when the switch is off, so the fully-disabled case stays a single cheap
	// indexed lookup and otherwise matches pre-rippling exactly.
	active := rippleEnabled()
	if !active && myid > 0 && len(messages) > 0 {
		probeIDs := make([]uint64, 0, len(messages))
		for _, m := range messages {
			probeIDs = append(probeIDs, m.ID)
		}
		var anyReach int
		// rippling_reach may not exist until the reach engine (PR A) ships → treat as inactive.
		//
		// A bare SELECT EXISTS(...) with no
		// top-level FROM - one scalar expression. GORM's query callback always
		// registers a FROM clause, but Statement.Build only renders the clause
		// NAMES it is given, so restricting BuildClauses to {"SELECT"} emits the
		// SELECT alone and leaves the registered-but-unwalked FROM out.
		// Proven by the retired ormharness's bareexists_test.go (removed in
		// d22ba1d6c) and used by the other sites in this
		// category (amp.go, chatmessage.go's rippled-in probes).
		//
		// An earlier version of this conversion selected from a one-row derived
		// table instead ("FROM (SELECT 1) AS d") and recorded the added FROM as an
		// approved diff. It worked, but it was the worse answer: it changed the
		// executed SQL to avoid a limitation that turned out not to exist, and an
		// approved diff should record a divergence we could not avoid, not one we
		// chose. This renders byte-identically to the original, so the site needs
		// no approved diff at all.
		tx := db.Table("rippling_reach").
			Select("EXISTS(SELECT 1 FROM rippling_reach WHERE msgid IN ?)", probeIDs)
		tx.Statement.BuildClauses = []string{"SELECT"}
		_ = tx.Scan(&anyReach).Error
		active = anyReach == 1
	}

	if active && myid > 0 && len(messages) > 0 {
		ids := make([]uint64, 0, len(messages))
		for _, m := range messages {
			ids = append(ids, m.ID)
		}
		blockedSet := make(map[uint64]bool)

		// Reach-blocked: rippled out but not yet to the viewer's location.
		// LIMITATION (multi-location, deferred): this tests only the viewer's primary
		// location. A member with several saved locations (e.g. home + relatives) should
		// be reach-eligible if ANY of them is within the post's reach. Extending this to
		// iterate the member's full location set is future work.
		latlng := user.GetLatLng(myid)
		reachBlocked := ReachBlockedOrigins(myid, ids, float64(latlng.Lat), float64(latlng.Lng))

		// When the reach is expected to arrive at this viewer. Worked out here rather
		// than left to the client because it needs the post's BLURRED ripple origin
		// and its stored schedule, neither of which the feed ships - only the
		// resulting date crosses the wire.
		//
		// One budget-bounded routing search per blocked post (~40ms at a 30-minute
		// budget on the UK graph), run concurrently so a feed with several blocked
		// posts costs one search's latency rather than the sum. Only blocked posts
		// pay it, which is a small minority of any feed.
		coverage := make(map[uint64]rippling.Coverage, len(reachBlocked))
		if len(reachBlocked) > 0 {
			hazard := rippling.LoadHazardHours(db)

			// Per-request backstop on top of FetchDriveTime's process-wide cap, cache
			// and breaker: "blocked posts are a small minority of any feed" is false for
			// a viewer outside the reach of many rippling posts (2026-08-13: one viewer's
			// polls drove ~600 routing searches/min and a load-31 spike on the routing
			// host). Past the cap the remaining blocked posts simply carry no ETA — the
			// hold itself is still reported.
			const maxCoverageLookups = 24
			lookups := 0

			var covMu sync.Mutex
			var covWg sync.WaitGroup
			for msgid, origin := range reachBlocked {
				if !origin.Ok || origin.Arrival == nil || len(origin.Schedule) == 0 {
					continue
				}
				if lookups >= maxCoverageLookups {
					break
				}
				lookups++
				covWg.Add(1)
				go func(msgid uint64, origin ReachOrigin) {
					defer covWg.Done()

					// Search no further than this post's own widest budget: beyond it
					// the answer is "no tick ever covers you" however far they are, and
					// the search cost scales with the budget.
					budget := origin.Schedule[len(origin.Schedule)-1].DriveMin
					dt, ok := rippling.FetchDriveTime(
						origin.Lat, origin.Lng,
						float64(latlng.Lat), float64(latlng.Lng),
						budget,
					)
					if !ok {
						// Routing unavailable: no estimate, rather than a guess.
						return
					}

					cov, ok := rippling.CoverageAt(origin.Schedule, hazard, *origin.Arrival, dt.Minutes, dt.Reachable)
					if !ok {
						return
					}

					covMu.Lock()
					coverage[msgid] = cov
					covMu.Unlock()
				}(msgid, origin)
			}
			covWg.Wait()
		}

		for msgid := range reachBlocked {
			blockedSet[msgid] = true
		}
		if n := len(reachBlocked); n > 0 {
			// Q5 (§15): count reply-blocked-by-reach events (one per post the member
			// can't reply to yet). Best-effort — errors ignored so it never affects the
			// response.
			db.Table("rippling_event_metrics").Clauses(clause.OnConflict{
				DoUpdates: clause.Assignments(map[string]interface{}{
					"count": gorm.Expr("count + ?", n),
				}),
			}).Create(map[string]interface{}{
				"day":   gorm.Expr("CURDATE()"),
				"event": gorm.Expr("'reply_blocked'"),
				"count": n,
			})
		}

		// Banned-blocked: the viewer is banned from every group the post is on. Only run
		// the per-message check when the viewer actually has a ban somewhere.
		var banCount int64
		db.Table("users_banned").Where("userid = ?", myid).Count(&banCount)
		if banCount > 0 {
			var bannedBlocked []struct {
				Msgid uint64 `gorm:"column:msgid"`
			}
			db.Table("messages_groups mg").
				Select("mg.msgid").
				Joins("LEFT JOIN users_banned ub ON ub.groupid = mg.groupid AND ub.userid = ?", myid).
				Where("mg.msgid IN (?) AND mg.deleted = 0", ids).
				Group("mg.msgid").
				Having("COUNT(mg.groupid) = COUNT(ub.groupid)").
				Scan(&bannedBlocked)
			for _, b := range bannedBlocked {
				blockedSet[b.Msgid] = true

				// A banned viewer is not waiting for the ripple, they are not
				// getting through at all. Telling them when it would arrive would
				// be a promise we have no intention of keeping, so drop any
				// estimate the reach check produced for the same post.
				delete(coverage, b.Msgid)
			}
		}

		if len(blockedSet) > 0 {
			notEligible := false
			for ix := range messages {
				if blockedSet[messages[ix].ID] {
					messages[ix].ReplyEligible = &notEligible

					// Only the reach reason carries an arrival. A ban also lands in
					// blockedSet and has no coverage entry, so it stays nil.
					if cov, found := coverage[messages[ix].ID]; found {
						at := cov.At
						fully := cov.Covered
						messages[ix].ReachesYouAt = &at
						messages[ix].ReachesYouFully = &fully
					}
				}
			}
		}
	}

	return messages
}

// checkWorryWords checks message subjects and textbodies against global concern
// keywords (fuzzy match mode).  Matches are stored in Message.Worry.
func checkWorryWords(db *gorm.DB, messages []Message) {
	var globalWords []WorryWord
	db.Table("concern_keywords").
		Select("id, keyword, CASE category " +
			"WHEN 'substance_regulated' THEN 'Regulated' " +
			"WHEN 'substance_reportable' THEN 'Reportable' " +
			"WHEN 'substance_medicine' THEN 'Medicine' " +
			"WHEN 'review' THEN 'Review' " +
			"WHEN 'allowed' THEN 'Allowed' " +
			"ELSE 'Review' END AS type").
		Where("match_mode = 'fuzzy' AND scope = 'global'").
		Scan(&globalWords)

	// Collect unique group IDs from all messages so we can load group-specific
	// worry words in one pass.
	groupIDs := map[uint64]bool{}
	for _, msg := range messages {
		for _, mg := range msg.MessageGroups {
			groupIDs[mg.Groupid] = true
		}
	}

	// Load group-specific worry words from groups.settings->'$.spammers.worrywords'.
	groupWords := map[uint64][]WorryWord{}
	for gid := range groupIDs {
		var raw *string
		db.Table("groups").Select("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords'))").Where("id = ?", gid).Scan(&raw)
		if raw != nil && *raw != "" && *raw != "null" {
			parts := strings.Split(*raw, ",")
			for _, p := range parts {
				w := strings.TrimSpace(p)
				if w != "" {
					groupWords[gid] = append(groupWords[gid], WorryWord{
						Keyword: strings.ToLower(w),
						Type:    "Review",
					})
				}
			}
		}
	}

	// Build the combined word list per message (global + group-specific).
	for i, msg := range messages {
		words := make([]WorryWord, len(globalWords))
		copy(words, globalWords)
		for _, mg := range msg.MessageGroups {
			if gw, ok := groupWords[mg.Groupid]; ok {
				words = append(words, gw...)
			}
		}

		matches := matchWorryWords(msg.Subject, msg.Textbody, words)
		if len(matches) > 0 {
			messages[i].Worry = matches
		}
	}
}

// fuzzyLevenshteinMinKwLen mirrors ContentCheckService::FUZZY_LEVENSHTEIN_MIN_KW_LEN:
// below this length, edit-distance fuzzy matching is skipped and only exact /
// inflectional matches count, to avoid short-word false positives.
const fuzzyLevenshteinMinKwLen = 8

// inflectionVariants mirrors PHP's ContentCheckService::inflectionVariants:
// the plural/-ing/-ed forms accepted as equivalent to kwLower, without
// admitting arbitrary 1-edit neighbours.
func inflectionVariants(kwLower string) []string {
	variants := []string{kwLower + "s", kwLower + "es"}
	l := len(kwLower)

	if l > 1 && strings.HasSuffix(kwLower, "y") {
		variants = append(variants, kwLower[:l-1]+"ies")
	}

	if strings.HasSuffix(kwLower, "e") {
		// English: drop the trailing 'e' before -ing; add only 'd' for -ed.
		variants = append(variants, kwLower+"d", kwLower[:l-1]+"ing")
	} else {
		variants = append(variants, kwLower+"ed", kwLower+"ing")
		// CVC rule: double the final consonant before -ed/-ing ("swap" -> "swapped").
		if l >= 3 {
			last := kwLower[l-1]
			pen := kwLower[l-2]
			if !strings.ContainsRune("aeiou", rune(last)) && strings.ContainsRune("aeiou", rune(pen)) {
				variants = append(variants, kwLower+string(last)+"ed", kwLower+string(last)+"ing")
			}
		}
	}

	return variants
}

// matchesFuzzyToken reports whether token equals kw, one of its inflectional
// variants, or (for keywords at least fuzzyLevenshteinMinKwLen long) is within
// Damerau-Levenshtein distance 1 of kw with a comparable length. Mirrors
// ContentCheckService::matchesFuzzy's per-token branch so Go and PHP flag the
// same misspellings from the same match_mode='fuzzy' concern_keywords rows
// (Discourse 9939/44). Both token and kw must already be lower-cased.
func matchesFuzzyToken(token, kw string) bool {
	if token == kw {
		return true
	}

	for _, v := range inflectionVariants(kw) {
		if token == v {
			return true
		}
	}

	kwLen := len(kw)
	if kwLen < fuzzyLevenshteinMinKwLen {
		return false
	}

	tokLen := len(token)
	ratio := float64(tokLen) / float64(kwLen)
	if ratio < 0.75 || ratio > 1.25 {
		return false
	}

	if user.DamerauLevenshtein(token, kw, 1) > 1 {
		return false
	}

	// Reject initial-consonant swaps: "hangers" vs "bangers" differ only at
	// position 0 and are a different word, not a typo.
	minLen := tokLen
	if kwLen < minLen {
		minLen = kwLen
	}
	for i := 0; i < minLen; i++ {
		if token[i] != kw[i] {
			return i != 0
		}
	}

	return true
}

// matchWorryWords scans subject and textbody for worry word matches.
// checks for pound sign, removes Allowed words before scanning,
// uses case-insensitive contains for phrases (keywords with spaces), and
// fuzzy matching (exact, inflectional, or Damerau-Levenshtein distance 1 for
// longer words) for single words, mirroring PHP's matchesFuzzy.
func matchWorryWords(subject, textbody string, words []WorryWord) []WorryMatch {
	var matches []WorryMatch
	found := map[string]bool{}

	subjectLower := strings.ToLower(subject)
	textbodyLower := strings.ToLower(textbody)

	for _, scan := range []string{subjectLower, textbodyLower} {
		// Check for pound sign.
		if strings.Contains(scan, "\u00a3") {
			if !found["\u00a3"] {
				matches = append(matches, WorryMatch{
					Word:      "\u00a3",
					Worryword: WorryWord{Keyword: "\u00a3", Type: "Review"},
				})
				found["\u00a3"] = true
			}
		}

		// Remove Allowed words before checking.
		cleaned := scan
		for _, w := range words {
			if w.Type == "Allowed" {
				cleaned = removeWordBoundary(cleaned, strings.ToLower(w.Keyword))
			}
		}

		// Check phrases (keywords containing a space) via case-insensitive contains.
		for _, w := range words {
			kw := strings.ToLower(w.Keyword)
			if w.Type == "Allowed" || !strings.Contains(kw, " ") {
				continue
			}
			if found[kw] {
				continue
			}
			if strings.Contains(subjectLower, kw) || strings.Contains(textbodyLower, kw) {
				matches = append(matches, WorryMatch{
					Word:      w.Keyword,
					Worryword: WorryWord{Keyword: w.Keyword, Type: w.Type},
				})
				found[kw] = true
			}
		}

		// Split on word boundaries and check individual words.
		tokens := splitOnWordBoundary(cleaned)
		for _, token := range tokens {
			token = strings.TrimSpace(token)
			if token == "" {
				continue
			}
			for _, w := range words {
				kw := strings.ToLower(w.Keyword)
				if w.Type == "Allowed" || found[kw] || len(kw) == 0 {
					continue
				}
				if matchesFuzzyToken(token, kw) {
					matches = append(matches, WorryMatch{
						Word:      w.Keyword,
						Worryword: WorryWord{Keyword: w.Keyword, Type: w.Type},
					})
					found[kw] = true
				}
			}
		}
	}

	return matches
}

// removeWordBoundary removes all occurrences of a word (case-insensitive,
// word-boundary aware) from the text.
func removeWordBoundary(text, word string) string {
	re, err := regexp.Compile(`(?i)\b` + regexp.QuoteMeta(word) + `\b`)
	if err != nil {
		return text
	}
	return re.ReplaceAllString(text, "")
}

// splitOnWordBoundary splits text on non-alphanumeric characters (matching
// PHP's preg_split("/\b/", ...)).
func splitOnWordBoundary(text string) []string {
	re := regexp.MustCompile(`[^a-zA-Z0-9]+`)
	return re.Split(text, -1)
}

func GetMessagesForUser(c *fiber.Ctx) error {
	db := database.DBConn

	myid := user.WhoAmI(c)

	if c.Params("id") != "" {
		id, err1 := strconv.ParseUint(c.Params("id"), 10, 64)
		active, err2 := strconv.ParseBool(c.Query("active", "false"))

		if err1 == nil && err2 == nil {
			msgs := []MessageSummary{}

			selectCols := "messages.lat, messages.lng, messages.id, messages_groups.groupid, messages_groups.collection, messages.type, messages_groups.arrival, messages.date, " +
				"messages_spatial.id AS spatialid, " +
				"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id) AS hasoutcome, " +
				"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id AND outcome IN (?, ?)) AS successful, " +
				"EXISTS(SELECT id FROM messages_promises WHERE messages_promises.msgid = messages.id) AS promised, "

			whereTail := "fromuser = ? AND messages.deleted IS NULL AND users.deleted IS NULL AND messages_groups.deleted = 0 AND " +
				// Rippling-out adds a messages_groups row (rippled_in=1) per group a post ripples
				// into, so without this a rippled post appears once PER GROUP in My Posts. Restrict
				// to the origin membership (rippled_in=0) so each of the user's own posts shows
				// exactly once; the rippled-in copies are system propagation, not separate posts.
				"messages_groups.rippled_in = 0 AND messages.type IN (?, ?)"

			if myid > 0 && id == myid {
				// Own messages are always treated as seen.
				//
				// `active` is the only toggle - 2 possible rendered forms, both
				// proven by the retired ormharness (shapes.json /
				// TestTier3Shapes_2de07c2af78b, removed in d22ba1d6c).
				tx := db.Table("messages").
					Select(selectCols+"0 AS unseen", utils.TAKEN, utils.RECEIVED).
					Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
					Joins("INNER JOIN users ON users.id = messages.fromuser").
					Joins("LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id").
					Where(whereTail, id, utils.OFFER, utils.WANTED)
				if active {
					// The original spliced these as literal quoted text, not
					// binds ("... IN ('"+COLLECTION_PENDING+"', '"+COLLECTION_REJECTED+"'))"),
					// so the conversion matches that exactly here.
					tx = tx.Having("((hasoutcome = 0 AND spatialid IS NOT NULL) OR messages_groups.collection IN ('" +
						utils.COLLECTION_PENDING + "', '" + utils.COLLECTION_REJECTED + "'))")
				}
				tx.Order("unseen DESC, messages_groups.arrival DESC").Scan(&msgs)
			} else {
				// Another user - we are only interested in active and public messages.
				//
				// Same
				// `active` toggle as 2de07c2af78b above (the other-user twin) -
				// 2 possible rendered forms, both proven by the retired
				// ormharness (shapes.json / TestTier3Shapes_bca1186d1ea4,
				// removed in d22ba1d6c).
				tx := db.Table("messages").
					Select(selectCols+"NOT EXISTS(SELECT msgid FROM messages_likes WHERE messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?) AS unseen",
						utils.TAKEN, utils.RECEIVED, myid, utils.MESSAGE_LIKES_VIEW).
					Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
					Joins("INNER JOIN users ON users.id = messages.fromuser")
				if active {
					// For our own user, we might have messages which are not public yet because they're pending,
					// and we still want to show those.
					tx = tx.Joins("INNER JOIN messages_spatial ON messages_spatial.msgid = messages.id")
				} else {
					tx = tx.Joins("LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id")
				}
				tx = tx.Where(whereTail, id, utils.OFFER, utils.WANTED)
				if active {
					tx = tx.Having("hasoutcome = 0")
				}
				tx.Order("unseen DESC, messages_groups.arrival DESC").Scan(&msgs)
			}

			if active {
				msgs = filterExpiredMessages(db, msgs)
			} else {
				markExpiredMessages(db, msgs)
			}

			for ix, r := range msgs {
				// Protect anonymity of poster a bit.
				msgs[ix].Lat, msgs[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
			}

			return c.JSON(msgs)
		}
	}

	return fiber.NewError(fiber.StatusNotFound, "User not found")
}

const (
	defaultMaxAgeToShow = 90
	defaultRepostOffer  = 3
	defaultRepostWanted = 14
	defaultRepostMax    = 10
	ongoingChatWindow   = 6 * 24 * time.Hour
)

type groupReposts struct {
	Offer  int `json:"offer"`
	Wanted int `json:"wanted"`
	Max    int `json:"max"`
}

type groupSettings struct {
	MaxAgeToShow *int          `json:"maxagetoshow"`
	Reposts      *groupReposts `json:"reposts"`
}

// applyExpiry computes per-group expiry and marks expired messages.
// Messages past their expiry age are kept alive only if there is an
// ongoing chat within 6 days. Returns the indices of expired messages.
func applyExpiry(db *gorm.DB, msgs []MessageSummary) []int {
	if len(msgs) == 0 {
		return nil
	}

	// Fetch group settings in one query.
	groupIDs := map[uint64]bool{}
	for _, m := range msgs {
		if !m.Hasoutcome {
			groupIDs[m.Groupid] = true
		}
	}

	type groupRow struct {
		ID       uint64  `gorm:"column:id"`
		Settings *string `gorm:"column:settings"`
	}
	ids := make([]uint64, 0, len(groupIDs))
	for id := range groupIDs {
		ids = append(ids, id)
	}

	settingsMap := map[uint64]groupSettings{}
	if len(ids) > 0 {
		var groups []groupRow
		// Identical sibling of
		// 340a0eccf392 above in computeExpiresat; converted together
		// (ratchet gate h).
		db.Table("groups").Select("id, settings").Where("id IN ?", ids).Scan(&groups)

		for _, g := range groups {
			var s groupSettings
			if g.Settings != nil {
				json.Unmarshal([]byte(*g.Settings), &s)
			}
			settingsMap[g.ID] = s
		}
	}

	// First pass: identify candidates past expiry age.
	now := time.Now()
	var candidateIDs []uint64
	candidateIndices := map[uint64][]int{}

	for i := range msgs {
		m := &msgs[i]
		if m.Hasoutcome {
			continue
		}

		s := settingsMap[m.Groupid]

		maxAgeToShow := defaultMaxAgeToShow
		if s.MaxAgeToShow != nil {
			maxAgeToShow = *s.MaxAgeToShow
		}

		repostDays := defaultRepostOffer
		repostMax := defaultRepostMax
		if s.Reposts != nil {
			if m.Type == utils.OFFER {
				repostDays = s.Reposts.Offer
			} else {
				repostDays = s.Reposts.Wanted
			}
			repostMax = s.Reposts.Max
		} else if m.Type == utils.WANTED {
			repostDays = defaultRepostWanted
		}

		maxReposts := repostDays * (repostMax + 1)
		expireTime := maxAgeToShow
		if maxReposts > expireTime {
			expireTime = maxReposts
		}

		// Age the post against the same expireTime V1 uses (maxagetoshow /
		// EXPIRE_TIME = 90 days, or the repost window). For Rejected posts age by
		// the ORIGINAL date (messages.date) rather than arrival: a rejected post's
		// arrival can be recent while the post itself is years old, which would
		// otherwise keep a long-dead rejected message in the member's active posts
		// (Discourse topic 9481/561). V1 capped a member's own posts by age too.
		ageBasis := m.Arrival
		if m.Collection == utils.COLLECTION_REJECTED && !m.Date.IsZero() {
			ageBasis = m.Date
		}
		daysAgo := int(now.Sub(ageBasis).Hours() / 24)
		if daysAgo > expireTime {
			candidateIDs = append(candidateIDs, m.ID)
			candidateIndices[m.ID] = append(candidateIndices[m.ID], i)
		}
	}

	if len(candidateIDs) == 0 {
		return nil
	}

	// Batch query: latest chat activity for all candidate messages.
	//
	// Recency is measured by the latest chat message that actually REFERENCES the
	// post (chat_messages.date where refmsgid = the post), NOT chat_rooms.latestmessage.
	// Freegle user-to-user rooms are one long-lived room per pair of people, so the
	// room's overall latest message reflects any conversation between them. Using it
	// pinned years-old posts in the member's active My Posts whenever the two users
	// had chatted about anything else recently (Discourse 9481/583): the reported
	// posts' own chat reference was from 2020, but the shared room had a message 3
	// days ago, so the room-level check kept them "active" indefinitely. The per-post
	// reference date is the real "is this post still being discussed" signal.
	type chatLatest struct {
		Refmsgid uint64     `gorm:"column:refmsgid"`
		Latest   *time.Time `gorm:"column:latest"`
	}
	var chatResults []chatLatest
	db.Table("chat_messages").Select("refmsgid, MAX(date) AS latest").
		Where("refmsgid IN ?", candidateIDs).Group("refmsgid").Scan(&chatResults)

	recentChat := map[uint64]bool{}
	for _, cr := range chatResults {
		if cr.Latest != nil && !cr.Latest.IsZero() && now.Sub(*cr.Latest) < ongoingChatWindow {
			recentChat[cr.Refmsgid] = true
		}
	}

	// Mark expired messages.
	var expired []int
	for _, msgID := range candidateIDs {
		if recentChat[msgID] {
			continue
		}
		for _, idx := range candidateIndices[msgID] {
			msgs[idx].Hasoutcome = true
			expired = append(expired, idx)
		}
	}

	return expired
}

// filterExpiredMessages returns only non-expired messages (for active=true).
func filterExpiredMessages(db *gorm.DB, msgs []MessageSummary) []MessageSummary {
	applyExpiry(db, msgs)

	result := make([]MessageSummary, 0, len(msgs))
	for _, m := range msgs {
		if !m.Hasoutcome {
			result = append(result, m)
		}
	}
	return result
}

// FilterExpiredSummaries applies the same age-based expiry the My Posts endpoint
// uses (filterExpiredMessages/applyExpiry) and returns only the still-active
// summaries. The browse feeds' "own posts" arms — which query the messages table
// directly and so bypass the messages_spatial pruning that removes expired posts
// for everyone else — use this so a poster's own post drops off the feed at the
// same moment it drops off My Posts, instead of lingering (within the 90-day
// window) until the daily batch inserts an outcome row.
func FilterExpiredSummaries(db *gorm.DB, msgs []MessageSummary) []MessageSummary {
	return filterExpiredMessages(db, msgs)
}

// markExpiredMessages sets Hasoutcome=true on expired messages in-place (for active=false).
// Also marks messages without spatial entries (and not Pending/Rejected) as having outcomes,
// matching the active=true HAVING clause so navbar count and page count stay consistent.
func markExpiredMessages(db *gorm.DB, msgs []MessageSummary) {
	applyExpiry(db, msgs)

	for i := range msgs {
		m := &msgs[i]
		if !m.Hasoutcome && m.SpatialID == nil &&
			m.Collection != utils.COLLECTION_PENDING &&
			m.Collection != utils.COLLECTION_REJECTED {
			m.Hasoutcome = true
		}
	}
}

func Search(c *fiber.Ctx) error {
	db := database.DBConn
	term, _ := url.QueryUnescape(c.Params("term"))
	term = strings.TrimSpace(term)
	myid := user.WhoAmI(c)

	msgtype := c.Query("messagetype", "All")

	groupidss := strings.Split(c.Query("groupids", ""), ",")
	var groupids []uint64

	if len(groupidss) > 0 {
		for _, g := range groupidss {
			gid, err := strconv.ParseUint(g, 10, 64)
			if err == nil {
				groupids = append(groupids, gid)
			}
		}
	}

	// If groupids contains 0 ("All my communities" in ModTools), handle based on role:
	// - Admin/Support: clear groupids so the search covers all groups (no filter).
	// - Everyone else: replace with the user's actual memberships so they only see
	//   messages from groups they belong to.
	hasZero := false
	for _, gid := range groupids {
		if gid == 0 {
			hasZero = true
			break
		}
	}
	if hasZero && myid > 0 {
		if auth.IsAdminOrSupport(myid) {
			groupids = nil
		} else {
			var userGroupIDs []uint64
			db.Table("memberships").Select("groupid").Where("userid = ? AND collection = ?", myid, utils.COLLECTION_APPROVED).Scan(&userGroupIDs)
			if len(userGroupIDs) > 0 {
				groupids = userGroupIDs
			}
		}
	}

	// We want to record the search history, but we can do that in parallel to the actual search.
	// Word popularity is handled when the message is inserted into the index.
	var wg sync.WaitGroup
	wg.Add(1)

	go func() {
		defer wg.Done()

		if myid > 0 {
			db.Table("search_history").Create(map[string]interface{}{
				"userid": myid, "term": term, "locationid": nil, "groups": c.Query("groupids", ""),
			})

			db.Table("users_searches").Create(map[string]interface{}{
				"userid": myid, "term": term, "locationid": nil,
			})
		} else {
			db.Table("search_history").Create(map[string]interface{}{
				"userid": gorm.Expr("NULL"), "term": term, "locationid": nil, "groups": c.Query("groupids", ""),
			})
		}
	}()

	// A purely-numeric search term (optionally "#"-prefixed) is a message id:
	// return that exact message rather than word-matching the digits against
	// message subjects/text. Reported on Discourse (topic 9585): searching
	// "#120975040" surfaced unrelated posts whose title merely contained those
	// digits. strconv.ParseUint succeeds only for an all-digits term, so ordinary
	// searches fall through to the word search below. Access stays restricted by
	// groupFilter, so a mod only gets the message if it is in their groups.
	if idStr := strings.TrimPrefix(term, "#"); idStr != "" {
		if msgid, err := strconv.ParseUint(idStr, 10, 64); err == nil {
			byID := SearchByMsgID(db, msgid, groupids)
			if len(byID) > 0 {
				wg.Wait()
				return c.JSON(byID)
			}
		}
	}

	nelat, _ := strconv.ParseFloat(c.Query("nelat", "0"), 32)
	nelng, _ := strconv.ParseFloat(c.Query("nelng", "0"), 32)
	swlat, _ := strconv.ParseFloat(c.Query("swlat", "0"), 32)
	swlng, _ := strconv.ParseFloat(c.Query("swlng", "0"), 32)

	// --- Browse-scoped search (Discourse group-listings 9933). When the client passes
	// browse=1, the universe searched must be EXACTLY the set of posts the member would see
	// scrolling to the bottom of their browse feed for their current filters:
	//   Nearby             -> posts whose rippling reach covers the member (+ their own posts)
	//   All my communities -> their member groups (the client sends the groupids)
	//   A specific group   -> that group (the client sends the groupid)
	// plus their "How far away" slider cap and "Sort by" order. Previously search applied no
	// location scope at all, so the vector store returned nationwide semantic matches while
	// genuinely-in-feed posts were crowded out. Without the flag (ModTools, explore pages,
	// map-viewport searches) behaviour is unchanged.
	const browseDistanceUnlimited = 9007199254740991.0 // Number.MAX_SAFE_INTEGER: slider at max ("no limit")

	browseScoped := c.Query("browse", "") == "1" && myid > 0
	var memberLat, memberLng float64
	browseMaxMiles := float64(browseDistanceUnlimited)
	var browseSort string

	if browseScoped {
		if ll := user.GetLatLng(myid); ll.Lat != 0 || ll.Lng != 0 {
			memberLat, memberLng = float64(ll.Lat), float64(ll.Lng)
		}
		// Same two-key resolution as isochrone.resolveMaxDistance: the member's own
		// choice, else their density band default (browseReachMaxDistance, written by
		// browse:backfill-max-distance). Browse-scoped search shares the feed's universe
		// (Discourse 9933), so missing the fallback here would surface posts in search
		// that the feed itself hides.
		var rawDist, rawDefaultDist, rawSort string
		db.Table("users").
			Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseReachMaxDistance')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseSort')), '')").
			Where("id = ?", myid).
			Row().Scan(&rawDist, &rawDefaultDist, &rawSort)
		for _, raw := range []string{rawDist, rawDefaultDist} {
			if raw == "" {
				continue
			}
			// An unparseable value is treated as no value and falls through to the
			// next key, matching isochrone.resolveMaxDistance and the Laravel
			// DistancePreferenceFilter - all three must agree or the feed, its badge
			// and search would disagree about the same member.
			if v, err := strconv.ParseFloat(raw, 64); err == nil && v > 0 {
				browseMaxMiles = v

				break
			}
		}
		browseSort = rawSort
	}

	// Nearby view (browse-scoped, no group selected): compute the feed's msgid universe up
	// front and give it to BOTH search arms, so their internal LIMIT/top-K cuts happen WITHIN
	// the feed universe. Filtering afterwards does not work: for a common term the capped
	// candidate sets fill up with out-of-feed posts first (measured live: only 4 of the 16
	// in-feed "table" posts survived to the candidate pool).
	var universeIDs []uint64
	var universeSet map[uint64]bool

	if browseScoped && len(groupids) == 0 && (memberLat != 0 || memberLng != 0) {
		universeIDs = nearbyFeedMsgIDs(db, myid, memberLat, memberLng)

		if len(universeIDs) == 0 {
			// An empty feed means there is nothing to search.
			wg.Wait()
			return c.JSON([]SearchResult{})
		}

		universeSet = make(map[uint64]bool, len(universeIDs))
		for _, id := range universeIDs {
			universeSet[id] = true
		}
	}

	// applyBrowseFilters completes feed parity for browse-scoped searches: stamp each result's
	// distance from the member, apply the "How far away" slider cap, and order by "Sort by".
	// (The universe itself is enforced at candidate selection above.) Applied at every return.
	applyBrowseFilters := func(rs []SearchResult) []SearchResult {
		if !browseScoped || (memberLat == 0 && memberLng == 0) {
			return rs
		}
		for i := range rs {
			rs[i].Distance = utils.Haversine(memberLat, memberLng, rs[i].Lat, rs[i].Lng)
		}
		if browseMaxMiles < browseDistanceUnlimited {
			kept := rs[:0]
			for _, r := range rs {
				if r.Distance <= browseMaxMiles {
					kept = append(kept, r)
				}
			}
			rs = kept
		}
		switch browseSort {
		case "Nearby": // the client's "Closest" option
			sort.SliceStable(rs, func(i, j int) bool { return rs[i].Distance < rs[j].Distance })
		case "Newest":
			// Sort by ORIGINAL post time (messages.arrival), not SearchResult.Arrival, which is
			// the ripple-bumped messages_spatial arrival - ordering by that floats days-old posts
			// to the top whenever their reach grows (same trap as Discourse 9844 on the feed).
			if len(rs) > 0 {
				ids := make([]uint64, 0, len(rs))
				for _, r := range rs {
					ids = append(ids, r.Msgid)
				}
				var rows []struct {
					ID      uint64    `gorm:"column:id"`
					Arrival time.Time `gorm:"column:arrival"`
				}
				db.Table("messages").Select("id, arrival").Where("id IN ?", ids).Scan(&rows)
				posted := make(map[uint64]time.Time, len(rows))
				for _, row := range rows {
					posted[row.ID] = row.Arrival
				}
				sort.SliceStable(rs, func(i, j int) bool { return posted[rs[i].Msgid].After(posted[rs[j].Msgid]) })
			}
		}
		return rs
	}

	var res []SearchResult

	if len(term) > 0 {
		// Pure vector search. VectorSearch combines semantic (cosine) ranking with
		// an in-memory lexical guarantee — a post whose subject literally contains
		// the query words is always returned, even below the cosine threshold — so
		// it fully replaces the retired keyword index and its typo/soundex cascade.
		// The store is loaded synchronously at startup; if it somehow has no
		// entries we return nothing rather than fall back to an index that no
		// longer exists. Results are already blurred and deduplicated by
		// VectorSearch. Search is spatial-reach based (store group + bbox filters);
		// a post is found in its spatial area, not on every group it was cross-
		// posted/rippled into.
		if embedding.Global.Count() > 0 {
			vectorResults, vectorStats, vectorErr := VectorSearch(term, SEARCH_LIMIT, groupids, universeSet, msgtype,
				float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			logVectorSearch(term, groupids, msgtype, myid, len(vectorResults), vectorErr != nil, vectorStats)
			if vectorErr != nil {
				fmt.Printf("Vector search failed: %v\n", vectorErr)
			} else {
				res = vectorResults
			}
		}
	}

	// Return results where Msgid is not 0, deduplicated by msgid as a safety net.
	// VectorSearch already dedups, but keep this so any future change can't leak a
	// duplicate; we keep the first (highest-ranked) occurrence.
	filtered := []SearchResult{}
	seen := make(map[uint64]bool, len(res))

	for _, r := range res {
		if r.Msgid != 0 && !seen[r.Msgid] {
			seen[r.Msgid] = true
			filtered = append(filtered, r)
		}
	}

	wg.Wait()

	return c.JSON(applyBrowseFilters(filtered))
}

// Activity represents a recent activity in groups
// swagger:model Activity
type Activity struct {
	ID      uint64          `json:"id"`
	Message ActivityMessage `json:"message"`
	Group   ActivityGroup   `json:"group"`
}

// ActivityMessage represents a message in an activity
// swagger:model ActivityMessage
type ActivityMessage struct {
	ID      uint64    `json:"id"`
	Subject string    `json:"subject"`
	Arrival time.Time `json:"arrival"`
	Delta   int64     `json:"delta"`
}

// ActivityGroup represents a group in an activity
// swagger:model ActivityGroup
type ActivityGroup struct {
	ID          uint64  `json:"id"`
	Nameshort   string  `json:"nameshort"`
	Namefull    string  `json:"-"`
	Namedisplay string  `json:"namedisplay"`
	Lat         float32 `json:"lat"`
	Lng         float32 `json:"lng"`
}

type ActivityQuery struct {
	Id        uint64
	Subject   string
	Arrival   time.Time
	Delta     int64
	Groupid   uint64
	Nameshort string
	Namefull  string
	Lat       float32
	Lng       float32
}

func GetRecentActivity(c *fiber.Ctx) error {
	var activity []ActivityQuery

	db := database.DBConn

	start := time.Now().Add(-time.Hour * 24).Format("2006-01-02 15:04:05")

	db.Table("messages").
		Select("messages.id, messages_groups.arrival, messages_groups.groupid, messages.subject, "+
			"groups.nameshort, groups.namefull, groups.lat, groups.lng").
		Joins("INNER JOIN messages_groups ON messages.id = messages_groups.msgid").
		Joins("INNER JOIN `groups` ON messages_groups.groupid = groups.id").
		Joins("INNER JOIN users ON messages.fromuser = users.id").
		Where("messages_groups.arrival > ? AND collection = ?", start, utils.COLLECTION_APPROVED).
		Order("messages_groups.arrival ASC").
		Limit(100).
		Scan(&activity)

	last := int64(0)

	var ret []Activity

	for _, r := range activity {
		namedisplay := r.Nameshort

		if len(r.Namefull) > 0 {
			namedisplay = r.Namefull
		}

		arrival := r.Arrival.Unix()
		delta := int64(0)

		if last != 0 {
			delta = arrival - last
		}

		last = arrival

		ret = append(ret, Activity{
			ID: r.Id,
			Message: ActivityMessage{
				ID:      r.Id,
				Subject: r.Subject,
				Arrival: r.Arrival,
				Delta:   delta,
			},
			Group: ActivityGroup{
				ID:          r.Groupid,
				Lat:         r.Lat,
				Lng:         r.Lng,
				Nameshort:   r.Nameshort,
				Namefull:    r.Namefull,
				Namedisplay: namedisplay,
			},
		})
	}

	return c.JSON(ret)
}

// =============================================================================
// Merged from message/message_mod.go
// =============================================================================

// logModAction inserts a mod log entry for message actions (approve, reject, reply, etc).
func logModAction(db *gorm.DB, logType string, subtype string, groupid uint64, userid uint64, byuser uint64, msgid uint64, stdmsgid uint64, text string) {
	// `user` is a reserved word in MySQL — backtick to match V1's Log::log().
	if stdmsgid > 0 {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": logType, "subtype": subtype, "groupid": groupid,
			"user": userid, "byuser": byuser, "msgid": msgid, "stdmsgid": stdmsgid, "text": text,
		})
	} else {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": logType, "subtype": subtype, "groupid": groupid,
			"user": userid, "byuser": byuser, "msgid": msgid, "text": text,
		})
	}
}

// logMessageReceived writes the V1-parity Message/Received log entry:
// byuser is NULL (a Received log is a system event, not a mod action) and
// text is the RFC822 Message-Id header (V1 Message::submit() records
// $this->messageid). Only logs when the INSERT actually modifies a row
// (which also means we don't emit duplicate Received logs if the caller
// re-runs — the unique-by-msgid check is deferred to the caller context).
func logMessageReceived(db *gorm.DB, groupid uint64, fromuser uint64, msgid uint64) {
	var messageid string
	db.Table("messages").Select("COALESCE(messageid, '')").Where("id = ?", msgid).Scan(&messageid)
	result := db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"), "type": flog.LOG_TYPE_MESSAGE, "subtype": flog.LOG_SUBTYPE_RECEIVED,
		"groupid": groupid, "user": fromuser, "byuser": gorm.Expr("NULL"), "msgid": msgid, "text": messageid,
	})
	if result.Error != nil {
		log.Printf("Failed to log Message/Received for msg %d group %d: %v", msgid, groupid, result.Error)
	}
}

// getPrimaryGroupForMessage returns one groupid for a message.
//
// Deprecated: use a request-supplied groupid when available. Multi-group
// messages have N groups; this function picks one arbitrarily. For per-group
// moderation actions (hold/release/spam/delete) always use the groupid the
// mod is acting on. Remaining legitimate callers are owner-initiated global
// paths (draft conversion, JoinAndPost), mod context bootstrap, and submit
// subject reconstruction — contexts where no explicit group is available.
func getPrimaryGroupForMessage(db *gorm.DB, msgid uint64) uint64 {
	var groupid uint64
	db.Table("messages_groups").Select("groupid").Where("msgid = ?", msgid).Limit(1).Scan(&groupid)
	return groupid
}

// getAllGroupsForMessage returns all groupids for a message.
// Returns groups regardless of deleted state, so mods can still moderate and reject
// messages even after the poster has deleted them.
func getAllGroupsForMessage(db *gorm.DB, msgid uint64) []uint64 {
	var groupids []uint64
	db.Table("messages_groups").Select("groupid").Where("msgid = ?", msgid).Scan(&groupids)
	return groupids
}

// constructLocationString builds a location string for a message's subject,
// using the area name + vague postcode format.
// The vague postcode is the outward code only (e.g., "CB22" from "CB22 3AA").
func constructLocationString(db *gorm.DB, msgid uint64) string {
	type locInfo struct {
		Name   string
		Type   string
		Areaid uint64
	}
	var loc locInfo
	db.Table("locations l").
		Select("l.name, l.type, COALESCE(l.areaid, 0) as areaid").
		Joins("INNER JOIN messages m ON m.locationid = l.id").
		Where("m.id = ?", msgid).
		Scan(&loc)

	if loc.Name == "" {
		return ""
	}

	if loc.Type == "Postcode" && loc.Areaid > 0 {
		// Get the area name.
		var areaName string
		db.Table("locations").Select("name").Where("id = ?", loc.Areaid).Scan(&areaName)

		// Vague postcode: take only the outward code (before the space).
		vaguePC := loc.Name
		if idx := strings.Index(vaguePC, " "); idx > 0 {
			vaguePC = vaguePC[:idx]
		}

		return areaName + " " + vaguePC
	}

	// Not a postcode with area — use the location name as-is,
	// but ensure vague (strip inward code if it looks like a postcode).
	if loc.Type == "Postcode" {
		if idx := strings.Index(loc.Name, " "); idx > 0 {
			return loc.Name[:idx]
		}
	}
	return loc.Name
}

// getGroupKeyword returns the keyword for a message type from the group's settings.
// Falls back to uppercase type (the V1 default).
func getGroupKeyword(db *gorm.DB, groupid uint64, msgType string) string {
	if groupid > 0 {
		key := strings.ToUpper(msgType)
		// Build the JSON path directly (safe — key is always a known value like "OFFER").
		jsonPath := "$.keywords." + key
		var keyword *string
		db.Table("groups").Select("JSON_UNQUOTE(JSON_EXTRACT(settings, ?))", jsonPath).Where("id = ?", groupid).Scan(&keyword)
		if keyword != nil && *keyword != "" && *keyword != "null" {
			return *keyword
		}
	}
	return strings.ToUpper(msgType)
}

// isModForMessage checks if the user is a system admin/support or a moderator/owner
// of any group the message is on. Returns true even if messages_groups rows are soft-deleted,
// so mods can reject or delete messages even after the poster has deleted them.
func isModForMessage(db *gorm.DB, myid uint64, msgid uint64) bool {
	// Check system admin/support.
	if auth.IsAdminOrSupport(myid) {
		return true
	}

	// Check if mod of any group the message is on.
	// Don't filter on mg.deleted = 0 so mods can still moderate after poster deletes.
	var count int64
	result := db.Table("messages_groups mg").
		Joins("JOIN memberships m ON m.groupid = mg.groupid").
		Where("mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)", msgid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Count(&count)
	if result.Error != nil {
		log.Printf("Failed to check mod permission for user %d message %d: %v", myid, msgid, result.Error)
		return false
	}
	return count > 0
}

// resolveAuthorizedGroups returns the list of groupids the caller is authorised
// to act on for this message, given the requested groupid (0 = no specific group).
//
// Rules:
//   - reqGroupid > 0: caller must be a mod of that specific group and the
//     message must be on it. Returns [reqGroupid].
//   - reqGroupid == 0 & admin/support: returns all groups on the message.
//   - reqGroupid == 0 & regular mod: returns only the message's groups that the
//     caller moderates. A mod of A will NOT act on B when a message is on [A,B].
//   - Returns 403 if the caller has no authority.
func resolveAuthorizedGroups(myid uint64, reqGroupid uint64, groupids []uint64) ([]uint64, error) {
	if reqGroupid > 0 {
		if !auth.IsModOfGroup(myid, reqGroupid) {
			return nil, fiber.NewError(fiber.StatusForbidden, "Not a moderator for this group")
		}
		onGroup := false
		for _, gid := range groupids {
			if gid == reqGroupid {
				onGroup = true
				break
			}
		}
		if !onGroup {
			return nil, fiber.NewError(fiber.StatusNotFound, "Message not on that group")
		}
		return []uint64{reqGroupid}, nil
	}

	if auth.IsAdminOrSupport(myid) {
		return groupids, nil
	}

	var authorized []uint64
	for _, gid := range groupids {
		if auth.IsModOfGroup(myid, gid) {
			authorized = append(authorized, gid)
		}
	}
	if len(authorized) == 0 {
		return nil, fiber.NewError(fiber.StatusForbidden, "Not a moderator for any group on this message")
	}
	return authorized, nil
}

// MessageModContext holds common context needed by mod action handlers.
type MessageModContext struct {
	Fromuser uint64
	Groupid  uint64
	Groupids []uint64
	Subject  string
}

// getMessageModContext checks mod permission and fetches common context for mod actions.
// Returns nil if the user is not a moderator for this message.
func getMessageModContext(db *gorm.DB, myid uint64, msgid uint64) *MessageModContext {
	if !isModForMessage(db, myid, msgid) {
		return nil
	}
	ctx := &MessageModContext{}
	row := db.Table("messages").Select("fromuser, subject").Where("id = ?", msgid).Row()
	if err := row.Scan(&ctx.Fromuser, &ctx.Subject); err != nil {
		log.Printf("Failed to fetch mod context for message %d: %v", msgid, err)
		return nil
	}
	ctx.Groupid = getPrimaryGroupForMessage(db, msgid)
	ctx.Groupids = getAllGroupsForMessage(db, msgid)
	return ctx
}

// logAndNotifyMods logs a mod action and queues push notifications to moderators of the
// acted-on group only. Notifying mods of other groups the message happens to be on would
// leak cross-post membership and spam mods whose group wasn't affected.
func logAndNotifyMods(db *gorm.DB, subtype string, ctx *MessageModContext, myid uint64, msgid uint64, stdmsgid uint64, text string) {
	logModAction(db, flog.LOG_TYPE_MESSAGE, subtype, ctx.Groupid, ctx.Fromuser, myid, msgid, stdmsgid, text)
	if ctx.Groupid == 0 {
		return
	}
	if err := queue.QueueTask(queue.TaskPushNotifyGroupMods, map[string]interface{}{
		"group_id": ctx.Groupid,
	}); err != nil {
		log.Printf("Failed to queue push notification for group %d: %v", ctx.Groupid, err)
	}
}

// addApprovedMessageToSpatialIndex inserts/updates the messages_spatial rows for a
// message that has just become Approved, so it appears in browse/search immediately
// instead of waiting for the every-5-minute reconciler (MessageSpatialService).
// messages_spatial backs the public browse/map, so it must only contain Approved
// messages with a location — Pending/Spam/Rejected must never be added here. The
// query re-checks collection=Approved so this is a safe no-op if called otherwise.
//
// messages_spatial is keyed on (msgid, groupid): a cross-posted message gets one row
// per group it is approved on, so it shows in browse/search on each of those groups.
func addApprovedMessageToSpatialIndex(db *gorm.DB, msgid uint64) {
	type spatialRow struct {
		Lat     float64
		Lng     float64
		Msgtype string
		Groupid uint64
		Arrival string
	}
	var rows []spatialRow
	// Pin to the write host: the caller has just UPDATEd messages_groups.collection
	// to Approved on the source. Under the read/write split a plain SELECT would be
	// routed to the read replica, which may not have applied that write yet (Galera
	// apply-lag), so the row would be missed and the post left out of the spatial
	// index until the periodic reconciler runs.
	db.Clauses(dbresolver.Write).Table("messages").
		Select("messages.lat AS lat, messages.lng AS lng, messages.type AS msgtype, "+
			"messages_groups.groupid AS groupid, "+
			"DATE_FORMAT(messages_groups.arrival, '%Y-%m-%d %H:%i:%s') AS arrival").
		Joins("INNER JOIN messages_groups ON messages_groups.msgid = messages.id").
		Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
		Where("messages.id = ? AND messages_groups.collection = ? "+
			"AND messages_groups.deleted = 0 AND messages.deleted IS NULL "+
			"AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL "+
			"AND messages_outcomes.id IS NULL",
			msgid, utils.COLLECTION_APPROVED).
		Scan(&rows)

	for _, row := range rows {
		if row.Groupid == 0 || (row.Lat == 0 && row.Lng == 0) {
			continue
		}

		// groupid is part of the unique key, so it is never updated on conflict.
		db.Table("messages_spatial").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "point"}, Value: clause.Column{Table: "excluded", Name: "point"}},
				{Column: clause.Column{Name: "msgtype"}, Value: clause.Column{Table: "excluded", Name: "msgtype"}},
				{Column: clause.Column{Name: "arrival"}, Value: clause.Column{Table: "excluded", Name: "arrival"}},
			},
		}).Create(map[string]interface{}{
			"msgid":   msgid,
			"point":   gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)", row.Lng, row.Lat),
			"groupid": row.Groupid,
			"msgtype": row.Msgtype,
			"arrival": row.Arrival,
		})
	}
}

// invalidateMessageSearchIndexes drops the keyword-index (messages_index) and/or vector
// embedding (messages_embeddings) rows for a message whose subject/body has just changed.
// Both are populated ONCE for messages "missing" from those tables
// (MessageSearchService.indexUnindexedMessages / GenerateEmbeddingsCommand) and are never
// refreshed on edit, so a search for a term the edit introduced would never match.
// Deleting the stale rows lets those background jobs re-index and re-embed from the new
// text. Discourse 9954: a Wanted edited to add "Moulinex" was unfindable by that word.
//
// The two stores are driven by different fields, so they take independent invalidation
// flags: messages_index is derived from the message SUBJECT only (indexString is only ever
// called with subject text), while messages_embeddings is derived from subject+textbody. A
// body-only edit must not drop the keyword index - those rows still accurately reflect the
// unchanged subject, and dropping them would make the message unsearchable by keyword for
// no reason until the next background run.
//
// Deleting the messages_embeddings row is necessary but not sufficient for vector search:
// apiv2 serves vector search entirely from an in-process store (embedding.Global) that
// Refresh()es every ~2 min and is presence-keyed, so a delete+re-embed landing between two
// ticks would leave the STALE embedding in memory (see Store.Refresh's "Known limitation").
// We therefore also Evict the msgid from that store so the next Refresh reloads the
// regenerated blob.
func invalidateMessageSearchIndexes(db *gorm.DB, msgid uint64, subjectChanged bool, textChanged bool) {
	if subjectChanged {
		db.Table("messages_index").Where("msgid = ?", msgid).Delete(nil)
	}
	if subjectChanged || textChanged {
		db.Table("messages_embeddings").Where("msgid = ?", msgid).Delete(nil)
		embedding.Global.Evict(msgid)
	}
}

// handleApprove approves a pending message.
func handleApprove(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}
	// Set ctx.Groupid to the primary acted-on group (for logging).
	ctx.Groupid = authorizedGroups[0]

	// Move to Approved with arrival=NOW() so immediate-email recipients get it.
	// Guard against double-approve by requiring collection != Approved.
	// Restrict to groups the caller is authorised for.
	if result := db.Table("messages_groups").
		Where("msgid = ? AND groupid IN ? AND collection != ?", req.ID, authorizedGroups, utils.COLLECTION_APPROVED).
		Updates(map[string]interface{}{
			"collection": utils.COLLECTION_APPROVED, "approvedby": myid,
			"approvedat": gorm.Expr("NOW()"), "arrival": gorm.Expr("NOW()"),
		}); result.Error != nil {
		log.Printf("Failed to approve message %d: %v", req.ID, result.Error)
	}

	// Release hold on the same authorised groups.
	// Identical to cc381d7c669b
	// (handleRelease); converted together per gate (h).
	db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Update("heldby", gorm.Expr("NULL"))

	// Clearing this group's messages_groups.heldby above is the whole job: holds are
	// per-group, so there is no message-wide flag to recompute and clear.

	// Now Approved — add to the spatial index so the post appears in browse/search
	// immediately rather than waiting for the periodic reconciler.
	addApprovedMessageToSpatialIndex(db, req.ID)

	// Mark as ham if it was flagged as spam on any authorised group (fall back to messages table).
	var spamtype *string
	db.Table("messages_groups").Select("spamtype").
		Where("msgid = ? AND groupid IN ? AND spamtype IS NOT NULL", req.ID, authorizedGroups).
		Limit(1).Scan(&spamtype)
	if spamtype == nil {
		db.Table("messages").Select("spamtype").Where("id = ?", req.ID).Scan(&spamtype)
	}
	if spamtype != nil && *spamtype != "" {
		db.Table("messages_spamham").Clauses(clause.Insert{Modifier: "REPLACE"}).
			Create(map[string]interface{}{"msgid": req.ID, "spamham": gorm.Expr("'Ham'")})
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	// Queue email to poster (includes stdmsg content for the batch processor).
	// The batch processor will also create the mod log entry and notify group moderators.
	// One task per authorised group so per-group logging and notifications are correct.
	for _, gid := range authorizedGroups {
		// Identical golden to
		// 02b3821ea3b9, 7603ee833330 and e1f780721381; converted together per gate (h).
		db.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_approved",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				req.ID, gid, myid, subject, body, stdmsgid, "Approve"),
		})
	}

	// Notify freebiealerts.app about newly approved Offer posts.
	// Clearance/bulk-offer posts are excluded — the concierge manages their
	// fulfilment directly and freebiealerts.app is not the right channel for them.
	var approvedMsgType string
	db.Table("messages").Select("type").Where("id = ?", req.ID).Scan(&approvedMsgType)
	var isClearance int64
	db.Table("messages_bulk_items").Where("msgid = ?", req.ID).Count(&isClearance)
	if approvedMsgType == "Offer" && isClearance == 0 {
		if err := queue.QueueTask(queue.TaskFreebieAlertsAdd, map[string]interface{}{
			"msgid": req.ID,
		}); err != nil {
			log.Printf("Failed to queue freebie alerts add for message %d: %v", req.ID, err)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleReject rejects a pending message.
// MessageOriginGroup returns the group a message was first posted to — the group whose
// messages_groups.arrival matches the message's own arrival. With rippling-out a post is
// added to nearby groups later (at ripple time), so the origin is the earliest-arriving
// group AND its arrival must be at/near messages.arrival. Only that group's rejection
// notifies the poster (#6); a secondary (rippled-in) group's rejection stays silent.
//
// Returns 0 when the origin cannot be determined — including when the origin group's row
// was HARD-deleted (handleDeleteMessage / handleMove), leaving only later rippled-in
// rows: those fail the arrival-match, so we return 0 and the caller falls back to
// notifying all groups (the safe direction — notify rather than silently drop).
// Soft-deleted (deleted=1) origin rows from a plain-delete rejection still persist and
// are matched correctly, so a later secondary rejection stays silent as intended.
//
// messages_groups has no surrogate id column (its key is the composite (msgid, groupid)),
// so groupid is the tiebreak when two groups share the same arrival second: lowest groupid
// wins, deterministically. Manual cross-posting is retired by #10, so same-second ties are
// rare (TN same-second import order).
func MessageOriginGroup(db *gorm.DB, msgid uint64) uint64 {
	var res struct {
		Groupid  uint64
		IsOrigin bool
	}
	db.Table("messages_groups mg").
		Select("mg.groupid AS groupid, (mg.arrival <= m.arrival + INTERVAL 10 MINUTE) AS is_origin").
		Joins("JOIN messages m ON m.id = mg.msgid").
		Where("mg.msgid = ?", msgid).
		Order("mg.arrival ASC, mg.groupid ASC").
		Limit(1).
		Scan(&res)
	if !res.IsOrigin {
		return 0
	}
	return res.Groupid
}

func handleReject(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}
	ctx.Groupid = authorizedGroups[0]

	// Only groups where this message is still awaiting moderation - Pending, or
	// auto-flagged into Spam (which ModTools presents in the same queue with the
	// same Reject action) - can be rejected/deleted here. If it has since been
	// (re-)approved to live, this click is a no-op (Discourse 9815): we must not
	// move a non-pending row and - for a reject-with-explanation - must not log
	// a phantom rejection or email the poster a "rejected" notice while the post
	// stays live. Spam was originally omitted, which made Reject on a
	// spam-flagged post a SILENT no-op: the API answered ret=1, ModTools
	// swallowed it, and mods concluded the button was broken (Vale of White
	// Horse, msgid 121384453 - ten identical attempts across three browsers).
	moderatable := []string{utils.COLLECTION_PENDING, utils.COLLECTION_SPAM}
	var pendingGroups []uint64
	db.Table("messages_groups").Select("groupid").
		Where("msgid = ? AND groupid IN ? AND collection IN ? AND deleted = 0",
			req.ID, authorizedGroups, moderatable).Scan(&pendingGroups)

	if subject != "" && len(pendingGroups) == 0 {
		return c.JSON(fiber.Map{"ret": 1, "status": "Message is no longer pending and was not rejected"})
	}

	// With a subject (stdmsg), move to Rejected collection (user can edit and resubmit).
	// Without a subject (plain delete), mark as deleted.
	if subject != "" {
		if result := db.Table("messages_groups").
			Where("msgid = ? AND groupid IN ? AND collection IN ?", req.ID, pendingGroups, moderatable).
			Updates(map[string]interface{}{"collection": utils.COLLECTION_REJECTED, "rejectedat": gorm.Expr("NOW()"), "heldby": gorm.Expr("NULL")}); result.Error != nil {
			log.Printf("Failed to reject message %d: %v", req.ID, result.Error)
		}
	} else {
		if result := db.Table("messages_groups").
			Where("msgid = ? AND groupid IN ? AND collection IN ?", req.ID, authorizedGroups, moderatable).
			Updates(map[string]interface{}{"deleted": gorm.Expr("1"), "heldby": gorm.Expr("NULL")}); result.Error != nil {
			log.Printf("Failed to delete pending message %d: %v", req.ID, result.Error)
		}

		// Cascade soft-delete: if no non-deleted groups remain, mark messages.deleted
		// so list queries filtering `messages.deleted IS NULL` don't see an orphan row.
		var remainingGroups int64
		// Pin to the write host: this gates the parent-message soft-delete on rows we
		// just modified, so it must read the source, not a possibly-lagging replica.
		db.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", req.ID).Count(&remainingGroups)
		if remainingGroups == 0 {
			// Identical golden to
			// ef364ece98ef and 22ed790e0691; converted together per gate (h).
			if result := db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")}); result.Error != nil {
				log.Printf("Failed to soft-delete rejected message %d: %v", req.ID, result.Error)
			}
			if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
				"msgid": req.ID,
			}); err != nil {
				log.Printf("Failed to queue freebie alerts remove for message %d: %v", req.ID, err)
			}
		}
	}

	// A rejected or deleted copy is no longer held, and the UPDATEs above clear its
	// per-group heldby. Holds being per-group, a stale hold on one copy can no longer
	// keep the whole post showing "Held" and strand a mod on another group the post
	// rippled into (Discourse 9894).

	// Determine the message's ORIGIN group — the first group it was posted to (the
	// earliest messages_groups arrival). With rippling-out, a post is added to nearby
	// groups over time; a rejection by a SECONDARY (non-origin) group just stops it
	// showing in that group's area and must NOT be sent back to the poster (#6): they
	// posted it on their origin group and it remains available there, so a secondary
	// "out of area" rejection is not their concern.
	originGid := MessageOriginGroup(db, req.ID)

	// Queue the rejection email only for the origin group (the batch processor creates
	// one log+push per group). Secondary-group rejections are silent to the poster and
	// logged for #9 observability (how often rippling pushes a post somewhere a group
	// rejects it). Iterate only the groups actually rejected here (Pending at the time)
	// so a group where the post had already gone live gets no phantom email/log (#9815).
	for _, gid := range pendingGroups {
		if originGid != 0 && gid != originGid {
			log.Printf("ripple: secondary-group reject msgid=%d groupid=%d byuser=%d (poster not notified)", req.ID, gid, myid)
			RecordRippleEvent(db, "secondary_reject")
			ClipReachForRejectedGroup(db, req.ID, gid)
			continue
		}
		// Identical golden to
		// b25ea3ba4ade, 7603ee833330 and e1f780721381; converted together per gate (h).
		db.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_rejected",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				req.ID, gid, myid, subject, body, stdmsgid, "Reject"),
		})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// ClipReachForRejectedGroup removes a rejecting secondary group's area from a post's
// rippling reach, so the post stops showing — and stops being reply-eligible —
// in that group's area (#6). The post's reach grid (rippling_reach.polygon_cells)
// is trimmed by the group's DPA-or-CGA area (groups.polyindex). If the reach lies
// wholly within the rejected group, nothing remains, so the reach row is dropped.
//
// Errors are ignored on purpose: until the reach engine (PR A) is live there is no
// rippling_reach table/row to clip, in which case this is a harmless no-op.
func ClipReachForRejectedGroup(db *gorm.DB, msgid, gid uint64) {
	// Record the rejected group BEFORE clipping the polygon, so the expander
	// (ExpandService::advanceDue) re-subtracts it on every tick — otherwise the next tick
	// overwrites `polygon` from the cached schedule and silently undoes this rejection.
	// Dedup the id; ignored (best-effort) if the rejected_groups column is not present yet.
	db.Table("rippling_reach").
		Where("msgid = ? AND (rejected_groups IS NULL OR JSON_CONTAINS(rejected_groups, CAST(? AS JSON)) = 0)", msgid, gid).
		Update("rejected_groups", gorm.Expr("JSON_ARRAY_APPEND(COALESCE(rejected_groups, JSON_ARRAY()), '$', ?)", gid))

	// The whole clip is grid arithmetic - read the row's cells and the
	// group's area, subtract, write back (or delete the row when nothing
	// remains). The sandwich inner bound is NULLed inside; the outer bound
	// stays stale-loose, a still-valid superset of the SHRUNK reach.
	clipReachCellsOnly(db, msgid, gid)
}

// clipReachCellsOnly is ClipReachForRejectedGroup's implementation: no
// stored polygon exists, so the clip is Subtract over two grids on the shared
// lattice. The group's area is rasterised by the spatial server (the one
// rasteriser); on any failure the reach is left UNCLIPPED and the failure
// logged - over-reaching into a group that rejected the post is visible and
// recoverable, where writing a wrong or empty grid would silently change who
// may reply everywhere.
func clipReachCellsOnly(db *gorm.DB, msgid, gid uint64) {
	var row struct {
		Cells    []byte  `gorm:"column:cells"`
		GroupWkt *string `gorm:"column:group_wkt"`
	}
	if err := db.Table("rippling_reach mr").
		Joins("JOIN `groups` g ON g.id = ?", gid).
		Select("mr.polygon_cells AS cells, ST_AsText(g.polyindex) AS group_wkt").
		Where("mr.msgid = ? AND g.polyindex IS NOT NULL AND ST_GeometryType(g.polyindex) <> 'POINT'", msgid).
		Scan(&row).Error; err != nil {
		log.Printf("clip cells: fetch failed for msgid=%d gid=%d: %v", msgid, gid, err)
		return
	}
	if row.GroupWkt == nil {
		// No reach row, or the group has no usable area: nothing to clip.
		return
	}
	if len(row.Cells) == 0 {
		log.Printf("clip cells: msgid=%d has no stored cells; reach left unclipped for gid=%d", msgid, gid)
		return
	}
	groupBytes, err := spatial.RasterizeWKT(*row.GroupWkt)
	if err != nil {
		log.Printf("clip cells: rasterise group %d failed: %v", gid, err)
		return
	}
	reach, err := rippling.DecodeCellSet(row.Cells)
	if err != nil {
		log.Printf("clip cells: msgid=%d stored cells unreadable: %v", msgid, err)
		return
	}
	group, err := rippling.DecodeCellSet(groupBytes)
	if err != nil {
		log.Printf("clip cells: group %d cells unreadable: %v", gid, err)
		return
	}

	if !reach.Intersects(group) {
		return
	}
	if reach.Within(group) {
		// Nothing valid remains: drop the reach row, exactly as the legacy
		// path's wholly-within DELETE did.
		db.Table("rippling_reach").Where("msgid = ?", msgid).Delete(nil)
		return
	}

	clipped := reach.Subtract(group).Encode()
	set := map[string]interface{}{"polygon_cells": clipped}
	if rippling.ReachBoundsReady(db) {
		set["inner_bound"] = gorm.Expr("NULL")
	}
	db.Table("rippling_reach").Where("msgid = ?", msgid).Updates(set)
}

// RecordRippleEvent bumps the per-day counter for a rippling-out event (design §15/§16 —
// "instrument from day one"), surfaced read-only in sysadmin. Best-effort: errors are
// ignored so instrumentation never affects the request (e.g. before the table ships).
func RecordRippleEvent(db *gorm.DB, event string) {
	db.Table("rippling_event_metrics").Clauses(clause.OnConflict{
		DoUpdates: clause.Assignments(map[string]interface{}{"count": gorm.Expr("count + 1")}),
	}).Create(map[string]interface{}{
		"day":   gorm.Expr("CURDATE()"),
		"event": event,
		"count": gorm.Expr("1"),
	})
}

// handleDeleteMessage deletes a message (mod action).
func handleDeleteMessage(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Get context before deleting (needs messages_groups rows).
	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}
	ctx.Groupid = authorizedGroups[0]

	// Per-group delete: remove only the authorized groups' rows.
	// Identical golden to f90b6df0a3bb
	// (handleRejectToDraft); converted together per gate (h).
	if result := db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Delete(nil); result.Error != nil {
		log.Printf("Failed to delete messages_groups for message %d groups %v: %v", req.ID, authorizedGroups, result.Error)
	}

	// If no non-deleted groups remain, soft-delete the message itself.
	var remainingGroups int64
	// Pin to the write host: this gates the parent-message soft-delete on rows we
	// just modified, so it must read the source, not a possibly-lagging replica.
	db.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", req.ID).Count(&remainingGroups)
	if remainingGroups == 0 {
		// Identical golden to
		// 522c1e7c91cf and 22ed790e0691; converted together per gate (h).
		if result := db.Table("messages").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")}); result.Error != nil {
			log.Printf("Failed to soft-delete message %d: %v", req.ID, result.Error)
		}

		// Remove from freebiealerts.app — post is no longer available on any group.
		if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
			"msgid": req.ID,
		}); err != nil {
			log.Printf("Failed to queue freebie alerts remove for message %d: %v", req.ID, err)
		}
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	// Queue email+log+push via background task for each authorized group.
	// The batch processor will create the mod log entry and notify group moderators.
	for _, gid := range authorizedGroups {
		// Identical golden to
		// b25ea3ba4ade, 02b3821ea3b9 and e1f780721381; converted together per gate (h).
		db.Table("background_tasks").Create(map[string]interface{}{
			"task_type": "email_message_rejected",
			"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
				req.ID, gid, myid, subject, body, stdmsgid, "Delete Approved Message"),
		})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleSpam marks a message as spam.
func handleSpam(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}

	// Record for spam training.
	db.Table("messages_spamham").Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(map[string]interface{}{"msgid": req.ID, "spamham": utils.COLLECTION_SPAM})

	// Per-group spam: soft-delete only the authorized groups' rows.
	db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Update("deleted", gorm.Expr("1"))

	// If no non-deleted groups remain, soft-delete the message itself.
	var remainingGroups int64
	// Pin to the write host: this gates the parent-message soft-delete on rows we
	// just modified, so it must read the source, not a possibly-lagging replica.
	db.Clauses(dbresolver.Write).Table("messages_groups").Where("msgid = ? AND deleted = 0", req.ID).Count(&remainingGroups)
	if remainingGroups == 0 {
		// Identical golden to
		// 499057e391e9 (DeleteMessageEndpoint); converted together per gate (h).
		db.Table("messages").Where("id = ?", req.ID).Update("deleted", gorm.Expr("NOW()"))

		// Remove from freebiealerts.app — post is no longer available on any group.
		if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
			"msgid": req.ID,
		}); err != nil {
			log.Printf("Failed to queue freebie alerts remove for message %d: %v", req.ID, err)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleHold holds a pending message (assigns heldby to the mod).
func handleHold(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}

	// Per-group hold: set heldby on the authorized groups' rows.
	// Identical golden to
	// 1a12de474647 (handleBackToPending); converted together per gate (h).
	db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Update("heldby", myid)

	// Log to each group we acted on.
	for _, gid := range authorizedGroups {
		ctx.Groupid = gid
		logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleBackToPending moves an approved message back to pending.
func handleBackToPending(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}

	// Per-group hold for re-review.
	// Identical golden to
	// 8c1766162f86 (handleHold); converted together per gate (h).
	db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Update("heldby", myid)

	// Pull the WHOLE post back to Pending, not just this mod's groups: a moderator moving
	// any copy back to pending takes the post off the board on EVERY community it is on
	// (home + rippled-out copies), so it is never left stranded and still visible on the
	// neighbouring communities. Each community then approves or rejects its own copy
	// independently. Clear approvedby/approvedat on every live copy first, then flip to
	// Pending.
	db.Table("messages_groups").Where("msgid = ? AND collection = ?", req.ID, utils.COLLECTION_APPROVED).
		Updates(map[string]interface{}{"approvedby": gorm.Expr("NULL"), "approvedat": gorm.Expr("NULL")})
	microvolunteering.SendForReviewAllGroups(db, req.ID, "A moderator moved this post back to pending for review.")

	// Freeze the ripple once the origin is Pending: the copies persist for per-group
	// moderation and a later re-approval brings a copy back without re-rippling or
	// re-notifying members.
	microvolunteering.FreezeReachIfOriginPending(db, req.ID)

	// Log to each group we acted on.
	for _, gid := range authorizedGroups {
		ctx.Groupid = gid
		logAndNotifyMods(db, flog.LOG_SUBTYPE_HOLD, ctx, myid, req.ID, 0, "Back to pending")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleRelease releases a held message.
func handleRelease(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return err
	}

	// Per-group release.
	// Identical golden to
	// 6180dc848f02 (handleApprove); converted together per gate (h).
	db.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, authorizedGroups).
		Update("heldby", gorm.Expr("NULL"))

	// Clearing the per-group heldby above is the whole job: a hold belongs to a
	// (message, group) pair, so there is no message-wide flag left to recompute.

	// Log to each group we acted on.
	for _, gid := range authorizedGroups {
		ctx.Groupid = gid
		logAndNotifyMods(db, flog.LOG_SUBTYPE_RELEASE, ctx, myid, req.ID, 0, "")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleApproveEdits approves pending edits on a message.
func handleApproveEdits(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !isModForMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	// Clear the editedby flag.
	// Identical golden to
	// 83ab41e7c9ac (handleRevertEdits); converted together per gate (h).
	db.Table("messages").Where("id = ?", req.ID).Update("editedby", gorm.Expr("NULL"))

	// Find the latest pending edit to apply its changes.
	type editRecord struct {
		ID         uint64
		Newsubject *string
		Newtext    *string
	}
	var edit editRecord
	db.Table("messages_edits").Select("id, newsubject, newtext").
		Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", req.ID).
		Order("id DESC").Limit(1).Scan(&edit)

	if edit.ID > 0 {
		// Apply the changes from the latest edit.
		if edit.Newsubject != nil {
			db.Table("messages").Where("id = ?", req.ID).Update("subject", *edit.Newsubject)
		}
		if edit.Newtext != nil {
			db.Table("messages").Where("id = ?", req.ID).Update("textbody", *edit.Newtext)
		}
		// Applied an edit → whichever of the keyword index / vector embedding depend on
		// the field(s) just written are now stale.
		invalidateMessageSearchIndexes(db, req.ID, edit.Newsubject != nil, edit.Newtext != nil)
	}

	// Mark ALL pending edits as approved.
	db.Table("messages_edits").
		Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", req.ID).
		Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "approvedat": gorm.Expr("NOW()")})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleRevertEdits reverts pending edits on a message.
func handleRevertEdits(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !isModForMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	// Restore the original text from the most recent pending edit before marking it reverted.
	// The PATCH edit flow immediately writes the new text into messages, so we must explicitly
	// restore the old values here — otherwise the edited text stays visible after rejection.
	type editOldValues struct {
		Oldsubject *string
		Oldtext    *string
	}
	var old editOldValues
	db.Table("messages_edits").Select("oldsubject, oldtext").
		Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", req.ID).
		Order("id DESC").Limit(1).Scan(&old)
	if old.Oldsubject != nil || old.Oldtext != nil {
		// The guard
		// above means at least one of Oldsubject/Oldtext is set, so this is a
		// genuine 3-shape site (SubjectOnly, TextbodyOnly, Both), not an
		// N-independent-fields one - small enough for the retired harness's
		// shapes.json, unlike
		// applyPatchMessageCore's 8-field SET a few hundred lines down (site
		// e9f2c662be69), which stays raw. All 3 shapes were proven by the
		// retired ormharness (shapes.json /
		// TestTier1BatchShapes_99713f48c505, removed in d22ba1d6c).
		assignments := clause.Set{
			{Column: clause.Column{Name: "editedby"}, Value: gorm.Expr("NULL")},
		}
		if old.Oldsubject != nil {
			assignments = append(assignments, clause.Assignment{Column: clause.Column{Name: "subject"}, Value: *old.Oldsubject})
		}
		if old.Oldtext != nil {
			assignments = append(assignments, clause.Assignment{Column: clause.Column{Name: "textbody"}, Value: *old.Oldtext})
		}
		db.Table("messages").Clauses(assignments).Where("id = ?", req.ID).Updates(map[string]interface{}{})

		// Reverting restored the previous subject/body, so whichever of the keyword index
		// / vector embedding depend on the restored field(s) are out of sync again - drop
		// them to be rebuilt.
		invalidateMessageSearchIndexes(db, req.ID, old.Oldsubject != nil, old.Oldtext != nil)
	} else {
		// No recorded old values — just clear the editedby flag.
		// Identical golden to
		// 06b3d2e46af9 (handleApproveEdits); converted together per gate (h).
		db.Table("messages").Where("id = ?", req.ID).Update("editedby", gorm.Expr("NULL"))
	}

	// Mark all pending edits as reverted.
	db.Table("messages_edits").
		Where("msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL", req.ID).
		Updates(map[string]interface{}{"reviewrequired": gorm.Expr("0"), "revertedat": gorm.Expr("NOW()")})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handlePartnerConsent records partner consent on a message.
// Requires mod role and partner name.
func handlePartnerConsent(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !isModForMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	if req.Partner == nil || *req.Partner == "" {
		return fiber.NewError(fiber.StatusBadRequest, "partner is required")
	}

	// Look up partner in partners_keys.
	var partnerID uint64
	db.Table("partners_keys").Select("id").Where("partner = ?", *req.Partner).Scan(&partnerID)
	if partnerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Partner not found")
	}

	// Record consent in partners_messages.
	db.Table("partners_messages").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"partnerid": partnerID,
		"msgid":     req.ID,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleReply queues a mod reply email to the message poster.
func handleReply(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	subject := ""
	if req.Subject != nil {
		subject = *req.Subject
	}
	body := ""
	if req.Body != nil {
		body = *req.Body
	}
	stdmsgid := uint64(0)
	if req.Stdmsgid != nil {
		stdmsgid = *req.Stdmsgid
	}

	// Use request groupid if provided, otherwise fall back to context.
	if req.Groupid != nil && *req.Groupid > 0 {
		ctx.Groupid = *req.Groupid
	}

	// Write the mod log entry synchronously, exactly once, like the other mod actions
	// (hold/release/repost/edit). Previously the log was written by the batch processor,
	// but that INSERT is unconditional and runs again whenever the task is retried (e.g.
	// after a transient email-spool failure), producing duplicate "Replied" rows in the
	// mod history (Discourse 9672/6). The batch now skips the log for this action.
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPLIED, ctx.Groupid, ctx.Fromuser, myid, req.ID, stdmsgid, subject)

	// Queue the email via background task (the log is already written above).
	// Identical golden to
	// b25ea3ba4ade, 02b3821ea3b9 and 7603ee833330; converted together per gate (h).
	db.Table("background_tasks").Create(map[string]interface{}{
		"task_type": "email_message_reply",
		"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?)",
			req.ID, ctx.Groupid, myid, subject, body, stdmsgid, "Leave Approved Message"),
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleRejectToDraft converts a message back into a draft for reposting.
// The message owner or a moderator can do this. It moves the message out of
// messages_groups and into messages_drafts so the client can re-edit and
// re-submit via JoinAndPost.
func handleRejectToDraft(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Verify the message exists and check ownership/mod permission.
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	isOwner := fromuser == myid
	isMod := isModForMessage(db, myid, req.ID)
	if !isOwner && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to convert this message to draft")
	}

	// Determine which groups to take back to draft.
	//   - groupid in the request (a moderator acting on their own group):
	//     that group only.
	//   - no groupid (the owner withdrawing their own message): ALL groups
	//     the message is on — a withdrawal is global to the poster.
	var groupids []uint64
	if req.Groupid != nil && *req.Groupid > 0 {
		groupids = []uint64{*req.Groupid}
	} else {
		db.Table("messages_groups").Select("groupid").Where("msgid = ?", req.ID).Scan(&groupids)
	}
	// Fallback for a message with no live group rows (e.g. already partially
	// drafted): keep V1's behaviour of always producing a draft.
	if len(groupids) == 0 {
		if pg := getPrimaryGroupForMessage(db, req.ID); pg > 0 {
			groupids = []uint64{pg}
		}
	}

	// Use a transaction: insert draft(s) then remove the targeted group rows.
	tx := db.Begin()
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction failed")
	}

	// messages_drafts is unique per msgid, so a message has at most one draft
	// row. Record it against the first targeted group; on re-post via
	// JoinAndPost the owner picks the destination group(s) again. INSERT IGNORE
	// keeps an existing draft row intact.
	if len(groupids) > 0 {
		if err := tx.Table("messages_drafts").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid":   req.ID,
			"groupid": groupids[0],
			"userid":  myid,
		}).Error; err != nil {
			tx.Rollback()
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create draft")
		}
	}

	// Remove the targeted group rows. With a groupid this is just that group;
	// without one it's every group the message was on. Any groups not in the
	// set keep their live posting.
	// Identical golden to 3a50dbee0fa0
	// (handleDeleteMessage); converted together per gate (h). Runs on tx (a
	// *gorm.DB transaction), which the retired harness's dry-run build
	// function rendered identically to the plain connection - same reasoning
	// as the retired orm_wave2_pilot_test.go's handleMerge note (removed in
	// d22ba1d6c).
	if err := tx.Table("messages_groups").Where("msgid = ? AND groupid IN ?", req.ID, groupids).
		Delete(nil).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to remove from group")
	}

	// If the message is still live on other groups, leave its global state
	// (outcomes, availability, deadline) alone — those are shared across all
	// groups and the message is still active elsewhere. Only when this was the
	// last group does the message become a fresh draft and need a full reset.
	var remainingGroups int64
	if err := tx.Table("messages_groups").Where("msgid = ?", req.ID).Count(&remainingGroups).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to count remaining groups")
	}

	if remainingGroups == 0 {
		// Clear any previous outcome so the reposted message starts fresh.
		// Without this, a message that was withdrawn still shows as "withdrawn"
		// in posting history after reposting — the same wrong behaviour as V1.
		// Identical golden to
		// dc8914d8b9d5 and a08c7f4426c7; converted together per gate (h).
		if err := tx.Table("messages_outcomes").Where("msgid = ?", req.ID).Delete(nil).Error; err != nil {
			tx.Rollback()
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to clear outcome")
		}
		// Identical golden to
		// ce1d968cff70 and 4064113639bf; converted together per gate (h).
		tx.Table("messages_outcomes_intended").Where("msgid = ?", req.ID).Delete(nil)

		// Reset availablenow to availableinitially — if the item was promised to
		// someone who never collected, the repost should offer the full quantity again.
		// Also clear messages_by so there are no stale promise records.
		tx.Table("messages").Where("id = ?", req.ID).Update("availablenow", gorm.Expr("availableinitially"))
		tx.Table("messages_by").Where("msgid = ?", req.ID).Delete(nil)
	}

	if err := tx.Commit().Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction commit failed")
	}

	// Clear deadline if it's in the past or today — an old deadline is no longer
	// relevant when reposting and would cause the message to appear expired.
	// Only when fully redrafted: while still live elsewhere the deadline applies
	// to the active posting.
	if remainingGroups == 0 {
		var deadline *string
		db.Table("messages").Select("deadline").Where("id = ?", req.ID).Scan(&deadline)
		if deadline != nil && *deadline != "" {
			today := time.Now().Format("2006-01-02")
			if *deadline <= today {
				db.Table("messages").Where("id = ?", req.ID).Update("deadline", gorm.Expr("NULL"))
			}
		}
	}

	// Log the repost action.
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPOST, 0, fromuser, myid, req.ID, 0, "Repost started")

	// Return the message type (the client uses this).
	var msgType string
	db.Table("messages").Select("type").Where("id = ?", req.ID).Scan(&msgType)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "messagetype": msgType})
}

// deadlineDate reduces a client-supplied deadline to its date part.
// messages.deadline is a DATE column; bundled apps send a full ISO datetime
// ("2026-07-15T00:00:00.000Z"), which strict sql_mode rejects as a DATE
// literal. Current clients send plain YYYY-MM-DD, which passes through.
func deadlineDate(deadline string) string {
	if len(deadline) > 10 {
		return deadline[:10]
	}
	return deadline
}

// handleJoinAndPost joins a group and posts a message in one action.
func handleJoinAndPost(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// A member may only submit their own draft. A ChitChat moderator converting
	// a ChitChat post submits the draft they just created for that member, so
	// they submit as the member via ?onbehalfof=.
	author, err := onBehalfOf(c, myid)
	if err != nil {
		return err
	}

	var owner uint64
	db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&owner)
	if owner == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if owner != author {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	return JoinAndPostAs(c, myid, author, req)
}

// JoinAndPostAs joins author to the destination group and submits the draft as
// them. The ownership check lives in the caller: handleJoinAndPost enforces
// "your own draft" for the member route, while the ChitChat convert-to-post
// path instead requires the caller to be a ChitChat moderator or support/admin
// (newsfeed.canHidePost) and passes the member as author.
//
// No other caller should pass an author other than the caller. The caller is
// passed too because the new-user password below must only ever go to the
// person it belongs to.
func JoinAndPostAs(c *fiber.Ctx, caller uint64, author uint64, req PostMessageRequest) error {
	myid := author
	db := database.DBConn

	// Look up the existing draft message.
	type msgInfo struct {
		Fromuser uint64
		Type     string
	}
	var msg msgInfo
	db.Table("messages").Select("fromuser, type").Where("id = ?", req.ID).Scan(&msg)
	if msg.Fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Find the group — from request, then messages_drafts, then messages_groups.
	groupid := uint64(0)
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	} else {
		db.Table("messages_drafts").Select("groupid").Where("msgid = ?", req.ID).Limit(1).Scan(&groupid)
	}
	if groupid == 0 {
		groupid = getPrimaryGroupForMessage(db, req.ID)
	}
	if groupid == 0 {
		// The compose client normally resolves the member's location to a
		// group and sends groupid; some clients fail to (observed live:
		// WANTED posts whose draft stored a location but whose submit carried
		// no group - the member was then stuck at "groupid is required" for
		// good). The message knows where it is, so derive what the client
		// should have sent: the closest group to the post's own coordinates
		// (polygon containment is authoritative inside ClosestGroups).
		var loc struct {
			Lat float64 `gorm:"column:lat"`
			Lng float64 `gorm:"column:lng"`
		}
		db.Table("messages").Select("lat, lng").Where("id = ?", req.ID).Scan(&loc)
		if loc.Lat != 0 || loc.Lng != 0 {
			if g := location.ClosestSingleGroup(loc.Lat, loc.Lng, location.NEARBY); g != nil {
				groupid = g.ID
			}
		}
	}
	if groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	// Check if user is banned from this group.
	// V1 parity: a ban deletes the memberships row and inserts into users_banned —
	// there is no memberships.collection='Banned' row, so the check must hit users_banned.
	var bannedCount int64
	db.Table("users_banned").Where("userid = ? AND groupid = ?", myid, groupid).Count(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "You are banned from this group")
	}

	// Join group if not already a member.
	result := db.Table("memberships").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"userid":     myid,
		"groupid":    groupid,
		"role":       utils.ROLE_MEMBER,
		"collection": utils.COLLECTION_APPROVED,
	})

	// Log the join event when a new membership row was created.
	if result.RowsAffected > 0 {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": flog.LOG_TYPE_GROUP, "subtype": flog.LOG_SUBTYPE_JOINED,
			"groupid": groupid, "user": myid, "byuser": myid,
		})
	}

	// All messages start Pending — the content check batch job runs content checks
	// and promotes clean messages from non-moderated users to Approved.
	collection := utils.COLLECTION_PENDING
	var ourPostingStatus *string
	db.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", myid, groupid).Scan(&ourPostingStatus)

	if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
		return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
	}

	// Reconstruct subject with location and group keyword before submitting
	//. The draft subject may have been set without
	// a location, or the group keyword may differ from the draft's type prefix.
	locStr := constructLocationString(db, req.ID)
	if locStr != "" {
		var itemName *string
		db.Table("items i").
			Select("i.name").
			Joins("INNER JOIN messages_items mi ON mi.itemid = i.id").
			Where("mi.msgid = ?", req.ID).
			Limit(1).
			Scan(&itemName)
		if itemName != nil {
			keyword := getGroupKeyword(db, groupid, msg.Type)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			// Identical golden to
			// 2f30762bf955 (applyPatchMessageCore) and b53892a17f40 (PutMessageAs);
			// converted together per gate (h).
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"subject": newSubject, "suggestedsubject": newSubject})
		}
	}

	// Refuse to promote a draft that would land in the group with no subject.
	// This catches pre-validation drafts created before PUT /message required
	// item, and any other path that leaves subject empty by submit time.
	var finalSubject string
	// Pin to the write host: we may have just UPDATEd messages.subject above, and this
	// read gates a hard validation error. A lagging replica could see the old/empty
	// subject and wrongly reject a valid post.
	db.Clauses(dbresolver.Write).Table("messages").Select("COALESCE(subject, '')").Where("id = ?", req.ID).Scan(&finalSubject)
	if strings.TrimSpace(finalSubject) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Item is required")
	}

	// Save deadline and deliverypossible if provided.
	if req.Deadline != nil && *req.Deadline != "" {
		// messages.deadline is a DATE column and bundled apps send a full ISO
		// datetime, which strict sql_mode rejects outright - and with the error
		// unchecked the deadline was silently lost (Discourse #9481).
		if err := db.Table("messages").Where("id = ?", req.ID).Update("deadline", deadlineDate(*req.Deadline)).Error; err != nil {
			return fiber.NewError(fiber.StatusBadRequest, "Invalid deadline")
		}
	}
	if req.Deliverypossible != nil {
		db.Table("messages").Where("id = ?", req.ID).Update("deliverypossible", *req.Deliverypossible)
	}

	// Submit: insert into messages_groups and clean up draft.
	db.Table("messages_groups").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"msgid":      req.ID,
		"groupid":    groupid,
		"collection": collection,
		"arrival":    gorm.Expr("NOW()"),
	})

	// Clear any previous outcomes (V1 parity: submit() always deletes outcomes before re-posting).
	// Identical golden to 854c7e93efe3
	// and a08c7f4426c7; converted together per gate (h).
	db.Table("messages_outcomes").Where("msgid = ?", req.ID).Delete(nil)
	// Identical golden to 0486830f6eda
	// and 4064113639bf; converted together per gate (h).
	db.Table("messages_outcomes_intended").Where("msgid = ?", req.ID).Delete(nil)

	// Record posting (V1 parity: submit() inserts into messages_postings each time a message is submitted).
	db.Table("messages_postings").Create(map[string]interface{}{"msgid": req.ID, "groupid": groupid})

	// Record history entry for spam checking (V1 parity: Message::save() inserts into messages_history).
	// We fetch user email/name from the DB since platform messages don't have envelope headers.
	var histSubject string
	// Pin to the write host: this is the subject we may have just UPDATEd, written here
	// into messages_history. A lagging replica read would persist a stale/empty subject.
	db.Clauses(dbresolver.Write).Table("messages").Select("COALESCE(subject, '')").Where("id = ?", req.ID).Scan(&histSubject)
	var histFromname string
	db.Table("users").Select("COALESCE(fullname, '')").Where("id = ?", myid).Scan(&histFromname)
	// V1 parity: submit() calls inventEmail() to get/create the user's @users.ilovefreegle.org
	// proxy email, then sets messages.fromaddr to it. This address is checked by auto-repost,
	// chase-up, and other cron jobs via Mail::ourDomain().
	fromaddr := user.GetOrCreateInternalEmail(db, myid)

	db.Table("messages").Where("id = ?", req.ID).Update("fromaddr", fromaddr)

	// V1 parity: messages_history.fromaddr also uses the invented @users email, not the preferred email.
	db.Table("messages_history").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"msgid":    req.ID,
		"groupid":  groupid,
		"source":   gorm.Expr("'Platform'"),
		"fromuser": myid,
		"fromname": histFromname,
		"fromaddr": fromaddr,
		"subject":  histSubject,
		"arrival":  gorm.Expr("NOW()"),
		"fromip":   c.IP(),
	})

	db.Table("messages_drafts").Where("msgid = ?", req.ID).Delete(nil)

	// V1 parity: Message::submit() logs Message/Received with byuser=NULL
	// and text=messageid (RFC822 Message-Id header).
	logMessageReceived(db, groupid, myid, req.ID)

	// Do NOT add to messages_spatial here. Every post starts Pending (see above),
	// and messages_spatial backs the public browse/map — which is shown to all users,
	// including logged-out ones (see message.Bounds / message.Groups). So it must only
	// ever contain Approved messages. The message is added to the spatial index when it
	// becomes Approved: either the content-check batch job (messages:contentcheck)
	// auto-promotes it, or a moderator approves it (handleApprove). The poster still
	// sees their own pending post immediately via the fromuser branch of the browse query.

	// Check if user has a password (to determine if they're a new user).
	var hasPassword int64
	db.Table("users_logins").Where("userid = ? AND type = ?", myid, utils.LOGIN_TYPE_NATIVE).Count(&hasPassword)

	resp := fiber.Map{
		"ret":     0,
		"status":  "Success",
		"id":      req.ID,
		"groupid": groupid,
	}

	// Only for a poster submitting THEIR OWN draft. On the ChitChat convert
	// path the "new user" is the member and the response goes to the
	// moderator's client (and from there into the API response logs), so
	// minting a password here would hand out live credentials to the member's
	// account - observed doing exactly that on 2026-08-26 (Discourse #6999).
	// The member already has whatever login they signed up with; leave it be.
	if hasPassword == 0 && author == caller {
		// New user without a password — generate one and return it.
		password := utils.RandomHex(8)
		salt := auth.GetPasswordSalt()
		hashed := auth.HashPassword(password, salt)

		// uid must be the user ID (not email) so that VerifyPassword can find the row.
		db.Table("users_logins").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "credentials"}, Value: clause.Column{Table: "excluded", Name: "credentials"}},
				{Column: clause.Column{Name: "salt"}, Value: clause.Column{Table: "excluded", Name: "salt"}},
			},
		}).Create(map[string]interface{}{
			"userid":      myid,
			"type":        utils.LOGIN_TYPE_NATIVE,
			"uid":         myid,
			"credentials": hashed,
			"salt":        salt,
		})
		resp["newuser"] = true
		resp["newpassword"] = password
	}

	return c.JSON(resp)
}

// patchMessageRequest is the body for PATCH /message and PATCH /message/tn/:tnpostid.
type patchMessageRequest struct {
	ID                 uint64          `json:"id"`
	Subject            *string         `json:"subject"`
	Textbody           *string         `json:"textbody"`
	Type               *string         `json:"type"`
	Msgtype            *string         `json:"msgtype"`
	Messagetype        *string         `json:"messagetype"`
	Item               *string         `json:"item"`
	Availablenow       *int            `json:"availablenow"`
	Lat                *float64        `json:"lat"`
	Lng                *float64        `json:"lng"`
	Location           *string         `json:"location"`
	Locationid         *uint64         `json:"locationid"`
	Groupid            *uint64         `json:"groupid"`
	Attachments        AttachmentIDs   `json:"attachments"`
	BadAIImages        []uint64        `json:"badAIImages"`
	Deadline           *string         `json:"deadline"`
	Bulkitems          []BulkItemInput `json:"bulkitems"`
	Bulkslots          []string        `json:"bulkslots"`
	Accessinstructions *string         `json:"accessinstructions"`
}

// resolvePartnerAuth reads a ?partner= query param and resolves the acting user
// ID plus every candidate identity the partner's identifiers map to (a TN
// member can own two Freegle accounts - see user.FindTNCandidates). Returns
// (primary id, all candidates, error).
func resolvePartnerAuth(c *fiber.Ctx) (uint64, []uint64, error) {
	db := database.DBConn
	_, _, domain, err := user.ValidatePartnerKey(db, c.Query("partner"))
	if err != nil {
		return 0, nil, fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
	}

	email := c.Query("email")
	tnuseridStr := c.Query("tnuserid")
	var tnuserid uint64
	if tnuseridStr != "" {
		if v, err := strconv.ParseUint(tnuseridStr, 10, 64); err == nil {
			tnuserid = v
		}
	}

	if email != "" {
		parts := strings.SplitN(email, "@", 2)
		if len(parts) != 2 || parts[1] != domain {
			return 0, nil, fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
		}
	}

	candidates := user.FindTNCandidates(db, tnuserid, email)
	if len(candidates) == 0 {
		return 0, nil, fiber.NewError(fiber.StatusForbidden, "User not found for partner")
	}
	// Two candidates = the member's identity has diverged across two accounts.
	// The sync's job is to STOP divergence, not tolerate it: merge the twins
	// (falls back to the split candidates if the merge fails).
	candidates = user.HealTNDivergence(db, candidates)
	return candidates[0], candidates, nil
}

// actAsOwnerCandidate returns the message owner's id when the owner is one of
// the partner-resolved candidate identities, else the primary id. The
// partner's action on a message is legitimately the member's under whichever
// of their identities owns it.
func actAsOwnerCandidate(db *gorm.DB, primary uint64, candidates []uint64, msgID uint64) uint64 {
	if len(candidates) < 2 {
		return primary
	}
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgID).Scan(&fromuser)
	for _, cand := range candidates {
		if cand == fromuser {
			return fromuser
		}
	}
	return primary
}

// effLat/effLng are the CALLER's already-resolved coordinates, i.e. after
// the Locationid-driven DB lookup at site 5b7a006dd0a5 has already run (if
// it was going to) - this function only assembles the SET list from
// whatever the caller resolved, it does not decide whether that lookup
// happens. Locationid/Lat/Lng's cluster of 3 booleans (each present or not
// in the final SET list) has 8 combinations; the 7 non-empty ones are all
// reachable and are exactly what the retired message_fieldwise_tier9_test.go
// (removed in d22ba1d6c) declared as the group's forms:
//
//	LocationidOnly, LatOnly, LngOnly, LocationidLat, LocationidLng, LatLng,
//	LocationidLatLng
//
// (the 8th, all-absent, coincides with the site's "empty" case when no
// other field is set either, so it needs no form of its own).
//
// buildApplyPatchMessageCoreUpdateSet assembles the messages UPDATE's SET
// list as a clause.Set (a slice of clause.Assignment, gorm.io/gorm/clause),
// one assignment appended per field the request actually supplies - the same
// field-by-field branching the string-concatenation version this replaced
// used, just emitting an assignment instead of a "col = ?" text fragment +
// bound arg. clause.Set is order-preserving (it is a plain slice; Build()
// walks it in slice order, see gorm.io/gorm/clause/set.go), so the
// left-to-right assignment order MySQL evaluates a SET list in is exactly
// the order fields are appended below - unchanged from the string version.
//
// Previously kept raw with
// the reasoning that a dynamic SET list built by string concatenation has
// 2^n possible shapes and so cannot be a fixed GORM chain - true for a FIXED
// chain, but irrelevant here: the chain itself is fixed
// (.Table("messages").Where(...).Clauses(set).Updates(...)), only the
// PRE-BUILT clause.Set slice varies at runtime, exactly the way the SQL
// string used to. Proven against the identical fieldwise.json goldens
// already recorded for the string version (message_fieldwise_tier9_test.go),
// via the retired ormharness's AssertGoldenFieldwise (all removed in
// d22ba1d6c) - same n+2 cases, same golden SQL per
// case, now rendered by GORM instead of by hand.
func buildApplyPatchMessageCoreUpdateSet(subject, textbody, msgType, deadline *string, availablenow *int, locationid *uint64, effLat, effLng *float64) clause.Set {
	var set clause.Set

	if subject != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "subject"}, Value: *subject})
	}
	if textbody != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "textbody"}, Value: *textbody})
	}
	if msgType != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "type"}, Value: *msgType})
	}
	if availablenow != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "availablenow"}, Value: *availablenow})
	}
	if deadline != nil {
		if *deadline == "" || *deadline == "null" {
			set = append(set, clause.Assignment{Column: clause.Column{Name: "deadline"}, Value: gorm.Expr("NULL")})
		} else {
			// messages.deadline is a DATE column and bundled apps send a full ISO
			// datetime, which strict sql_mode rejects outright, silently losing the
			// deadline (Discourse #9481). Narrow it to a date before assigning.
			set = append(set, clause.Assignment{Column: clause.Column{Name: "deadline"}, Value: deadlineDate(*deadline)})
		}
	}
	if locationid != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "locationid"}, Value: *locationid})
	}
	if effLat != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "lat"}, Value: *effLat})
	}
	if effLng != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "lng"}, Value: *effLng})
	}

	return set
}

// applyPatchMessageCore performs the edit on a message without writing the HTTP response.
// Returns non-nil on failure. Callers are responsible for writing the success response.
func applyPatchMessageCore(c *fiber.Ctx, myid uint64, req patchMessageRequest, fromPartner bool) error {
	db := database.DBConn

	// Editing a clearance (bulk offer) is gated on the Clearance permission.
	if req.Bulkitems != nil && !auth.HasPermission(myid, auth.PERM_CLEARANCE) {
		return fiber.NewError(fiber.StatusForbidden, "You do not have permission to edit a clearance")
	}

	// Check ownership or mod permission.
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	isOwner := fromuser == myid
	isMod := isModForMessage(db, myid, req.ID)

	if !isOwner && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to modify this message")
	}

	// Get old values for edit tracking.
	type msgValues struct {
		Subject    string
		Textbody   string
		Type       string
		Locationid *uint64
	}
	var old msgValues
	db.Table("messages").Select("subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid").Where("id = ?", req.ID).Scan(&old)

	// Snapshot old item IDs as JSON (V1 stores item IDs array in olditems/newitems).
	type itemRow struct{ ID uint64 }
	var oldItemRows []itemRow
	db.Table("messages_items").Select("itemid AS id").Where("msgid = ?", req.ID).Order("itemid").Scan(&oldItemRows)
	oldItemIDs := make([]uint64, len(oldItemRows))
	for i, r := range oldItemRows {
		oldItemIDs[i] = r.ID
	}
	var oldItemsJSON *string
	if len(oldItemIDs) > 0 {
		b, _ := json.Marshal(oldItemIDs)
		s := string(b)
		oldItemsJSON = &s
	}

	// Snapshot old attachment IDs as JSON (V1 stores attachment IDs in oldimages/newimages).
	type attachRow struct{ ID uint64 }
	var oldAttachRows []attachRow
	db.Table("messages_attachments").Select("id").Where("msgid = ?", req.ID).Order("id").Scan(&oldAttachRows)
	oldAttachIDs := make([]uint64, len(oldAttachRows))
	for i, r := range oldAttachRows {
		oldAttachIDs[i] = r.ID
	}
	var oldImagesJSON *string
	if len(oldAttachIDs) > 0 {
		b, _ := json.Marshal(oldAttachIDs)
		s := string(b)
		oldImagesJSON = &s
	}

	// Build a single UPDATE with all changed fields - see
	// buildApplyPatchMessageCoreUpdateSet above (site 2de07c2af78b /
	// e9f2c662be69) for the SET list assembly itself, factored out for
	// fieldwise proof and built as a dynamic clause.Set.
	if req.Type != nil {
		// also update messages_groups.msgtype.
		db.Table("messages_groups").Where("msgid = ?", req.ID).Update("msgtype", *req.Type)
	}
	// Master's availablenow/deadline SET entries are not repeated here: this
	// branch assembles the whole SET list in
	// buildApplyPatchMessageCoreUpdateSet, which already covers both columns
	// with the same NULL-on-empty rule. Master's deadlineDate() conversion is
	// applied there rather than at this call site.
	// Resolve location name to locationid if provided.
	if req.Location != nil && *req.Location != "" && (req.Locationid == nil || *req.Locationid == 0) {
		var locID uint64
		db.Table("locations").Select("id").Where("name = ?", *req.Location).Limit(1).Scan(&locID)
		if locID > 0 {
			req.Locationid = &locID
		}
	}
	// A caller that supplies fresh coordinates without an explicit location name/id
	// (the TN partner only ever knows GPS coordinates for a post, never Freegle's
	// internal location rows) would otherwise leave locationid untouched. Since the
	// subject's derived "vague postcode" (constructLocationString) and the mod/owner
	// -facing location object are both read from locationid rather than lat/lng, that
	// left the displayed postcode pinned to whatever it was before the edit, and
	// uncorrectable, because every subsequent TN edit repeats the same gap. On
	// production, edited TN posts are ~17x more likely than never-edited ones to
	// have a locationid disagreeing with their own coordinates. Re-derive the
	// nearest postcode from the new coordinates,
	// mirroring the same lat/lng fallback already used when reading a message back.
	// Scoped to partner callers. The Freegle web client resolves its postcode
	// picker to a locationid before submitting, and ModTools edits a location by
	// name, so an unscoped derivation would only fire for a caller that sent
	// coordinates and no location at all - silently overwriting what they meant.
	if fromPartner && req.Lat != nil && req.Lng != nil && (req.Locationid == nil || *req.Locationid == 0) {
		nearest := location.ClosestPostcode(float32(*req.Lat), float32(*req.Lng))
		if nearest.ID > 0 {
			req.Locationid = &nearest.ID
		}
	}
	// Effective coordinates for this edit. Use the coords the client sent; but if the
	// location changed without matching coords, derive lat/lng from the chosen location.
	// Without this a location-only edit sets locationid yet leaves lat/lng stale or NULL,
	// making the post undiscoverable — browse/search read messages.lat/lng directly
	// (Discourse 9865). Locations are static reference data, so this lookup returns the
	// row reliably; it is not a timing/race concern.
	effLat, effLng := req.Lat, req.Lng
	if req.Locationid != nil && (effLat == nil || effLng == nil) {
		var llat, llng *float64
		db.Table("locations").Select("lat, lng").Where("id = ?", *req.Locationid).Row().Scan(&llat, &llng)
		if effLat == nil {
			effLat = llat
		}
		if effLng == nil {
			effLng = llng
		}
	}

	if set := buildApplyPatchMessageCoreUpdateSet(req.Subject, req.Textbody, req.Type, req.Deadline, req.Availablenow, req.Locationid, effLat, effLng); len(set) > 0 {
		db.Table("messages").Where("id = ?", req.ID).Clauses(set).Updates(map[string]interface{}{})
	}

	// Keep the spatial index point in sync when an already-indexed message's location
	// changes. We deliberately UPDATE only — never INSERT — so editing a Pending
	// message's location cannot leak it into messages_spatial (which backs the public
	// browse). Only Approved messages have a spatial row; the approval path inserts.
	if effLat != nil && effLng != nil {
		db.Table("messages_spatial").
			Where("msgid = ?", req.ID).
			Update("point", gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)", *effLng, *effLat))
	}

	// PHP parity (message.php:371-372): when a groupid is supplied, persist it to
	// messages_drafts so the subsequent JoinAndPost reads the user's chosen group
	// rather than the original one from RejectToDraft.  Without this, the group
	// change is silently dropped and the message is reposted to the wrong community.
	// The UPDATE is a no-op when the message is not in draft state (0 rows affected).
	if req.Groupid != nil && *req.Groupid > 0 {
		var groupExists int64
		db.Table("groups").Where("id = ?", *req.Groupid).Count(&groupExists)
		if groupExists > 0 {
			db.Table("messages_drafts").Where("msgid = ?", req.ID).Update("groupid", *req.Groupid)
		}
	}

	// If the user is setting a future deadline, clear any Expired outcome so the post
	// becomes active again (batch job marks posts Expired when deadline passes; extending
	// the deadline should move the post back out of "Old Posts").
	// Note: only Expired is cleared — Taken/Received/Withdrawn outcomes are permanent.
	// messages_outcomes_intended is deliberately NOT touched here: an in-progress intended
	// outcome (e.g. user started marking a post Taken but didn't finish) is unrelated to
	// extending a deadline and must not be silently discarded.
	// The string comparison works because ISO 8601 date/datetime strings sort lexicographically
	// in date order when zero-padded to the same precision — any future YYYY-MM-DD or
	// YYYY-MM-DDTHH:MM:SS.sssZ value will compare greater than today's YYYY-MM-DD string.
	if req.Deadline != nil && *req.Deadline != "" && *req.Deadline != "null" {
		today := time.Now().Format("2006-01-02")
		if *req.Deadline > today {
			db.Table("messages_outcomes").Where("msgid = ? AND outcome = 'Expired'", req.ID).Delete(nil)
		}
	}

	// Update item if provided.
	if req.Item != nil && *req.Item != "" {
		var itemID uint64
		db.Table("items").Select("id").Where("name = ?", *req.Item).Scan(&itemID)
		if itemID == 0 {
			// Genuinely new item — insert it. ON DUPLICATE KEY handles a concurrent/lagged
			// insert; read the id from the write result, not a read-split-routable SELECT (9832).
			// See 3cbad581b884
			// (PutMessageAs) for why gorm.WithResult() rather than "@id" is
			// needed for the LAST_INSERT_ID(id) idiom.
			itemRes := gorm.WithResult()
			db.Table("items").Clauses(itemRes, clause.OnConflict{
				DoUpdates: clause.Set{
					{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				},
			}).Create(map[string]interface{}{"name": *req.Item})
			if itemRes.Result != nil {
				if id, idErr := itemRes.Result.LastInsertId(); idErr == nil {
					itemID = uint64(id)
				}
			}
		}
		// Do NOT update items.name when found by case-insensitive match.
		// items is a shared canonical dictionary; normalising the casing from a single
		// message edit would flip-flop the name globally every time a different mod
		// happens to use a different casing. The subject is rebuilt below using the
		// explicitly-provided req.Item string, so the desired casing is preserved in
		// messages.subject without touching the shared dictionary.
		if itemID > 0 {
			db.Table("messages_items").Where("msgid = ?", req.ID).Delete(nil)
			db.Table("messages_items").Create(map[string]interface{}{"msgid": req.ID, "itemid": itemID})
		}
	}

	// Reconstruct subject from type + item + location when item/type/location changed,
	// but ONLY when the caller did not supply an explicit subject.  An explicit subject
	// always wins — passing msgtype alongside a new subject must not silently clobber it.
	if req.Subject == nil && (req.Item != nil || req.Type != nil || req.Location != nil || req.Locationid != nil) {
		var msgType string
		var itemName *string
		db.Table("messages").Select("type").Where("id = ?", req.ID).Scan(&msgType)
		if req.Item != nil && *req.Item != "" {
			// Use the submitted name directly so the moderator's desired casing is
			// preserved in the subject without altering the shared items dictionary.
			itemName = req.Item
		} else {
			db.Table("items i").
				Select("i.name").
				Joins("INNER JOIN messages_items mi ON mi.itemid = i.id").
				Where("mi.msgid = ?", req.ID).
				Limit(1).
				Scan(&itemName)
		}

		// Build the location string using area + vague postcode.
		locStr := constructLocationString(db, req.ID)

		if itemName != nil && locStr != "" {
			// Use the group keyword for the type (V1: group settings, defaults to
			// uppercase). Prefer the contextual group from the request — keywords
			// can differ per group — falling back to the primary group for legacy
			// callers that don't supply one.
			groupid := uint64(0)
			if req.Groupid != nil && *req.Groupid > 0 {
				groupid = *req.Groupid
			}
			if groupid == 0 {
				groupid = getPrimaryGroupForMessage(db, req.ID)
			}
			keyword := getGroupKeyword(db, groupid, msgType)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			// Identical golden to
			// a218fb801dd5 (JoinAndPostAs) and b53892a17f40 (PutMessageAs);
			// converted together per gate (h).
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"subject": newSubject, "suggestedsubject": newSubject})
		}
	}

	// Issue 1: If the message OWNER edits a rejected message, move back to Pending for re-review.
	// Mods editing a rejected message should NOT auto-resubmit it.
	if fromuser == myid {
		db.Table("messages_groups").Where("msgid = ? AND collection = ?", req.ID, utils.COLLECTION_REJECTED).
			Update("collection", utils.COLLECTION_PENDING)
	}

	// Issue 2: Log the edit (type='Message', subtype='Edit').
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_EDIT, 0, fromuser, myid, req.ID, 0, "Message edited")

	// Update attachment ordering if provided.
	// req.Attachments is nil when the field is absent from JSON (don't touch).
	// req.Attachments is [] (empty, non-nil) when all attachments are removed (#338).
	if req.Attachments != nil {
		recordAIDeletions(db, myid, req.ID, req.Attachments, req.BadAIImages)

		if len(req.Attachments) > 0 {
			// If the keep-list has both AI and non-AI attachments, drop the AI ones.
			// A user uploading their own photo always supersedes the AI illustration.
			type attachExtern struct {
				ID           uint64
				Externalmods string
			}
			var attRows []attachExtern
			db.Table("messages_attachments").Select("id, COALESCE(externalmods, '') AS externalmods").
				Where("id IN ? AND msgid = ?", req.Attachments, req.ID).Scan(&attRows)
			externByID := make(map[uint64]string, len(attRows))
			for _, r := range attRows {
				externByID[r.ID] = r.Externalmods
			}
			hasNonAI := false
			for _, attid := range req.Attachments {
				if !strings.Contains(externByID[attid], `"ai":true`) {
					hasNonAI = true
					break
				}
			}
			if hasNonAI {
				var filtered []uint64
				for _, attid := range req.Attachments {
					if !strings.Contains(externByID[attid], `"ai":true`) {
						filtered = append(filtered, attid)
					}
				}
				req.Attachments = filtered
			}

			for i, attid := range req.Attachments {
				primary := i == 0
				db.Table("messages_attachments").Where("id = ?", attid).
					Updates(map[string]interface{}{"msgid": req.ID, "primary": primary})
			}
			// Delete any attachments not in the new list.
			db.Table("messages_attachments").Where("msgid = ? AND id NOT IN (?)", req.ID, req.Attachments).Delete(nil)
		} else {
			// Empty array — remove all attachments.
			// Identical golden to
			// cebe07bfb873 (PatchMessageByTN); converted together per gate (h).
			db.Table("messages_attachments").Where("msgid = ?", req.ID).Delete(nil)
		}
	}

	// If subject, type, or textbody changed and user is not mod, create edit record for review.
	// Re-read the current subject from DB — it may have been reconstructed from type/item/location
	// changes above (line 1830-1846), so req.Subject alone is insufficient.
	var current msgValues
	db.Table("messages").Select("subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid").Where("id = ?", req.ID).Scan(&current)

	// Snapshot new item IDs as JSON (after item update).
	var newItemRows []itemRow
	db.Table("messages_items").Select("itemid AS id").Where("msgid = ?", req.ID).Order("itemid").Scan(&newItemRows)
	newItemIDs := make([]uint64, len(newItemRows))
	for i, r := range newItemRows {
		newItemIDs[i] = r.ID
	}
	var newItemsJSON *string
	if len(newItemIDs) > 0 {
		b, _ := json.Marshal(newItemIDs)
		s := string(b)
		newItemsJSON = &s
	}

	// Snapshot new attachment IDs as JSON (after attachment update).
	var newAttachRows []attachRow
	db.Table("messages_attachments").Select("id").Where("msgid = ?", req.ID).Order("id").Scan(&newAttachRows)
	newAttachIDs := make([]uint64, len(newAttachRows))
	for i, r := range newAttachRows {
		newAttachIDs[i] = r.ID
	}
	var newImagesJSON *string
	if len(newAttachIDs) > 0 {
		b, _ := json.Marshal(newAttachIDs)
		s := string(b)
		newImagesJSON = &s
	}

	subjectChanged := current.Subject != old.Subject
	textChanged := current.Textbody != old.Textbody
	typeChanged := current.Type != old.Type
	locationChanged := !locationIDsEqual(old.Locationid, current.Locationid)
	itemsChanged := !stringPtrEqual(oldItemsJSON, newItemsJSON)
	imagesChanged := !stringPtrEqual(oldImagesJSON, newImagesJSON)

	// Subject, textbody, and item name are exactly the fields
	// ContentCheckService::checkMessage() scans (concern keywords, per-group
	// worry words, phone numbers, vague-item, not-an-item, URLs, ...). A row
	// that has already been checked is never re-scanned on its own, so editing
	// in new content would otherwise leave the automated moderation filters
	// silently skipped, catchable only by a mod noticing by hand. Stamp
	// messages.editedat: the batch derives "checked, then edited" from
	// editedat > contentcheck_checked_at and re-scans, for both mods and
	// owners - a mod stripping the issue that triggered a flag also needs the
	// clean edit re-verified. Deriving from the edit audit stamp rather than
	// keeping a separate mark means the state cannot drift, and needs no
	// schema beyond columns that already exist.
	//
	// Stamping, NOT clearing contentcheck_checked_at. That stamp doubles as
	// "safe to show a moderator": the Pending list (message_list.go) and the
	// work counts (groupWork.go, session.go) hide rows that have never been
	// checked, so a brand-new post is not shown before the checks have had
	// their say. Clearing it on edit made the post the moderator had just
	// edited vanish out of their own queue - card and badge together - until
	// the batch re-stamped it half a minute later, reappearing only on a
	// manual reload (Discourse 10001).
	//
	// The stored reasons still go, as they always did: they are what ModTools
	// shows as "why is this pending", and a reason the mod has just edited out
	// is worse than no reason at all - another mod could reject a post over a
	// problem that is no longer there. The recheck writes the true set back.
	if subjectChanged || textChanged || itemsChanged {
		db.Table("messages").Where("id = ?", req.ID).
			Updates(map[string]interface{}{
				"editedat": gorm.Expr("NOW()"),
				"editedby": myid,
			})
		db.Table("messages_groups").Where("msgid = ?", req.ID).
			Updates(map[string]interface{}{
				"contentcheck_reasons": gorm.Expr("NULL"),
			})
	}

	// The subject/body drive the search indexes (messages_index keyword search and
	// messages_embeddings vector search), which are each populated once for "missing"
	// messages and never refreshed on edit. Drop the stale rows for ANY editor (owner or
	// mod) so the background indexer/embedder rebuild from the new text. Discourse 9954.
	if subjectChanged || textChanged {
		invalidateMessageSearchIndexes(db, req.ID, subjectChanged, textChanged)
	}

	if (subjectChanged || textChanged || typeChanged || locationChanged || itemsChanged || imagesChanged) && !isMod {
		// Store oldtype/newtype only when type actually changed.
		var oldType, newType interface{}
		if typeChanged {
			oldType = old.Type
			newType = current.Type
		}

		// Store oldsubject/newsubject only when subject actually changed.
		var oldSubject, newSubject interface{}
		if subjectChanged {
			oldSubject = old.Subject
			newSubject = current.Subject
		}

		// Store oldtext/newtext only when body actually changed.
		var oldText, newText interface{}
		if textChanged {
			oldText = old.Textbody
			newText = current.Textbody
		}

		// Store olditems/newitems only when items changed (V1 parity: JSON array of item IDs).
		var oldItemsVal, newItemsVal interface{}
		if itemsChanged {
			oldItemsVal = oldItemsJSON
			newItemsVal = newItemsJSON
		}

		// Store oldimages/newimages only when attachments changed (V1 parity: JSON array of attachment IDs).
		var oldImagesVal, newImagesVal interface{}
		if imagesChanged {
			oldImagesVal = oldImagesJSON
			newImagesVal = newImagesJSON
		}

		// Store oldlocation/newlocation only when locationid changed.
		var oldLocationVal, newLocationVal interface{}
		if locationChanged {
			oldLocationVal = old.Locationid
			newLocationVal = current.Locationid
		}

		// V1 parity: reviewrequired is only set when the message is Approved
		// AND the member's posting status would put them in Pending (i.e. they
		// are moderated). Unmoderated members' edits go live with no review.
		reviewRequired := 0
		groupIDs := getAllGroupsForMessage(db, req.ID)

		for _, gid := range groupIDs {
			// Check if the message is currently Approved on this group.
			var collection string
			db.Table("messages_groups").Select("collection").Where("msgid = ? AND groupid = ?", req.ID, gid).Scan(&collection)

			if strings.EqualFold(collection, "Approved") {
				// Check if the group is set to moderate all posts.
				var groupModerated, groupClosed int
				db.Table("groups").Select("COALESCE(JSON_EXTRACT(settings, '$.moderated'), 0), COALESCE(JSON_EXTRACT(settings, '$.closed'), 0)").Where("id = ?", gid).Row().Scan(&groupModerated, &groupClosed)

				if groupModerated == 1 || groupClosed == 1 {
					// Group moderates all posts — this edit needs review.
					reviewRequired = 1
				} else {
					// Check the member's individual posting status.
					var postingStatus *string
					db.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", myid, gid).Scan(&postingStatus)

					// NULL, empty, or MODERATED → member is moderated → review required.
					if postingStatus == nil || *postingStatus == "" || strings.EqualFold(*postingStatus, "MODERATED") || strings.EqualFold(*postingStatus, "PROHIBITED") {
						reviewRequired = 1
					}
				}
			}
		}

		db.Table("messages_edits").Create(map[string]interface{}{
			"msgid": req.ID, "byuser": myid, "oldsubject": oldSubject, "newsubject": newSubject,
			"oldtype": oldType, "newtype": newType, "oldtext": oldText, "newtext": newText,
			"olditems": oldItemsVal, "newitems": newItemsVal, "oldimages": oldImagesVal, "newimages": newImagesVal,
			"oldlocation": oldLocationVal, "newlocation": newLocationVal, "reviewrequired": reviewRequired,
		})
		db.Table("messages").Where("id = ?", req.ID).Update("editedby", myid)

		// Only notify mods when review is required.
		if reviewRequired == 1 {
			for _, gid := range groupIDs {
				if err := queue.QueueTask(queue.TaskPushNotifyGroupMods, map[string]interface{}{
					"group_id": gid,
				}); err != nil {
					log.Printf("Failed to queue push notification for group %d on edit review: %v", gid, err)
				}
			}
		}
	}

	// Bulk offer: rebuild the structured catalogue (attachments are already
	// relinked above) and keep availableinitially/availablenow in sync with the
	// total quantity. A nil slice leaves the catalogue untouched; an explicit
	// (possibly empty) slice rebuilds it, including resetting availability to 0
	// when all items are removed. The textbody summary is rebuilt too unless the
	// caller supplied their own textbody.
	if req.Bulkitems != nil {
		total := upsertBulkItems(db, req.ID, req.Bulkitems)
		// Identical golden to
		// 9d1cfd7098bc (PutMessageAs); converted together per gate (h).
		db.Table("messages").Where("id = ?", req.ID).
			Updates(map[string]interface{}{"availableinitially": total, "availablenow": total})
		if req.Textbody == nil {
			if summary := buildBulkSummary(req.Bulkitems, req.Bulkslots); summary != "" {
				// Identical golden to
				// 9beaa0265ff1 (PutMessageAs); converted together per gate (h).
				db.Table("messages").Where("id = ?", req.ID).
					Updates(map[string]interface{}{"textbody": summary, "message": summary})
			}
		}
		go ingestBulkItemPhotos(db, req.ID)
	}
	if req.Bulkslots != nil {
		upsertBulkSlots(db, req.ID, req.Bulkslots)
	}
	if req.Accessinstructions != nil {
		saveAccessInstructions(db, req.ID, *req.Accessinstructions)
	}

	return nil
}

// applyPatchMessage performs the edit on a message after auth and ID are resolved.
func applyPatchMessage(c *fiber.Ctx, myid uint64, req patchMessageRequest) error {
	if err := applyPatchMessageCore(c, myid, req, false); err != nil {
		return err
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// PatchMessage updates a message (PATCH /message).
//
// @Summary Update a message
// @Tags message
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/message [patch]
func PatchMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	var partnerCandidates []uint64
	if c.Query("partner") != "" {
		var err error
		myid, partnerCandidates, err = resolvePartnerAuth(c)
		if err != nil {
			return err
		}
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req patchMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Type == nil && req.Msgtype != nil {
		req.Type = req.Msgtype
	}
	if req.Type == nil && req.Messagetype != nil {
		req.Type = req.Messagetype
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	myid = actAsOwnerCandidate(database.DBConn, myid, partnerCandidates, req.ID)

	return applyPatchMessage(c, myid, req)
}

// PatchMessageByTN updates a message by TN post ID (PATCH /message/tn/:tnpostid).
func PatchMessageByTN(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	var partnerCandidates []uint64
	if c.Query("partner") != "" {
		var err error
		myid, partnerCandidates, err = resolvePartnerAuth(c)
		if err != nil {
			return err
		}
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	tnpostid := c.Params("tnpostid")
	if tnpostid == "" {
		return fiber.NewError(fiber.StatusBadRequest, "tnpostid is required")
	}

	db := database.DBConn
	var msgIDs []uint64
	db.Table("messages").Select("id").Where("tnpostid = ?", tnpostid).Scan(&msgIDs)
	if len(msgIDs) == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found for that TN post ID")
	}

	var req patchMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.Type == nil && req.Msgtype != nil {
		req.Type = req.Msgtype
	}
	if req.Type == nil && req.Messagetype != nil {
		req.Type = req.Messagetype
	}

	// TN never sends an explicit attachments array.  We detect photo changes
	// through the textbody:
	//   • No trashnothing.com/pics/ links → user removed all photos → remove AI.
	//   • Links present → scrape+store TN photos AND remove AI (TN photos replace it).
	// In both cases the AI attachment must go; in the second case we also strip the
	// "Check out the pictures…" block from the stored textbody and kick off scraping.
	//
	// This MUST be computed once, from the original textbody, BEFORE the per-message
	// loop below.  A tnpostid can map to several crossposted FD messages; if we
	// extracted the links and stripped req.Textbody inside the loop, the first
	// iteration would remove the pic links from req.Textbody, so every subsequent
	// crossposted copy would read an already-stripped body, find no links, and have
	// its attachments deleted but never re-scraped — leaving all but the first copy
	// with no photo.
	var picPageURLs []string
	if req.Textbody != nil {
		picPageURLs = tnPicPageURLRegexp.FindAllString(*req.Textbody, -1)
		if len(picPageURLs) > 0 {
			// Strip the photo-link block before persisting the textbody.
			stripped := tnPicHeaderRegexp.ReplaceAllString(*req.Textbody, "")
			stripped = tnPicURLLineRegexp.ReplaceAllString(stripped, "")
			stripped = strings.TrimSpace(stripped)
			req.Textbody = &stripped
		}
	}

	for _, msgID := range msgIDs {
		req.ID = msgID
		// A crossposted TN post's copies all belong to the same TN member, but
		// that member may own two Freegle accounts - act as whichever owns
		// this copy.
		actingid := actAsOwnerCandidate(db, myid, partnerCandidates, msgID)

		// Attachments must reach their final state BEFORE the edit's change signal is
		// written.  applyPatchMessageCore writes the messages_edits row that /api/changes
		// reports as "Edited"; TN then fetches the message to read its attachments.  If we
		// scraped after the signal (or asynchronously), TN could poll in the gap and get a
		// partial photo set — the "only 1 photo back, all of them on a forced re-fetch" bug.
		// So we delete the old attachments and synchronously scrape the new ones first, then
		// call the core.  This matches V1, which scrapes + saves attachments before edit()
		// (http/api/message.php).
		//
		// TN's textbody is the authoritative photo set for its posts.  Whenever TN sends a
		// textbody (even an empty one) we delete ALL existing attachments and then let the
		// scraper add the new set (if pic links were present).  This fixes two further bugs:
		//   #2 — textbody with no pic links: old non-AI photos were left behind.
		//   #3 — textbody with new pic links: old non-AI photos coexisted with the new ones.
		//
		// recordAIDeletions is called with an empty keep-list so any AI attachment is
		// properly logged (microaction + messages_ai_declined) before deletion.
		if req.Textbody != nil {
			recordAIDeletions(db, actingid, msgID, []uint64{}, nil)
			// Identical golden to
			// 8ef16859487a (applyPatchMessageCore); converted together per gate (h).
			db.Table("messages_attachments").Where("msgid = ?", msgID).Delete(nil)
		}

		// If TN photos were present, scrape them and store as attachments (synchronously).
		if len(picPageURLs) > 0 {
			TNPhotoScrapeRunner(db, msgID, picPageURLs)
		}

		if err := applyPatchMessageCore(c, actingid, req, true); err != nil {
			return err
		}
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// DeleteMessageEndpoint handles DELETE /message/:id.
//
// @Summary Delete a message
// @Tags message
// @Produce json
// @Param id path integer true "Message ID"
// @Success 200 {object} map[string]interface{}
// @Router /api/message/{id} [delete]
func DeleteMessageEndpoint(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	id, err := c.ParamsInt("id")
	if err != nil || id <= 0 {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid message ID")
	}
	msgid := uint64(id)

	db := database.DBConn

	// Check ownership.
	var fromuser uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgid).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	isMod := fromuser != myid && isModForMessage(db, myid, msgid)
	if fromuser != myid && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to delete this message")
	}

	// Identical golden to 73672934d660
	// (handleSpam); converted together per gate (h).
	db.Table("messages").Where("id = ?", msgid).Update("deleted", gorm.Expr("NOW()"))

	// Write audit-log entry when a moderator deletes a message. Log against the
	// group the mod acted on when supplied (?groupid=), else fall back to the
	// primary group.
	if isMod {
		groupid := uint64(c.QueryInt("groupid", 0))
		if groupid == 0 {
			groupid = getPrimaryGroupForMessage(db, msgid)
		}
		logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, groupid, fromuser, myid, msgid, 0, "")
	}

	// Remove from freebiealerts.app — post is no longer available.
	if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
		"msgid": msgid,
	}); err != nil {
		log.Printf("Failed to queue freebie alerts remove for message %d: %v", msgid, err)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// findOrCreateUserForDraft looks up a user by email, or creates one if not found.
// Returns the user ID, JWT string, persistent token map, and any error.
// This supports the give/want flow where users post without signing up first.
//
// SECURITY: For existing users, we do NOT create a session/JWT. Knowing someone's
// email address must not grant authentication. A session is only created for
// brand-new users.
func findOrCreateUserForDraft(db *gorm.DB, email string) (uint64, string, fiber.Map, error) {
	email = strings.TrimSpace(email)

	// Basic email validation.
	if !strings.Contains(email, "@") || len(email) > 254 {
		return 0, "", nil, fmt.Errorf("invalid email address")
	}

	// Look up existing user by email.
	var existingUID uint64
	db.Table("users_emails").Select("userid").Where("email = ?", email).Limit(1).Scan(&existingUID)

	if existingUID > 0 {
		// Existing user — return their ID so the draft is linked to them,
		// but do NOT create a session.  The user must authenticate separately.
		return existingUID, "", nil, nil
	}

	// New user — create user, email, session, JWT.
	// Plain, isolated, literal single-row
	// INSERT; id read back via GORM's map-Create "@id" writeback, which reads the
	// id back from the very connection that ran the INSERT (proven in
	// test/insertid_gorm_writeback_test.go).
	userRow := map[string]interface{}{"added": gorm.Expr("NOW()")}
	if err := db.Table("users").Create(userRow).Error; err != nil {
		return 0, "", nil, fmt.Errorf("failed to create user: %w", err)
	}
	newUserIDInt, _ := userRow["@id"].(int64)
	if newUserIDInt == 0 {
		return 0, "", nil, fmt.Errorf("failed to get new user ID")
	}
	newUserID := uint64(newUserIDInt)

	// Add email.
	// Plain, isolated, literal single-row
	// INSERT; no id readback needed here.
	canon := user.CanonicalizeEmail(email)
	db.Table("users_emails").Create(map[string]interface{}{
		"userid":    newUserID,
		"email":     email,
		"preferred": gorm.Expr("1"),
		"validated": gorm.Expr("NOW()"),
		"canon":     canon,
		"backwards": user.ReverseString(canon),
	})

	// Create session. series must be a random numeric value (bigint
	// unsigned); using userID collided across every session for the same
	// user and defeated UNIQUE KEY (id, series, token).
	series := utils.RandomUint64()
	token := utils.RandomHex(16)
	// Plain, isolated, literal single-row
	// INSERT; id read back via GORM's map-Create "@id" writeback, which reads the
	// id back from the write connection that ran the INSERT (proven in
	// test/insertid_gorm_writeback_test.go), same guarantee ExecInsertGetID
	// gave. A
	// "SELECT id ... ORDER BY id DESC" here would be routed to a read replica
	// under the read/write split and could return a stale/0 id (Discourse 9832
	// class), embedding a wrong sessionid in the JWT below - which is why this
	// stays on the id-writeback mechanism rather than a separate lookup.
	sessionRow := map[string]interface{}{
		"userid":     newUserID,
		"series":     series,
		"token":      token,
		"lastactive": gorm.Expr("NOW()"),
	}
	if err := db.Table("sessions").Create(sessionRow).Error; err != nil {
		return 0, "", nil, fmt.Errorf("failed to create session: %w", err)
	}
	sessionIDInt, _ := sessionRow["@id"].(int64)
	sessionID := uint64(sessionIDInt)

	// Generate JWT.
	jwtToken := jwt.NewWithClaims(jwt.SigningMethodHS256, jwt.MapClaims{
		"id":        fmt.Sprint(newUserID),
		"sessionid": fmt.Sprint(sessionID),
		"exp":       time.Now().Unix() + 30*24*60*60,
	})
	jwtString, err := jwtToken.SignedString([]byte(os.Getenv("JWT_SECRET")))
	if err != nil {
		return 0, "", nil, err
	}

	persistent := fiber.Map{
		"id":     sessionID,
		"series": series,
		"token":  token,
		"userid": newUserID,
	}
	return newUserID, jwtString, persistent, nil
}

// PutMessage creates a new message draft (PUT /message).
// Accepts both authenticated and unauthenticated requests (with email).
// For unauthenticated requests, finds or creates the user by email.
//
// @Summary Create or update a message
// @Tags message
// @Accept json
// @Produce json
// @Success 200 {object} map[string]interface{}
// @Router /api/message [put]
// PutMessage creates a message for the caller, or for another member when a
// ChitChat moderator is converting their ChitChat post into a real OFFER/WANTED.
func PutMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	author, err := onBehalfOf(c, myid)
	if err != nil {
		return err
	}

	return PutMessageAs(c, author)
}

// onBehalfOf resolves the ?onbehalfof= parameter to the member a post belongs
// to. Absent (the normal case) it is the caller.
//
// Posting as someone else is restricted to ChitChat moderators and
// support/admin, and the check lives here rather than in each caller so no
// route can acquire the capability by omission.
// OnBehalfPosting is where a post made on someone else's behalf would land:
// their own postcode and their own community, never the moderator's.
type OnBehalfPosting struct {
	Locationid   uint64 `json:"locationid"`
	Locationname string `json:"locationname"`
	Groupid      uint64 `json:"groupid"`
	Groupname    string `json:"groupname"`
	// Moderated says the post will WAIT in Pending for a human moderator
	// rather than being auto-promoted by the content check - true for a
	// fully-moderated group or a member with no/MODERATED/PROHIBITED
	// posting status (V1 User::postToCollection semantics, the same answer
	// the batch content check gives). The modal warns the converting
	// moderator, because a post they cannot then see looks like a failed
	// convert (Discourse #6999).
	Moderated bool `json:"moderated"`
}

// ResolveOnBehalfPosting works out the location and group a post for `author`
// would use. PutMessageAs calls it when it actually posts, and the convert
// preview calls it to show the moderator the same answer beforehand - one
// function so the preview cannot promise a postcode the post then ignores.
//
// The location is the one the member CHOSE, settings.mylocation - the same
// postcode their own posts carry. Deliberately not derived from lastlocation or
// a nearest-postcode lookup: those say where they last were, not where they say
// they are, so they would stamp a postcode on a member's post that the member
// never picked. If they have not set one, we refuse rather than guess.
//
// The error text is shown to the moderator, so it says what to do about it.
func ResolveOnBehalfPosting(author uint64) (*OnBehalfPosting, error) {
	db := database.DBConn

	var chosen struct {
		Locationid   uint64
		Locationname string
		Lat          float64
		Lng          float64
	}

	db.Table("users").
		Select("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.id')) AS locationid, "+
			"JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.name')) AS locationname, "+
			"JSON_EXTRACT(settings, '$.mylocation.lat') AS lat, "+
			"JSON_EXTRACT(settings, '$.mylocation.lng') AS lng").
		Where("id = ?", author).Scan(&chosen)

	if chosen.Locationid == 0 || chosen.Locationname == "" {
		return nil, errors.New("That member hasn't set their location, so we can't post for them - ask them to set it first")
	}

	// The group must be one they are ALREADY in. Submitting joins the author to
	// the destination group, so an arbitrary group would quietly sign a member
	// up to a community they never chose. Default to their nearest membership.
	var groupid uint64
	// Order() itself takes no bind
	// args (clause/order_by.go's OrderByColumn has no Vars field), so the two
	// binds in ST_Distance_Sphere(...) go through clause.OrderBy{Expression:
	// gorm.Expr(...)} instead, which Order() passes straight to AddClause.
	db.Table("memberships m").
		Select("m.groupid").
		Joins("INNER JOIN `groups` g ON g.id = m.groupid").
		Where("m.userid = ? AND m.collection = ?", author, utils.COLLECTION_APPROVED).
		Order(clause.OrderBy{Expression: gorm.Expr("ST_Distance_Sphere(POINT(g.lng, g.lat), POINT(?, ?))", chosen.Lng, chosen.Lat)}).
		Limit(1).
		Scan(&groupid)

	if groupid == 0 {
		return nil, errors.New("That member isn't in any community, so we can't post for them")
	}

	var groupname string
	db.Table("groups").Select("COALESCE(NULLIF(namefull, ''), nameshort)").Where("id = ?", groupid).Scan(&groupname)

	return &OnBehalfPosting{
		Locationid:   chosen.Locationid,
		Locationname: chosen.Locationname,
		Groupid:      groupid,
		Groupname:    groupname,
		Moderated:    postingWouldBeModerated(author, groupid),
	}, nil
}

// postingWouldBeModerated says whether a post by author on groupid waits in
// Pending for a human moderator. Same tests the content check batch job
// applies (and applyPatchMessageCore's edit-review path above): the group's
// "moderate everything" setting, else the member's posting status, where no
// membership row, NULL, empty, MODERATED and PROHIBITED all mean a human
// looks first.
func postingWouldBeModerated(author uint64, groupid uint64) bool {
	db := database.DBConn

	var groupModerated, groupClosed int
	db.Table("groups").Select("COALESCE(JSON_EXTRACT(settings, '$.moderated'), 0), COALESCE(JSON_EXTRACT(settings, '$.closed'), 0)").Where("id = ?", groupid).Row().Scan(&groupModerated, &groupClosed)
	if groupModerated == 1 || groupClosed == 1 {
		return true
	}

	var ps *string
	db.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", author, groupid).Scan(&ps)

	return ps == nil || *ps == "" ||
		strings.EqualFold(*ps, utils.POSTING_STATUS_MODERATED) ||
		strings.EqualFold(*ps, utils.POSTING_STATUS_PROHIBITED)
}

func onBehalfOf(c *fiber.Ctx, myid uint64) (uint64, error) {
	obo := uint64(c.QueryInt("onbehalfof", 0))
	if obo == 0 || obo == myid {
		return myid, nil
	}

	if !auth.IsChitChatMod(myid) {
		return 0, fiber.NewError(fiber.StatusForbidden, "Permission denied")
	}

	return obo, nil
}

// PutMessageAs creates a message attributed to author, which is normally the
// caller. It differs only for the ChitChat convert-to-post path, where a
// ChitChat moderator turns someone's ChitChat post into a real OFFER/WANTED and
// the post must belong to that member rather than to the moderator.
//
// Passing an author other than the caller is restricted to ChitChat moderators
// and support/admin (newsfeed.canHidePost). That caller is responsible for the
// permission check and for logging the mod action; no other caller should pass
// a different author.
func PutMessageAs(c *fiber.Ctx, author uint64) error {
	myid := author

	type PutMessageRequest struct {
		Groupid            uint64          `json:"groupid"`
		Type               string          `json:"type"`
		Messagetype        string          `json:"messagetype"` // Client sends this; alias for Type.
		Subject            string          `json:"subject"`
		Item               string          `json:"item"`
		Textbody           string          `json:"textbody"`
		Collection         string          `json:"collection"` // Draft (default) or Pending.
		Locationid         *uint64         `json:"locationid"`
		Availableinitially *int            `json:"availableinitially"`
		Availablenow       *int            `json:"availablenow"`
		Attachments        AttachmentIDs   `json:"attachments"`
		Email              string          `json:"email"`
		Bulkitems          []BulkItemInput `json:"bulkitems"`
		Bulkslots          []string        `json:"bulkslots"`
		Accessinstructions string          `json:"accessinstructions"`
	}

	var req PutMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	// Posting a clearance (bulk offer) is gated on the Clearance permission.
	if len(req.Bulkitems) > 0 && !auth.HasPermission(myid, auth.PERM_CLEARANCE) {
		return fiber.NewError(fiber.StatusForbidden, "You do not have permission to post a clearance")
	}

	// Handle messagetype alias from client.
	if req.Type == "" && req.Messagetype != "" {
		req.Type = req.Messagetype
	}

	// Generate subject from type + item if subject not provided.
	if req.Subject == "" && req.Item != "" {
		req.Subject = req.Type + ": " + req.Item
	}

	// Default to Draft collection (client compose flow creates drafts).
	if req.Collection == "" {
		req.Collection = "Draft"
	}

	db := database.DBConn

	// Handle unauthenticated user with email — find or create, then generate JWT.
	var jwtString string
	var persistent fiber.Map
	if myid == 0 && req.Email != "" {
		var err error
		myid, jwtString, persistent, err = findOrCreateUserForDraft(db, req.Email)
		if err != nil {
			if strings.Contains(err.Error(), "invalid email") {
				return fiber.NewError(fiber.StatusBadRequest, "Invalid email address")
			}
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create user")
		}
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	if req.Type != "Offer" && req.Type != "Wanted" {
		return fiber.NewError(fiber.StatusBadRequest, "type must be Offer or Wanted")
	}

	if strings.TrimSpace(req.Item) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Item is required")
	}

	// Posting on someone's behalf: pin the post to THEIR location, never to
	// whatever the moderator's client sent. A moderator moderates from wherever
	// they happen to be, so trusting the client here would stamp their postcode
	// on a member's post and put it in front of the wrong people.
	if author != user.WhoAmI(c) {
		// Same resolution the convert preview showed the moderator - see
		// ResolveOnBehalfPosting.
		posting, err := ResolveOnBehalfPosting(author)
		if err != nil {
			return fiber.NewError(fiber.StatusBadRequest, err.Error())
		}

		req.Locationid = &posting.Locationid

		if req.Groupid == 0 {
			req.Groupid = posting.Groupid
		} else {
			var memberCount int64
			db.Table("memberships").Where("userid = ? AND groupid = ?", author, req.Groupid).Count(&memberCount)
			if memberCount == 0 {
				return fiber.NewError(fiber.StatusBadRequest, "That member isn't in that community")
			}
		}
	}

	// For non-Draft, check membership and fetch posting status in one query.
	var ourPostingStatus *string
	var isMember bool
	if req.Collection != "Draft" && req.Groupid > 0 {
		type MembershipInfo struct {
			OurPostingStatus *string
		}
		var info MembershipInfo
		result := db.Table("memberships").Select("ourPostingStatus").Where("userid = ? AND groupid = ?", myid, req.Groupid).Limit(1).Scan(&info)
		if result.RowsAffected == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Not a member of this group")
		}
		isMember = true
		ourPostingStatus = info.OurPostingStatus
	}

	// PUT /message only accepted availablenow and set both fields
	// to that value. If only availablenow is provided, mirror it to
	// availableinitially so the frontend doesn't need to send both.
	availInit := 1
	if req.Availableinitially != nil {
		availInit = *req.Availableinitially
	} else if req.Availablenow != nil {
		availInit = *req.Availablenow
	}
	availNow := availInit
	if req.Availablenow != nil {
		availNow = *req.Availablenow
	}

	// Create message.
	fromip := c.IP()
	// Geolocate the IP to a country so ModTools can flag posts from outside the
	// UK (MessageHistory.vue). V1 (Message.php) did this at receive time; the
	// web submit path lost it when it moved to Go. Store the ISO code (NULL when
	// unknown); the read path expands it to a full name for display.
	var fromcountry *string
	if cc := utils.CountryCodeForIP(fromip); cc != "" {
		fromcountry = &cc
	}
	// V1 parity (Message.php:2708/2717): invent a unique messageid because
	// downstream dedupe/cross-reference joins assume it's populated.
	messageid := fmt.Sprintf("%.6f@%s", float64(time.Now().UnixNano())/1e9, utils.USER_DOMAIN)
	if req.Groupid > 0 {
		messageid = fmt.Sprintf("%s-%d", messageid, req.Groupid)
	}
	// Use the INSERT's own auto-increment id. A "SELECT id ... ORDER BY id DESC
	// LIMIT 1" here is unsafe under the read/write split: the SELECT is routed to
	// a read replica that may not yet have applied this INSERT, so it can return
	// the user's PREVIOUS message - causing the new post (and its photos) to be
	// grafted onto an existing one (Discourse 9832 "mixed up offers"). Read the id
	// back from the write connection via LastInsertId, as CreateGroup does.
	// Table()+map Create
	// reads the generated id back from the same sql.Result the INSERT
	// returned, under the map key "@id" - see
	// test/insertid_gorm_writeback_test.go.
	row := map[string]interface{}{
		"fromuser":           myid,
		"type":               req.Type,
		"subject":            req.Subject,
		"textbody":           req.Textbody,
		"message":            req.Textbody,
		"arrival":            gorm.Expr("NOW()"),
		"date":               gorm.Expr("NOW()"),
		"source":             gorm.Expr("'Platform'"),
		"availableinitially": availInit,
		"availablenow":       availNow,
		"locationid":         req.Locationid,
		"fromip":             fromip,
		"fromcountry":        fromcountry,
		"messageid":          messageid,
	}
	if err := db.Table("messages").Create(row).Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create message")
	}

	lastID, _ := row["@id"].(int64)
	if lastID <= 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to retrieve message ID")
	}
	newMsgID := uint64(lastID)

	// For Draft collection, store in messages_drafts.
	// For other collections, add to messages_groups.
	if req.Collection == "Draft" {
		// A draft can legitimately have no group yet (compose starts before a
		// group is chosen) and the schema says so: messages_drafts.groupid is
		// nullable with ON DELETE SET NULL. Passing the client's 0 straight
		// through failed the groups FK - and the error went unchecked, so the
		// draft silently didn't exist while the messages row survived as an
		// orphan, and the client's submit then 400'd and retried, minting
		// another orphan each time.
		var draftGroupid interface{}
		if req.Groupid > 0 {
			draftGroupid = req.Groupid
		}
		if err := db.Table("messages_drafts").Create(map[string]interface{}{
			"msgid": newMsgID, "groupid": draftGroupid, "userid": myid,
		}).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create draft")
		}
	} else if req.Groupid > 0 && isMember {
		// Determine collection based on user's posting status,
		// ignoring whatever the client sent. This prevents moderated users from
		// bypassing moderation by sending collection="Approved".
		// (User::postToCollection line 819):
		//   (!$ps || $ps == MODERATED || $ps == PROHIBITED) → Pending
		//   anything else → Approved
		// ourPostingStatus was already fetched during the membership check above.
		collection := utils.COLLECTION_PENDING

		if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
			return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
		}
		if ourPostingStatus != nil &&
			!strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_MODERATED) &&
			!strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) &&
			*ourPostingStatus != "" {
			collection = utils.COLLECTION_APPROVED
		}

		db.Table("messages_groups").Create(map[string]interface{}{
			"msgid": newMsgID, "groupid": req.Groupid, "collection": collection, "arrival": gorm.Expr("NOW()"),
		})

		// V1 parity: log Message/Received when a post is submitted directly (non-draft).
		logMessageReceived(db, req.Groupid, myid, newMsgID)
	}

	// Link attachments.
	for _, attID := range req.Attachments {
		db.Table("messages_attachments").Where("id = ?", attID).Update("msgid", newMsgID)
	}

	// Create item record.
	if req.Item != "" {
		// ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id) already lets the write report the id for
		// both new and existing rows; take it from the result, not a read-split-routable SELECT.
		// GORM's own "@id"
		// map writeback is skipped when RowsAffected is 0, which MySQL
		// reports on a no-op duplicate hit - exactly the common case here.
		// Clauses(gorm.WithResult()) hands back the raw sql.Result instead,
		// which has no such condition (proven in
		// test/insertid_gorm_writeback_test.go's
		// WithResultBeatsTheRowsAffectedZeroTrap).
		itemRes := gorm.WithResult()
		db.Table("items").Clauses(itemRes, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
			},
		}).Create(map[string]interface{}{"name": req.Item})
		var itemID uint64
		if itemRes.Result != nil {
			if id, idErr := itemRes.Result.LastInsertId(); idErr == nil {
				itemID = uint64(id)
			}
		}
		if itemID > 0 {
			db.Table("messages_items").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
				"msgid":  newMsgID,
				"itemid": itemID,
			})
		}
	}

	// Bulk offer: create the structured catalogue. Total quantity drives
	// availableinitially/availablenow, and the textbody falls back to a
	// readable summary so non-bulk-aware consumers still show the items.
	if len(req.Bulkitems) > 0 {
		total := upsertBulkItems(db, newMsgID, req.Bulkitems)
		if total > 0 {
			// Identical golden to
			// 01bedb9d631d (applyPatchMessageCore); converted together per gate (h).
			db.Table("messages").Where("id = ?", newMsgID).
				Updates(map[string]interface{}{"availableinitially": total, "availablenow": total})
		}
		if strings.TrimSpace(req.Textbody) == "" {
			if summary := buildBulkSummary(req.Bulkitems, req.Bulkslots); summary != "" {
				// Identical golden to
				// b560d268dc4e (applyPatchMessageCore); converted together per gate (h).
				db.Table("messages").Where("id = ?", newMsgID).
					Updates(map[string]interface{}{"textbody": summary, "message": summary})
			}
		}
		// Download any spreadsheet-supplied photo URLs into real attachments.
		go ingestBulkItemPhotos(db, newMsgID)
	}
	if req.Bulkslots != nil {
		upsertBulkSlots(db, newMsgID, req.Bulkslots)
	}
	if strings.TrimSpace(req.Accessinstructions) != "" {
		saveAccessInstructions(db, newMsgID, req.Accessinstructions)
	}

	// If the user explicitly chose a location, remember it (GET /isochrone
	// auto-creates an isochrone for the user from lastlocation).
	if req.Locationid != nil && *req.Locationid > 0 {
		db.Table("users").Where("id = ?", myid).Update("lastlocation", *req.Locationid)
	}

	// Denormalise the post's location onto the message so it is discoverable in
	// browse/search, which read messages.lat/lng directly (see bounds.go). Prefer
	// the chosen locationid; if the client didn't send one, fall back to the user's
	// last known location so the post is still findable (parity with the email path,
	// IncomingMailService). Resolve lat/lng with a JOIN on the WRITE connection in a
	// single statement. The previous code only denormalised when the client sent a
	// locationid, and did it via a separate best-effort SELECT whose Scan error was
	// unchecked and whose !=0 guard silently skipped the UPDATE on any miss — so a post
	// could go live with no lat/lng and be undiscoverable (Discourse 9865). If nothing
	// resolves (no locationid and no lastlocation), lat/lng stay NULL and
	// ContentCheckService holds the post for a moderator to add a postcode.
	// Table()'s argument
	// passes through unquoted once it contains a space, so the verbatim JOIN
	// text travels with it; the column-to-column assignments go through an
	// explicit clause.Set, the same shape pinned by the retired ormharness's
	// updatejoin_replace_test.go TestUpdateJoin_TwoJoinsWithColumnValues
	// (removed in d22ba1d6c).
	db.Table("messages m JOIN users u ON u.id = ? JOIN locations l ON l.id = COALESCE(m.locationid, u.lastlocation)", myid).
		Clauses(clause.Set{
			{Column: clause.Column{Table: "m", Name: "locationid"}, Value: clause.Column{Table: "l", Name: "id"}},
			{Column: clause.Column{Table: "m", Name: "lat"}, Value: clause.Column{Table: "l", Name: "lat"}},
			{Column: clause.Column{Table: "m", Name: "lng"}, Value: clause.Column{Table: "l", Name: "lng"}},
		}).
		Where("m.id = ? AND (m.lat IS NULL OR m.lng IS NULL)", newMsgID).
		Updates(map[string]interface{}{})
	// Do NOT insert into messages_spatial here — drafts must not appear in
	// browse/search results. Spatial index is populated by handleJoinAndPost
	// after the message is submitted to a group (matching V1 behaviour).

	// Reconstruct subject with location, now that locationid is set.
	// The initial subject was set as "Type: Item" without location; rebuild as
	// "KEYWORD: Item (Area PC)". Skipped when no location could be resolved.
	locStr := constructLocationString(db, newMsgID)
	if locStr != "" && req.Item != "" {
		groupid := req.Groupid
		if groupid == 0 {
			// Draft may not have a group yet; use item name without location keyword.
			groupid = getPrimaryGroupForMessage(db, newMsgID)
		}
		keyword := getGroupKeyword(db, groupid, req.Type)
		newSubject := keyword + ": " + req.Item + " (" + locStr + ")"
		// Identical golden to
		// a218fb801dd5 (JoinAndPostAs) and 2f30762bf955 (applyPatchMessageCore);
		// converted together per gate (h).
		db.Table("messages").Where("id = ?", newMsgID).
			Updates(map[string]interface{}{"subject": newSubject, "suggestedsubject": newSubject})
	}

	resp := fiber.Map{"ret": 0, "status": "Success", "id": newMsgID}
	if jwtString != "" {
		resp["jwt"] = jwtString
		resp["persistent"] = persistent
	}
	return c.JSON(resp)
}

// =============================================================================
// Merged from message/message_write.go
// =============================================================================

// PostMessageRequest handles action-based POST to /message.
type PostMessageRequest struct {
	ID               uint64  `json:"id"`
	Action           string  `json:"action"`
	Userid           *uint64 `json:"userid"`
	Count            *int    `json:"count"`
	Outcome          string  `json:"outcome"`
	Happiness        *string `json:"happiness"`
	Comment          *string `json:"comment"`
	Message          *string `json:"message"`
	Subject          *string `json:"subject"`
	Body             *string `json:"body"`
	Stdmsgid         *uint64 `json:"stdmsgid"`
	Groupid          *uint64 `json:"groupid"`
	Type             string  `json:"type"`
	Textbody         *string `json:"textbody"`
	Item             *string `json:"item"`
	Partner          *string `json:"partner"`
	Deadline         *string `json:"deadline"`
	Deliverypossible *bool   `json:"deliverypossible"`
	ForcePending     *bool   `json:"forcepending"`
	Tnpostid         *string `json:"tnpostid"`
	Source           *string `json:"source"`
	// Bulk-offer interest (action "BulkInterest").
	BulkInterest []BulkInterestInput `json:"bulkinterest"`
	// Whose interest to record/edit (action "BulkInterest"). Nil = the caller.
	// Only the offerer may pass another user's id — e.g. to record a replier's
	// verbally-expressed interest against the structured catalogue.
	Interestuserid *uint64 `json:"interestuserid"`
	// Bulk-offer interest state change (action "BulkInterestState").
	Bulkitemid *uint64 `json:"bulkitemid"`
	State      *string `json:"state"`
}

// BulkInterestInput is one item the caller is expressing interest in.
type BulkInterestInput struct {
	Bulkitemid uint64  `json:"bulkitemid"`
	Quantity   int     `json:"quantity"`
	Cancollect *string `json:"cancollect"`
}

// PostMessage dispatches POST /message actions.
func PostMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	// Partner auth: if partner query param is present, authenticate via partner key
	// instead of JWT. The partner acts on behalf of the identified user.
	partnerKey := c.Query("partner")
	var partnerDomain string
	var partnerCandidates []uint64
	partnerOwnerMode := false
	if partnerKey != "" {
		db := database.DBConn
		_, _, domain, err := user.ValidatePartnerKey(db, partnerKey)
		if err != nil {
			return fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
		}

		email := c.Query("email")
		tnuseridStr := c.Query("tnuserid")
		var tnuserid uint64
		if tnuseridStr != "" {
			if v, err := strconv.ParseUint(tnuseridStr, 10, 64); err == nil {
				tnuserid = v
			}
		}

		if email != "" {
			parts := strings.SplitN(email, "@", 2)
			if len(parts) != 2 || parts[1] != domain {
				return fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
			}
		}

		if email == "" && tnuseridStr == "" {
			// Partner-key-only auth (e.g. Trash Nothing): the partner acts as the
			// message's owner, provided the message fromaddr is in the partner
			// domain. Resolved per message id below. This mirrors V1
			// getRolesForMessages, where a partner with a valid key acquires owner
			// rights on a message from its domain and then acts as its fromuser.
			partnerDomain = domain
			partnerOwnerMode = true
		} else {
			partnerCandidates = user.FindTNCandidates(db, tnuserid, email)
			if len(partnerCandidates) == 0 {
				return fiber.NewError(fiber.StatusForbidden, "User not found for partner")
			}
			// Two candidates = diverged twin accounts; the sync's job is to
			// STOP divergence - merge them (falls back to the split
			// candidates for per-message arbitration if the merge fails).
			partnerCandidates = user.HealTNDivergence(db, partnerCandidates)
			myid = partnerCandidates[0]
		}
	}

	if myid == 0 && !partnerOwnerMode {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PostMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	// When tnpostid is provided, apply the action to ALL Freegle messages with that TN post ID.
	if req.ID == 0 && req.Tnpostid != nil && *req.Tnpostid != "" {
		db := database.DBConn
		var msgIDs []uint64
		db.Table("messages").Select("id").Where("tnpostid = ?", *req.Tnpostid).Scan(&msgIDs)
		if len(msgIDs) == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Message not found for that TN post ID")
		}
		for i, msgID := range msgIDs {
			req.ID = msgID
			actingid := myid
			if partnerOwnerMode {
				// Act as each message's owner, but only for messages whose
				// fromaddr is in the partner domain.
				actingid = user.FindPartnerOwnerForMessage(db, partnerDomain, msgID)
				if actingid == 0 {
					if i == 0 {
						return fiber.NewError(fiber.StatusForbidden, "Message not in partner domain")
					}
					continue
				}
			} else {
				// The member may own two Freegle accounts (see
				// user.FindTNCandidates) - act as whichever owns this copy.
				actingid = actAsOwnerCandidate(db, myid, partnerCandidates, msgID)
			}
			if err := dispatchPostMessageAction(c, actingid, req); err != nil {
				if i == 0 {
					return err
				}
				log.Printf("tnpostid %s: failed to apply %s to message %d: %v", *req.Tnpostid, req.Action, msgID, err)
			}
		}
		return nil
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	// In partner-key-only mode, act as the message owner if its fromaddr is in the
	// partner domain; otherwise the partner has no rights to this message.
	if partnerOwnerMode {
		myid = user.FindPartnerOwnerForMessage(database.DBConn, partnerDomain, req.ID)
		if myid == 0 {
			return fiber.NewError(fiber.StatusForbidden, "Message not in partner domain")
		}
	} else if len(partnerCandidates) > 1 {
		myid = actAsOwnerCandidate(database.DBConn, myid, partnerCandidates, req.ID)
	}

	return dispatchPostMessageAction(c, myid, req)
}

// moderationActionsBlockedByHold are the moderator actions that change moderation
// state and so must not run while a DIFFERENT moderator holds the message.
//
// A hold used to be advisory: ModTools hides Approve/Reject when someone else
// holds a post (ModMessage.vue), but nothing on the server enforced it, so a mod
// whose screen was stale acted anyway. In Discourse #9946 a mod rejected a post
// 27 minutes after a colleague held it and opened a modmail conversation, off a
// pending list his browser had fetched 90 minutes earlier. No amount of client
// refreshing closes that race - the check has to be here.
//
// Release is deliberately absent: it is the designed escape hatch for taking a
// post off someone else's hold, so it must stay available or a post is stranded
// when the holding mod goes away. Member-facing actions (Promise, Outcome, View,
// Reply, ...) are absent too - a mod hold must not stop the owner using their own
// post.
var moderationActionsBlockedByHold = map[string]bool{
	"Approve":       true,
	"Reject":        true,
	"Delete":        true,
	"Spam":          true,
	"Hold":          true,
	"ApproveEdits":  true,
	"RevertEdits":   true,
	"Move":          true,
	"BackToPending": true,
	"RejectToDraft": true,
	"BackToDraft":   true,
}

// heldByAnotherMod returns the id and name of a DIFFERENT moderator holding this
// message on any of the groups the action would touch, or 0 if it is free to act
// on.
func heldByAnotherMod(myid uint64, req PostMessageRequest) (uint64, string) {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		// Not a moderator for this message - let the handler produce its own
		// (403) error rather than masking it with a confusing 409.
		return 0, ""
	}

	reqGid := uint64(0)
	if req.Groupid != nil {
		reqGid = *req.Groupid
	}
	authorizedGroups, err := resolveAuthorizedGroups(myid, reqGid, ctx.Groupids)
	if err != nil {
		return 0, ""
	}

	// Holds are per-group: a message held on one group must not block moderation on
	// another group it is also pending on.
	var holder uint64
	db.Table("messages_groups").Select("heldby").
		Where("msgid = ? AND groupid IN ? AND heldby IS NOT NULL AND heldby != ? AND deleted = 0",
			req.ID, authorizedGroups, myid).
		Limit(1).Scan(&holder)
	if holder == 0 {
		return 0, ""
	}

	var holderName string
	db.Table("users").Select("fullname").Where("id = ?", holder).Scan(&holderName)
	return holder, holderName
}

// dispatchPostMessageAction routes a POST /message action to the correct handler.
func dispatchPostMessageAction(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	// Enforced centrally rather than per-handler so a new moderation action
	// cannot silently skip the check by forgetting to call it.
	if moderationActionsBlockedByHold[req.Action] {
		if holder, holderName := heldByAnotherMod(myid, req); holder != 0 {
			return c.Status(fiber.StatusConflict).JSON(fiber.Map{
				"ret":        1,
				"status":     "Held by another moderator",
				"heldby":     holder,
				"heldbyname": holderName,
			})
		}
	}

	switch req.Action {
	case "Promise":
		return handlePromise(c, myid, req)
	case "Renege":
		return handleRenege(c, myid, req)
	case "OutcomeIntended":
		return handleOutcomeIntended(c, myid, req)
	case "Outcome":
		return handleOutcome(c, myid, req)
	case "AddBy":
		return handleAddBy(c, myid, req)
	case "RemoveBy":
		return handleRemoveBy(c, myid, req)
	case "View":
		return handleView(c, myid, req)
	case "Approve":
		return handleApprove(c, myid, req)
	case "Reject":
		return handleReject(c, myid, req)
	case "Delete":
		return handleDeleteMessage(c, myid, req)
	case "Spam":
		return handleSpam(c, myid, req)
	case "Hold":
		return handleHold(c, myid, req)
	case "Release":
		return handleRelease(c, myid, req)
	case "ApproveEdits":
		return handleApproveEdits(c, myid, req)
	case "RevertEdits":
		return handleRevertEdits(c, myid, req)
	case "PartnerConsent":
		return handlePartnerConsent(c, myid, req)
	case "Reply":
		return handleReply(c, myid, req)
	case "JoinAndPost":
		return handleJoinAndPost(c, myid, req)
	case "Move":
		return handleMove(c, myid, req)
	case "BackToPending":
		return handleBackToPending(c, myid, req)
	case "RejectToDraft", "BackToDraft":
		return handleRejectToDraft(c, myid, req)
	case "BulkInterest":
		return handleBulkInterest(c, myid, req)
	case "BulkInterestState":
		return handleBulkInterestState(c, myid, req)
	case "BulkEditLink":
		return handleBulkEditLink(c, myid, req)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
	}
}

// handlePromise records a promise of an item to a user.
// If userid is omitted or 0, the promise is recorded against the current user,
// meaning "promised but we don't know to whom" (e.g. arranged outside Freegle or via Trash Nothing).
func handlePromise(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Verify message exists and is owned by the current user.
	var msgUserid uint64
	db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&msgUserid)
	if msgUserid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if msgUserid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	promisedTo := myid
	if req.Userid != nil && *req.Userid > 0 {
		promisedTo = *req.Userid
	}

	// REPLACE INTO - idempotent.
	db.Table("messages_promises").Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(map[string]interface{}{"msgid": req.ID, "userid": promisedTo})

	// Create a chat message of type Promised if promising to another user.
	if req.Userid != nil && *req.Userid > 0 && *req.Userid != myid {
		createSystemChatMessage(db, myid, *req.Userid, req.ID, utils.CHAT_MESSAGE_PROMISED)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleRenege removes a promise and records reliability data.
func handleRenege(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	var msgUserid uint64
	db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&msgUserid)
	if msgUserid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if msgUserid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	promisedTo := myid
	if req.Userid != nil && *req.Userid > 0 {
		promisedTo = *req.Userid
	}

	// Record renege for reliability tracking (only if not reneging on self).
	if promisedTo != myid {
		db.Table("messages_reneged").Create(map[string]interface{}{"userid": promisedTo, "msgid": req.ID})
	}

	// Delete the promise.
	db.Table("messages_promises").Where("msgid = ? AND userid = ?", req.ID, promisedTo).Delete(nil)

	// Create a chat message of type Reneged if reneging on another user.
	if req.Userid != nil && *req.Userid > 0 && *req.Userid != myid {
		createSystemChatMessage(db, myid, *req.Userid, req.ID, utils.CHAT_MESSAGE_RENEGED)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleOutcomeIntended records an intended outcome.
func handleOutcomeIntended(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.Outcome == "" {
		return fiber.NewError(fiber.StatusBadRequest, "outcome is required")
	}

	// Verify valid outcome.
	if req.Outcome != utils.OUTCOME_TAKEN && req.Outcome != utils.OUTCOME_RECEIVED && req.Outcome != utils.OUTCOME_WITHDRAWN && req.Outcome != utils.OUTCOME_REPOST {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid outcome")
	}

	// Verify caller owns the message or is a moderator.
	if !canModifyMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to modify this message")
	}

	// Simple insert-or-update.
	db.Table("messages_outcomes_intended").Clauses(clause.OnConflict{
		DoUpdates: clause.Set{
			{Column: clause.Column{Name: "outcome"}, Value: clause.Column{Table: "excluded", Name: "outcome"}},
		},
	}).Create(map[string]interface{}{
		"msgid":   req.ID,
		"outcome": req.Outcome,
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleOutcome marks a message with an outcome (Taken, Received, Withdrawn).
// Records the outcome in the DB and queues background processing for
// notifications and chat messages.
func handleOutcome(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.Outcome == "" {
		return fiber.NewError(fiber.StatusBadRequest, "outcome is required")
	}

	if req.Outcome != utils.OUTCOME_TAKEN && req.Outcome != utils.OUTCOME_RECEIVED && req.Outcome != utils.OUTCOME_WITHDRAWN {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid outcome")
	}

	// Get message type and verify existence.
	var msgType string
	db.Table("messages").Select("type").Where("id = ?", req.ID).Scan(&msgType)
	if msgType == "" {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	// Verify caller owns the message or is a moderator.
	if !canModifyMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to modify this message")
	}

	// Validate outcome against message type (Taken only on Offer, Received only on Wanted).
	if req.Outcome == utils.OUTCOME_TAKEN && msgType != "Offer" {
		return fiber.NewError(fiber.StatusBadRequest, "Taken outcome only valid for Offer messages")
	}
	if req.Outcome == utils.OUTCOME_RECEIVED && msgType != "Wanted" {
		return fiber.NewError(fiber.StatusBadRequest, "Received outcome only valid for Wanted messages")
	}

	// For Withdrawn: if the message is still pending on any group, soft-delete it
	// instead of recording an outcome.  We use UPDATE ... SET deleted = NOW() (matching
	// the rest of the codebase) rather than a hard DELETE so that moderators who loaded
	// the pending queue before the withdrawal can still reject / delete the message
	// without getting a spurious 403 from getMessageModContext failing to scan a
	// now-absent messages row.
	if req.Outcome == utils.OUTCOME_WITHDRAWN {
		var pendingCount int64
		db.Table("messages_groups").Where("msgid = ? AND collection = ?", req.ID, utils.COLLECTION_PENDING).Count(&pendingCount)
		if pendingCount > 0 {
			// Capture the groups the post is actively pending on *before* the
			// soft-delete, so we can write a per-group audit log below.
			var pendingGroups []uint64
			db.Table("messages_groups").Select("groupid").Where("msgid = ? AND collection = ? AND deleted = 0", req.ID, utils.COLLECTION_PENDING).Scan(&pendingGroups)

			// V1 parity (Message::delete()): soft-delete messages_groups first, then the
			// message itself.  Without this, the orphaned Pending row (deleted=0) gets
			// picked up by AutoApproveService 48 hours later and auto-approved as if the
			// member never withdrew it — making the message reappear in ModTools.
			db.Table("messages_groups").Where("msgid = ? AND collection = ?", req.ID, utils.COLLECTION_PENDING).
				Update("deleted", gorm.Expr("1"))
			// Identical golden to
			// 522c1e7c91cf and ef364ece98ef; converted together per gate (h).
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})

			// V1 parity (Message::delete() logs SUBTYPE_DELETED per group): without an
			// audit-log entry the post silently vanishes from the mod pending queue while
			// its "Posted"/Received log remains, so mods see "logs say posted but there's
			// no post and it's not in pending" (Discourse #9703). Log a Deleted entry per
			// group: `user` is the message author, `byuser` the actor (the member
			// withdrawing), and text notes that it was a withdrawal.
			var fromuser uint64
			db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&fromuser)
			for _, gid := range pendingGroups {
				logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, gid, fromuser, myid, req.ID, 0, "Withdrawn")
			}

			if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
				"msgid": req.ID,
			}); err != nil {
				log.Printf("Failed to queue freebie alerts remove for withdrawn pending message %d: %v", req.ID, err)
			}
			return c.JSON(fiber.Map{"ret": 0, "status": "Success", "deleted": true})
		}
	}

	// Check for existing outcome. System-generated expiry markers are
	// overwriteable; anything user-recorded is a real conflict.
	//
	// Overwriteable rows:
	//   - outcome = 'Expired'                    (deadline-expiry batch)
	//   - outcome = 'Withdrawn', comments = 'Auto-expired' (spatial-index
	//     expiry batch — the post was already auto-withdrawn by the system,
	//     so the owner clicking Taken from a chase-up notification that
	//     pre-dated the auto-expiry should be accepted)
	//
	// Counting instead of scanning into a scalar avoids the older bug where
	// a multi-row result (Expired + Auto-expired Withdrawn, left by the
	// batch before the iznik-batch fix) returned a non-deterministic row to
	// the check and 409'd valid Taken requests.
	var existingTotal, autoExpiredCount int64
	db.Table("messages_outcomes").Where("msgid = ?", req.ID).Count(&existingTotal)
	db.Table("messages_outcomes").
		Where("msgid = ? AND (outcome = ? OR (outcome = ? AND comments = 'Auto-expired'))",
			req.ID, utils.OUTCOME_EXPIRED, utils.OUTCOME_WITHDRAWN).
		Count(&autoExpiredCount)
	if existingTotal > 0 && existingTotal != autoExpiredCount {
		return fiber.NewError(fiber.StatusConflict, "Outcome already recorded")
	}

	// Clear any intended outcome.
	// Identical golden to
	// 0486830f6eda and ce1d968cff70; converted together per gate (h).
	db.Table("messages_outcomes_intended").Where("msgid = ?", req.ID).Delete(nil)

	// Clear any existing outcome (for expired overwrite).
	// Identical golden to
	// 854c7e93efe3 and dc8914d8b9d5; converted together per gate (h).
	db.Table("messages_outcomes").Where("msgid = ?", req.ID).Delete(nil)

	// Record the outcome.
	happiness := ""
	if req.Happiness != nil {
		happiness = *req.Happiness
	}
	var comment *string
	if req.Comment != nil && *req.Comment != "" {
		comment = req.Comment
	}

	if happiness != "" {
		db.Table("messages_outcomes").Create(map[string]interface{}{
			"msgid": req.ID, "outcome": req.Outcome, "happiness": happiness, "comments": comment,
		})
	} else {
		db.Table("messages_outcomes").Create(map[string]interface{}{
			"msgid": req.ID, "outcome": req.Outcome, "comments": comment,
		})
	}

	// Record who took/received the item.
	if (req.Outcome == utils.OUTCOME_TAKEN || req.Outcome == utils.OUTCOME_RECEIVED) && req.Userid != nil && *req.Userid > 0 {
		var availNow int
		db.Table("messages").Select("availablenow").Where("id = ?", req.ID).Scan(&availNow)
		db.Table("messages_by").Create(map[string]interface{}{
			"msgid": req.ID, "userid": *req.Userid, "count": availNow,
		})
	}

	// Mark successful in spatial index so that:
	// - isochrone queries exclude it (they filter on successful = 0)
	// - dashboard heatmap includes it (it filters on successful = 1)
	// V1 parity: markSuccessfulInSpatial() in Message.php.
	if req.Outcome == utils.OUTCOME_TAKEN || req.Outcome == utils.OUTCOME_RECEIVED {
		db.Table("messages_spatial").Where("msgid = ?", req.ID).Update("successful", gorm.Expr("1"))
	}

	// When a post is collected while still Pending in some groups - chiefly the rippling-out
	// case, where it was rippled into a neighbouring group and is awaiting that group's approval,
	// but also ordinary cross-posts - retire those Pending appearances so the now-taken item
	// leaves those mod queues (and is never auto-approved/mailed into them later). We do this
	// ONLY when the post is Approved on some other group, so the post and its Taken record
	// survive and a post pending only on its single group is never stranded. Mirrors the
	// Withdrawn-while-pending cleanup above, but keeps the message.
	if req.Outcome == utils.OUTCOME_TAKEN || req.Outcome == utils.OUTCOME_RECEIVED {
		var approvedElsewhere int64
		db.Table("messages_groups").Where("msgid = ? AND collection = ? AND deleted = 0", req.ID, utils.COLLECTION_APPROVED).Count(&approvedElsewhere)
		if approvedElsewhere > 0 {
			var pendingGroups []uint64
			db.Table("messages_groups").Select("groupid").Where("msgid = ? AND collection = ? AND deleted = 0", req.ID, utils.COLLECTION_PENDING).Scan(&pendingGroups)
			if len(pendingGroups) > 0 {
				db.Table("messages_groups").
					Where("msgid = ? AND collection = ? AND deleted = 0", req.ID, utils.COLLECTION_PENDING).
					Update("deleted", gorm.Expr("1"))
				// V1 parity: log a Deleted entry per group so the post's disappearance from
				// that pending queue is audited (matches the Withdrawn-pending path).
				var fromuser uint64
				db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&fromuser)
				for _, gid := range pendingGroups {
					logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, gid, fromuser, myid, req.ID, 0, req.Outcome)
				}
			}
		}
	}

	// Remove from freebiealerts.app — post is no longer available regardless of outcome type.
	if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
		"msgid": req.ID,
	}); err != nil {
		log.Printf("Failed to queue freebie alerts remove for message %d: %v", req.ID, err)
	}

	// Queue background processing for notifications/chat messages.
	// The background job handles: logging, chat notifications to interested users,
	// and marking chats as up-to-date.
	messageForOthers := ""
	if req.Message != nil {
		messageForOthers = *req.Message
	}
	userid := uint64(0)
	if req.Userid != nil {
		userid = *req.Userid
	}

	db.Table("background_tasks").Create(map[string]interface{}{
		"task_type": "message_outcome",
		"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'outcome', ?, 'happiness', ?, 'comment', ?, 'userid', ?, 'byuser', ?, 'message', ?)",
			req.ID, req.Outcome, happiness, comment, userid, myid, messageForOthers),
	})

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// canModifyMessage checks if the user is the message poster or a moderator/owner of a group the message is on.
func canModifyMessage(db *gorm.DB, myid uint64, msgid uint64) bool {
	var msgUserid uint64
	db.Table("messages").Select("fromuser").Where("id = ?", msgid).Scan(&msgUserid)
	if msgUserid == myid {
		return true
	}

	// Check if user is a moderator/owner of any group the message is on.
	var modCount int64
	db.Table("messages_groups mg").
		Joins("JOIN memberships m ON mg.groupid = m.groupid").
		Where("mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)", msgid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
		Count(&modCount)
	return modCount > 0
}

// handleAddBy records who is taking items from a message.
// If userid is omitted or null, records as userid=0 meaning "someone else" (not a known Freegle user).
func handleAddBy(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !canModifyMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to modify this message")
	}

	count := 1
	if req.Count != nil {
		count = *req.Count
	}

	// userid is nil for "someone else" (not a known Freegle user).
	var userid *uint64
	if req.Userid != nil && *req.Userid > 0 {
		userid = req.Userid
	}

	// Check if this user already has an entry.
	type byEntry struct {
		ID    uint64
		Count int
	}
	var existing byEntry
	if userid != nil {
		db.Table("messages_by").Select("id, count").Where("msgid = ? AND userid = ?", req.ID, *userid).Scan(&existing)
	} else {
		db.Table("messages_by").Select("id, count").Where("msgid = ? AND userid IS NULL", req.ID).Scan(&existing)
	}
	existingID := existing.ID
	existingCount := existing.Count

	if existingID > 0 {
		// Restore old count before updating.
		// Identical golden to
		// 228b6b678e0c (handleRemoveBy); converted together per gate (h).
		db.Table("messages").Where("id = ?", req.ID).
			Update("availablenow", gorm.Expr("LEAST(availableinitially, availablenow + ?)", existingCount))
		db.Table("messages_by").Where("id = ?", existingID).Update("count", count)
	} else {
		db.Table("messages_by").Create(map[string]interface{}{"userid": userid, "msgid": req.ID, "count": count})
	}

	// Reduce available count.
	db.Table("messages").Where("id = ?", req.ID).
		Update("availablenow", gorm.Expr("GREATEST(LEAST(availableinitially, availablenow - ?), 0)", count))

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleRemoveBy removes a taker and restores available count.
// If userid is omitted or null, removes the "someone else" entry.
func handleRemoveBy(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !canModifyMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to modify this message")
	}

	// Find the entry.
	type byEntry struct {
		ID    uint64
		Count int
	}
	var entry byEntry
	if req.Userid != nil && *req.Userid > 0 {
		db.Table("messages_by").Select("id, count").Where("msgid = ? AND userid = ?", req.ID, *req.Userid).Scan(&entry)
	} else {
		db.Table("messages_by").Select("id, count").Where("msgid = ? AND userid IS NULL", req.ID).Scan(&entry)
	}
	entryID := entry.ID
	entryCount := entry.Count

	if entryID > 0 {
		// Restore count and delete entry.
		// Identical golden to
		// 98534528cf3e (handleAddBy); converted together per gate (h).
		db.Table("messages").Where("id = ?", req.ID).
			Update("availablenow", gorm.Expr("LEAST(availableinitially, availablenow + ?)", entryCount))
		db.Table("messages_by").Where("id = ?", entryID).Delete(nil)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleView records a message view, de-duplicating within 30 minutes.
func handleView(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Optional source tag (e.g. "ripple_notify" from a notification link's ?src=), recording
	// HOW a genuine page-open arrived so notification-click opens are distinguishable from
	// organic browse. nil when absent; COALESCE below means it never clears a known source.
	var src interface{}
	if req.Source != nil && *req.Source != "" {
		src = *req.Source
	}

	// Check for a recent view within 30 minutes to avoid double-counting.
	var recentCount int64
	db.Table("messages_likes").Where("msgid = ? AND userid = ? AND type = 'View' AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)", req.ID, myid).Count(&recentCount)

	// pageview=1 marks a genuine page-open (a real eyeball), as opposed to a list-scroll
	// impression (MarkSeen writes 0) or a legacy row (NULL). The 'View' type still marks
	// "seen" for list de-duplication. source records the arrival path; COALESCE keeps any
	// existing source so a later organic view never clears the notification attribution.
	if recentCount == 0 {
		// First view in the window: create/refresh the row as a genuine page-open.
		db.Table("messages_likes").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "timestamp"}, Value: gorm.Expr("NOW()")},
				{Column: clause.Column{Name: "count"}, Value: gorm.Expr("count + 1")},
				{Column: clause.Column{Name: "pageview"}, Value: gorm.Expr("1")},
				{Column: clause.Column{Name: "source"}, Value: gorm.Expr("COALESCE(?, source)", src)},
			},
		}).Create(map[string]interface{}{
			"msgid":    req.ID,
			"userid":   myid,
			"type":     gorm.Expr("'View'"),
			"pageview": gorm.Expr("1"),
			"source":   src,
		})
	} else {
		// A recent 'View' row already exists, so we de-duplicate the count - but that row
		// may be a list-scroll impression (pageview=0) or legacy (NULL). A real page-open
		// must still upgrade it to a genuine view; otherwise a scroll immediately before an
		// open would suppress the open and the eyeball would never be recorded.
		db.Table("messages_likes").Where("msgid = ? AND userid = ? AND type = 'View'", req.ID, myid).
			Updates(map[string]interface{}{
				"pageview": gorm.Expr("1"),
				"source":   gorm.Expr("COALESCE(?, source)", src),
			})
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// createSystemChatMessage creates a system chat message between two users for a message.
// If no chat room exists between the users, one is created.
func createSystemChatMessage(db *gorm.DB, fromUser uint64, toUser uint64, refmsgid uint64, msgType string) {
	// Find existing chat room between these users.
	var chatID uint64
	db.Table("chat_rooms").Select("id").Where("(user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?)", fromUser, toUser, toUser, fromUser).Limit(1).Scan(&chatID)

	if chatID == 0 {
		// Create a User2User chat room. ON DUPLICATE KEY handles race conditions
		// (unique key on user1, user2, chattype). Clauses(gorm.WithResult()) reads
		// the id from the same sql.Result the write returned — avoids the GORM
		// connection-pool race a separate SELECT LAST_INSERT_ID() would have.
		res := gorm.WithResult()
		tx := db.Table("chat_rooms").Clauses(res, clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "id"}, Value: gorm.Expr("LAST_INSERT_ID(id)")},
				{Column: clause.Column{Name: "latestmessage"}, Value: gorm.Expr("NOW()")},
			},
		}).Create(map[string]interface{}{
			"user1": fromUser, "user2": toUser, "chattype": utils.CHAT_TYPE_USER2USER, "latestmessage": gorm.Expr("NOW()"),
		})
		if tx.Error != nil || res.Result == nil {
			return
		}
		chatIDInt, err := res.Result.LastInsertId()
		if err != nil || chatIDInt == 0 {
			return
		}
		chatID = uint64(chatIDInt)
	}

	// Insert chat message.
	db.Table("chat_messages").Create(map[string]interface{}{
		"chatid": chatID, "userid": fromUser, "type": msgType, "refmsgid": refmsgid,
		"date": time.Now(), "message": gorm.Expr("''"), "processingrequired": gorm.Expr("1"),
	})
}

// handleMove moves a message from its current group to a different group.
// The user must be a moderator/owner of both the source and target groups.
// The message is placed into Pending collection on the target group.
func handleMove(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.Groupid == nil || *req.Groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	// Must be mod of the source group (i.e. a group the message is currently on).
	if !isModForMessage(db, myid, req.ID) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator for this message")
	}

	// Must also be mod of the target group.
	if !user.IsModOfGroup(myid, *req.Groupid) {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator on the target group")
	}

	// Use a transaction to ensure DELETE + INSERT are atomic.
	// Without this, a failure after DELETE would orphan the message.
	err := db.Transaction(func(tx *gorm.DB) error {
		// Runs on tx (a *gorm.DB
		// transaction), which the retired harness's dry-run build function
		// rendered identically to the plain connection - same reasoning as
		// the retired orm_wave2_pilot_test.go's handleMerge note (removed in
		// d22ba1d6c).
		result := tx.Table("messages_groups").Where("msgid = ?", req.ID).Delete(nil)
		if result.Error != nil {
			return result.Error
		}
		if result.RowsAffected == 0 {
			return fmt.Errorf("message not found in any group")
		}

		// VALUES(...) with one
		// scalar-subquery element, not INSERT ... SELECT: the row source is
		// still an explicit VALUES list, so a normal Create keeps it one
		// statement.
		result = tx.Table("messages_groups").Create(map[string]interface{}{
			"msgid":      req.ID,
			"groupid":    *req.Groupid,
			"collection": utils.COLLECTION_PENDING,
			"arrival":    gorm.Expr("NOW()"),
			"msgtype":    gorm.Expr("(SELECT type FROM messages WHERE id = ?)", req.ID),
		})
		return result.Error
	})

	if err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to move message: "+err.Error())
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// locationIDsEqual returns true if both locationid pointers represent the same value.
func locationIDsEqual(a, b *uint64) bool {
	if a == nil && b == nil {
		return true
	}
	if a == nil || b == nil {
		return false
	}
	return *a == *b
}

// stringPtrEqual returns true if both string pointers represent the same value.
func stringPtrEqual(a, b *string) bool {
	if a == nil && b == nil {
		return true
	}
	if a == nil || b == nil {
		return false
	}
	return *a == *b
}

// recordAIDeletions checks which attachments on msgID will be removed by the new keepList,
// and for each AI-generated attachment being removed, records a Reject microaction.
// Attachments whose IDs appear in badAttachmentIDs are force-rejected immediately,
// bypassing quorum — used when a moderator marks the image as bad for any post.
func recordAIDeletions(db *gorm.DB, userID uint64, msgID uint64, keepList []uint64, badAttachmentIDs []uint64) {
	type aiCandidate struct {
		ID           uint64
		Externaluid  string
		Externalmods json.RawMessage
	}

	var candidates []aiCandidate
	if len(keepList) > 0 {
		db.Table("messages_attachments").Select("id, COALESCE(externaluid, '') AS externaluid, externalmods").
			Where("msgid = ? AND id NOT IN ?", msgID, keepList).Scan(&candidates)
	} else {
		db.Table("messages_attachments").Select("id, COALESCE(externaluid, '') AS externaluid, externalmods").Where("msgid = ?", msgID).Scan(&candidates)
	}

	foundAI := false
	for _, att := range candidates {
		if att.Externaluid == "" || !isAIAttachment(att.Externalmods) {
			continue
		}
		foundAI = true
		var aiImageID uint64
		db.Table("ai_images").Select("id").Where("externaluid = ?", att.Externaluid).Limit(1).Scan(&aiImageID)
		if aiImageID > 0 {
			if containsUint64(badAttachmentIDs, att.ID) {
				microvolunteering.ForceRejectAIImage(db, userID, aiImageID)
			} else {
				microvolunteering.RecordAIAttachmentDeletion(db, userID, aiImageID)
			}
		}
	}
	// Mirror V1 (Message.php:3974–3989): whenever an AI attachment is removed,
	// protect the message from the illustrations cron re-injecting a cached image.
	if foundAI {
		db.Table("messages_ai_declined").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
			"msgid": msgID,
		})
	}
}

func containsUint64(slice []uint64, val uint64) bool {
	for _, v := range slice {
		if v == val {
			return true
		}
	}
	return false
}

// isAIAttachment returns true if the externalmods JSON contains {"ai": true}.
func isAIAttachment(mods json.RawMessage) bool {
	if len(mods) == 0 {
		return false
	}
	var m struct {
		AI interface{} `json:"ai"`
	}
	if err := json.Unmarshal(mods, &m); err != nil {
		return false
	}
	switch v := m.AI.(type) {
	case bool:
		return v
	case float64:
		return v != 0
	default:
		return false
	}
}

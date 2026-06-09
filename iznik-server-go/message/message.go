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
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"github.com/golang-jwt/jwt/v4"
	"golang.org/x/net/html"
	"gorm.io/gorm"
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

// TNPhotoScrapeRunner launches TN photo scraping.  Swappable in tests to run synchronously.
var TNPhotoScrapeRunner = func(db *gorm.DB, msgID uint64, picPageURLs []string) {
	go scrapeTNPhotosToAttachments(db, msgID, picPageURLs)
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
			db.Exec("INSERT IGNORE INTO messages_attachments (msgid, externaluid, `primary`) VALUES (?, ?, ?)",
				msgID, externaluid, primary)
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
	ID                 uint64              `json:"id" gorm:"primary_key"`
	Arrival            time.Time           `json:"arrival"`
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
	Heldby           *uint64    `json:"heldby"`
	Source           *string    `json:"source"`
	Sourceheader     *string    `json:"sourceheader"`
	Fromaddr         *string    `json:"fromaddr"`
	Fromip           *string    `json:"fromip"`
	Fromcountry      *string    `json:"fromcountry"`
	Repostat           *time.Time          `json:"repostat"`
	Canrepost        bool       `json:"canrepost"`
	Deliverypossible bool       `json:"deliverypossible"`
	Deadline         *time.Time `json:"deadline"`
	Edits            []MessageEdit    `json:"edits,omitempty" gorm:"-"`
	RawMessage       *string          `json:"message,omitempty" gorm:"column:message"`
	Worry            []WorryMatch     `json:"worry,omitempty" gorm:"-"`
	Postings         []MessagePosting `json:"postings,omitempty" gorm:"-"`
	Tnpostid         *string          `json:"tnpostid"`
	Expiresat        *time.Time       `json:"expiresat,omitempty" gorm:"-"`
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
	ID              uint64     `json:"id"`
	Oldsubject      *string    `json:"oldsubject"`
	Newsubject      *string    `json:"newsubject"`
	Oldtext         *string    `json:"oldtext"`
	Newtext         *string    `json:"newtext"`
	Reviewrequired  int        `json:"reviewrequired"`
	Timestamp       *time.Time `json:"timestamp"`
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
	db.Raw("SELECT id, settings FROM `groups` WHERE id IN (?)", groupIDs).Scan(&groups)

	var latest *time.Time

	for _, g := range groups {
		arrival, ok := arrivalByGroup[g.ID]
		if !ok {
			continue
		}

		// Mirror V1 Message::getPublic() (iznik-server Message.php:1106-1110):
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
				userDeletedFilter := "AND users.deleted IS NULL"
				rawMessageField := ""
				if isMod {
					userDeletedFilter = ""
					rawMessageField = "messages.message, "
				}
				err := db.Raw("SELECT messages.id, messages.arrival, messages.date, messages.fromuser, "+
					"messages.subject, messages.type, textbody, lat, lng, availablenow, availableinitially, locationid,"+
					"deliverypossible, deadline, heldby, messages.source, messages.sourceheader, messages.fromaddr, messages.fromip, messages.fromcountry, messages.tnpostid, "+
					rawMessageField+
					"CASE WHEN messages_likes.msgid IS NULL THEN 1 ELSE 0 END AS unseen FROM messages "+
					"LEFT JOIN users ON users.id = messages.fromuser "+
					"LEFT JOIN messages_likes ON messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ? "+
					"WHERE messages.id = ? AND messages.deleted IS NULL " + userDeletedFilter, myid, utils.MESSAGE_LIKES_VIEW, id).First(&message).Error
				found = !errors.Is(err, gorm.ErrRecordNotFound)
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
				db.Raw("SELECT groupid, msgid, arrival, collection, autoreposts, approvedby, heldby, spamtype, spamreason, contentcheck_checked_at, contentcheck_reasons FROM messages_groups WHERE msgid = ? AND deleted = 0", id).Scan(&messageGroups)
			}()

			var messageAttachments []MessageAttachment

			wg.Add(1)
			go func() {
				defer wg.Done()
				// Mask rejected/regenerating AI images: if the externaluid matches an ai_image
			// that is no longer active, return an empty externaluid so the frontend shows
			// a placeholder instead of the rejected illustration.
			db.Raw(`SELECT ma.id, ma.msgid, ma.archived,
				CASE WHEN ai.id IS NOT NULL THEN '' ELSE COALESCE(ma.externaluid, '') END AS externaluid,
				ma.externalmods
				FROM messages_attachments ma
				LEFT JOIN ai_images ai ON ai.externaluid = ma.externaluid AND ai.status IN ('rejected', 'regenerating', 'suppressed')
				WHERE ma.msgid = ?
				ORDER BY ma.`+"`primary`"+` DESC, ma.id ASC`, id).Scan(&messageAttachments)
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
				db.Raw("SELECT DISTINCT chat_messages.id, refmsgid, chat_messages.date, userid, fromuser, "+
					"CASE WHEN users.fullname IS NOT NULL THEN users.fullname ELSE CONCAT(users.firstname, ' ', users.lastname) END AS displayname "+
					"FROM chat_messages "+
					"INNER JOIN messages ON messages.id = chat_messages.refmsgid "+
					"INNER JOIN messages_groups ON messages_groups.msgid = messages.id "+
					"INNER JOIN users ON users.id = chat_messages.userid "+
					"WHERE refmsgid = ? AND chat_messages.type = ? AND (messages.fromuser != ? OR chat_messages.userid != ?) "+
					"AND reviewrequired = 0 AND reviewrejected = 0 "+
					"AND DATEDIFF(chat_messages.date, messages_groups.arrival) < ? "+
					"GROUP BY userid;", id, utils.MESSAGE_INTERESTED, myid, myid, utils.OPEN_AGE).Scan(&messageReply)

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
				db.Raw("SELECT DISTINCT(chatid) FROM chat_messages WHERE refmsgid = ?;", id).Pluck("id", &refchatids)
			}()

			// Fetch pending edits (mod-only, for edit review page).
			var messageEdits []MessageEdit
			if isMod {
				wg.Add(1)
				go func() {
					defer wg.Done()
					db.Raw("SELECT id, oldsubject, newsubject, oldtext, newtext, reviewrequired, `timestamp` AS `timestamp` "+
						"FROM messages_edits WHERE msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL "+
						"ORDER BY id DESC", id).Scan(&messageEdits)
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
			db.Raw("SELECT mp.msgid, mp.groupid, mp.date, mp.repost, mp.autorepost, "+
				"COALESCE(g.namefull, g.nameshort) AS namedisplay "+
				"FROM messages_postings mp "+
				"INNER JOIN `groups` g ON mp.groupid = g.id "+
				"WHERE mp.msgid = ? ORDER BY mp.date ASC", id).Scan(&messagePostings)

			message.MessageGroups = messageGroups
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
					var repostStr []string
					db.Raw("SELECT CASE WHEN JSON_EXTRACT(settings, '$.reposts') IS NULL THEN '{''offer'' => 3, ''wanted'' => 7, ''max'' => 5, ''chaseups'' => 5}' ELSE JSON_EXTRACT(settings, '$.reposts') END AS reposts FROM `groups` INNER JOIN messages_groups ON messages_groups.groupid = groups.id WHERE msgid = ?", message.ID).Pluck("reposts", &repostStr)

					var reposts []group.RepostSettings

					for _, r := range repostStr {
						var rs group.RepostSettings
						json.Unmarshal([]byte(r), &rs)
						reposts = append(reposts, rs)
					}

					for _, r := range reposts {
						var interval int

						if message.Type == utils.OFFER {
							interval = r.Offer
						} else {
							interval = r.Wanted
						}

						if interval < 365 {
							if len(message.MessageGroups) > 0 {
								ra := message.MessageGroups[0].Arrival.AddDate(0, 0, interval)
								repostAt = &ra

								if repostAt.Before(time.Now()) {
									canRepost = true
								}
							}
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
		db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND role IN (?, ?) AND collection = ? LIMIT 1", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, utils.COLLECTION_APPROVED).Scan(&modCount)
		if modCount > 0 || auth.IsAdminOrSupport(myid) {
			checkWorryWords(db, messages)
		}
	}

	return messages
}

// checkWorryWords checks message subjects and textbodies against global concern
// keywords (fuzzy match mode).  Matches are stored in Message.Worry.
func checkWorryWords(db *gorm.DB, messages []Message) {
	var globalWords []WorryWord
	db.Raw(`SELECT id, keyword,
		CASE category
			WHEN 'substance_regulated' THEN 'Regulated'
			WHEN 'substance_reportable' THEN 'Reportable'
			WHEN 'substance_medicine' THEN 'Medicine'
			WHEN 'review' THEN 'Review'
			WHEN 'allowed' THEN 'Allowed'
			ELSE 'Review'
		END AS type
	FROM concern_keywords
	WHERE match_mode = 'fuzzy' AND scope = 'global'`).Scan(&globalWords)

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
		db.Raw("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, '$.spammers.worrywords')) FROM `groups` WHERE id = ?", gid).Scan(&raw)
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

// matchWorryWords scans subject and textbody for worry word matches.
// checks for pound sign, removes Allowed words before scanning,
// uses case-insensitive contains for phrases (keywords with spaces), and
// levenshtein distance < 1 (i.e. exact match) for single words with
// length-ratio filtering.
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
					Word: "\u00a3",
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
					Word: w.Keyword,
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
				// V1: ratio 0.75-1.25 and levenshtein < 1 (exact match).
				ratio := float64(len(token)) / float64(len(kw))
				if ratio >= 0.75 && ratio <= 1.25 && strings.EqualFold(token, kw) {
					matches = append(matches, WorryMatch{
						Word: w.Keyword,
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

			sql := "SELECT messages.lat, messages.lng, messages.id, messages_groups.groupid, messages_groups.collection, messages.type, messages_groups.arrival, messages.date, " +
				"messages_spatial.id AS spatialid, " +
				"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id) AS hasoutcome, " +
				"EXISTS(SELECT id FROM messages_outcomes WHERE messages_outcomes.msgid = messages.id AND outcome IN (?, ?)) AS successful, " +
				"EXISTS(SELECT id FROM messages_promises WHERE messages_promises.msgid = messages.id) AS promised, "

			if myid > 0 && id == myid {
				// Own messages are always treated as seen.
				sql += "0 AS unseen "
			} else {
				sql += "NOT EXISTS(SELECT msgid FROM messages_likes WHERE messages_likes.msgid = messages.id AND messages_likes.userid = ? AND messages_likes.type = ?) AS unseen "
			}

			sql += "FROM messages " +
				"INNER JOIN messages_groups ON messages_groups.msgid = messages.id " +
				"INNER JOIN users ON users.id = messages.fromuser "

			if active {
				if myid > 0 && id == myid {
					// For our own user, we might have messages which are not public yet because they're pending,
					// and we still want to show those.
					sql += "LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id "
				} else {
					// Another user - we are only interested in active and public messages.
					sql += "INNER JOIN messages_spatial ON messages_spatial.msgid = messages.id "
				}
			} else {
				sql += "LEFT JOIN messages_spatial ON messages_spatial.msgid = messages.id "
			}

			sql += "WHERE fromuser = ? AND messages.deleted IS NULL AND users.deleted IS NULL AND messages_groups.deleted = 0 AND " +
				"messages.type IN (?, ?)"

			if active {
				if myid > 0 && id == myid {
					sql += " HAVING ((hasoutcome = 0 AND spatialid IS NOT NULL) OR messages_groups.collection IN ('" + utils.COLLECTION_PENDING + "', '" + utils.COLLECTION_REJECTED + "'))"
				} else {
					sql += " HAVING hasoutcome = 0"
				}
			}

			sql += " ORDER BY unseen DESC, messages_groups.arrival DESC"

			if myid > 0 && id == myid {
				// Own messages - no unseen userid parameter needed.
				db.Raw(sql, utils.TAKEN, utils.RECEIVED, id, utils.OFFER, utils.WANTED).Scan(&msgs)
			} else {
				db.Raw(sql, utils.TAKEN, utils.RECEIVED, myid, utils.MESSAGE_LIKES_VIEW, id, utils.OFFER, utils.WANTED).Scan(&msgs)
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
		db.Raw("SELECT id, settings FROM `groups` WHERE id IN (?)", ids).Scan(&groups)

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
	type chatLatest struct {
		Refmsgid uint64     `gorm:"column:refmsgid"`
		Latest   *time.Time `gorm:"column:latest"`
	}
	var chatResults []chatLatest
	db.Raw("SELECT chat_messages.refmsgid, MAX(latestmessage) AS latest "+
		"FROM chat_rooms INNER JOIN chat_messages ON chat_rooms.id = chat_messages.chatid "+
		"WHERE chat_messages.refmsgid IN (?) GROUP BY chat_messages.refmsgid", candidateIDs).Scan(&chatResults)

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
			db.Raw("SELECT groupid FROM memberships WHERE userid = ? AND collection = ?", myid, utils.COLLECTION_APPROVED).Scan(&userGroupIDs)
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

		var ID int64

		if myid > 0 {
			db.Raw("INSERT INTO search_history (userid, term, locationid, `groups`) VALUES (?, ?, ?, ?);",
				myid,
				term,
				nil,
				c.Query("groupids", ""),
			).Scan(&ID)

			db.Raw("INSERT INTO users_searches (userid, term, locationid) VALUES (?, ?, ?);",
				myid,
				term,
				nil,
			).Scan(&ID)
		} else {
			db.Raw("INSERT INTO search_history (userid, term, locationid, `groups`) VALUES (NULL, ?, ?, ?);",
				term,
				nil,
				c.Query("groupids", ""),
			).Scan(&ID)
		}
	}()

	nelat, _ := strconv.ParseFloat(c.Query("nelat", "0"), 32)
	nelng, _ := strconv.ParseFloat(c.Query("nelng", "0"), 32)
	swlat, _ := strconv.ParseFloat(c.Query("swlat", "0"), 32)
	swlng, _ := strconv.ParseFloat(c.Query("swlng", "0"), 32)

	searchmode := c.Query("searchmode", "keyword")

	// We've seen problems with crashes inside Gorm.  Best I can tell, it looks like a Gorm bug exposed when an
	// array is resized.  So as a workaround we create slices with capacity, then filter out the empty ones at
	// the end.
	var res []SearchResult
	var res2 []SearchResult

	if len(term) > 0 {
		if term == "" {
			return fiber.NewError(fiber.StatusBadRequest, "No search term")
		}

		// Hybrid search: vector + keyword run in parallel, merged so that exact
		// lexical matches always appear even when the embedding model misses them
		// (e.g. short titles, UK retail terms like "white goods").
		if searchmode == "vector" && embedding.Global.Count() > 0 {
			expandedWords := ExpandQuery(term)

			var vectorResults []SearchResult
			var vectorStats VectorStats
			var vectorErr error
			var keyExact, keyStarts []SearchResult

			var hybridWg sync.WaitGroup
			hybridWg.Add(2)

			go func() {
				defer hybridWg.Done()
				vectorResults, vectorStats, vectorErr = VectorSearch(term, SEARCH_LIMIT, groupids, msgtype,
					float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			go func() {
				defer hybridWg.Done()
				if len(expandedWords) > 0 {
					keyExact = GetWordsExact(db, expandedWords, SEARCH_LIMIT, groupids, msgtype,
						float32(nelat), float32(nelng), float32(swlat), float32(swlng))
					keyStarts = GetWordsStarts(db, expandedWords, SEARCH_LIMIT, groupids, msgtype,
						float32(nelat), float32(nelng), float32(swlat), float32(swlng))
				}
			}()

			hybridWg.Wait()

			fallbackTaken := vectorErr != nil
			logVectorSearch(term, groupids, msgtype, myid, searchmode, len(vectorResults), fallbackTaken, vectorStats)

			if vectorErr != nil {
				fmt.Printf("Vector search failed: %v\n", vectorErr)
			}

			// Merge: vector results first (semantic ranking), then keyword-only
			// results the embedding missed (exact-match guarantee).
			merged := mergeHybrid(vectorResults, append(keyExact, keyStarts...))

			if len(merged) > 0 {
				wg.Wait()
				return c.JSON(merged)
			}
			// Both vector and keyword exact/starts returned nothing; fall through to
			// typo and soundex cascade.
		}

		if len(res) == 0 {
			words := GetWords(term)

			var wg sync.WaitGroup
			wg.Add(2)

			go func() {
				defer wg.Done()
				res = GetWordsExact(db, words, SEARCH_LIMIT, groupids, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			go func() {
				defer wg.Done()
				// Add in prefix matches, which helps with plurals.
				res2 = GetWordsStarts(db, words, SEARCH_LIMIT, groupids, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			wg.Wait()

			res = append(res, res2...)

			if len(res) == 0 {
				res = GetWordsTypo(db, words, SEARCH_LIMIT, groupids, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}

			if len(res) == 0 {
				res = GetWordsSounds(db, words, SEARCH_LIMIT, groupids, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}

			// Blur
			for ix, r := range res {
				res[ix].Lat, res[ix].Lng = utils.Blur(r.Lat, r.Lng, utils.BLUR_USER)
			}
		}
	}

	// Return results where Msgid is not 0
	filtered := []SearchResult{}

	for _, r := range res {
		if r.Msgid != 0 {
			filtered = append(filtered, r)
		}
	}

	wg.Wait()

	return c.JSON(filtered)
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

	db.Raw("SELECT messages.id, messages_groups.arrival, messages_groups.groupid, messages.subject, "+
		"groups.nameshort, groups.namefull, groups.lat, groups.lng "+
		"FROM messages "+
		"INNER JOIN messages_groups ON messages.id = messages_groups.msgid "+
		"INNER JOIN `groups` ON messages_groups.groupid = groups.id "+
		"INNER JOIN users ON messages.fromuser = users.id "+
		"WHERE messages_groups.arrival > ? AND collection = ? "+
		"ORDER BY messages_groups.arrival ASC LIMIT 100;",
		start,
		utils.COLLECTION_APPROVED).Scan(&activity)

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
		db.Exec("INSERT INTO logs (timestamp, type, subtype, groupid, `user`, byuser, msgid, stdmsgid, text) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?, ?)",
			logType, subtype, groupid, userid, byuser, msgid, stdmsgid, text)
	} else {
		db.Exec("INSERT INTO logs (timestamp, type, subtype, groupid, `user`, byuser, msgid, text) VALUES (NOW(), ?, ?, ?, ?, ?, ?, ?)",
			logType, subtype, groupid, userid, byuser, msgid, text)
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
	db.Raw("SELECT COALESCE(messageid, '') FROM messages WHERE id = ?", msgid).Scan(&messageid)
	result := db.Exec(
		"INSERT INTO logs (timestamp, type, subtype, groupid, `user`, byuser, msgid, text) VALUES (NOW(), ?, ?, ?, ?, NULL, ?, ?)",
		flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RECEIVED, groupid, fromuser, msgid, messageid,
	)
	if result.Error != nil {
		log.Printf("Failed to log Message/Received for msg %d group %d: %v", msgid, groupid, result.Error)
	}
}

// getPrimaryGroupForMessage returns the first groupid for a message.
func getPrimaryGroupForMessage(db *gorm.DB, msgid uint64) uint64 {
	var groupid uint64
	db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ? LIMIT 1", msgid).Scan(&groupid)
	return groupid
}

// getAllGroupsForMessage returns all groupids for a message.
// Returns groups regardless of deleted state, so mods can still moderate and reject
// messages even after the poster has deleted them.
func getAllGroupsForMessage(db *gorm.DB, msgid uint64) []uint64 {
	var groupids []uint64
	db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ?", msgid).Scan(&groupids)
	return groupids
}

// constructLocationString builds a location string for a message's subject,
// using the area name + vague postcode format.
// The vague postcode is the outward code only (e.g., "CB22" from "CB22 3AA").
// Pass an optional overrideAreaName to use a mod-supplied area name instead of
// the DB-derived mapping default (Discourse #9769/1 — area name not preserved on save).
func constructLocationString(db *gorm.DB, msgid uint64, overrideAreaName ...string) string {
	customArea := ""
	if len(overrideAreaName) > 0 {
		customArea = overrideAreaName[0]
	}

	type locInfo struct {
		Name   string
		Type   string
		Areaid uint64
	}
	var loc locInfo
	db.Raw("SELECT l.name, l.type, COALESCE(l.areaid, 0) as areaid FROM locations l "+
		"INNER JOIN messages m ON m.locationid = l.id WHERE m.id = ?", msgid).Scan(&loc)

	if loc.Name == "" {
		return ""
	}

	if loc.Type == "Postcode" {
		// Vague postcode: take only the outward code (before the space).
		vaguePC := loc.Name
		if idx := strings.Index(vaguePC, " "); idx > 0 {
			vaguePC = vaguePC[:idx]
		}

		// Prefer the mod-supplied name; fall back to the DB mapping area name.
		areaName := customArea
		if areaName == "" && loc.Areaid > 0 {
			db.Raw("SELECT name FROM locations WHERE id = ?", loc.Areaid).Scan(&areaName)
		}

		if areaName != "" {
			return areaName + " " + vaguePC
		}
		return vaguePC
	}

	if customArea != "" {
		return customArea
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
		db.Raw("SELECT JSON_UNQUOTE(JSON_EXTRACT(settings, ?)) FROM `groups` WHERE id = ?",
			jsonPath, groupid).Scan(&keyword)
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
	result := db.Raw(`SELECT COUNT(*) FROM messages_groups mg
		JOIN memberships m ON m.groupid = mg.groupid
		WHERE mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)`, msgid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&count)
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
	row := db.Raw("SELECT fromuser, subject FROM messages WHERE id = ?", msgid).Row()
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

// addApprovedMessageToSpatialIndex inserts/updates the messages_spatial row for a
// message that has just become Approved, so it appears in browse/search immediately
// instead of waiting for the every-5-minute reconciler (MessageSpatialService).
// messages_spatial backs the public browse/map, so it must only contain Approved
// messages with a location — Pending/Spam/Rejected must never be added here. The
// query re-checks collection=Approved so this is a safe no-op if called otherwise.
func addApprovedMessageToSpatialIndex(db *gorm.DB, msgid uint64) {
	var row struct {
		Lat     float64
		Lng     float64
		Msgtype string
		Groupid uint64
		Arrival string
	}
	db.Raw("SELECT messages.lat AS lat, messages.lng AS lng, messages.type AS msgtype, "+
		"messages_groups.groupid AS groupid, "+
		"DATE_FORMAT(messages_groups.arrival, '%Y-%m-%d %H:%i:%s') AS arrival "+
		"FROM messages "+
		"INNER JOIN messages_groups ON messages_groups.msgid = messages.id "+
		"LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id "+
		"WHERE messages.id = ? AND messages_groups.collection = ? "+
		"AND messages_groups.deleted = 0 AND messages.deleted IS NULL "+
		"AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL "+
		"AND messages_outcomes.id IS NULL "+
		"ORDER BY messages_groups.arrival DESC LIMIT 1",
		msgid, utils.COLLECTION_APPROVED).Scan(&row)

	if row.Groupid == 0 || (row.Lat == 0 && row.Lng == 0) {
		return
	}

	db.Exec("INSERT INTO messages_spatial (msgid, point, groupid, msgtype, arrival) "+
		"VALUES (?, ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857), ?, ?, ?) "+
		"ON DUPLICATE KEY UPDATE point = VALUES(point), groupid = VALUES(groupid), "+
		"msgtype = VALUES(msgtype), arrival = VALUES(arrival)",
		msgid, row.Lng, row.Lat, row.Groupid, row.Msgtype, row.Arrival)
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
	if result := db.Exec("UPDATE messages_groups SET collection = ?, approvedby = ?, approvedat = NOW(), arrival = NOW() WHERE msgid = ? AND groupid IN ? AND collection != ?",
		utils.COLLECTION_APPROVED, myid, req.ID, authorizedGroups, utils.COLLECTION_APPROVED); result.Error != nil {
		log.Printf("Failed to approve message %d: %v", req.ID, result.Error)
	}

	// Release hold on the same authorised groups.
	db.Exec("UPDATE messages_groups SET heldby = NULL WHERE msgid = ? AND groupid IN ?", req.ID, authorizedGroups)

	// Check if still held on any group — if not, clear messages.heldby for backwards compat.
	var stillHeldCount int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND heldby IS NOT NULL", req.ID).Scan(&stillHeldCount)
	if stillHeldCount == 0 {
		db.Exec("UPDATE messages SET heldby = NULL WHERE id = ?", req.ID)
	}

	// Now Approved — add to the spatial index so the post appears in browse/search
	// immediately rather than waiting for the periodic reconciler.
	addApprovedMessageToSpatialIndex(db, req.ID)

	// Mark as ham if it was flagged as spam on any authorised group (fall back to messages table).
	var spamtype *string
	db.Raw("SELECT spamtype FROM messages_groups WHERE msgid = ? AND groupid IN ? AND spamtype IS NOT NULL LIMIT 1", req.ID, authorizedGroups).Scan(&spamtype)
	if spamtype == nil {
		db.Raw("SELECT spamtype FROM messages WHERE id = ?", req.ID).Scan(&spamtype)
	}
	if spamtype != nil && *spamtype != "" {
		db.Exec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, 'Ham')", req.ID)
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
		db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?))",
			"email_message_approved", req.ID, gid, myid, subject, body, stdmsgid, "Approve")
	}

	// Notify freebiealerts.app about newly approved Offer posts.
	var approvedMsgType string
	db.Raw("SELECT type FROM messages WHERE id = ?", req.ID).Scan(&approvedMsgType)
	if approvedMsgType == "Offer" {
		if err := queue.QueueTask(queue.TaskFreebieAlertsAdd, map[string]interface{}{
			"msgid": req.ID,
		}); err != nil {
			log.Printf("Failed to queue freebie alerts add for message %d: %v", req.ID, err)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleReject rejects a pending message.
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

	// With a subject (stdmsg), move to Rejected collection (user can edit and resubmit).
	// Without a subject (plain delete), mark as deleted.
	if subject != "" {
		if result := db.Exec("UPDATE messages_groups SET collection = ?, rejectedat = NOW() WHERE msgid = ? AND groupid IN ? AND collection = ?",
			utils.COLLECTION_REJECTED, req.ID, authorizedGroups, utils.COLLECTION_PENDING); result.Error != nil {
			log.Printf("Failed to reject message %d: %v", req.ID, result.Error)
		}
	} else {
		if result := db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid IN ? AND collection = ?",
			req.ID, authorizedGroups, utils.COLLECTION_PENDING); result.Error != nil {
			log.Printf("Failed to delete pending message %d: %v", req.ID, result.Error)
		}

		// Cascade soft-delete: if no non-deleted groups remain, mark messages.deleted
		// so list queries filtering `messages.deleted IS NULL` don't see an orphan row.
		var remainingGroups int64
		db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", req.ID).Scan(&remainingGroups)
		if remainingGroups == 0 {
			if result := db.Exec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?", req.ID); result.Error != nil {
				log.Printf("Failed to soft-delete rejected message %d: %v", req.ID, result.Error)
			}
			if err := queue.QueueTask(queue.TaskFreebieAlertsRemove, map[string]interface{}{
				"msgid": req.ID,
			}); err != nil {
				log.Printf("Failed to queue freebie alerts remove for message %d: %v", req.ID, err)
			}
		}
	}

	// Queue rejection email per authorised group (batch processor creates one log+push per group).
	for _, gid := range authorizedGroups {
		db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?))",
			"email_message_rejected", req.ID, gid, myid, subject, body, stdmsgid, "Reject")
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
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
	if result := db.Exec("DELETE FROM messages_groups WHERE msgid = ? AND groupid IN ?", req.ID, authorizedGroups); result.Error != nil {
		log.Printf("Failed to delete messages_groups for message %d groups %v: %v", req.ID, authorizedGroups, result.Error)
	}

	// If no non-deleted groups remain, soft-delete the message itself.
	var remainingGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", req.ID).Scan(&remainingGroups)
	if remainingGroups == 0 {
		if result := db.Exec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?", req.ID); result.Error != nil {
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
		db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?))",
			"email_message_rejected", req.ID, gid, myid, subject, body, stdmsgid, "Delete Approved Message")
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
	db.Exec("REPLACE INTO messages_spamham (msgid, spamham) VALUES (?, ?)", req.ID, utils.COLLECTION_SPAM)

	// Per-group spam: soft-delete only the authorized groups' rows.
	db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND groupid IN ?", req.ID, authorizedGroups)

	// If no non-deleted groups remain, soft-delete the message itself.
	var remainingGroups int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND deleted = 0", req.ID).Scan(&remainingGroups)
	if remainingGroups == 0 {
		db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", req.ID)

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
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid IN ?", myid, req.ID, authorizedGroups)

	// Also update messages.heldby for backwards compatibility during migration.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

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
	db.Exec("UPDATE messages_groups SET heldby = ? WHERE msgid = ? AND groupid IN ?", myid, req.ID, authorizedGroups)

	// Also update messages.heldby for backwards compatibility.
	db.Exec("UPDATE messages SET heldby = ? WHERE id = ?", myid, req.ID)

	// Move from Approved back to Pending for authorized groups.
	db.Exec("UPDATE messages_groups SET collection = ?, approvedby = NULL, approvedat = NULL WHERE msgid = ? AND groupid IN ? AND collection = ?",
		utils.COLLECTION_PENDING, req.ID, authorizedGroups, utils.COLLECTION_APPROVED)

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
	db.Exec("UPDATE messages_groups SET heldby = NULL WHERE msgid = ? AND groupid IN ?", req.ID, authorizedGroups)

	// Check if still held on any group — if not, clear messages.heldby for backwards compat.
	var stillHeldCount int64
	db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND heldby IS NOT NULL", req.ID).Scan(&stillHeldCount)
	if stillHeldCount == 0 {
		db.Exec("UPDATE messages SET heldby = NULL WHERE id = ?", req.ID)
	}

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
	db.Exec("UPDATE messages SET editedby = NULL WHERE id = ?", req.ID)

	// Find the latest pending edit to apply its changes.
	type editRecord struct {
		ID         uint64
		Newsubject *string
		Newtext    *string
	}
	var edit editRecord
	db.Raw("SELECT id, newsubject, newtext FROM messages_edits WHERE msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL ORDER BY id DESC LIMIT 1",
		req.ID).Scan(&edit)

	if edit.ID > 0 {
		// Apply the changes from the latest edit.
		if edit.Newsubject != nil {
			db.Exec("UPDATE messages SET subject = ? WHERE id = ?", *edit.Newsubject, req.ID)
		}
		if edit.Newtext != nil {
			db.Exec("UPDATE messages SET textbody = ? WHERE id = ?", *edit.Newtext, req.ID)
		}
	}

	// Mark ALL pending edits as approved.
	db.Exec("UPDATE messages_edits SET reviewrequired = 0, approvedat = NOW() WHERE msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL",
		req.ID)

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
	db.Raw("SELECT oldsubject, oldtext FROM messages_edits WHERE msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL ORDER BY id DESC LIMIT 1", req.ID).Scan(&old)
	if old.Oldsubject != nil || old.Oldtext != nil {
		clauses := []string{"editedby = NULL"}
		args := []interface{}{}
		if old.Oldsubject != nil {
			clauses = append(clauses, "subject = ?")
			args = append(args, *old.Oldsubject)
		}
		if old.Oldtext != nil {
			clauses = append(clauses, "textbody = ?")
			args = append(args, *old.Oldtext)
		}
		args = append(args, req.ID)
		db.Exec("UPDATE messages SET "+strings.Join(clauses, ", ")+" WHERE id = ?", args...)
	} else {
		// No recorded old values — just clear the editedby flag.
		db.Exec("UPDATE messages SET editedby = NULL WHERE id = ?", req.ID)
	}

	// Mark all pending edits as reverted.
	db.Exec("UPDATE messages_edits SET reviewrequired = 0, revertedat = NOW() WHERE msgid = ? AND reviewrequired = 1 AND approvedat IS NULL AND revertedat IS NULL",
		req.ID)

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
	db.Raw("SELECT id FROM partners_keys WHERE partner = ?", *req.Partner).Scan(&partnerID)
	if partnerID == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Partner not found")
	}

	// Record consent in partners_messages.
	db.Exec("INSERT IGNORE INTO partners_messages (partnerid, msgid) VALUES (?, ?)", partnerID, req.ID)

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

	// Queue email+log via background task.
	// The batch processor will also create the mod log entry.
	db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'groupid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?))",
		"email_message_reply", req.ID, ctx.Groupid, myid, subject, body, stdmsgid, "Leave Approved Message")

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
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", req.ID).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	isOwner := fromuser == myid
	isMod := isModForMessage(db, myid, req.ID)
	if !isOwner && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to convert this message to draft")
	}

	// Use a transaction: insert draft then delete from groups.
	tx := db.Begin()
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction failed")
	}

	// Determine the group for the draft (use first group the message is in).
	groupid := getPrimaryGroupForMessage(db, req.ID)

	// Insert into messages_drafts (ignore if already a draft).
	if err := tx.Exec("INSERT IGNORE INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)",
		req.ID, groupid, myid).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create draft")
	}

	// Remove from messages_groups.
	if err := tx.Exec("DELETE FROM messages_groups WHERE msgid = ?", req.ID).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to remove from groups")
	}

	// Clear any previous outcome so the reposted message starts fresh.
	// Without this, a message that was withdrawn still shows as "withdrawn"
	// in posting history after reposting — the same wrong behaviour as V1.
	if err := tx.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", req.ID).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to clear outcome")
	}
	tx.Exec("DELETE FROM messages_outcomes_intended WHERE msgid = ?", req.ID)

	// Reset availablenow to availableinitially — if the item was promised to
	// someone who never collected, the repost should offer the full quantity again.
	// Also clear messages_by so there are no stale promise records.
	tx.Exec("UPDATE messages SET availablenow = availableinitially WHERE id = ?", req.ID)
	tx.Exec("DELETE FROM messages_by WHERE msgid = ?", req.ID)

	if err := tx.Commit().Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction commit failed")
	}

	// Clear deadline if it's in the past or today — an old deadline is no longer
	// relevant when reposting and would cause the message to appear expired.
	var deadline *string
	db.Raw("SELECT deadline FROM messages WHERE id = ?", req.ID).Scan(&deadline)
	if deadline != nil && *deadline != "" {
		today := time.Now().Format("2006-01-02")
		if *deadline <= today {
			db.Exec("UPDATE messages SET deadline = NULL WHERE id = ?", req.ID)
		}
	}

	// Log the repost action.
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPOST, 0, fromuser, myid, req.ID, 0, "Repost started")

	// Return the message type (the client uses this).
	var msgType string
	db.Raw("SELECT type FROM messages WHERE id = ?", req.ID).Scan(&msgType)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success", "messagetype": msgType})
}

// handleJoinAndPost joins a group and posts a message in one action.
func handleJoinAndPost(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Look up the existing draft message.
	type msgInfo struct {
		Fromuser uint64
		Type     string
	}
	var msg msgInfo
	db.Raw("SELECT fromuser, type FROM messages WHERE id = ?", req.ID).Scan(&msg)
	if msg.Fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}
	if msg.Fromuser != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	// Find the group — from request, then messages_drafts, then messages_groups.
	groupid := uint64(0)
	if req.Groupid != nil && *req.Groupid > 0 {
		groupid = *req.Groupid
	} else {
		db.Raw("SELECT groupid FROM messages_drafts WHERE msgid = ? LIMIT 1", req.ID).Scan(&groupid)
	}
	if groupid == 0 {
		groupid = getPrimaryGroupForMessage(db, req.ID)
	}
	if groupid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "groupid is required")
	}

	// Check if user is banned from this group.
	// V1 parity: a ban deletes the memberships row and inserts into users_banned —
	// there is no memberships.collection='Banned' row, so the check must hit users_banned.
	var bannedCount int64
	db.Raw("SELECT COUNT(*) FROM users_banned WHERE userid = ? AND groupid = ?", myid, groupid).Scan(&bannedCount)
	if bannedCount > 0 {
		return fiber.NewError(fiber.StatusForbidden, "You are banned from this group")
	}

	// Join group if not already a member.
	result := db.Exec("INSERT IGNORE INTO memberships (userid, groupid, role, collection) VALUES (?, ?, ?, ?)",
		myid, groupid, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED)

	// Log the join event when a new membership row was created.
	if result.RowsAffected > 0 {
		db.Exec("INSERT INTO logs (timestamp, type, subtype, groupid, user, byuser) VALUES (NOW(), ?, ?, ?, ?, ?)",
			flog.LOG_TYPE_GROUP, flog.LOG_SUBTYPE_JOINED, groupid, myid, myid)
	}

	// All messages start Pending — the content check batch job runs content checks
	// and promotes clean messages from non-moderated users to Approved.
	collection := utils.COLLECTION_PENDING
	var ourPostingStatus *string
	db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ?", myid, groupid).Scan(&ourPostingStatus)

	if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
		return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post on this group")
	}

	// Reconstruct subject with location and group keyword before submitting
	//. The draft subject may have been set without
	// a location, or the group keyword may differ from the draft's type prefix.
	locStr := constructLocationString(db, req.ID)
	if locStr != "" {
		var itemName *string
		db.Raw("SELECT i.name FROM items i INNER JOIN messages_items mi ON mi.itemid = i.id WHERE mi.msgid = ? LIMIT 1", req.ID).Scan(&itemName)
		if itemName != nil {
			keyword := getGroupKeyword(db, groupid, msg.Type)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			db.Exec("UPDATE messages SET subject = ?, suggestedsubject = ? WHERE id = ?", newSubject, newSubject, req.ID)
		}
	}

	// Refuse to promote a draft that would land in the group with no subject.
	// This catches pre-validation drafts created before PUT /message required
	// item, and any other path that leaves subject empty by submit time.
	var finalSubject string
	db.Raw("SELECT COALESCE(subject, '') FROM messages WHERE id = ?", req.ID).Scan(&finalSubject)
	if strings.TrimSpace(finalSubject) == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Item is required")
	}

	// Save deadline and deliverypossible if provided.
	if req.Deadline != nil && *req.Deadline != "" {
		db.Exec("UPDATE messages SET deadline = ? WHERE id = ?", *req.Deadline, req.ID)
	}
	if req.Deliverypossible != nil {
		db.Exec("UPDATE messages SET deliverypossible = ? WHERE id = ?", *req.Deliverypossible, req.ID)
	}

	// Submit: insert into messages_groups and clean up draft.
	db.Exec("INSERT IGNORE INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW())",
		req.ID, groupid, collection)

	// Clear any previous outcomes (V1 parity: submit() always deletes outcomes before re-posting).
	db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", req.ID)
	db.Exec("DELETE FROM messages_outcomes_intended WHERE msgid = ?", req.ID)

	// Record posting (V1 parity: submit() inserts into messages_postings each time a message is submitted).
	db.Exec("INSERT INTO messages_postings (msgid, groupid) VALUES (?, ?)", req.ID, groupid)

	// Record history entry for spam checking (V1 parity: Message::save() inserts into messages_history).
	// We fetch user email/name from the DB since platform messages don't have envelope headers.
	var histSubject string
	db.Raw("SELECT COALESCE(subject, '') FROM messages WHERE id = ?", req.ID).Scan(&histSubject)
	var histFromname string
	db.Raw("SELECT COALESCE(fullname, '') FROM users WHERE id = ?", myid).Scan(&histFromname)
	// V1 parity: submit() calls inventEmail() to get/create the user's @users.ilovefreegle.org
	// proxy email, then sets messages.fromaddr to it. This address is checked by auto-repost,
	// chase-up, and other cron jobs via Mail::ourDomain().
	fromaddr := user.GetOrCreateInternalEmail(db, myid)

	db.Exec("UPDATE messages SET fromaddr = ? WHERE id = ?", fromaddr, req.ID)

	// V1 parity: messages_history.fromaddr also uses the invented @users email, not the preferred email.
	db.Exec("INSERT IGNORE INTO messages_history (msgid, groupid, source, fromuser, fromname, fromaddr, subject, arrival, fromip) VALUES (?, ?, 'Platform', ?, ?, ?, ?, NOW(), ?)",
		req.ID, groupid, myid, histFromname, fromaddr, histSubject, c.IP())

	db.Exec("DELETE FROM messages_drafts WHERE msgid = ?", req.ID)

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
	db.Raw("SELECT COUNT(*) FROM users_logins WHERE userid = ? AND type = ?", myid, utils.LOGIN_TYPE_NATIVE).Scan(&hasPassword)

	resp := fiber.Map{
		"ret":     0,
		"status":  "Success",
		"id":      req.ID,
		"groupid": groupid,
	}

	if hasPassword == 0 {
		// New user without a password — generate one and return it.
		password := utils.RandomHex(8)
		salt := auth.GetPasswordSalt()
		hashed := auth.HashPassword(password, salt)

		// uid must be the user ID (not email) so that VerifyPassword can find the row.
		db.Exec("INSERT INTO users_logins (userid, type, uid, credentials, salt) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE credentials = VALUES(credentials), salt = VALUES(salt)",
			myid, utils.LOGIN_TYPE_NATIVE, myid, hashed, salt)
		resp["newuser"] = true
		resp["newpassword"] = password
	}

	return c.JSON(resp)
}

// patchMessageRequest is the body for PATCH /message and PATCH /message/tn/:tnpostid.
type patchMessageRequest struct {
	ID           uint64   `json:"id"`
	Subject      *string  `json:"subject"`
	Textbody     *string  `json:"textbody"`
	Type         *string  `json:"type"`
	Msgtype      *string  `json:"msgtype"`
	Messagetype  *string  `json:"messagetype"`
	Item         *string  `json:"item"`
	Availablenow *int     `json:"availablenow"`
	Lat          *float64 `json:"lat"`
	Lng          *float64 `json:"lng"`
	Location     *string  `json:"location"`
	Locationid   *uint64  `json:"locationid"`
	Areaname     *string  `json:"areaname"`
	Groupid      *uint64  `json:"groupid"`
	Attachments  []uint64 `json:"attachments"`
	BadAIImages  []uint64 `json:"badAIImages"`
	Deadline     *string  `json:"deadline"`
}

// resolvePartnerAuth reads a ?partner= query param and resolves the acting user ID.
// Returns the resolved user ID (0 on failure) and an error to return to the client.
func resolvePartnerAuth(c *fiber.Ctx) (uint64, error) {
	db := database.DBConn
	_, _, domain, err := user.ValidatePartnerKey(db, c.Query("partner"))
	if err != nil {
		return 0, fiber.NewError(fiber.StatusForbidden, "Invalid partner key")
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
			return 0, fiber.NewError(fiber.StatusForbidden, "Email domain does not match partner domain")
		}
	}

	myid := user.FindByTNIdOrEmail(db, tnuserid, email)
	if myid == 0 {
		return 0, fiber.NewError(fiber.StatusForbidden, "User not found for partner")
	}
	return myid, nil
}

// applyPatchMessageCore performs the edit on a message without writing the HTTP response.
// Returns non-nil on failure. Callers are responsible for writing the success response.
func applyPatchMessageCore(c *fiber.Ctx, myid uint64, req patchMessageRequest) error {
	db := database.DBConn

	// Check ownership or mod permission.
	var fromuser uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", req.ID).Scan(&fromuser)
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
	db.Raw("SELECT subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid FROM messages WHERE id = ?", req.ID).Scan(&old)

	// Snapshot old item IDs as JSON (V1 stores item IDs array in olditems/newitems).
	type itemRow struct{ ID uint64 }
	var oldItemRows []itemRow
	db.Raw("SELECT itemid AS id FROM messages_items WHERE msgid = ? ORDER BY itemid", req.ID).Scan(&oldItemRows)
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
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id", req.ID).Scan(&oldAttachRows)
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

	// Build a single UPDATE with all changed fields.
	setClauses := []string{}
	args := []interface{}{}

	if req.Subject != nil {
		setClauses = append(setClauses, "subject = ?")
		args = append(args, *req.Subject)
	}
	if req.Textbody != nil {
		setClauses = append(setClauses, "textbody = ?")
		args = append(args, *req.Textbody)
	}
	if req.Type != nil {
		setClauses = append(setClauses, "type = ?")
		args = append(args, *req.Type)
		// also update messages_groups.msgtype.
		db.Exec("UPDATE messages_groups SET msgtype = ? WHERE msgid = ?", *req.Type, req.ID)
	}
	if req.Availablenow != nil {
		setClauses = append(setClauses, "availablenow = ?")
		args = append(args, *req.Availablenow)
	}
	if req.Deadline != nil {
		if *req.Deadline == "" || *req.Deadline == "null" {
			setClauses = append(setClauses, "deadline = NULL")
		} else {
			setClauses = append(setClauses, "deadline = ?")
			args = append(args, *req.Deadline)
		}
	}
	// Resolve location name to locationid if provided.
	if req.Location != nil && *req.Location != "" && (req.Locationid == nil || *req.Locationid == 0) {
		var locID uint64
		db.Raw("SELECT id FROM locations WHERE name = ? LIMIT 1", *req.Location).Scan(&locID)
		if locID > 0 {
			req.Locationid = &locID
		}
	}
	if req.Locationid != nil {
		setClauses = append(setClauses, "locationid = ?")
		args = append(args, *req.Locationid)
	}
	if req.Lat != nil {
		setClauses = append(setClauses, "lat = ?")
		args = append(args, *req.Lat)
	}
	if req.Lng != nil {
		setClauses = append(setClauses, "lng = ?")
		args = append(args, *req.Lng)
	}

	if len(setClauses) > 0 {
		args = append(args, req.ID)
		db.Exec("UPDATE messages SET "+strings.Join(setClauses, ", ")+" WHERE id = ?", args...)
	}

	// Keep the spatial index point in sync when an already-indexed message's location
	// changes. We deliberately UPDATE only — never INSERT — so editing a Pending
	// message's location cannot leak it into messages_spatial (which backs the public
	// browse). Only Approved messages have a spatial row; the approval path inserts.
	if req.Lat != nil && req.Lng != nil {
		db.Exec("UPDATE messages_spatial SET point = ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857) WHERE msgid = ?",
			*req.Lng, *req.Lat, req.ID)
	}

	// PHP parity (message.php:371-372): when a groupid is supplied, persist it to
	// messages_drafts so the subsequent JoinAndPost reads the user's chosen group
	// rather than the original one from RejectToDraft.  Without this, the group
	// change is silently dropped and the message is reposted to the wrong community.
	// The UPDATE is a no-op when the message is not in draft state (0 rows affected).
	if req.Groupid != nil && *req.Groupid > 0 {
		var groupExists int64
		db.Raw("SELECT COUNT(*) FROM `groups` WHERE id = ?", *req.Groupid).Scan(&groupExists)
		if groupExists > 0 {
			db.Exec("UPDATE messages_drafts SET groupid = ? WHERE msgid = ?", *req.Groupid, req.ID)
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
			db.Exec("DELETE FROM messages_outcomes WHERE msgid = ? AND outcome = 'Expired'", req.ID)
		}
	}

	// Update item if provided.
	if req.Item != nil && *req.Item != "" {
		var itemID uint64
		db.Raw("SELECT id FROM items WHERE name = ?", *req.Item).Scan(&itemID)
		if itemID == 0 {
			// Genuinely new item — insert it.
			db.Exec("INSERT INTO items (name) VALUES (?)", *req.Item)
			db.Raw("SELECT id FROM items WHERE name = ?", *req.Item).Scan(&itemID)
		}
		// Do NOT update items.name when found by case-insensitive match.
		// items is a shared canonical dictionary; normalising the casing from a single
		// message edit would flip-flop the name globally every time a different mod
		// happens to use a different casing. The subject is rebuilt below using the
		// explicitly-provided req.Item string, so the desired casing is preserved in
		// messages.subject without touching the shared dictionary.
		if itemID > 0 {
			db.Exec("DELETE FROM messages_items WHERE msgid = ?", req.ID)
			db.Exec("INSERT INTO messages_items (msgid, itemid) VALUES (?, ?)", req.ID, itemID)
		}
	}

	// Reconstruct subject from type + item + location when item/type/location/areaname changed.
	if req.Item != nil || req.Type != nil || req.Location != nil || req.Locationid != nil || req.Areaname != nil {
		var msgType string
		var itemName *string
		db.Raw("SELECT type FROM messages WHERE id = ?", req.ID).Scan(&msgType)
		if req.Item != nil && *req.Item != "" {
			// Use the submitted name directly so the moderator's desired casing is
			// preserved in the subject without altering the shared items dictionary.
			itemName = req.Item
		} else {
			db.Raw("SELECT i.name FROM items i INNER JOIN messages_items mi ON mi.itemid = i.id WHERE mi.msgid = ? LIMIT 1", req.ID).Scan(&itemName)
		}

		// Build the location string. When the mod supplied an areaname, pass it as
		// an override so the spelling they chose is used rather than the mapping default
		// (Discourse #9769/1 — area name reset to mapping default on save).
		var locStr string
		if req.Areaname != nil && *req.Areaname != "" {
			locStr = constructLocationString(db, req.ID, *req.Areaname)
		} else {
			locStr = constructLocationString(db, req.ID)
		}

		if itemName != nil && locStr != "" {
			// Use the group keyword for the type (V1: group settings, defaults to uppercase).
			groupid := getPrimaryGroupForMessage(db, req.ID)
			keyword := getGroupKeyword(db, groupid, msgType)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			db.Exec("UPDATE messages SET subject = ?, suggestedsubject = ? WHERE id = ?", newSubject, newSubject, req.ID)
		}
	}

	// Issue 1: If the message OWNER edits a rejected message, move back to Pending for re-review.
	// Mods editing a rejected message should NOT auto-resubmit it.
	if fromuser == myid {
		db.Exec("UPDATE messages_groups SET collection = ? WHERE msgid = ? AND collection = ?", utils.COLLECTION_PENDING, req.ID, utils.COLLECTION_REJECTED)
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
			db.Raw("SELECT id, COALESCE(externalmods, '') AS externalmods FROM messages_attachments WHERE id IN (?) AND msgid = ?", req.Attachments, req.ID).Scan(&attRows)
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
				db.Exec("UPDATE messages_attachments SET msgid = ?, `primary` = ? WHERE id = ?", req.ID, primary, attid)
			}
			// Delete any attachments not in the new list.
			db.Exec("DELETE FROM messages_attachments WHERE msgid = ? AND id NOT IN (?)", req.ID, req.Attachments)
		} else {
			// Empty array — remove all attachments.
			db.Exec("DELETE FROM messages_attachments WHERE msgid = ?", req.ID)
		}
	}

	// If subject, type, or textbody changed and user is not mod, create edit record for review.
	// Re-read the current subject from DB — it may have been reconstructed from type/item/location
	// changes above (line 1830-1846), so req.Subject alone is insufficient.
	var current msgValues
	db.Raw("SELECT subject, COALESCE(textbody, '') as textbody, COALESCE(type, '') as type, locationid FROM messages WHERE id = ?", req.ID).Scan(&current)

	// Snapshot new item IDs as JSON (after item update).
	var newItemRows []itemRow
	db.Raw("SELECT itemid AS id FROM messages_items WHERE msgid = ? ORDER BY itemid", req.ID).Scan(&newItemRows)
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
	db.Raw("SELECT id FROM messages_attachments WHERE msgid = ? ORDER BY id", req.ID).Scan(&newAttachRows)
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
			db.Raw("SELECT collection FROM messages_groups WHERE msgid = ? AND groupid = ?", req.ID, gid).Scan(&collection)

			if strings.EqualFold(collection, "Approved") {
				// Check if the group is set to moderate all posts.
				var groupModerated, groupClosed int
				db.Raw("SELECT COALESCE(JSON_EXTRACT(settings, '$.moderated'), 0), COALESCE(JSON_EXTRACT(settings, '$.closed'), 0) FROM `groups` WHERE id = ?", gid).Row().Scan(&groupModerated, &groupClosed)

				if groupModerated == 1 || groupClosed == 1 {
					// Group moderates all posts — this edit needs review.
					reviewRequired = 1
				} else {
					// Check the member's individual posting status.
					var postingStatus *string
					db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ?", myid, gid).Scan(&postingStatus)

					// NULL, empty, or MODERATED → member is moderated → review required.
					if postingStatus == nil || *postingStatus == "" || strings.EqualFold(*postingStatus, "MODERATED") || strings.EqualFold(*postingStatus, "PROHIBITED") {
						reviewRequired = 1
					}
				}
			}
		}

		db.Exec("INSERT INTO messages_edits (msgid, byuser, oldsubject, newsubject, oldtype, newtype, oldtext, newtext, olditems, newitems, oldimages, newimages, oldlocation, newlocation, reviewrequired) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
			req.ID, myid, oldSubject, newSubject, oldType, newType, oldText, newText, oldItemsVal, newItemsVal, oldImagesVal, newImagesVal, oldLocationVal, newLocationVal, reviewRequired)
		db.Exec("UPDATE messages SET editedby = ? WHERE id = ?", myid, req.ID)

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

	return nil
}

// applyPatchMessage performs the edit on a message after auth and ID are resolved.
func applyPatchMessage(c *fiber.Ctx, myid uint64, req patchMessageRequest) error {
	if err := applyPatchMessageCore(c, myid, req); err != nil {
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

	if c.Query("partner") != "" {
		var err error
		myid, err = resolvePartnerAuth(c)
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

	return applyPatchMessage(c, myid, req)
}

// PatchMessageByTN updates a message by TN post ID (PATCH /message/tn/:tnpostid).
func PatchMessageByTN(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	if c.Query("partner") != "" {
		var err error
		myid, err = resolvePartnerAuth(c)
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
	db.Raw("SELECT id FROM messages WHERE tnpostid = ?", tnpostid).Scan(&msgIDs)
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

	for _, msgID := range msgIDs {
		req.ID = msgID

		// TN never sends an explicit attachments array.  We detect photo changes
		// through the textbody:
		//   • No trashnothing.com/pics/ links → user removed all photos → remove AI.
		//   • Links present → scrape+store TN photos AND remove AI (TN photos replace it).
		// In both cases the AI attachment must go; in the second case we also strip the
		// "Check out the pictures…" block from the stored textbody and kick off scraping.
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

		if err := applyPatchMessageCore(c, myid, req); err != nil {
			return err
		}

		// Remove any AI attachment whenever textbody is updated (both cases).
		if req.Textbody != nil {
			var aiCount int64
			db.Raw("SELECT COUNT(*) FROM messages_attachments WHERE msgid = ? AND externalmods LIKE ?",
				msgID, `%"ai":true%`).Scan(&aiCount)
			if aiCount > 0 {
				recordAIDeletions(db, myid, msgID, []uint64{}, nil)
				db.Exec("DELETE FROM messages_attachments WHERE msgid = ? AND externalmods LIKE ?",
					msgID, `%"ai":true%`)
			}
		}

		// If TN photos were present, scrape them and store as attachments.
		if len(picPageURLs) > 0 {
			TNPhotoScrapeRunner(db, msgID, picPageURLs)
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
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", msgid).Scan(&fromuser)
	if fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	isMod := fromuser != myid && isModForMessage(db, myid, msgid)
	if fromuser != myid && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to delete this message")
	}

	db.Exec("UPDATE messages SET deleted = NOW() WHERE id = ?", msgid)

	// Write audit-log entry when a moderator deletes a message.
	if isMod {
		groupid := getPrimaryGroupForMessage(db, msgid)
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
	db.Raw("SELECT userid FROM users_emails WHERE email = ? LIMIT 1", email).Scan(&existingUID)

	if existingUID > 0 {
		// Existing user — return their ID so the draft is linked to them,
		// but do NOT create a session.  The user must authenticate separately.
		return existingUID, "", nil, nil
	}

	// New user — create user, email, session, JWT.
	// Use raw database/sql to get LastInsertId() from the same result —
	// avoids the GORM connection-pool race where a separate
	// SELECT LAST_INSERT_ID() query could land on a different connection.
	sqlDB, err := db.DB()
	if err != nil {
		return 0, "", nil, fmt.Errorf("failed to get DB connection: %w", err)
	}

	sqlResult, err := sqlDB.Exec("INSERT INTO users (added) VALUES (NOW())")
	if err != nil {
		return 0, "", nil, fmt.Errorf("failed to create user: %w", err)
	}

	newUserIDInt, err := sqlResult.LastInsertId()
	if err != nil || newUserIDInt == 0 {
		return 0, "", nil, fmt.Errorf("failed to get new user ID")
	}
	newUserID := uint64(newUserIDInt)

	// Add email.
	canon := user.CanonicalizeEmail(email)
	db.Exec("INSERT INTO users_emails (userid, email, preferred, validated, canon, backwards) VALUES (?, ?, 1, NOW(), ?, ?)",
		newUserID, email, canon, user.ReverseString(canon))

	// Create session. series must be a random numeric value (bigint
	// unsigned); using userID collided across every session for the same
	// user and defeated UNIQUE KEY (id, series, token).
	series := utils.RandomUint64()
	token := utils.RandomHex(16)
	db.Exec("INSERT INTO sessions (userid, series, token, lastactive) VALUES (?, ?, ?, NOW())",
		newUserID, series, token)

	// Use token to find our specific session (avoids race with concurrent requests).
	var sessionID uint64
	db.Raw("SELECT id FROM sessions WHERE userid = ? AND token = ? ORDER BY id DESC LIMIT 1", newUserID, token).Scan(&sessionID)

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
func PutMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	type PutMessageRequest struct {
		Groupid            uint64   `json:"groupid"`
		Type               string   `json:"type"`
		Messagetype        string   `json:"messagetype"` // Client sends this; alias for Type.
		Subject            string   `json:"subject"`
		Item               string   `json:"item"`
		Textbody           string   `json:"textbody"`
		Collection         string   `json:"collection"` // Draft (default) or Pending.
		Locationid         *uint64  `json:"locationid"`
		Availableinitially *int     `json:"availableinitially"`
		Availablenow       *int     `json:"availablenow"`
		Attachments        []uint64 `json:"attachments"`
		Email              string   `json:"email"`
	}

	var req PutMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
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

	// For non-Draft, check membership and fetch posting status in one query.
	var ourPostingStatus *string
	var isMember bool
	if req.Collection != "Draft" && req.Groupid > 0 {
		type MembershipInfo struct {
			OurPostingStatus *string
		}
		var info MembershipInfo
		result := db.Raw("SELECT ourPostingStatus FROM memberships WHERE userid = ? AND groupid = ? LIMIT 1", myid, req.Groupid).Scan(&info)
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
	// V1 parity (Message.php:2708/2717): invent a unique messageid because
	// downstream dedupe/cross-reference joins assume it's populated.
	messageid := fmt.Sprintf("%.6f@%s", float64(time.Now().UnixNano())/1e9, utils.USER_DOMAIN)
	if req.Groupid > 0 {
		messageid = fmt.Sprintf("%s-%d", messageid, req.Groupid)
	}
	result := db.Exec("INSERT INTO messages (fromuser, type, subject, textbody, message, arrival, date, source, availableinitially, availablenow, locationid, fromip, messageid) VALUES (?, ?, ?, ?, ?, NOW(), NOW(), 'Platform', ?, ?, ?, ?, ?)",
		myid, req.Type, req.Subject, req.Textbody, req.Textbody, availInit, availNow, req.Locationid, fromip, messageid)

	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create message")
	}

	var newMsgID uint64
	db.Raw("SELECT id FROM messages WHERE fromuser = ? ORDER BY id DESC LIMIT 1", myid).Scan(&newMsgID)

	if newMsgID == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to retrieve message ID")
	}

	// For Draft collection, store in messages_drafts.
	// For other collections, add to messages_groups.
	if req.Collection == "Draft" {
		db.Exec("INSERT INTO messages_drafts (msgid, groupid, userid) VALUES (?, ?, ?)",
			newMsgID, req.Groupid, myid)
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

		db.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival) VALUES (?, ?, ?, NOW())",
			newMsgID, req.Groupid, collection)

		// V1 parity: log Message/Received when a post is submitted directly (non-draft).
		logMessageReceived(db, req.Groupid, myid, newMsgID)
	}

	// Link attachments.
	for _, attID := range req.Attachments {
		db.Exec("UPDATE messages_attachments SET msgid = ? WHERE id = ?", newMsgID, attID)
	}

	// Create item record.
	if req.Item != "" {
		db.Exec("INSERT INTO items (name) VALUES (?) ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)", req.Item)
		var itemID uint64
		db.Raw("SELECT id FROM items WHERE name = ? LIMIT 1", req.Item).Scan(&itemID)
		if itemID > 0 {
			db.Exec("INSERT IGNORE INTO messages_items (msgid, itemid) VALUES (?, ?)", newMsgID, itemID)
		}
	}

	// Add spatial data if locationid is provided, and update the user's last known location
	// (so that GET /isochrone can auto-create an isochrone for the user).
	if req.Locationid != nil && *req.Locationid > 0 {
		db.Exec("UPDATE users SET lastlocation = ? WHERE id = ?", *req.Locationid, myid)

		var lat, lng float64
		db.Raw("SELECT lat, lng FROM locations WHERE id = ?", *req.Locationid).Row().Scan(&lat, &lng)
		if lat != 0 || lng != 0 {
			db.Exec("UPDATE messages SET locationid = ?, lat = ?, lng = ? WHERE id = ?",
				*req.Locationid, lat, lng, newMsgID)
			// Do NOT insert into messages_spatial here — drafts must not appear
			// in browse/search results. Spatial index is populated by handleJoinAndPost
			// after the message is submitted to a group (matching V1 behaviour).
		}

		// Reconstruct subject with location.
		// The initial subject was set as "Type: Item" without location.
		// Now that locationid is set, rebuild as "KEYWORD: Item (Area PC)".
		locStr := constructLocationString(db, newMsgID)
		if locStr != "" && req.Item != "" {
			groupid := req.Groupid
			if groupid == 0 {
				// Draft may not have a group yet; use item name without location keyword.
				groupid = getPrimaryGroupForMessage(db, newMsgID)
			}
			keyword := getGroupKeyword(db, groupid, req.Type)
			newSubject := keyword + ": " + req.Item + " (" + locStr + ")"
			db.Exec("UPDATE messages SET subject = ?, suggestedsubject = ? WHERE id = ?", newSubject, newSubject, newMsgID)
		}
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
	ID        uint64  `json:"id"`
	Action    string  `json:"action"`
	Userid    *uint64 `json:"userid"`
	Count     *int    `json:"count"`
	Outcome   string  `json:"outcome"`
	Happiness *string `json:"happiness"`
	Comment   *string `json:"comment"`
	Message   *string `json:"message"`
	Subject   *string `json:"subject"`
	Body      *string `json:"body"`
	Stdmsgid  *uint64 `json:"stdmsgid"`
	Groupid   *uint64 `json:"groupid"`
	Type      string  `json:"type"`
	Textbody  *string `json:"textbody"`
	Item      *string `json:"item"`
	Partner          *string `json:"partner"`
	Deadline         *string `json:"deadline"`
	Deliverypossible *bool   `json:"deliverypossible"`
	ForcePending     *bool   `json:"forcepending"`
	Tnpostid         *string `json:"tnpostid"`
}

// PostMessage dispatches POST /message actions.
func PostMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	// Partner auth: if partner query param is present, authenticate via partner key
	// instead of JWT. The partner acts on behalf of the identified user.
	partnerKey := c.Query("partner")
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

		myid = user.FindByTNIdOrEmail(db, tnuserid, email)
		if myid == 0 {
			return fiber.NewError(fiber.StatusForbidden, "User not found for partner")
		}
	}

	if myid == 0 {
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
		db.Raw("SELECT id FROM messages WHERE tnpostid = ?", *req.Tnpostid).Scan(&msgIDs)
		if len(msgIDs) == 0 {
			return fiber.NewError(fiber.StatusNotFound, "Message not found for that TN post ID")
		}
		for i, msgID := range msgIDs {
			req.ID = msgID
			if err := dispatchPostMessageAction(c, myid, req); err != nil {
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

	return dispatchPostMessageAction(c, myid, req)
}

// dispatchPostMessageAction routes a POST /message action to the correct handler.
func dispatchPostMessageAction(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
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
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", req.ID).Scan(&msgUserid)
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
	db.Exec("REPLACE INTO messages_promises (msgid, userid) VALUES (?, ?)", req.ID, promisedTo)

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
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", req.ID).Scan(&msgUserid)
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
		db.Exec("INSERT INTO messages_reneged (userid, msgid) VALUES (?, ?)", promisedTo, req.ID)
	}

	// Delete the promise.
	db.Exec("DELETE FROM messages_promises WHERE msgid = ? AND userid = ?", req.ID, promisedTo)

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
	db.Exec("INSERT INTO messages_outcomes_intended (msgid, outcome) VALUES (?, ?) ON DUPLICATE KEY UPDATE outcome = VALUES(outcome)",
		req.ID, req.Outcome)

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
	db.Raw("SELECT type FROM messages WHERE id = ?", req.ID).Scan(&msgType)
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
		db.Raw("SELECT COUNT(*) FROM messages_groups WHERE msgid = ? AND collection = ?", req.ID, utils.COLLECTION_PENDING).Scan(&pendingCount)
		if pendingCount > 0 {
			// Capture the groups the post is actively pending on *before* the
			// soft-delete, so we can write a per-group audit log below.
			var pendingGroups []uint64
			db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ? AND collection = ? AND deleted = 0", req.ID, utils.COLLECTION_PENDING).Scan(&pendingGroups)

			// V1 parity (Message::delete()): soft-delete messages_groups first, then the
			// message itself.  Without this, the orphaned Pending row (deleted=0) gets
			// picked up by AutoApproveService 48 hours later and auto-approved as if the
			// member never withdrew it — making the message reappear in ModTools.
			db.Exec("UPDATE messages_groups SET deleted = 1 WHERE msgid = ? AND collection = ?", req.ID, utils.COLLECTION_PENDING)
			db.Exec("UPDATE messages SET deleted = NOW(), messageid = NULL WHERE id = ?", req.ID)

			// V1 parity (Message::delete() logs SUBTYPE_DELETED per group): without an
			// audit-log entry the post silently vanishes from the mod pending queue while
			// its "Posted"/Received log remains, so mods see "logs say posted but there's
			// no post and it's not in pending" (Discourse #9703). Log a Deleted entry per
			// group: `user` is the message author, `byuser` the actor (the member
			// withdrawing), and text notes that it was a withdrawal.
			var fromuser uint64
			db.Raw("SELECT fromuser FROM messages WHERE id = ?", req.ID).Scan(&fromuser)
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
	db.Raw("SELECT COUNT(*) FROM messages_outcomes WHERE msgid = ?", req.ID).Scan(&existingTotal)
	db.Raw(`SELECT COUNT(*) FROM messages_outcomes
	        WHERE msgid = ?
	          AND (outcome = ? OR (outcome = ? AND comments = 'Auto-expired'))`,
		req.ID, utils.OUTCOME_EXPIRED, utils.OUTCOME_WITHDRAWN).Scan(&autoExpiredCount)
	if existingTotal > 0 && existingTotal != autoExpiredCount {
		return fiber.NewError(fiber.StatusConflict, "Outcome already recorded")
	}

	// Clear any intended outcome.
	db.Exec("DELETE FROM messages_outcomes_intended WHERE msgid = ?", req.ID)

	// Clear any existing outcome (for expired overwrite).
	db.Exec("DELETE FROM messages_outcomes WHERE msgid = ?", req.ID)

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
		db.Exec("INSERT INTO messages_outcomes (msgid, outcome, happiness, comments) VALUES (?, ?, ?, ?)",
			req.ID, req.Outcome, happiness, comment)
	} else {
		db.Exec("INSERT INTO messages_outcomes (msgid, outcome, comments) VALUES (?, ?, ?)",
			req.ID, req.Outcome, comment)
	}

	// Record who took/received the item.
	if (req.Outcome == utils.OUTCOME_TAKEN || req.Outcome == utils.OUTCOME_RECEIVED) && req.Userid != nil && *req.Userid > 0 {
		var availNow int
		db.Raw("SELECT availablenow FROM messages WHERE id = ?", req.ID).Scan(&availNow)
		db.Exec("INSERT INTO messages_by (msgid, userid, count) VALUES (?, ?, ?)",
			req.ID, *req.Userid, availNow)
	}

	// Mark successful in spatial index so that:
	// - isochrone queries exclude it (they filter on successful = 0)
	// - dashboard heatmap includes it (it filters on successful = 1)
	// V1 parity: markSuccessfulInSpatial() in Message.php.
	if req.Outcome == utils.OUTCOME_TAKEN || req.Outcome == utils.OUTCOME_RECEIVED {
		db.Exec("UPDATE messages_spatial SET successful = 1 WHERE msgid = ?", req.ID)
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

	db.Exec("INSERT INTO background_tasks (task_type, data) VALUES (?, JSON_OBJECT('msgid', ?, 'outcome', ?, 'happiness', ?, 'comment', ?, 'userid', ?, 'byuser', ?, 'message', ?))",
		"message_outcome", req.ID, req.Outcome, happiness, comment, userid, myid, messageForOthers)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// canModifyMessage checks if the user is the message poster or a moderator/owner of a group the message is on.
func canModifyMessage(db *gorm.DB, myid uint64, msgid uint64) bool {
	var msgUserid uint64
	db.Raw("SELECT fromuser FROM messages WHERE id = ?", msgid).Scan(&msgUserid)
	if msgUserid == myid {
		return true
	}

	// Check if user is a moderator/owner of any group the message is on.
	var modCount int64
	db.Raw("SELECT COUNT(*) FROM messages_groups mg JOIN memberships m ON mg.groupid = m.groupid WHERE mg.msgid = ? AND m.userid = ? AND m.role IN (?, ?)",
		msgid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&modCount)
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
		db.Raw("SELECT id, count FROM messages_by WHERE msgid = ? AND userid = ?",
			req.ID, *userid).Scan(&existing)
	} else {
		db.Raw("SELECT id, count FROM messages_by WHERE msgid = ? AND userid IS NULL",
			req.ID).Scan(&existing)
	}
	existingID := existing.ID
	existingCount := existing.Count

	if existingID > 0 {
		// Restore old count before updating.
		db.Exec("UPDATE messages SET availablenow = LEAST(availableinitially, availablenow + ?) WHERE id = ?",
			existingCount, req.ID)
		db.Exec("UPDATE messages_by SET count = ? WHERE id = ?", count, existingID)
	} else {
		db.Exec("INSERT INTO messages_by (userid, msgid, count) VALUES (?, ?, ?)",
			userid, req.ID, count)
	}

	// Reduce available count.
	db.Exec("UPDATE messages SET availablenow = GREATEST(LEAST(availableinitially, availablenow - ?), 0) WHERE id = ?",
		count, req.ID)

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
		db.Raw("SELECT id, count FROM messages_by WHERE msgid = ? AND userid = ?",
			req.ID, *req.Userid).Scan(&entry)
	} else {
		db.Raw("SELECT id, count FROM messages_by WHERE msgid = ? AND userid IS NULL",
			req.ID).Scan(&entry)
	}
	entryID := entry.ID
	entryCount := entry.Count

	if entryID > 0 {
		// Restore count and delete entry.
		db.Exec("UPDATE messages SET availablenow = LEAST(availableinitially, availablenow + ?) WHERE id = ?",
			entryCount, req.ID)
		db.Exec("DELETE FROM messages_by WHERE id = ?", entryID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleView records a message view, de-duplicating within 30 minutes.
func handleView(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	// Check for a recent view within 30 minutes to avoid redundant writes.
	var recentCount int64
	db.Raw("SELECT COUNT(*) FROM messages_likes WHERE msgid = ? AND userid = ? AND type = 'View' AND timestamp >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
		req.ID, myid).Scan(&recentCount)

	if recentCount == 0 {
		db.Exec("INSERT INTO messages_likes (msgid, userid, type) VALUES (?, ?, 'View') ON DUPLICATE KEY UPDATE timestamp = NOW(), count = count + 1",
			req.ID, myid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// createSystemChatMessage creates a system chat message between two users for a message.
// If no chat room exists between the users, one is created.
func createSystemChatMessage(db *gorm.DB, fromUser uint64, toUser uint64, refmsgid uint64, msgType string) {
	// Find existing chat room between these users.
	var chatID uint64
	db.Raw("SELECT id FROM chat_rooms WHERE (user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?) LIMIT 1",
		fromUser, toUser, toUser, fromUser).Scan(&chatID)

	if chatID == 0 {
		// Create a User2User chat room. ON DUPLICATE KEY handles race conditions
		// (unique key on user1, user2, chattype).
		// Use raw database/sql to get LastInsertId() from the same result —
		// avoids the GORM connection-pool race.
		sqlDB, err := db.DB()
		if err != nil {
			return
		}
		sqlResult, err := sqlDB.Exec("INSERT INTO chat_rooms (user1, user2, chattype, latestmessage) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), latestmessage = NOW()",
			fromUser, toUser, utils.CHAT_TYPE_USER2USER)
		if err != nil {
			return
		}
		chatIDInt, err := sqlResult.LastInsertId()
		if err != nil || chatIDInt == 0 {
			return
		}
		chatID = uint64(chatIDInt)
	}

	// Insert chat message.
	db.Exec("INSERT INTO chat_messages (chatid, userid, type, refmsgid, date, message, processingrequired) VALUES (?, ?, ?, ?, ?, '', 1)",
		chatID, fromUser, msgType, refmsgid, time.Now())
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
		result := tx.Exec("DELETE FROM messages_groups WHERE msgid = ?", req.ID)
		if result.Error != nil {
			return result.Error
		}
		if result.RowsAffected == 0 {
			return fmt.Errorf("message not found in any group")
		}

		result = tx.Exec("INSERT INTO messages_groups (msgid, groupid, collection, arrival, msgtype) VALUES (?, ?, ?, NOW(), (SELECT type FROM messages WHERE id = ?))",
			req.ID, *req.Groupid, utils.COLLECTION_PENDING, req.ID)
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
		db.Raw("SELECT id, COALESCE(externaluid, '') AS externaluid, externalmods FROM messages_attachments WHERE msgid = ? AND id NOT IN (?)", msgID, keepList).Scan(&candidates)
	} else {
		db.Raw("SELECT id, COALESCE(externaluid, '') AS externaluid, externalmods FROM messages_attachments WHERE msgid = ?", msgID).Scan(&candidates)
	}

	foundAI := false
	for _, att := range candidates {
		if att.Externaluid == "" || !isAIAttachment(att.Externalmods) {
			continue
		}
		foundAI = true
		var aiImageID uint64
		db.Raw("SELECT id FROM ai_images WHERE externaluid = ? LIMIT 1", att.Externaluid).Scan(&aiImageID)
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
		db.Exec("INSERT IGNORE INTO messages_ai_declined (msgid) VALUES (?)", msgID)
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

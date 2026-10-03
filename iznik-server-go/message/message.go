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
	"github.com/freegle/iznik-server-go/driving"
	"github.com/freegle/iznik-server-go/embedding"
	"github.com/freegle/iznik-server-go/item"
	"github.com/freegle/iznik-server-go/location"
	flog "github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/queue"
	"github.com/freegle/iznik-server-go/reachqueue"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/roadblur"
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
	// Road drive time/distance from the VIEWER's home to this post's blurred
	// location, filled by one batched routing call per message fetch (nil when
	// the viewer is logged out, has no location, or the reach engine cannot
	// answer - clients then show crow-flies). Shipping it with the message
	// saves the client a /drivedistance round trip per rendered card.
	Roadmins   *float64           `json:"roadmins,omitempty" gorm:"-"`
	Roadmiles  *float64           `json:"roadmiles,omitempty" gorm:"-"`
	Locationid uint64             `json:"-"`
	Location   *location.Location `json:"location,omitempty" gorm:"-"`
	Item       *item.Item         `json:"item" gorm:"-"`
	// Heldby is messages.heldby directly: a message has one moderation state, not one
	// per group. nil unless the caller is a moderator (see GetMessagesByIds), so a
	// held post does not announce that fact to anyone else.
	Heldby *uint64 `json:"heldby"`
	// Collection is messages.collection: this message's single national moderation state
	// (Incoming/Pending/Approved/Spam/Rejected). There is no per-group collection any more.
	Collection string `json:"collection"`
	// Approvedby/Approvedat/Rejectedat carry the moderation decision. Approvedby is nil
	// unless the caller is a moderator (see GetMessagesByIds), for the same reason as
	// Heldby above: it would otherwise reveal a moderator's identity to a general viewer.
	Approvedby          *uint64    `json:"approvedby"`
	Approvedat          *time.Time `json:"approvedat,omitempty"`
	Rejectedat          *time.Time `json:"rejectedat,omitempty"`
	Autoreposts         int        `json:"autoreposts,omitempty"`
	Lastautopostwarning *time.Time `json:"lastautopostwarning,omitempty"`
	Lastchaseup         *time.Time `json:"lastchaseup,omitempty"`
	// ContentcheckCheckedAt/ContentcheckReasons are moderator-only (see GetMessagesByIds):
	// the reasons name the keyword that flagged the post, which would tell a spammer
	// exactly what to avoid next time.
	ContentcheckCheckedAt *time.Time       `json:"contentcheckcheckedat,omitempty"`
	ContentcheckReasons   *json.RawMessage `json:"contentcheckreasons,omitempty"`
	Source                *string          `json:"source"`
	Sourceheader          *string          `json:"sourceheader"`
	Fromaddr              *string          `json:"fromaddr"`
	Fromip                *string          `json:"fromip"`
	Fromcountry           *string          `json:"fromcountry"`
	Repostat              *time.Time       `json:"repostat"`
	Canrepost             bool             `json:"canrepost"`
	Deliverypossible      bool             `json:"deliverypossible"`
	Deadline              *time.Time       `json:"deadline"`
	Edits                 []MessageEdit    `json:"edits,omitempty" gorm:"-"`
	RawMessage            *string          `json:"message,omitempty" gorm:"column:message"`
	Postings              []MessagePosting `json:"postings,omitempty" gorm:"-"`
	Tnpostid              *string          `json:"tnpostid"`
	Expiresat             *time.Time       `json:"expiresat,omitempty" gorm:"-"`
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
	// ReachFinished: the post's reach has stopped expanding (rippling_reach.status
	// 'done') without covering the viewer, so it is never going to. Set instead of
	// ReachesYouAt, whose final-tick date would already be in the past and read as
	// "any moment now" about a reach that ended weeks ago (Discourse 9808/797). The
	// reply is still held for the moment and released by the finished-reach sweep.
	ReachFinished *bool `json:"reachfinished,omitempty" gorm:"-"`
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
	Msgid      uint64 `json:"msgid"`
	Date       string `json:"date"`
	Repost     bool   `json:"repost"`
	Autorepost bool   `json:"autorepost"`
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

// computeExpiresat calculates when a message expires, using the single
// national maxagetoshow/repost defaults (defaultMaxAgeToShow/defaultRepostOffer/
// defaultRepostWanted/defaultRepostMax below) — there is no more per-group
// settings row to look up. Mirrors the legacy V1 PHP Message::getPublic()
// formula: $expiretime = max($repost * ($reposts['max'] + 1), $maxagetoshow).
//
// Identical sibling of applyExpiry below (ratchet gate h): converted together
// since both did the same per-group settings lookup and must use the same
// national defaults now that there is only one scope.
func computeExpiresat(msgType string, arrival time.Time) *time.Time {
	if arrival.IsZero() {
		return nil
	}

	maxAgeDays := defaultMaxAgeToShow
	repostDays := defaultRepostWanted
	if msgType == utils.OFFER {
		repostDays = defaultRepostOffer
	}

	repostLifetime := repostDays * (defaultRepostMax + 1)
	if repostLifetime > maxAgeDays {
		maxAgeDays = repostLifetime
	}

	expires := arrival.Add(time.Duration(maxAgeDays) * 24 * time.Hour)
	return &expires
}
func rippleEnabled() bool {
	v := os.Getenv("RIPPLE_ENABLED")
	return v == "true" || v == "1"
}

// defaultSearchMode returns the searchmode used when the caller doesn't specify
// one. Vector-hybrid is the default for every caller (public site, ModTools,
// apps). VECTOR_SEARCH_DEFAULT=keyword is the no-deploy rollback lever that
// reverts the whole site to the legacy keyword cascade. Both this env var and
// the ?searchmode param are scheduled for removal once the keyword machinery is
// retired.
func defaultSearchMode() string {
	if os.Getenv("VECTOR_SEARCH_DEFAULT") == "keyword" {
		return "keyword"
	}
	return "vector"
}

// addRoadMetrics fills Roadmins/Roadmiles from the viewer's home for a batch
// of already-blurred messages: ONE routing call for the whole fetch, so the
// client never needs a per-card /drivedistance round trip. Best-effort - any
// failure just leaves the fields nil and clients fall back to crow-flies.
func addRoadMetrics(myid uint64, messages []Message) {
	if myid == 0 || len(messages) == 0 {
		return
	}
	latlng := user.GetLatLng(myid)
	if latlng.Lat == 0 && latlng.Lng == 0 {
		return
	}
	targets := make([]driving.Target, 0, len(messages))
	for ix := range messages {
		if messages[ix].Lat != 0 || messages[ix].Lng != 0 {
			targets = append(targets, driving.Target{
				ID:  int64(ix),
				Lat: float64(messages[ix].Lat),
				Lng: float64(messages[ix].Lng),
			})
		}
	}
	for _, r := range driving.FetchDriveMetrics(roadblur.RoutingURL(), float64(latlng.Lat), float64(latlng.Lng), targets) {
		if r.Mins != nil && r.ID >= 0 && int(r.ID) < len(messages) {
			messages[r.ID].Roadmins = r.Mins
			messages[r.ID].Roadmiles = r.Miles
		}
	}
}

// AddSummaryRoadMetrics fills Roadmins/Roadmiles on feed summaries with ONE routing call
// from the viewer to every post's blurred point. Every feed a drive-minutes budget filters
// must stamp these: the client's slider filter decides from the summary's roadmins on the
// FIRST render, so a feed that omits them forces a per-post async road lookup whose late
// answer flips the filter's verdict — the "posts flash up then collapse to You're up to
// date" flicker. Best-effort: on any routing failure the fields stay nil and the client
// falls back to crow-flies, which is stable (the async lookup fails the same way, so the
// verdict never changes after paint).
func AddSummaryRoadMetrics(viewerLat, viewerLng float64, res []MessageSummary) {
	if len(res) == 0 {
		return
	}
	targets := make([]driving.Target, 0, len(res))
	for ix := range res {
		if res[ix].Lat != 0 || res[ix].Lng != 0 {
			targets = append(targets, driving.Target{
				ID:  int64(ix),
				Lat: res[ix].Lat,
				Lng: res[ix].Lng,
			})
		}
	}
	for _, r := range driving.FetchDriveMetrics(roadblur.RoutingURL(), viewerLat, viewerLng, targets) {
		if r.Mins != nil && r.ID >= 0 && int(r.ID) < len(res) {
			res[r.ID].Roadmins = r.Mins
			res[r.ID].Roadmiles = r.Miles
		}
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
				// isMod
				// is the only toggle (it drives the deleted-sender filter and
				// the raw message field together) - 2 possible rendered forms,
				// both proven by the retired ormharness (shapes.json /
				// TestTier3Shapes_08bb471351a0, removed in d22ba1d6c).
				selectCols := "messages.id, messages.arrival, messages.date, messages.fromuser, " +
					// National model: there is only one scope, so the earliest this post could
					// have been seen IS its arrival — no more per-group MIN to take.
					"messages.arrival AS visible_since, " +
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

			// National: there is no more per-group messages_groups/rippling_proximity data
			// to fetch here. Visibility (formerly "at least one live messages_groups entry")
			// and holds now live directly on messages.collection/heldby, read in the base
			// select above.
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

			// Postings (repost history) are public information, returned to all callers —
			// matching V1 behaviour. National: no more group to name.
			var messagePostings []MessagePosting
			db.Table("messages_postings mp").
				Select("mp.msgid, mp.date, mp.repost, mp.autorepost").
				Where("mp.msgid = ?", id).
				Order("mp.date ASC").
				Scan(&messagePostings)

			// National: message.Heldby is already read straight off messages.heldby by the
			// base select above — there is no more per-group hold to resolve to it. Just
			// strip it from anyone who isn't a moderator.
			if !isMod {
				message.Heldby = nil
			}
			message.Expiresat = computeExpiresat(message.Type, message.Arrival)
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

			// National: a message is publicly visible once it has a Collection (Approved or
			// Pending — set on approval/hold, matching the old "both APPROVED and PENDING are
			// visible to all users" rule), or always to a moderator. A message that never
			// gets a Collection (e.g. a chat message ingested by email) stays hidden, which
			// preserves the original messages_groups privacy gate.
			if found && (message.Collection != "" || isMod) {
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
							}
							message.Location = loc
						}
					} else if message.Lat != 0 && message.Lng != 0 {
						l := location.ClosestPostcode(float32(message.Lat), float32(message.Lng))
						message.Location = &l
					}
				}

				// Protect anonymity of poster a bit.
				message.Lat, message.Lng = roadblur.RoadBlur(message.Lat, message.Lng, utils.BLUR_USER)

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
					message.ContentcheckReasons = nil
					message.ContentcheckCheckedAt = nil
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
						loc = &l
					}()
				}

				wgExtra.Add(1)
				go func() {
					defer wgExtra.Done()

					// National: there is only one scope, so there is only one
					// repost interval — no more per-group settings to OR across.
					// Matches the national defaults used by computeExpiresat.
					interval := defaultRepostWanted
					if message.Type == utils.OFFER {
						interval = defaultRepostOffer
					}

					ra := message.Arrival.AddDate(0, 0, interval)
					repostAt = &ra
					canRepost = ra.Before(time.Now())
				}()

				wgExtra.Wait()

				message.Item = i
				message.Repostat = repostAt
				message.Canrepost = canRepost

				// Precise location only for mods and message owner.
				// Other viewers get blurred lat/lng (handled elsewhere).
				if message.Fromuser == myid || auth.IsModerator(myid) {
					message.Location = loc
				}

				// Bulk-offer catalogue: group the (now path-resolved) attachments by
				// item and attach per-item interest. The full per-user interest list
				// is only visible to the offerer or a moderator.
				canSeeInterest := message.Fromuser == myid || isMod
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
		finished := make(map[uint64]bool, len(reachBlocked))
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
				// A finished reach is not coming. No routing search and no date:
				// the member is told the reach has ended instead (ReachFinished).
				if origin.Finished {
					finished[msgid] = true
					continue
				}
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
				delete(finished, b.Msgid)
			}
		}

		if len(blockedSet) > 0 {
			notEligible := false
			for ix := range messages {
				if blockedSet[messages[ix].ID] {
					messages[ix].ReplyEligible = &notEligible

					// Only the reach reason carries an arrival. A ban also lands in
					// blockedSet and has no coverage entry, so it stays nil.
					if finished[messages[ix].ID] {
						done := true
						messages[ix].ReachFinished = &done
					} else if cov, found := coverage[messages[ix].ID]; found {
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

			// One batched routing call resolves every location's road-aware blur.
			blurCoords := make([][2]float64, 0, len(msgs))
			for _, r := range msgs {
				blurCoords = append(blurCoords, [2]float64{float64(r.Lat), float64(r.Lng)})
			}
			roadblur.RoadBlurPrewarm(blurCoords, utils.BLUR_USER)
			for ix, r := range msgs {
				// Protect anonymity of poster a bit.
				msgs[ix].Lat, msgs[ix].Lng = roadblur.RoadBlur(r.Lat, r.Lng, utils.BLUR_USER)
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

// applyExpiry computes national expiry (the single defaultMaxAgeToShow/
// defaultRepost* set below — there are no more per-group settings) and marks
// expired messages. Messages past their expiry age are kept alive only if
// there is an ongoing chat within 6 days. Returns the indices of expired
// messages.
//
// Identical sibling of computeExpiresat above (ratchet gate h): converted
// together since both did the same per-group settings lookup.
func applyExpiry(db *gorm.DB, msgs []MessageSummary) []int {
	if len(msgs) == 0 {
		return nil
	}

	now := time.Now()
	var candidateIDs []uint64
	candidateIndices := map[uint64][]int{}

	for i := range msgs {
		m := &msgs[i]
		if m.Hasoutcome {
			continue
		}

		repostDays := defaultRepostWanted
		if m.Type == utils.OFFER {
			repostDays = defaultRepostOffer
		}

		expireTime := defaultMaxAgeToShow
		maxReposts := repostDays * (defaultRepostMax + 1)
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

	// ?originonly=true is the Approved Messages "Only this group's own posts (hide
	// rippled-in)" box. The listing honoured it and a search with a term dropped it, so
	// every result of a search could be a rippled-in copy (Discourse 9808/798). Applied
	// at every return that carries results, like applyBrowseFilters.
	originOnly := c.Query("originonly") == "true"
	applyOriginOnly := func(rs []SearchResult) []SearchResult {
		if !originOnly {
			return rs
		}
		return dropRippledIn(db, rs, groupids)
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
				return c.JSON(applyOriginOnly(byID))
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
	var browseMaxMinutes float64
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
		var rawDist, rawDefaultDist, rawSort, rawMins string
		db.Table("users").
			Select("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxDistance')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseReachMaxDistance')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseSort')), ''), "+
				"COALESCE(JSON_UNQUOTE(JSON_EXTRACT(settings, '$.browseMaxMinutes')), '')").
			Where("id = ?", myid).
			Row().Scan(&rawDist, &rawDefaultDist, &rawSort, &rawMins)
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
		// The drive-minutes budget, resolved like isochrone.resolveMaxMinutes: the
		// member's own setting only, applied below only while the miles slider is
		// limited - the same precedence the feed, the badge and the digest use.
		if v, err := strconv.ParseFloat(rawMins, 64); err == nil && v > 0 {
			browseMaxMinutes = v
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
	// distance from the member, apply the "How far away" slider cap - drive MINUTES first when
	// the member has a budget and the routing engine answers, crow miles otherwise, the same
	// rule as the feed, the badge and the digest - and order by "Sort by". (The universe
	// itself is enforced at candidate selection above.) Applied at every return.
	applyBrowseFilters := func(rs []SearchResult) []SearchResult {
		// Every result, browse-scoped or not, carries the two dates the browse card and its
		// order are built from (SearchResult.Posted/VisibleSince) - stamped before the
		// browse-only work below so no return path leaves them zero.
		if len(rs) > 0 {
			ids := make([]uint64, 0, len(rs))
			for _, r := range rs {
				ids = append(ids, r.Msgid)
			}
			when := whenVisible(db, ids)
			for i := range rs {
				if w, ok := when[rs[i].Msgid]; ok {
					rs[i].Posted = w.Posted
					rs[i].VisibleSince = w.VisibleSince
				}
			}
		}
		if !browseScoped || (memberLat == 0 && memberLng == 0) {
			return rs
		}
		for i := range rs {
			rs[i].Distance = utils.Haversine(memberLat, memberLng, rs[i].Lat, rs[i].Lng)
		}
		if browseMaxMiles < browseDistanceUnlimited {
			// Road metrics in ONE batched call, stamped on the results so the client's
			// payload-only verdict and Closest sort see the same numbers the filter
			// used. Only fetched when the budget can apply; failures leave the fields
			// nil and the crow rule below governs, stably.
			if browseMaxMinutes > 0 {
				targets := make([]driving.Target, 0, len(rs))
				for i := range rs {
					if rs[i].Lat != 0 || rs[i].Lng != 0 {
						targets = append(targets, driving.Target{ID: int64(i), Lat: rs[i].Lat, Lng: rs[i].Lng})
					}
				}
				for _, r := range driving.FetchDriveMetrics(roadblur.RoutingURL(), memberLat, memberLng, targets) {
					if r.Mins != nil && r.ID >= 0 && int(r.ID) < len(rs) {
						rs[r.ID].Roadmins = r.Mins
						rs[r.ID].Roadmiles = r.Miles
					}
				}
			}
			kept := rs[:0]
			for _, r := range rs {
				if browseMaxMinutes > 0 && r.Roadmins != nil {
					if *r.Roadmins <= browseMaxMinutes {
						kept = append(kept, r)
					}
					continue
				}
				if r.Distance <= browseMaxMiles {
					kept = append(kept, r)
				}
			}
			rs = kept
		}
		switch browseSort {
		case "Nearby": // the client's "Closest" option - road miles when stamped, like the feed
			sort.SliceStable(rs, func(i, j int) bool {
				di, dj := rs[i].Distance, rs[j].Distance
				if rs[i].Roadmiles != nil {
					di = *rs[i].Roadmiles
				}
				if rs[j].Roadmiles != nil {
					dj = *rs[j].Roadmiles
				}
				return di < dj
			})
		case "Newest":
			// The feed's clock, VisibleSince (stamped above) - NOT SearchResult.Arrival, the
			// ripple-bumped messages_spatial arrival, which floats days-old posts to the top
			// whenever their reach grows (Discourse 9844), and not the write time either,
			// which is what the client's fallback sorted by while the cards showed the
			// repost date (Discourse 9808/801). Same key the client re-sorts on.
			sort.SliceStable(rs, func(i, j int) bool { return rs[i].VisibleSince.After(rs[j].VisibleSince) })
		}
		return rs
	}

	searchmode := c.Query("searchmode", defaultSearchMode())

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
				vectorResults, vectorStats, vectorErr = VectorSearch(term, SEARCH_LIMIT, universeSet, msgtype,
					float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			go func() {
				defer hybridWg.Done()
				if len(expandedWords) > 0 {
					keyExact = GetWordsExact(db, expandedWords, SEARCH_LIMIT, groupids, universeIDs, msgtype,
						float32(nelat), float32(nelng), float32(swlat), float32(swlng))
					keyStarts = GetWordsStarts(db, expandedWords, SEARCH_LIMIT, groupids, universeIDs, msgtype,
						float32(nelat), float32(nelng), float32(swlat), float32(swlng))
				}
			}()

			hybridWg.Wait()

			fallbackTaken := vectorErr != nil
			logVectorSearch(term, msgtype, myid, searchmode, len(vectorResults), fallbackTaken, vectorStats)

			if vectorErr != nil {
				fmt.Printf("Vector search failed: %v\n", vectorErr)
			}

			// Merge: vector results first (semantic ranking), then keyword-only
			// results the embedding missed (exact-match guarantee).
			merged := mergeHybrid(vectorResults, append(keyExact, keyStarts...))

			if len(merged) > 0 {
				wg.Wait()
				return c.JSON(applyOriginOnly(applyBrowseFilters(merged)))
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
				res = GetWordsExact(db, words, SEARCH_LIMIT, groupids, universeIDs, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			go func() {
				defer wg.Done()
				// Add in prefix matches, which helps with plurals.
				res2 = GetWordsStarts(db, words, SEARCH_LIMIT, groupids, universeIDs, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}()

			wg.Wait()

			res = append(res, res2...)

			if len(res) == 0 {
				res = GetWordsTypo(db, words, SEARCH_LIMIT, groupids, universeIDs, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}

			if len(res) == 0 {
				res = GetWordsSounds(db, words, SEARCH_LIMIT, groupids, universeIDs, msgtype, float32(nelat), float32(nelng), float32(swlat), float32(swlng))
			}

			// Blur: one batched routing call, then cache hits.
			blurCoords2 := make([][2]float64, 0, len(res))
			for _, r := range res {
				blurCoords2 = append(blurCoords2, [2]float64{float64(r.Lat), float64(r.Lng)})
			}
			roadblur.RoadBlurPrewarm(blurCoords2, utils.BLUR_USER)
			for ix, r := range res {
				res[ix].Lat, res[ix].Lng = roadblur.RoadBlur(r.Lat, r.Lng, utils.BLUR_USER)
			}
		}
	}

	// Return results where Msgid is not 0, deduplicated by msgid. The keyword path
	// merges an exact-match pass with a starts-with pass (res2); any exact match is
	// also a starts-with match, so without this dedup essentially every match would be
	// returned twice. A message cross-posted to several of the searched groups likewise
	// yields one spatial row per group and must collapse to a single result. We keep the
	// first occurrence (exact matches are appended first, so they win). This mirrors the
	// dedup mergeHybrid already applies on the vector path.
	filtered := []SearchResult{}
	seen := make(map[uint64]bool, len(res))

	for _, r := range res {
		if r.Msgid != 0 && !seen[r.Msgid] {
			seen[r.Msgid] = true
			filtered = append(filtered, r)
		}
	}

	wg.Wait()

	return c.JSON(applyOriginOnly(applyBrowseFilters(filtered)))
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
func logModAction(db *gorm.DB, logType string, subtype string, userid uint64, byuser uint64, msgid uint64, stdmsgid uint64, text string) {
	// `user` is a reserved word in MySQL — backtick to match V1's Log::log().
	if stdmsgid > 0 {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": logType, "subtype": subtype,
			"user": userid, "byuser": byuser, "msgid": msgid, "stdmsgid": stdmsgid, "text": text,
		})
	} else {
		db.Table("logs").Create(map[string]interface{}{
			"timestamp": gorm.Expr("NOW()"), "type": logType, "subtype": subtype,
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
func logMessageReceived(db *gorm.DB, fromuser uint64, msgid uint64) {
	var messageid string
	db.Table("messages").Select("COALESCE(messageid, '')").Where("id = ?", msgid).Scan(&messageid)
	result := db.Table("logs").Create(map[string]interface{}{
		"timestamp": gorm.Expr("NOW()"), "type": flog.LOG_TYPE_MESSAGE, "subtype": flog.LOG_SUBTYPE_RECEIVED,
		"user": fromuser, "byuser": gorm.Expr("NULL"), "msgid": msgid, "text": messageid,
	})
	if result.Error != nil {
		log.Printf("Failed to log Message/Received for msg %d: %v", msgid, result.Error)
	}
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

// MessageModContext holds common context needed by mod action handlers.
type MessageModContext struct {
	Fromuser uint64
	Subject  string
}

// getMessageModContext checks mod permission and fetches common context for mod actions.
// Returns nil if the user is not a moderator for this message.
func getMessageModContext(db *gorm.DB, myid uint64, msgid uint64) *MessageModContext {
	if !auth.IsModerator(myid) {
		return nil
	}
	ctx := &MessageModContext{}
	row := db.Table("messages").Select("fromuser, subject").Where("id = ?", msgid).Row()
	if err := row.Scan(&ctx.Fromuser, &ctx.Subject); err != nil {
		log.Printf("Failed to fetch mod context for message %d: %v", msgid, err)
		return nil
	}
	return ctx
}


// addApprovedMessageToSpatialIndex inserts/updates the messages_spatial row for a
// message that has just become Approved, so it appears in browse/search immediately
// instead of waiting for the every-5-minute reconciler (MessageSpatialService).
// messages_spatial backs the public browse/map, so it must only contain Approved
// messages with a location — Pending/Spam/Rejected must never be added here. The
// query re-checks collection=Approved so this is a safe no-op if called otherwise.
//
// messages_spatial is keyed on msgid alone: one row per message, nationally.
func addApprovedMessageToSpatialIndex(db *gorm.DB, msgid uint64) {
	type spatialRow struct {
		Lat     float64
		Lng     float64
		Msgtype string
		Arrival string
	}
	var rows []spatialRow
	// Pin to the write host: the caller has just UPDATEd messages.collection to
	// Approved on the source. Under the read/write split a plain SELECT would be
	// routed to the read replica, which may not have applied that write yet (Galera
	// apply-lag), so the row would be missed and the post left out of the spatial
	// index until the periodic reconciler runs.
	db.Clauses(dbresolver.Write).Table("messages").
		Select("messages.lat AS lat, messages.lng AS lng, messages.type AS msgtype, "+
			"DATE_FORMAT(messages.arrival, '%Y-%m-%d %H:%i:%s') AS arrival").
		Joins("LEFT JOIN messages_outcomes ON messages_outcomes.msgid = messages.id").
		Where("messages.id = ? AND messages.collection = ? "+
			"AND messages.deleted IS NULL "+
			"AND messages.lat IS NOT NULL AND messages.lng IS NOT NULL "+
			"AND messages_outcomes.id IS NULL",
			msgid, utils.COLLECTION_APPROVED).
		Scan(&rows)

	for _, row := range rows {
		if row.Lat == 0 && row.Lng == 0 {
			continue
		}

		db.Table("messages_spatial").Clauses(clause.OnConflict{
			DoUpdates: clause.Set{
				{Column: clause.Column{Name: "point"}, Value: clause.Column{Table: "excluded", Name: "point"}},
				{Column: clause.Column{Name: "msgtype"}, Value: clause.Column{Table: "excluded", Name: "msgtype"}},
				{Column: clause.Column{Name: "arrival"}, Value: clause.Column{Table: "excluded", Name: "arrival"}},
			},
		}).Create(map[string]interface{}{
			"msgid":   msgid,
			"point":   gorm.Expr("ST_GeomFromText(CONCAT('POINT(', ?, ' ', ?, ')'), 3857)", row.Lng, row.Lat),
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

// handlePartnerConsent records partner consent on a message.
// Requires mod role and partner name.
func handlePartnerConsent(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if !auth.IsModerator(myid) {
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

	// Write the mod log entry synchronously, exactly once, like the other mod actions
	// (hold/release/repost/edit). Previously the log was written by the batch processor,
	// but that INSERT is unconditional and runs again whenever the task is retried (e.g.
	// after a transient email-spool failure), producing duplicate "Replied" rows in the
	// mod history (Discourse 9672/6). The batch now skips the log for this action.
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPLIED, ctx.Fromuser, myid, req.ID, stdmsgid, subject)

	// Queue the email via background task (the log is already written above).
	// Identical golden to
	// b25ea3ba4ade, 02b3821ea3b9 and 7603ee833330; converted together per gate (h).
	db.Table("background_tasks").Create(map[string]interface{}{
		"task_type": "email_message_reply",
		"data": gorm.Expr("JSON_OBJECT('msgid', ?, 'byuser', ?, 'subject', ?, 'body', ?, 'stdmsgid', ?, 'action', ?, 'notifyposter', ?)",
			req.ID, myid, subject, body, stdmsgid, "Leave Approved Message", 1),
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
	isMod := auth.IsModerator(myid)
	if !isOwner && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to convert this message to draft")
	}

	tx := db.Begin()
	if tx.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction failed")
	}

	// Capture any mod-applied hold before the message leaves the live site, so
	// JoinAndPostAs can restore it on repost without a mod having released it
	// (Discourse 9946/8).
	var heldby *uint64
	tx.Table("messages").Select("heldby").Where("id = ?", req.ID).Scan(&heldby)

	// messages_drafts is unique per msgid, so a message has at most one draft
	// row. INSERT IGNORE keeps an existing draft row intact.
	if err := tx.Table("messages_drafts").Clauses(clause.Insert{Modifier: "IGNORE"}).Create(map[string]interface{}{
		"msgid":  req.ID,
		"heldby": heldby,
		"userid": myid,
	}).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to create draft")
	}

	// Take the message off the live site.
	if err := tx.Table("messages").Where("id = ?", req.ID).Update("deleted", gorm.Expr("NOW()")).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to withdraw message")
	}

	// Clear any previous outcome so the reposted message starts fresh. Without
	// this, a message that was withdrawn still shows as "withdrawn" in posting
	// history after reposting — the same wrong behaviour as V1.
	if err := tx.Table("messages_outcomes").Where("msgid = ?", req.ID).Delete(nil).Error; err != nil {
		tx.Rollback()
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to clear outcome")
	}
	tx.Table("messages_outcomes_intended").Where("msgid = ?", req.ID).Delete(nil)

	// Reset availablenow to availableinitially — if the item was promised to
	// someone who never collected, the repost should offer the full quantity
	// again. Also clear messages_by so there are no stale promise records.
	tx.Table("messages").Where("id = ?", req.ID).Update("availablenow", gorm.Expr("availableinitially"))
	tx.Table("messages_by").Where("msgid = ?", req.ID).Delete(nil)

	if err := tx.Commit().Error; err != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Transaction commit failed")
	}

	// Clear deadline if it's in the past or today — an old deadline is no
	// longer relevant when reposting and would cause the message to appear
	// expired.
	var deadline *string
	db.Table("messages").Select("deadline").Where("id = ?", req.ID).Scan(&deadline)
	if deadline != nil && *deadline != "" {
		today := time.Now().Format("2006-01-02")
		if *deadline <= today {
			db.Table("messages").Where("id = ?", req.ID).Update("deadline", gorm.Expr("NULL"))
		}
	}

	// Log the repost action.
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REPOST, fromuser, myid, req.ID, 0, "Repost started")

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

// messageKeyword returns the fixed English subject-line keyword for a message
// type. Every community used to be able to set its own (e.g. "FREEBIE" instead
// of "OFFER") via a per-group keyword table; frozen-settings.md fixes it at
// the site-wide majority value (OFFER/WANTED English keywords) and the table
// and its readers are gone.
func messageKeyword(msgType string) string {
	switch msgType {
	case utils.WANTED:
		return "WANTED"
	case utils.TAKEN:
		return "TAKEN"
	case utils.RECEIVED:
		return "RECEIVED"
	default:
		return "OFFER"
	}
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

	// Fetch any hold RejectToDraft captured earlier, so it can be reapplied
	// below (Discourse 9946/8): a member's edit-and-repost should not lose a
	// mod's hold just because they went round the draft loop again. There is
	// no group to scope the hold to any more - it is just this message.
	type draftInfo struct {
		Heldby *uint64
	}
	var draft draftInfo
	db.Table("messages_drafts").Select("heldby").Where("msgid = ?", req.ID).Limit(1).Scan(&draft)

	// A site-wide ban blocks posting outright (users.banned/bannedby, which
	// replaced the old per-group users_banned table).
	var banned *time.Time
	db.Table("users").Select("banned").Where("id = ?", myid).Scan(&banned)
	if banned != nil {
		return fiber.NewError(fiber.StatusForbidden, "You are banned")
	}

	// All messages start Pending — the content check batch job runs content checks
	// and promotes clean messages from non-moderated users to Approved.
	collection := utils.COLLECTION_PENDING
	var postingStatus *string
	db.Table("users").Select("postingstatus").Where("id = ?", myid).Scan(&postingStatus)

	if postingStatus != nil && strings.EqualFold(*postingStatus, utils.POSTING_STATUS_PROHIBITED) {
		return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post")
	}

	// Reconstruct subject with location and keyword before submitting. The draft
	// subject may have been set without a location, or the message may have been
	// created before the keyword logic below existed.
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
			keyword := messageKeyword(msg.Type)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			// Identical golden to
			// 2f30762bf955 (applyPatchMessageCore) and b53892a17f40 (PutMessageAs);
			// converted together per gate (h).
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"subject": newSubject, "suggestedsubject": newSubject})
		}
	}

	// Refuse to submit a draft with no subject. This catches pre-validation
	// drafts created before PUT /message required item, and any other path
	// that leaves subject empty by submit time.
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

	// Submit: the message carries one national collection value, so there is
	// no per-group row to insert any more. Set it explicitly (rather than
	// relying on the column default) because a repost via RejectToDraft can
	// be submitting a message whose collection is still Rejected or Spam.
	updateFields := map[string]interface{}{
		"collection": collection,
		"arrival":    gorm.Expr("NOW()"),
	}
	if draft.Heldby != nil {
		// Restore the hold RejectToDraft captured earlier - preserves a mod's
		// hold across a member's edit-and-repost without a mod having
		// released it (Discourse 9946/8).
		updateFields["heldby"] = *draft.Heldby
	}
	db.Table("messages").Where("id = ?", req.ID).Updates(updateFields)

	// Clear any previous outcomes (V1 parity: submit() always deletes outcomes before re-posting).
	// Identical golden to 854c7e93efe3
	// and a08c7f4426c7; converted together per gate (h).
	db.Table("messages_outcomes").Where("msgid = ?", req.ID).Delete(nil)
	reachqueue.BumpReachForRepost(db, req.ID)
	// Identical golden to 0486830f6eda
	// and 4064113639bf; converted together per gate (h).
	db.Table("messages_outcomes_intended").Where("msgid = ?", req.ID).Delete(nil)

	// Record posting (V1 parity: submit() inserts into messages_postings each time a message is submitted).
	db.Table("messages_postings").Create(map[string]interface{}{"msgid": req.ID})

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
	logMessageReceived(db, myid, req.ID)

	// Do NOT add to messages_spatial here. Every post starts Pending (see above),
	// and messages_spatial backs the public browse/map — which is shown to all users,
	// including logged-out ones. So it must only ever contain Approved messages.
	// The message is added to the spatial index when it becomes Approved: either the
	// content-check batch job (messages:contentcheck) auto-promotes it, or a moderator
	// restores/approves it. The poster still sees their own pending post immediately
	// via the fromuser branch of the browse query.

	// Check if user has a password (to determine if they're a new user).
	var hasPassword int64
	db.Table("users_logins").Where("userid = ? AND type = ?", myid, utils.LOGIN_TYPE_NATIVE).Count(&hasPassword)

	resp := fiber.Map{
		"ret":    0,
		"status": "Success",
		"id":     req.ID,
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
	ID uint64 `json:"id"`
	// Action selects Restore, TakeDown, or (nil, or explicitly "Edit") the plain
	// field edit below. See applyPatchMessage. Reason is used only by TakeDown.
	Action             *string         `json:"action"`
	Reason             *string         `json:"reason"`
	Subject            *string         `json:"subject"`
	Textbody           *string         `json:"textbody"`
	Type               *string         `json:"type"`
	Msgtype            *string         `json:"msgtype"`
	Messagetype        *string         `json:"messagetype"`
	Item               *string         `json:"item"`
	Availablenow       *int            `json:"availablenow"`
	Availableinitially *int            `json:"availableinitially"`
	Lat                *float64        `json:"lat"`
	Lng                *float64        `json:"lng"`
	Location           *string         `json:"location"`
	Locationid         *uint64         `json:"locationid"`
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
func buildApplyPatchMessageCoreUpdateSet(subject, textbody, msgType, deadline *string, availablenow, availableinitially *int, locationid *uint64, effLat, effLng *float64) clause.Set {
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
	// The owner editing "How many?" is telling us how many there are - they may
	// have found a few more - so the edited quantity moves availableinitially
	// too. Only an edit does: giving items away moves availablenow alone, which
	// is what makes availableinitially the count to measure the give-away
	// against. Leaving it behind broke both readers of that assumption -
	// handleAddBy/handleRemoveBy clamp with LEAST(availableinitially, ...), so
	// the first item taken from a post edited up from 1 to 5 collapsed it back
	// to 1, and applyRepost resets availablenow to availableinitially, so a
	// redraft did the same. 169 live offers in 90 days are in that state.
	// MessageEditModal.vue sends both keys; a caller that sends only
	// availablenow (TN partner edits) mirrors it, as handlePutMessage does.
	if availableinitially != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "availableinitially"}, Value: *availableinitially})
	} else if availablenow != nil {
		set = append(set, clause.Assignment{Column: clause.Column{Name: "availableinitially"}, Value: *availablenow})
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
	isMod := auth.IsModerator(myid)

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
	// Master's availablenow/deadline SET entries are not repeated here: this
	// branch assembles the whole SET list in
	// buildApplyPatchMessageCoreUpdateSet, which already covers those columns
	// (and availableinitially alongside availablenow) with the same
	// NULL-on-empty rule. Master's deadlineDate() conversion is
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

	if set := buildApplyPatchMessageCoreUpdateSet(req.Subject, req.Textbody, req.Type, req.Deadline, req.Availablenow, req.Availableinitially, req.Locationid, effLat, effLng); len(set) > 0 {
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
			// National model: there is no per-group keyword table any more, so
			// the subject uses the plain uppercased message type (OFFER/WANTED).
			keyword := strings.ToUpper(msgType)
			newSubject := keyword + ": " + *itemName + " (" + locStr + ")"
			// Identical golden to
			// a218fb801dd5 (JoinAndPostAs) and b53892a17f40 (PutMessageAs);
			// converted together per gate (h).
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"subject": newSubject, "suggestedsubject": newSubject})
		}
	}

	// Issue 1: national model has no Pending queue to move a rejected message
	// back into for re-review - a post is either live or taken down, and taking
	// down is not a place to wait. Instead, when the OWNER (not a mod) edits a
	// taken-down message, clear the takedown so the automatic content-check
	// recheck below (triggered by editedat advancing past
	// contentcheck_checked_at) picks the edited text up again, same as any
	// other edit. A mod editing someone else's taken-down message does NOT
	// auto-restore it - only the owner fixing their own content re-enters the
	// pipeline; a mod who wants it live uses the Restore action instead.
	if fromuser == myid {
		db.Table("messages").Where("id = ? AND deleted IS NOT NULL", req.ID).
			Update("deleted", gorm.Expr("NULL"))
	}

	// Issue 2: Log the edit (type='Message', subtype='Edit').
	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_EDIT, fromuser, myid, req.ID, 0, "Message edited")

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
				"editedat":             gorm.Expr("NOW()"),
				"editedby":             myid,
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

	if subjectChanged || textChanged || typeChanged || locationChanged || itemsChanged || imagesChanged {
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

		// National model: there is no per-group moderation status to check
		// review against, and no group mods to notify - a mod's edit is
		// trusted, and a member's edit re-enters the same automatic
		// content-check pipeline as any other edit (see the editedat/
		// contentcheck_reasons stamp above), not a human review queue.
		// reviewrequired is kept as a column (messages_edits is still the
		// audit trail /api/changes reads) but is always 0 now.
		db.Table("messages_edits").Create(map[string]interface{}{
			"msgid": req.ID, "byuser": myid, "oldsubject": oldSubject, "newsubject": newSubject,
			"oldtype": oldType, "newtype": newType, "oldtext": oldText, "newtext": newText,
			"olditems": oldItemsVal, "newitems": newItemsVal, "oldimages": oldImagesVal, "newimages": newImagesVal,
			"oldlocation": oldLocationVal, "newlocation": newLocationVal, "reviewrequired": 0,
		})
		db.Table("messages").Where("id = ?", req.ID).Update("editedby", myid)
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
// PATCH /message carries exactly three actions per modtools-rework.md: Restore,
// TakeDown, and Edit (the plain field-edit path this function already had -
// Approve/Reject/Hold/Release/BackToPending no longer exist, there is no queue
// for a post to wait in). Action nil, or "Edit", falls through to the edit.
func applyPatchMessage(c *fiber.Ctx, myid uint64, req patchMessageRequest) error {
	if req.Action != nil {
		switch *req.Action {
		case "Restore":
			return handleMessageRestore(c, myid, req.ID)
		case "TakeDown":
			return handleMessageTakeDown(c, myid, req.ID, req.Reason)
		case "Edit":
			// Falls through to the ordinary field edit below.
		default:
			return fiber.NewError(fiber.StatusBadRequest, "Unknown action")
		}
	}

	if err := applyPatchMessageCore(c, myid, req, false); err != nil {
		return err
	}
	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleMessageRestore implements PATCH /message {action: "Restore"}. It writes
// exactly the rows Laravel's App\Services\TakedownService::restore() writes -
// content check, judge, report resolution and this ModTools action are the
// four callers of one takedown/restore implementation, and must agree on the
// state a restored message ends up in. The poster is NOT told from here: a
// background_tasks row is queued and iznik-batch sends the chat message, the
// same path TakedownService's own callers use.
func handleMessageRestore(c *fiber.Ctx, myid uint64, msgid uint64) error {
	db := database.DBConn

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to restore this message")
	}

	type msgRow struct {
		Fromuser uint64
		Deleted  *string
	}
	var m msgRow
	db.Table("messages").Select("fromuser, deleted").Where("id = ?", msgid).Scan(&m)
	if m.Fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	wasDeleted := m.Deleted != nil

	db.Table("messages").Where("id = ?", msgid).
		Updates(map[string]interface{}{"deleted": gorm.Expr("NULL"), "collection": utils.COLLECTION_APPROVED})

	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_RESTORED, m.Fromuser, myid, msgid, 0, "Message restored")

	if wasDeleted {
		if err := queue.QueueTask(queue.TaskTellPoster, map[string]interface{}{
			"msgid": msgid, "action": "Restore",
		}); err != nil {
			log.Printf("Failed to queue tell-poster task for restore of message %d: %v", msgid, err)
		}
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleMessageTakeDown implements PATCH /message {action: "TakeDown", reason}.
// Mirrors App\Services\TakedownService::takeDown() literally: it sets ONLY
// messages.deleted and appends to messages.contentcheck_reasons (a deduped
// JSON array) - it does NOT set collection, which stays whatever the caller
// (content check, judge, or this handler's own earlier state) left it as.
// See handleMessageRestore for the shared four-caller contract.
func handleMessageTakeDown(c *fiber.Ctx, myid uint64, msgid uint64, reason *string) error {
	db := database.DBConn

	if !auth.IsModerator(myid) {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to take down this message")
	}

	why := "Taken down by a moderator"
	if reason != nil && strings.TrimSpace(*reason) != "" {
		why = strings.TrimSpace(*reason)
	}

	type msgRow struct {
		Fromuser         uint64
		Deleted          *string
		ContentcheckReasons *string
	}
	var m msgRow
	db.Table("messages").Select("fromuser, deleted, contentcheck_reasons").Where("id = ?", msgid).Scan(&m)
	if m.Fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	wasLive := m.Deleted == nil

	var reasons []string
	if m.ContentcheckReasons != nil && *m.ContentcheckReasons != "" {
		_ = json.Unmarshal([]byte(*m.ContentcheckReasons), &reasons)
	}
	found := false
	for _, r := range reasons {
		if r == why {
			found = true
			break
		}
	}
	if !found {
		reasons = append(reasons, why)
	}
	reasonsJSON, _ := json.Marshal(reasons)

	db.Table("messages").Where("id = ?", msgid).
		Updates(map[string]interface{}{
			"deleted":              gorm.Expr("NOW()"),
			"contentcheck_reasons": string(reasonsJSON),
		})

	// A taken-down post can no longer be routed to members waiting on it.
	microvolunteering.FreezeReachIfOriginPending(db, msgid)

	logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_REJECTED, m.Fromuser, myid, msgid, 0, why)

	if wasLive {
		if err := queue.QueueTask(queue.TaskTellPoster, map[string]interface{}{
			"msgid": msgid, "action": "TakeDown", "reason": why,
		}); err != nil {
			log.Printf("Failed to queue tell-poster task for takedown of message %d: %v", msgid, err)
		}
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

	isMod := fromuser != myid && auth.IsModerator(myid)
	if fromuser != myid && !isMod {
		return fiber.NewError(fiber.StatusForbidden, "Not allowed to delete this message")
	}

	// Identical golden to 73672934d660
	// (handleSpam); converted together per gate (h).
	db.Table("messages").Where("id = ?", msgid).Update("deleted", gorm.Expr("NOW()"))

	// Write audit-log entry when a moderator deletes a message.
	if isMod {
		logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, fromuser, myid, msgid, 0, "")
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
// their own postcode, never the moderator's.
type OnBehalfPosting struct {
	Locationid   uint64 `json:"locationid"`
	Locationname string `json:"locationname"`
	// Moderated says the post will WAIT in Pending for a human moderator
	// rather than being auto-promoted by the content check - true for a
	// member with no/MODERATED/PROHIBITED posting status (V1
	// User::postToCollection semantics, the same answer the batch content
	// check gives). The modal warns the converting moderator, because a
	// post they cannot then see looks like a failed convert (Discourse
	// #6999).
	Moderated bool `json:"moderated"`
}

// ResolveOnBehalfPosting works out the location a post for `author` would
// use. PutMessageAs calls it when it actually posts, and the convert preview
// calls it to show the moderator the same answer beforehand - one function
// so the preview cannot promise a postcode the post then ignores.
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
	}

	db.Table("users").
		Select("JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.id')) AS locationid, "+
			"JSON_UNQUOTE(JSON_EXTRACT(settings, '$.mylocation.name')) AS locationname").
		Where("id = ?", author).Scan(&chosen)

	if chosen.Locationid == 0 || chosen.Locationname == "" {
		return nil, errors.New("That member hasn't set their location, so we can't post for them - ask them to set it first")
	}

	return &OnBehalfPosting{
		Locationid:   chosen.Locationid,
		Locationname: chosen.Locationname,
		Moderated:    postingWouldBeModerated(author),
	}, nil
}

// postingWouldBeModerated says whether a post by author waits in Pending for
// a human moderator. Same test the content check batch job applies (and
// applyPatchMessageCore's edit-review path above): the member's site-wide
// posting status, where no status, NULL, empty, MODERATED and PROHIBITED all
// mean a human looks first.
func postingWouldBeModerated(author uint64) bool {
	db := database.DBConn

	var ps *string
	db.Table("users").Select("postingstatus").Where("id = ?", author).Scan(&ps)

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
	}

	// For non-Draft, fetch the poster's site-wide posting status so the
	// collection below can be derived from it - ignoring whatever collection
	// the client sent, so a moderated member can't bypass moderation by
	// sending collection="Approved".
	var ourPostingStatus *string
	if req.Collection != "Draft" {
		db.Table("users").Select("postingstatus").Where("id = ?", myid).Scan(&ourPostingStatus)
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

	// For Draft collection, store in messages_drafts. For anything else, the
	// message carries its own national collection - set it directly.
	if req.Collection == "Draft" {
		if err := db.Table("messages_drafts").Create(map[string]interface{}{
			"msgid": newMsgID, "userid": myid,
		}).Error; err != nil {
			return fiber.NewError(fiber.StatusInternalServerError, "Failed to create draft")
		}
	} else {
		// Determine collection based on the poster's site-wide posting status,
		// ignoring whatever the client sent. This prevents moderated users from
		// bypassing moderation by sending collection="Approved".
		// (User::postToCollection line 819):
		//   (!$ps || $ps == MODERATED || $ps == PROHIBITED) → Pending
		//   anything else → Approved
		// ourPostingStatus was already fetched above.
		collection := utils.COLLECTION_PENDING

		if ourPostingStatus != nil && strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) {
			return fiber.NewError(fiber.StatusForbidden, "You are not allowed to post")
		}
		if ourPostingStatus != nil &&
			!strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_MODERATED) &&
			!strings.EqualFold(*ourPostingStatus, utils.POSTING_STATUS_PROHIBITED) &&
			*ourPostingStatus != "" {
			collection = utils.COLLECTION_APPROVED
		}

		db.Table("messages").Where("id = ?", newMsgID).Update("collection", collection)

		// V1 parity: log Message/Received when a post is submitted directly (non-draft).
		logMessageReceived(db, myid, newMsgID)
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
	// browse/search results. Spatial index is populated once the message
	// becomes Approved (content check or a moderator), matching V1 behaviour.

	// Reconstruct subject with location, now that locationid is set.
	// The initial subject was set as "Type: Item" without location; rebuild as
	// "KEYWORD: Item (Area PC)". Skipped when no location could be resolved.
	locStr := constructLocationString(db, newMsgID)
	if locStr != "" && req.Item != "" {
		keyword := messageKeyword(req.Type)
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
	// Optional structured terms attached to a promise (action "Promise"). Stored
	// as JSON on messages_promises.terms and returned on the message's promises;
	// omit it and nothing changes. Deployments that turn a promise into a formal
	// agreement (see "AcceptAgreement") use it; Freegle's own clients do not send it.
	Terms *json.RawMessage `json:"terms"`
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
	"Move":          true,
	"RejectToDraft": true,
	"BackToDraft":   true,
}

// heldByAnotherMod returns the id and name of a DIFFERENT moderator holding this
// message, or 0 if it is free to act on. Holds are national now (messages.heldby):
// one message, one hold, no per-group fan-out.
func heldByAnotherMod(myid uint64, req PostMessageRequest) (uint64, string) {
	db := database.DBConn

	ctx := getMessageModContext(db, myid, req.ID)
	if ctx == nil {
		// Not a moderator for this message - let the handler produce its own
		// (403) error rather than masking it with a confusing 409.
		return 0, ""
	}

	var holder uint64
	db.Table("messages").Select("heldby").
		Where("id = ? AND heldby IS NOT NULL AND heldby != ?", req.ID, myid).
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
	case "AcceptAgreement":
		return handleAcceptAgreement(c, myid, req)
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
	case "PartnerConsent":
		return handlePartnerConsent(c, myid, req)
	case "Reply":
		return handleReply(c, myid, req)
	case "JoinAndPost":
		return handleJoinAndPost(c, myid, req)
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

	// REPLACE INTO - idempotent. Terms are optional: absent means NULL, exactly
	// as before this column existed.
	promise := map[string]interface{}{"msgid": req.ID, "userid": promisedTo}
	if req.Terms != nil && len(*req.Terms) > 0 && string(*req.Terms) != "null" {
		promise["terms"] = string(*req.Terms)
	}
	db.Table("messages_promises").Clauses(clause.Insert{Modifier: "REPLACE"}).
		Create(promise)

	// Create a chat message of type Promised if promising to another user.
	if req.Userid != nil && *req.Userid > 0 && *req.Userid != myid {
		createSystemChatMessage(db, myid, *req.Userid, req.ID, utils.CHAT_MESSAGE_PROMISED)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// handleAcceptAgreement lets the person a message was promised TO accept it,
// turning the promise into an agreement between the two members: it stamps
// messages_promises.acceptedat/acceptedby on the caller's own promise row.
//
// This is an opt-in step that Freegle's own clients never take - a Freegle
// promise is complete when the owner makes it. Deployments built on this
// codebase that need a two-sided agreement (terms proposed by the owner, then
// accepted by the other party) call it after "Promise". Only the promised-to
// member can accept, only once, and only while a promise exists; every other
// case is a 404 so nothing is ever created here.
func handleAcceptAgreement(c *fiber.Ctx, myid uint64, req PostMessageRequest) error {
	db := database.DBConn

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	result := db.Table("messages_promises").
		Where("msgid = ? AND userid = ? AND acceptedat IS NULL", req.ID, myid).
		Updates(map[string]interface{}{"acceptedat": gorm.Expr("NOW()"), "acceptedby": myid})
	if result.Error != nil {
		return fiber.NewError(fiber.StatusInternalServerError, "Failed to accept")
	}
	if result.RowsAffected == 0 {
		return fiber.NewError(fiber.StatusNotFound, "No unaccepted promise to you on this message")
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

	renegePromise(db, myid, promisedTo, req.ID)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// renegePromise withdraws the promise of message msgid from promiser to
// promisedTo: records it for reliability tracking, deletes the promise and
// posts a Reneged chat message - unless the promise was to themselves, which
// carries no record and no chat message.
func renegePromise(db *gorm.DB, promiser uint64, promisedTo uint64, msgid uint64) {
	if promisedTo != promiser {
		db.Table("messages_reneged").Create(map[string]interface{}{"userid": promisedTo, "msgid": msgid})
	}

	db.Table("messages_promises").Where("msgid = ? AND userid = ?", msgid, promisedTo).Delete(nil)

	if promisedTo != promiser {
		createSystemChatMessage(db, promiser, promisedTo, msgid, utils.CHAT_MESSAGE_RENEGED)
	}
}

// RenegePromisesTo withdraws every promise promiser has made to promisedTo on
// their own posts. Blocking someone calls this (V1 parity: ChatRoom::updateRoster
// reneged on Block), so a blocked member is not left holding a promise from
// someone who no longer wants to deal with them.
func RenegePromisesTo(db *gorm.DB, promiser uint64, promisedTo uint64) {
	var msgids []uint64
	db.Table("messages_promises").
		Joins("INNER JOIN messages ON messages.id = messages_promises.msgid").
		Where("messages.fromuser = ? AND messages_promises.userid = ?", promiser, promisedTo).
		Pluck("messages_promises.msgid", &msgids)

	for _, msgid := range msgids {
		renegePromise(db, promiser, promisedTo, msgid)
	}
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
	//
	// "Still pending" is now a single national fact on the message itself: there is one
	// collection value, not one per group, so there is no rippled-copy-elsewhere case to
	// carry along. Rows already soft-deleted do not count (Discourse 10102).
	if req.Outcome == utils.OUTCOME_WITHDRAWN {
		var collection string
		db.Table("messages").Select("collection").Where("id = ? AND deleted IS NULL", req.ID).Scan(&collection)
		if collection == utils.COLLECTION_PENDING {
			// V1 parity (Message::delete()): soft-delete the message.  Without this, the
			// orphaned Pending row gets picked up by AutoApproveService 48 hours later and
			// auto-approved as if the member never withdrew it — making the message
			// reappear in ModTools.
			db.Table("messages").Where("id = ?", req.ID).
				Updates(map[string]interface{}{"deleted": gorm.Expr("NOW()"), "messageid": gorm.Expr("NULL")})

			// V1 parity (Message::delete() logs SUBTYPE_DELETED): without an audit-log
			// entry the post silently vanishes from the mod pending queue while its
			// "Posted"/Received log remains, so mods see "logs say posted but there's no
			// post and it's not in pending" (Discourse #9703). `user` is the message
			// author, `byuser` the actor (the member withdrawing).
			var fromuser uint64
			db.Table("messages").Select("fromuser").Where("id = ?", req.ID).Scan(&fromuser)
			logModAction(db, flog.LOG_TYPE_MESSAGE, flog.LOG_SUBTYPE_DELETED, fromuser, myid, req.ID, 0, "Withdrawn")

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

	// There is no "retire the pending copy elsewhere" step any more: a message carries one
	// national collection value, so recording an outcome above is the whole story.

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

	// Otherwise a moderator/owner of a group the message was POSTED on. Outcomes and
	// "taken by" are facts about the whole post, so a copy that merely rippled into a
	// moderator's group (rippled_in = 1) gives them no standing here; their per-group
	// actions (reject, delete, hold) are unaffected (Discourse 10102).
	var modCount int64
	db.Table("messages_groups mg").
		Joins("JOIN memberships m ON mg.groupid = m.groupid").
		Where("mg.msgid = ? AND mg.rippled_in = 0 AND m.userid = ? AND m.role IN (?, ?)", msgid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).
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

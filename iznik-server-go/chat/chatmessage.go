package chat

import (
	"encoding/json"
	"fmt"
	stdlog "log"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/freegle/iznik-server-go/auth"
	"github.com/freegle/iznik-server-go/database"
	"github.com/freegle/iznik-server-go/log"
	"github.com/freegle/iznik-server-go/microvolunteering"
	"github.com/freegle/iznik-server-go/misc"
	"github.com/freegle/iznik-server-go/rippling"
	"github.com/freegle/iznik-server-go/user"
	"github.com/freegle/iznik-server-go/utils"
	"github.com/gofiber/fiber/v2"
	"gorm.io/gorm"
	"gorm.io/plugin/dbresolver"
)

// =============================================================================
// Types
// =============================================================================

type ChatMessage struct {
	ID                   uint64          `json:"id" gorm:"primary_key"`
	Chatid               uint64          `json:"chatid"`
	Userid               uint64          `json:"userid"`
	Type                 string          `json:"type"`
	Refmsgid             *uint64         `json:"refmsgid"`
	Refchatid            *uint64         `json:"refchatid"`
	Imageid              *uint64         `json:"imageid"`
	Image                *ChatAttachment `json:"image" gorm:"-"`
	Date                 time.Time       `json:"date"`
	Message              string          `json:"message"`
	Seenbyall            bool            `json:"seenbyall"`
	Mailedtoall          bool            `json:"mailedtoall"`
	Replyexpected        bool            `json:"replyexpected"`
	Replyreceived        bool            `json:"replyreceived"`
	Reportreason         *string         `json:"reportreason"`
	Reviewrequired       bool            `json:"reviewrequired"`
	Reviewrejected       bool            `json:"reviewrejected"`
	Processingrequired   bool            `json:"processingrequired"`
	Processingsuccessful bool            `json:"processingsuccessful"`
	// Processingfailreason says WHY a message was dropped during processing
	// (processingsuccessful = 0). A dropped message is never notified to the recipient and
	// is filtered out of their chat fetch, so without this a support volunteer sees a
	// message the recipient swears never arrived and has no way to explain it - which is
	// how a run of suppressed replies got put down to a rippling delay. Values come from
	// ChatMessage::PROCESSFAIL_* in the batch app.
	//
	// Read-only here (`gorm:"->"`): only the batch processor ever sets it, and without
	// this GORM adds it to every chat_messages INSERT, which fails outright on a database
	// that has the code but not yet the migration.
	Processingfailreason *string `json:"processingfailreason,omitempty" gorm:"->"`
	// HeldByRippling is true when this message is held by the rippling reply-hold engine
	// (a non-released row exists in rippling_held_replies). Populated for moderators (any
	// message) and for a normal caller on their OWN held reply, so the sender can show a
	// "waiting to send" indicator. It is NOT set on the poster's view (the delivery gate
	// removes held replies from their fetch entirely).
	HeldByRippling bool    `json:"heldbyrippling,omitempty" gorm:"-"`
	Addressid      *uint64 `json:"addressid" gorm:"-"`
	Modnote        bool    `json:"modnote" gorm:"-"`
	// Replysource is the client-reported surface an Interested reply came from (browse,
	// search, message_page, notification, ...). Advisory reply-provenance evidence for the
	// rippling attribution capture - sanitised there, stored in rippling_reply_attribution,
	// never a chat_messages column.
	Replysource *string `json:"replysource" gorm:"-"`
	Archived    int     `json:"-" gorm:"-"`
	Deleted     bool    `json:"-"`
}

// We need a separate struct for the query so that we can return image info in a single query.  If we put the
// fields into the ChatMessage struct, GORM will try to set them when we create a new message.
func (ChatMessageQuery) TableName() string {
	return "chat_messages"
}

type ChatMessageQuery struct {
	ChatMessage
	Imageuid  string          `json:"-"`
	Imagemods json.RawMessage `json:"-"`
}

type ChatAttachment struct {
	ID           uint64          `json:"id" gorm:"-"`
	Path         string          `json:"path"`
	Paththumb    string          `json:"paththumb"`
	Externaluid  string          `json:"externaluid"`
	Ouruid       string          `json:"ouruid"`
	Externalmods json.RawMessage `json:"externalmods"`
}

type ChatMessageLovejunk struct {
	Refmsgid       *uint64 `json:"refmsgid"`
	Partnerkey     string  `json:"partnerkey"`
	Message        string  `json:"message"`
	Ljuserid       *uint64 `json:"ljuserid" gorm:"-"`
	Firstname      *string `json:"firstname" gorm:"-"`
	Lastname       *string `json:"lastname" gorm:"-"`
	Profileurl     *string `json:"profileurl" gorm:"-"`
	Imageid        *uint64 `json:"imageid" gorm:"-"`
	Initialreply   bool    `json:"initialreply" gorm:"-"`
	Offerid        *uint64 `json:"offerid" gorm:"-"`
	PostcodePrefix *string `json:"postcodeprefix" gorm:"-"`
}

type ChatMessageLovejunkResponse struct {
	Id     uint64 `json:"id"`
	Chatid uint64 `json:"chatid"`
	Userid uint64 `json:"userid"`
}

func (ChatRosterEntry) TableName() string {
	return "chat_roster"
}

type ChatRosterEntry struct {
	Id             uint64     `json:"id"`
	Chatid         uint64     `json:"chatid"`
	Userid         uint64     `json:"userid"`
	Date           *time.Time `json:"date"`
	Status         string     `json:"status"`
	Lastmsgseen    *uint64    `json:"lastmsgseen"`
	Lastemailed    *time.Time `json:"lastemailed"`
	Lastmsgemailed *uint64    `json:"lastmsgemailed"`
	Lastip         *string    `json:"lastip"`
}

type PatchChatMessageRequest struct {
	ID            uint64 `json:"id"`
	Roomid        uint64 `json:"roomid"`
	Replyexpected *bool  `json:"replyexpected"`
}

type ModerationRequest struct {
	ID     uint64 `json:"id"`
	Action string `json:"action"`
}

// ReviewChatMessage represents a chat message in the review queue with associated room info.
type ReviewChatMessage struct {
	ID           uint64     `json:"id"`
	Chatid       uint64     `json:"chatid"`
	Userid       uint64     `json:"userid"`
	Type         string     `json:"type"`
	Message      string     `json:"message"`
	Date         *time.Time `json:"date"`
	Refmsgid     *uint64    `json:"refmsgid"`
	Reportreason *string    `json:"reportreason"`
	Chatroom     fiber.Map  `json:"chatroom"`
}

// =============================================================================
// GET handlers
// =============================================================================

// FetchChatMessages retrieves chat messages for a given chat and user.
// This is the core logic shared between the regular chat API and AMP email API.
// Parameters:
// - chatID: the chat room ID
// - userID: the requesting user's ID (for filtering own messages vs reviewed messages)
// - limit: maximum number of messages to return (0 = no limit)
// - excludeID: message ID to exclude (0 = don't exclude any)
// - descending: if true, return newest first; if false, return oldest first
// - modAccess: if true, include messages held for review
func FetchChatMessages(chatID, userID uint64, limit int, excludeID uint64, descending bool, modAccess bool) []ChatMessageQuery {
	db := database.DBConn

	// Build the query - don't return messages:
	// - held for review unless we sent them or we have mod access
	// - for deleted users unless that's us (or we have mod access — mods must
	//   see all messages including from accounts deleted after the fact, e.g.
	//   a phisher who deletes their account immediately after sending spam)
	var reviewFilter string
	if modAccess {
		// Mods can see all messages including those held for review.
		reviewFilter = "(reviewrejected = 0 OR userid = ?)"
	} else {
		// Also gate rippling held replies: an email/TN reply from outside the post's reach is
		// held (rippling_held_replies, status <> 'released') so it doesn't reach the poster early.
		// The PHP notification paths honour this gate; the in-app chat fetch must too, or the
		// poster reads the held reply here once chats:process-incoming flips processingsuccessful.
		// The sender still sees their own message (userid = ? branch); only the poster is gated.
		reviewFilter = "(userid = ? OR (reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 " +
			"AND NOT EXISTS (SELECT 1 FROM rippling_held_replies rhr WHERE rhr.chatmsgid = chat_messages.id AND rhr.status <> 'released')))"
	}

	// Mods reviewing a chat must see messages from soft-deleted users (V1 parity:
	// the PHP GetMessages query had no users.deleted filter). Regular users only
	// see their own messages when the sender has been deleted.
	var deletedFilter string
	if !modAccess {
		deletedFilter = " AND (users.deleted IS NULL OR users.id = ?)"
	}

	query := "SELECT chat_messages.*, chat_images.archived, chat_images.externaluid AS imageuid, chat_images.externalmods AS imagemods FROM chat_messages " +
		"LEFT JOIN chat_images ON chat_images.chatmsgid = chat_messages.id " +
		"INNER JOIN users ON users.id = chat_messages.userid " +
		"WHERE chatid = ? AND " + reviewFilter + deletedFilter

	var args []interface{}
	if modAccess {
		args = []interface{}{chatID, userID}
	} else {
		args = []interface{}{chatID, userID, userID}
	}

	if excludeID > 0 {
		query += " AND chat_messages.id != ?"
		args = append(args, excludeID)
	}

	if descending {
		query += " ORDER BY date DESC"
	} else {
		query += " ORDER BY date ASC"
	}

	if limit > 0 {
		query += fmt.Sprintf(" LIMIT %d", limit)
	}

	messages := []ChatMessageQuery{}
	db.Raw(query, args...).Scan(&messages)

	// Flag messages held by the rippling reply-hold engine (a non-released rippling_held_replies
	// row means the message is delivery-blocked — not a manual mod hold, which is chat_messages_held).
	// Mods see it on any message (review context). A normal caller sees it only on their OWN held
	// reply: the poster never receives a held reply (the delivery gate above strips it from the
	// query), so the sender's own copy is the only held message a non-mod fetch can contain. The
	// sender uses this to show a "waiting to send — we'll deliver it when the item reaches your
	// area" indicator on their out-of-reach reply. Batch lookup to avoid N+1.
	if len(messages) > 0 {
		msgIDs := make([]string, 0, len(messages))
		idxByID := make(map[uint64]int, len(messages))
		for ix, m := range messages {
			msgIDs = append(msgIDs, fmt.Sprintf("%d", m.ID))
			idxByID[m.ID] = ix
		}
		type ripplingHeld struct {
			Chatmsgid uint64 `gorm:"column:chatmsgid"`
		}
		var held []ripplingHeld
		db.Raw("SELECT chatmsgid FROM rippling_held_replies WHERE chatmsgid IN (" +
			strings.Join(msgIDs, ",") + ") AND status <> 'released'").Scan(&held)
		for _, h := range held {
			if ix, ok := idxByID[h.Chatmsgid]; ok {
				if modAccess || messages[ix].Userid == userID {
					messages[ix].HeldByRippling = true
				}
			}
		}
	}

	// Process images and deleted messages
	for ix, a := range messages {
		if a.Imageid != nil {
			path, paththumb := misc.BuildChatImageUrl(*a.Imageid, a.Imageuid, string(a.Imagemods), a.Archived)
			messages[ix].Image = &ChatAttachment{
				ID:           *a.Imageid,
				Ouruid:       a.Imageuid,
				Externalmods: a.Imagemods,
				Path:         path,
				Paththumb:    paththumb,
			}
		}

		if a.Deleted {
			messages[ix].Message = "(Message deleted)"
		}

		// strip review/processing fields from non-mod responses.
		if !modAccess {
			messages[ix].Reviewrequired = false
			messages[ix].Reviewrejected = false
			messages[ix].Processingrequired = false
			messages[ix].Processingsuccessful = false
		}
	}

	return messages
}

func GetChatMessages(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)

	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid chat id")
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	// Check if user can see this chat and determine mod access.
	// $modaccess is true when user is NOT user1/user2 but has mod/admin access.
	db := database.DBConn
	type roomInfo struct {
		User1   uint64
		User2   uint64
		Groupid uint64
	}
	var room roomInfo
	db.Raw("SELECT user1, user2, COALESCE(groupid, 0) AS groupid FROM chat_rooms WHERE id = ?", id).Scan(&room)

	if room.User1 == 0 && room.User2 == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Invalid chat id")
	}

	isParticipant := myid == room.User1 || myid == room.User2
	modAccess := false

	if !isParticipant {
		if !canSeeChatRoom(myid, room.User1, room.User2, room.Groupid) {
			return fiber.NewError(fiber.StatusNotFound, "Invalid chat id")
		}
		modAccess = true
	}

	messages := FetchChatMessages(id, myid, 0, 0, false, modAccess)
	return c.JSON(messages)
}

// GetReviewChatMessages handles GET /chatmessages for moderator review queue.
// When called without a roomid, returns messages pending review for the moderator's groups.
//
// @Summary Get chat messages for review
// @Tags chat
// @Produce json
// @Param roomid query integer false "Chat room ID (for room-specific messages)"
// @Param limit query integer false "Max messages to return"
// @Param context query integer false "Cursor for pagination (last message ID)"
// @Param groupid query integer false "Filter to specific group"
// @Success 200 {object} map[string]interface{}
// @Router /api/chatmessages [get]
func GetReviewChatMessages(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return c.Status(fiber.StatusUnauthorized).JSON(fiber.Map{"ret": 1, "status": "Not logged in"})
	}

	roomid, _ := strconv.ParseUint(c.Query("roomid", "0"), 10, 64)

	// If roomid is provided, return messages for that specific room.
	if roomid > 0 {
		return getChatMessagesForRoom(c, myid, roomid)
	}

	// Otherwise, return the review queue for this moderator.
	return getReviewQueue(c, myid)
}

// =============================================================================
// POST / CREATE handlers
// =============================================================================

// replyReachEvidence carries what the rippling reply gate already computed about the replier's
// location vs the post's reach polygon, so the attribution capture doesn't repeat the spatial
// query. checked distinguishes "gate ran and found no reach row" from "gate couldn't run"
// (no location, or rippling_reach doesn't exist yet).
type replyReachEvidence struct {
	haveLocation bool
	lat, lng     float64
	checked      bool
	reachRows    int
	inReach      int
}

// recordReplyAttribution snapshots, at reply time, the evidence for how the replier came to see
// the post, plus the derived attribution channel (see rippling.DeriveAttribution for the ladder
// and the rippling_reply_attribution migration for column semantics). Snapshotting matters
// because the evidence decays: the Nuxt reply flow joins the replier to the group in order to
// reply (useReplyStateMachine.handleJoinGroup) so a retrospective membership check would
// mis-count every rippling reply as home-group; members leave; locations drift; reach polygons
// grow. INSERT IGNORE = first reply only; frozen so none of that can rewrite history.
// Best-effort throughout: attribution must never break replying, so errors are swallowed, and
// until the graded columns are migrated (production lags dev) it falls back to the legacy
// was_home_member-only insert.
func recordReplyAttribution(db *gorm.DB, myid uint64, refmsgid uint64, reach replyReachEvidence, clientSource *string) {
	// Established member of an ORIGIN (non-rippled-in) group of the post, whose membership
	// predates this reply by more than the join grace (300s)? A join made to reply
	// (added ~ now) is excluded.
	var wasHome int
	db.Raw("SELECT EXISTS(SELECT 1 FROM messages_groups mg "+
		"INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? "+
		"AND mem.collection = ? AND mem.added < NOW() - INTERVAL 300 SECOND "+
		"WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0)",
		myid, utils.COLLECTION_APPROVED, refmsgid).Scan(&wasHome)

	if !rippling.AttributionSchemaReady(db) {
		db.Exec("INSERT IGNORE INTO rippling_reply_attribution (msgid, userid, replied_at, was_home_member) "+
			"VALUES (?, ?, NOW(), ?)", refmsgid, myid, wasHome)
		return
	}

	// Did we send this user the ripple "new post near you" mail for this post? Keyed lookup on
	// the notified ledger - the strongest direct ripple-delivery evidence.
	var wasNotified int
	db.Raw("SELECT EXISTS(SELECT 1 FROM rippling_reach_notified WHERE msgid = ? AND userid = ?)",
		refmsgid, myid).Scan(&wasNotified)

	// Established member of a group the post rippled INTO: added before the rippled copy
	// arrived, so they were already there when it rippled in and saw it in their own group's
	// feed/digest because of the ripple. The cut is the copy's arrival, NOT the 300s grace:
	// the join-to-reply flow can join the rippled copy's group, and a pre-arrival membership
	// is the only sound "they were already there" test.
	var wasRippleGroup int
	db.Raw("SELECT EXISTS(SELECT 1 FROM messages_groups mg "+
		"INNER JOIN memberships mem ON mem.groupid = mg.groupid AND mem.userid = ? "+
		"AND mem.collection = ? AND mem.added < mg.arrival "+
		"WHERE mg.msgid = ? AND mg.rippled_in = 1 AND mg.deleted = 0)",
		myid, utils.COLLECTION_APPROVED, refmsgid).Scan(&wasRippleGroup)

	// Had the post rippled AT ALL by reply time (a rippled-in copy, or a reach row)? This is
	// the ladder's hard guard: when 0, the reply can never be ripple-attributed. Reuse the
	// gate's reach lookup when it ran; only query rippling_reach (which may not exist yet -
	// best-effort) when it didn't.
	var postHadRippled int
	db.Raw("SELECT EXISTS(SELECT 1 FROM messages_groups WHERE msgid = ? AND rippled_in = 1 AND deleted = 0)",
		refmsgid).Scan(&postHadRippled)
	if postHadRippled == 0 && reach.reachRows > 0 {
		postHadRippled = 1
	}
	if postHadRippled == 0 && !reach.checked {
		var hasReach int
		if err := db.Raw("SELECT EXISTS(SELECT 1 FROM rippling_reach WHERE msgid = ?)",
			refmsgid).Scan(&hasReach).Error; err == nil && hasReach == 1 {
			postHadRippled = 1
		}
	}

	// Location evidence - nil (NULL in the row) when the replier has no usable location, which
	// is distinct from a definite "outside".
	var inOrigin, inReach *int
	if reach.haveLocation {
		v := 0
		// Inside any origin group's catchment? polyindex is the group's DPA-or-CGA; groups
		// with only a POINT placeholder can't contain anything and are excluded.
		db.Raw("SELECT EXISTS(SELECT 1 FROM messages_groups mg "+
			"INNER JOIN `groups` g ON g.id = mg.groupid "+
			"WHERE mg.msgid = ? AND mg.rippled_in = 0 AND mg.deleted = 0 "+
			"AND g.polyindex IS NOT NULL AND ST_GeometryType(g.polyindex) <> 'POINT' "+
			"AND ST_Contains(g.polyindex, ST_SRID(POINT(?, ?), ?)))",
			refmsgid, reach.lng, reach.lat, utils.SRID).Scan(&v)
		inOrigin = &v
		if reach.checked {
			r := 0
			if reach.reachRows > 0 && reach.inReach == 1 {
				r = 1
			}
			inReach = &r
		}
	}

	attribution := rippling.DeriveAttribution(wasHome, wasNotified, wasRippleGroup, postHadRippled, inOrigin, inReach)

	db.Exec("INSERT IGNORE INTO rippling_reply_attribution "+
		"(msgid, userid, replied_at, was_home_member, was_notified, was_ripple_group_member, "+
		"in_origin_catchment, in_reach, post_had_rippled, attribution, client_source) "+
		"VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?)",
		refmsgid, myid, wasHome, wasNotified, wasRippleGroup, inOrigin, inReach, postHadRippled,
		attribution, rippling.SanitizeClientSource(clientSource))
}

func CreateChatMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	db := database.DBConn
	id, err := strconv.ParseUint(c.Params("id"), 10, 64)

	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid chat id")
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var payload ChatMessage
	err = c.BodyParser(&payload)

	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}

	chattype := utils.CHAT_MESSAGE_DEFAULT

	// modnote flag creates a ModMail message (visible as group volunteer message).
	if payload.Modnote {
		chattype = utils.CHAT_MESSAGE_MODMAIL
	} else if payload.Refmsgid != nil {
		chattype = utils.CHAT_MESSAGE_INTERESTED
	} else if payload.Refchatid != nil {
		chattype = utils.CHAT_MESSAGE_REPORTEDUSER
	} else if payload.Imageid != nil {
		chattype = utils.CHAT_MESSAGE_IMAGE
	} else if payload.Addressid != nil {
		chattype = utils.CHAT_MESSAGE_ADDRESS
		s := fmt.Sprint(*payload.Addressid)
		payload.Message = s
	} else if payload.Message == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Message must be non-empty")
	}

	chatid := []ChatRoomListEntry{}

	// Allow user1, user2, or (for User2Mod chats) a moderator of the chat's group.
	db.Raw("SELECT id FROM chat_rooms WHERE id = ? AND user1 = ? "+
		"UNION SELECT id FROM chat_rooms WHERE id = ? AND user2 = ? "+
		"UNION SELECT cr.id FROM chat_rooms cr "+
		"INNER JOIN memberships m ON m.groupid = cr.groupid AND m.userid = ? AND m.role IN (?, ?) "+
		"WHERE cr.id = ? AND cr.chattype = ?",
		id, myid, id, myid, myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, id, utils.CHAT_TYPE_USER2MOD).Scan(&chatid)

	if len(chatid) == 0 {
		// mods can also post to User2User chats if they moderate
		// either user's group (used when adding mod messages from chat review).
		type roomBasic struct {
			User1   uint64
			User2   uint64
			Groupid uint64
		}
		var room roomBasic
		db.Raw("SELECT user1, user2, COALESCE(groupid, 0) AS groupid FROM chat_rooms WHERE id = ?", id).Scan(&room)

		if room.User1 == 0 && room.User2 == 0 || !canSeeChatRoom(myid, room.User1, room.User2, room.Groupid) {
			return fiber.NewError(fiber.StatusNotFound, "Invalid chat id")
		}
	}

	// Guard a reply whose referenced post no longer exists. chat_messages.refmsgid has a FK to
	// messages.id, and prod purges deleted/rejected/expired posts quickly. If the client replies
	// to a post that has since been purged, the INSERT below would violate the FK and fail with a
	// swallowed 500 ("Error creating chat message") — surfacing as the fatal "Oh Dear" page with no
	// trace anywhere (the access log drops 500s). Detect it up front and return a clean status so
	// the client can show "this post is no longer available" instead. (CreateChatMessageLoveJunk
	// already does the equivalent check; the in-app path never had it.)
	if payload.Refmsgid != nil {
		var refExists int
		db.Raw("SELECT EXISTS(SELECT 1 FROM messages WHERE id = ? AND deleted IS NULL)", *payload.Refmsgid).Scan(&refExists)
		if refExists == 0 {
			return fiber.NewError(fiber.StatusNotFound, "refmsg_gone")
		}
	}

	// Rippling-out reply gate (#5): an in-app reply to a post (CHAT_MESSAGE_INTERESTED) the
	// viewer can see but whose reach has not yet reached them (replyeligible=false in the read
	// path) must be rejected on the WRITE path too — the UI gate alone is bypassable by a stale
	// or modified client, or a ?reply= deep link. Mirror the read-path reply-eligibility check:
	// a rippling_reach row exists for the post and does NOT contain the replier's location.
	//
	// This only governs a User2User reply to the POSTER — the flow reply-eligibility is about. A
	// refmsgid-bearing message to mods (User2Mod) must NEVER be reach-gated: reporting a post has
	// to work regardless of where you are. Reports carry refmsgid (to link the post) and so are
	// typed CHAT_MESSAGE_INTERESTED, so without this scope a report of a rippled post 403s when the
	// reporter is outside the post's reach polygon (Discourse #9852). Restricting to User2User does
	// not weaken reply-eligibility, since genuine Interested replies are always User2User.
	// The room type also scopes the attribution capture below (a refmsgid message in a
	// User2Mod room is a REPORT, not a reply), so fetch it once here.
	roomType := ""
	reach := replyReachEvidence{}
	holdReply := false
	if chattype == utils.CHAT_MESSAGE_INTERESTED && payload.Refmsgid != nil {
		db.Raw("SELECT chattype FROM chat_rooms WHERE id = ?", id).Scan(&roomType)
		if roomType == utils.CHAT_TYPE_USER2USER {
			latlng := user.GetLatLng(myid)
			if latlng.Lat != 0 || latlng.Lng != 0 {
				reach.haveLocation = true
				reach.lat = float64(latlng.Lat)
				reach.lng = float64(latlng.Lng)
				// One query answers both "does a reach row exist" and "does it contain the
				// replier" (msgid is the PK, so at most one row): the gate blocks when a reach
				// row exists that does NOT contain the point, and the attribution capture
				// below reuses the containment result instead of repeating the spatial query.
				// rippling_reach may not exist until the reach engine (PR A) ships → fail open (allow).
				// Containment consults the sandwich bounds when migrated (see
				// rippling/reachbounds.go): the ~178KB exact polygon is only touched for the
				// boundary band, and degraded bounds fall back to it so replies to completed
				// posts still resolve exactly (held-then-taken-gone flow).
				var rc struct {
					ReachRows int `gorm:"column:reach_rows"`
					InReach   int `gorm:"column:in_reach"`
				}
				var gateErr error
				if rippling.ReachBoundsReady(db) {
					expr, exprArgs := rippling.ReachInReachExpr(reach.lng, reach.lat, utils.SRID)
					args := append(exprArgs, *payload.Refmsgid)
					gateErr = db.Raw("SELECT COUNT(*) AS reach_rows, COALESCE(MAX("+expr+"), 0) AS in_reach "+
						"FROM rippling_reach rr "+
						"WHERE rr.msgid = ?", args...).Scan(&rc).Error
				} else {
					gateErr = db.Raw("SELECT COUNT(*) AS reach_rows, "+
						"COALESCE(MAX(ST_Contains(polygon, ST_SRID(POINT(?, ?), ?))), 0) AS in_reach "+
						"FROM rippling_reach WHERE msgid = ?",
						latlng.Lng, latlng.Lat, utils.SRID, *payload.Refmsgid).Scan(&rc).Error
				}
				if gateErr == nil {
					reach.checked = true
					reach.reachRows = rc.ReachRows
					reach.inReach = rc.InReach
					// Out of reach: do NOT reject. HOLD the reply like an email/TN reply — create
					// the chat message below, then record a rippling_held_replies row so the poster
					// isn't notified until the post ripples to the replier. The existing
					// ripple:release-replies cron then delivers it (or 'taken-gone' if the post goes
					// first). Mirrors IncomingMailService::holdReplyIfOutsideReach for the web path.
					if rc.ReachRows > 0 && rc.InReach == 0 {
						holdReply = true
					}
				}
			}
		}
	}

	// We can see this chat room.  Create a chat message, but flagged as needing processing.  That means it
	// will only show up to the user who sent it until it is fully processed.
	payload.Userid = myid
	payload.Chatid = id
	payload.Type = chattype
	payload.Processingrequired = true
	payload.Date = time.Now()
	if result := db.Create(&payload); result.Error != nil {
		// Don't swallow the underlying DB error: without this, FK violations (e.g. a purged
		// refmsgid/chatid) and any other insert failure vanish — the access log drops 500s and
		// nothing reaches Sentry, leaving the user's "Oh Dear" undiagnosable.
		stdlog.Printf("Failed to create chat message in chat %d for user %d (refmsgid=%v): %v",
			id, myid, payload.Refmsgid, result.Error)
		return fiber.NewError(fiber.StatusInternalServerError, "Error creating chat message")
	}
	newid := payload.ID

	if newid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Error creating chat message")
	}

	// Rippling-out reply HOLD: the replier is outside the post's current reach, so the reply was
	// created but must not reach the poster yet. Record a rippling_held_replies row (source='web')
	// — the delivery gate withholds it from the poster until ripple:release-replies releases it
	// when the post ripples to the replier. Same table and lifecycle as email/TN holds; only the
	// source differs. Best-effort: an instrumentation failure must not fail the reply, and the
	// sender still sees their own message. The 'held' metric mirrors RippleReplyService::recordEvent
	// so web holds appear in the sysadmin rippling dashboard alongside email/TN holds.
	if holdReply {
		db.Exec("INSERT INTO rippling_held_replies "+
			"(chatid, chatmsgid, msgid, replieruserid, source, lat, lng, status, created_at) "+
			"VALUES (?, ?, ?, ?, 'web', ?, ?, 'held', NOW())",
			id, newid, *payload.Refmsgid, myid, reach.lat, reach.lng)
		db.Exec("INSERT INTO rippling_event_metrics (day, event, count) VALUES (CURDATE(), 'held', 1) " +
			"ON DUPLICATE KEY UPDATE count = count + 1")
	}

	// Rippling reply attribution (sysadmin KPI): for a genuine Interested reply, snapshot the
	// evidence for HOW the replier came to see the post, and the derived attribution channel.
	// Scoped to User2User: a refmsgid message in a User2Mod room is a report of the post, not
	// a reply, and must not pollute the reply-source metric. Best-effort: never blocks the reply.
	if chattype == utils.CHAT_MESSAGE_INTERESTED && payload.Refmsgid != nil && roomType == utils.CHAT_TYPE_USER2USER {
		recordReplyAttribution(db, myid, *payload.Refmsgid, reach, payload.Replysource)
	}

	// Replying to a post joins the replier to its group. This is meant to happen in the Nuxt reply
	// flow (useReplyStateMachine handleJoinGroup) via PUT /memberships, but a stale/racy client
	// isMember check can skip it, leaving a replier with a chat but NO group membership and no
	// location — the "member with no groups & no location" a mod flagged (Discourse #9969; ~2/day in
	// prod). Enforce it here, atomic with the reply, so it can't be skipped by any client. Held
	// (out-of-reach) replies are excluded: the post hasn't reached the replier yet. AddMembership is
	// the same idempotent join the LoveJunk path and the /memberships endpoint use — it skips
	// banned/already-member and writes the memberships_history processingrequired row that drives the
	// welcome email + spam check. Params mirror a normal web join's DB defaults (emailfrequency
	// 24=daily, events + volunteering allowed), NOT the LoveJunk FREQUENCY_NEVER. Best-effort: a join
	// hiccup must never fail the reply.
	if chattype == utils.CHAT_MESSAGE_INTERESTED && payload.Refmsgid != nil && roomType == utils.CHAT_TYPE_USER2USER && !holdReply {
		var refGroup uint64
		db.Raw("SELECT groupid FROM messages_groups WHERE msgid = ? ORDER BY groupid LIMIT 1", *payload.Refmsgid).Scan(&refGroup)
		if refGroup > 0 {
			user.AddMembership(myid, refGroup, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED, utils.FREQUENCY_DAILY, 1, 1, "Joined to reply to a post")
		}
	}

	// A report from the website is a User2Mod chat message referencing the reported
	// post (that's what the report flow sends). Treat it as a microvolunteering Reject
	// verdict feeding the review quorum: a moderator of the reported community pulls that
	// community's copy to Pending, and once the quorum of verdicts is reached the post is
	// pulled to Pending on ALL its groups. The report's target community is the User2Mod
	// chat's group. Only User2Mod refmsgid messages are reports - a User2User refmsgid
	// message is an Interested reply to the poster, not a report. Best-effort: never
	// blocks the report.
	if chattype == utils.CHAT_MESSAGE_INTERESTED && payload.Refmsgid != nil {
		var reportRoom struct {
			Chattype string
			Groupid  uint64
		}
		db.Raw("SELECT chattype, COALESCE(groupid, 0) AS groupid FROM chat_rooms WHERE id = ?", id).Scan(&reportRoom)
		if reportRoom.Chattype == utils.CHAT_TYPE_USER2MOD {
			microvolunteering.RecordReportVerdict(db, myid, *payload.Refmsgid, reportRoom.Groupid, payload.Message)
		}
	}

	if payload.Imageid != nil {
		// Update the chat image to link it to this chat message.  This also stops it being purged in
		// purge_chats.
		db.Exec("UPDATE chat_images SET chatmsgid = ? WHERE id = ?;", newid, *payload.Imageid)
	}

	// If anyone has closed this chat, reopen it so it reappears in their list.
	// Blocked chats are left as-is.  Only applies to User2User and User2Mod chats.
	result := db.Exec("UPDATE chat_roster SET status = ? WHERE chatid = ? AND status = ? "+
		"AND EXISTS (SELECT 1 FROM chat_rooms WHERE id = ? AND chattype IN (?, ?))",
		utils.CHAT_STATUS_OFFLINE, id, utils.CHAT_STATUS_CLOSED,
		id, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2MOD)

	if result.Error != nil {
		stdlog.Printf("Failed to reopen closed chat roster for chat %d: %v", id, result.Error)
	}

	ret := struct {
		Id int64 `json:"id"`
	}{}
	ret.Id = int64(newid)

	return c.JSON(ret)
}

func CreateChatMessageLoveJunk(c *fiber.Ctx) error {
	var payload ChatMessageLovejunk
	err := c.BodyParser(&payload)

	if err != nil || payload.Ljuserid == nil || payload.Partnerkey == "" || payload.Refmsgid == nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid parameters")
	}

	err2, myid := user.GetLoveJunkUser(*payload.Ljuserid, payload.Partnerkey, payload.Firstname, payload.Lastname, payload.PostcodePrefix, payload.Profileurl)

	if err2.Code != fiber.StatusOK {
		return err2
	}

	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	db := database.DBConn

	// Find the user who sent the message we are replying to.
	type msgInfo struct {
		Fromuser uint64
		Groupid  uint64
	}

	var m msgInfo

	db.Raw("SELECT fromuser, groupid FROM messages "+
		"INNER JOIN messages_groups ON messages_groups.msgid = messages.id "+
		"INNER JOIN users ON users.id = messages.fromuser "+
		"WHERE messages.id = ? AND users.deleted IS NULL", payload.Refmsgid).Scan(&m)

	if m.Fromuser == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Invalid message id "+strconv.FormatUint(*payload.Refmsgid, 10))
	}

	// Find any groups in users_banned for this user and group.  If we find one, we can't reply.
	var banned uint64
	db.Raw("SELECT userid FROM users_banned WHERE userid = ? AND groupid = ?", myid, m.Groupid).Scan(&banned)

	if banned > 0 {
		return fiber.NewError(fiber.StatusForbidden, "User banned from group")
	}

	// Ensure we're a member of the group.  This may fail if we're banned.
	if !user.AddMembership(myid, m.Groupid, utils.ROLE_MEMBER, utils.COLLECTION_APPROVED, utils.FREQUENCY_NEVER, 0, 0, "LoveJunk user joining to reply") {
		return fiber.NewError(fiber.StatusForbidden, "Failed to join relevant group")
	}

	// Find the chat between m.Fromuser and myid (check both user orderings -
	// older rooms may not be normalized so user1/user2 can be in either order)
	var chat ChatRoom
	db.Raw("SELECT * FROM chat_rooms WHERE chattype = ? AND ((user1 = ? AND user2 = ?) OR (user1 = ? AND user2 = ?))",
		utils.CHAT_TYPE_USER2USER, myid, m.Fromuser, m.Fromuser, myid).Scan(&chat)

	if chat.ID == 0 {
		// We don't yet have a chat.  We need to create one.
		chat.User1 = myid
		chat.User2 = m.Fromuser
		chat.Chattype = utils.CHAT_TYPE_USER2USER
		db.Create(&chat)

		if chat.ID == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Error creating chat")
		}

		// We also need to add both users into the roster for the chat (which is what will trigger replies to come
		// back to us).
		var roster ChatRosterEntry
		roster.Chatid = chat.ID
		roster.Userid = myid
		roster.Status = utils.CHAT_STATUS_ONLINE
		now := time.Now()
		roster.Date = &now
		db.Create(&roster)

		if roster.Id == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Error creating roster entry")
		}

		var roster2 ChatRosterEntry
		roster2.Chatid = chat.ID
		roster2.Userid = m.Fromuser
		roster2.Date = &now
		roster2.Status = utils.CHAT_STATUS_AWAY
		db.Create(&roster2)

		if roster2.Id == 0 {
			return fiber.NewError(fiber.StatusInternalServerError, "Error creating roster entry2")
		}
	}

	if payload.Offerid != nil {
		// Update the offer id in the chat room, which we need to be able to send back replies.  LoveJunk only allows
		// one offer per Freegle user and hence this can be stored in the chat room.
		db.Exec("UPDATE chat_rooms SET ljofferid = ? WHERE id = ?", *payload.Offerid, chat.ID)
	}

	var chattype string

	if payload.Initialreply {
		chattype = utils.CHAT_MESSAGE_INTERESTED
	} else {
		chattype = utils.CHAT_MESSAGE_DEFAULT
	}

	if payload.Message == "" {
		return fiber.NewError(fiber.StatusBadRequest, "Message must be non-empty")
	}

	// Create a chat message, but flagged as needing processing.
	var cm ChatMessage
	cm.Userid = myid
	cm.Chatid = chat.ID
	cm.Type = chattype
	cm.Processingrequired = true
	cm.Date = time.Now()
	cm.Message = payload.Message
	cm.Refmsgid = payload.Refmsgid
	db.Create(&cm)
	newid := cm.ID

	if newid == 0 {
		return fiber.NewError(fiber.StatusInternalServerError, "Error creating chat message")
	}

	if payload.Imageid != nil {
		// Link the chat image to this message, matching CreateChatMessage behaviour.
		db.Exec("UPDATE chat_images SET chatmsgid = ? WHERE id = ?;", newid, *payload.Imageid)
	}

	var ret ChatMessageLovejunkResponse
	ret.Id = newid
	ret.Chatid = chat.ID
	ret.Userid = myid

	return c.JSON(ret)
}

// =============================================================================
// PATCH / DELETE handlers
// =============================================================================

func PatchChatMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req PatchChatMessageRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 || req.Roomid == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id and roomid are required")
	}

	db := database.DBConn

	// Operations require message ownership.
	var msgUserid uint64
	db.Raw("SELECT userid FROM chat_messages WHERE id = ? AND chatid = ?", req.ID, req.Roomid).Scan(&msgUserid)

	if msgUserid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	if msgUserid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	// Update replyexpected if provided.
	if req.Replyexpected != nil {
		db.Exec("UPDATE chat_messages SET replyexpected = ? WHERE id = ?", *req.Replyexpected, req.ID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func DeleteChatMessage(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	idStr := c.Query("id")
	if idStr == "" {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	id, err := strconv.ParseUint(idStr, 10, 64)
	if err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid id")
	}

	db := database.DBConn

	// Verify the message exists and belongs to this user.
	var msgUserid uint64
	db.Raw("SELECT userid FROM chat_messages WHERE id = ?", id).Scan(&msgUserid)

	if msgUserid == 0 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found")
	}

	if msgUserid != myid {
		return fiber.NewError(fiber.StatusForbidden, "Not your message")
	}

	// Soft-delete: set type to Default, deleted to 1, clear imageid, remove chat_images.
	db.Exec("UPDATE chat_messages SET type = ?, deleted = 1, imageid = NULL WHERE id = ?",
		utils.CHAT_MESSAGE_DEFAULT, id)
	db.Exec("DELETE FROM chat_images WHERE chatmsgid = ?", id)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// =============================================================================
// Moderation handlers
// =============================================================================

// PostChatMessageModeration handles moderation actions on chat messages:
// Approve, ApproveAllFuture, Reject, Hold, Release, Redact
func PostChatMessageModeration(c *fiber.Ctx) error {
	myid := user.WhoAmI(c)
	if myid == 0 {
		return fiber.NewError(fiber.StatusUnauthorized, "Not logged in")
	}

	var req ModerationRequest
	if err := c.BodyParser(&req); err != nil {
		return fiber.NewError(fiber.StatusBadRequest, "Invalid request body")
	}

	if req.ID == 0 {
		return fiber.NewError(fiber.StatusBadRequest, "id is required")
	}

	db := database.DBConn

	// Check caller is a moderator on at least one group
	var modCount int64
	result := db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&modCount)
	if result.Error != nil {
		stdlog.Printf("Failed to check moderator status for user %d: %v", myid, result.Error)
		return fiber.NewError(fiber.StatusInternalServerError, "Database error")
	}
	if modCount == 0 {
		return fiber.NewError(fiber.StatusForbidden, "Not a moderator")
	}

	switch req.Action {
	case "Approve":
		return approveChatMessage(c, db, myid, req.ID, false)
	case "ApproveAllFuture":
		return approveChatMessage(c, db, myid, req.ID, true)
	case "Reject":
		return rejectChatMessage(c, db, myid, req.ID)
	case "Hold":
		return holdChatMessage(c, db, myid, req.ID)
	case "Release":
		return releaseChatMessage(c, db, myid, req.ID)
	case "Redact":
		return redactChatMessage(c, db, myid, req.ID)
	default:
		return fiber.NewError(fiber.StatusBadRequest, "Invalid action: "+req.Action)
	}
}

// =============================================================================
// Review queue helpers
// =============================================================================

// canSeeChatRoom checks if a user can view a chat room.
// Allows: direct participants, moderators of the chat's group, and moderators of any group
// where either participant is a member (for User2User chats during review).
func canSeeChatRoom(myid uint64, user1, user2, groupid uint64) bool {
	if user1 == myid || user2 == myid {
		return true
	}

	db := database.DBConn

	// Admin and Support can see all chat rooms.
	if auth.IsAdminOrSupport(myid) {
		return true
	}

	if groupid > 0 {
		var modCount int64
		result := db.Raw("SELECT COUNT(*) FROM memberships WHERE userid = ? AND groupid = ? AND role IN (?, ?)",
			myid, groupid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&modCount)
		if result.Error != nil {
			stdlog.Printf("Failed to check chat room mod permission user %d group %d: %v", myid, groupid, result.Error)
			return false
		}
		if modCount > 0 {
			return true
		}
	}

	// Fallback: check if mod of any group where either participant is a member.
	var modCount int64
	result := db.Raw("SELECT COUNT(*) FROM memberships m1 "+
		"INNER JOIN memberships m2 ON m1.groupid = m2.groupid "+
		"WHERE m1.userid = ? AND m1.role IN (?, ?) "+
		"AND m2.userid IN (?, ?)",
		myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER, user1, user2).Scan(&modCount)
	if result.Error != nil {
		stdlog.Printf("Failed to check chat room fallback mod permission user %d: %v", myid, result.Error)
		return false
	}
	return modCount > 0
}

// getChatMessagesForRoom returns messages from a specific chat room (for MT viewing).
func getChatMessagesForRoom(c *fiber.Ctx, myid uint64, roomid uint64) error {
	db := database.DBConn

	// Verify user can access this chat (participant or moderator of group).
	type roomCheck struct {
		ID       uint64
		User1    uint64
		User2    uint64
		Groupid  uint64
		Chattype string
	}
	var room roomCheck
	db.Raw("SELECT id, user1, user2, COALESCE(groupid, 0) AS groupid, chattype FROM chat_rooms WHERE id = ?", roomid).Scan(&room)

	if room.ID == 0 {
		return c.Status(fiber.StatusNotFound).JSON(fiber.Map{"ret": 2, "status": "Chat not found"})
	}

	if !canSeeChatRoom(myid, room.User1, room.User2, room.Groupid) {
		return c.Status(fiber.StatusForbidden).JSON(fiber.Map{"ret": 2, "status": "Permission denied"})
	}

	limit, _ := strconv.Atoi(c.Query("limit", "100"))
	if limit <= 0 || limit > 1000 {
		limit = 100
	}

	ctx, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	type msgRow struct {
		ID           uint64     `json:"id"`
		Chatid       uint64     `json:"chatid"`
		Userid       uint64     `json:"userid"`
		Type         string     `json:"type"`
		Message      string     `json:"message"`
		Date         *time.Time `json:"date"`
		Refmsgid     *uint64    `json:"refmsgid"`
		Reportreason *string    `json:"reportreason"`
	}

	ctxq := ""
	if ctx > 0 {
		ctxq = " AND chat_messages.id < " + strconv.FormatUint(ctx, 10)
	}

	// Mod access: this endpoint is only called by moderators viewing a chat from
	// the review queue, so include review messages.
	isParticipant := myid == room.User1 || myid == room.User2
	var reviewFilter string
	if isParticipant {
		reviewFilter = "(chat_messages.userid = ? OR (chat_messages.reviewrequired = 0 AND chat_messages.reviewrejected = 0 AND chat_messages.processingsuccessful = 1))"
	} else {
		// Mod viewing — show all messages except rejected ones.
		reviewFilter = "(chat_messages.reviewrejected = 0 OR chat_messages.userid = ?)"
	}

	// Mods reviewing a chat must see messages from soft-deleted users.
	// Participants only see their own messages when the sender is deleted.
	var deletedFilterRoom string
	var roomArgs []interface{}
	if isParticipant {
		deletedFilterRoom = " AND (users.deleted IS NULL OR users.id = ?)"
		roomArgs = []interface{}{roomid, myid, myid, limit}
	} else {
		roomArgs = []interface{}{roomid, myid, limit}
	}

	var msgs []msgRow
	db.Raw("SELECT chat_messages.id, chat_messages.chatid, chat_messages.userid, "+
		"chat_messages.type, chat_messages.message, chat_messages.date, "+
		"chat_messages.refmsgid, chat_messages.reportreason "+
		"FROM chat_messages "+
		"INNER JOIN users ON users.id = chat_messages.userid "+
		"WHERE chat_messages.chatid = ? "+
		"AND "+reviewFilter+deletedFilterRoom+ctxq+
		" ORDER BY chat_messages.id DESC LIMIT ?",
		roomArgs...).Scan(&msgs)

	if msgs == nil {
		msgs = []msgRow{}
	}

	// Build context for pagination.
	newCtx := fiber.Map{}
	if len(msgs) > 0 {
		newCtx["id"] = msgs[len(msgs)-1].ID
	}

	return c.JSON(fiber.Map{
		"ret":          0,
		"status":       "Success",
		"chatmessages": msgs,
		"context":      newCtx,
	})
}

// getReviewQueue returns chat messages pending moderation review.
func getReviewQueue(c *fiber.Ctx, myid uint64) error {
	db := database.DBConn

	// Get groups where user is a moderator.
	var groupIDs []uint64
	db.Raw("SELECT groupid FROM memberships WHERE userid = ? AND role IN (?, ?)", myid, utils.ROLE_MODERATOR, utils.ROLE_OWNER).Scan(&groupIDs)

	if len(groupIDs) == 0 {
		return c.JSON(fiber.Map{
			"ret":          0,
			"status":       "Success",
			"chatmessages": make([]interface{}, 0),
			"chatreports":  make([]interface{}, 0),
			"context":      fiber.Map{},
		})
	}

	limit, _ := strconv.Atoi(c.Query("limit", "100"))
	if limit <= 0 || limit > 1000 {
		limit = 100
	}

	ctx, _ := strconv.ParseUint(c.Query("context", "0"), 10, 64)

	groupIDStr := make([]string, len(groupIDs))
	for i, gid := range groupIDs {
		groupIDStr[i] = strconv.FormatUint(gid, 10)
	}
	groupIDList := strings.Join(groupIDStr, ",")

	ctxq := ""
	if ctx > 0 {
		ctxq = " AND cm.id < " + strconv.FormatUint(ctx, 10)
	}

	// Find messages pending review where either participant is in the mod's groups,
	// or the chat is a User2Mod chat for one of the mod's groups.
	type reviewRow struct {
		ID              uint64          `json:"id"`
		Chatid          uint64          `json:"chatid"`
		Userid          uint64          `json:"userid"`
		Type            string          `json:"type"`
		Message         string          `json:"message"`
		Date            *time.Time      `json:"date"`
		Refmsgid        *uint64         `json:"refmsgid"`
		Reportreason    *string         `json:"reportreason"`
		Imageid         *uint64         `json:"-"`
		ImageArchived   int             `json:"-"`
		Imageuid        string          `json:"-"`
		Imagemods       json.RawMessage `json:"-"`
		RoomChattype    string          `json:"-"`
		RoomUser1       uint64          `json:"-"`
		RoomUser2       uint64          `json:"-"`
		RoomGroupid     uint64          `json:"-"`
		Widerchatreview int             `json:"-"`
		HeldBy          uint64          `json:"-"`
		HeldTimestamp   *time.Time      `json:"-"`
		Msgid           *uint64         `json:"-"`
		Groupid         uint64          `json:"-"`
		Groupidfrom     uint64          `json:"-"`
	}

	// Check if this user participates in wider chat review.
	widerReview := user.HasWiderReview(myid)

	// Base query: messages from mod's own groups.
	baseQuery := "SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
		"cm.refmsgid, cm.reportreason, " +
		"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
		"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
		"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
		"COALESCE(cr.groupid, 0) AS room_groupid, " +
		"0 AS widerchatreview, " +
		"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
		"cme.msgid, " +
		"COALESCE((SELECT m1.groupid FROM memberships m1 WHERE m1.userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND m1.groupid IN (" + groupIDList + ") LIMIT 1), 0) AS groupid, " +
		"COALESCE((SELECT m2.groupid FROM memberships m2 WHERE m2.userid = cm.userid AND m2.groupid IN (" + groupIDList + ") LIMIT 1), 0) AS groupidfrom " +
		"FROM chat_messages cm " +
		"INNER JOIN chat_rooms cr ON cr.id = cm.chatid " +
		"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
		"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
		"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
		"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
		"WHERE cm.reviewrequired = 1 AND cm.reviewrejected = 0" + ctxq +
		" AND (" +
		// User2Mod: group is one of mod's groups
		"  (cr.chattype = ? AND cr.groupid IN (" + groupIDList + "))" +
		// User2User case 1: recipient (other user) is on one of mod's groups
		"  OR (cr.chattype = ? AND EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END AND groupid IN (" + groupIDList + ")))" +
		// User2User case 2: recipient has NO memberships, sender is on one of mod's groups (orphan safety net)
		"  OR (cr.chattype = ? AND NOT EXISTS (SELECT 1 FROM memberships WHERE userid = CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END) AND EXISTS (SELECT 1 FROM memberships WHERE userid = cm.userid AND groupid IN (" + groupIDList + ")))" +
		")"

	var msgs []reviewRow

	if widerReview {
		// Add UNION for wider chat review: messages from any group with widerchatreview=1,
		// excluding held messages and user-reported spam.
		// Wider query: only include messages where the recipient is NOT already
		// on the mod's own groups (those are covered by the base query with
		// widerchatreview=0 and full actions).
		recipientExpr := "(CASE WHEN cm.userid = cr.user1 THEN cr.user2 ELSE cr.user1 END)"
		widerQuery := " UNION " +
			"SELECT DISTINCT cm.id, cm.chatid, cm.userid, cm.type, cm.message, cm.date, " +
			"cm.refmsgid, cm.reportreason, " +
			"cm.imageid, COALESCE(ci.archived, 0) AS image_archived, " +
			"COALESCE(ci.externaluid, '') AS imageuid, ci.externalmods AS imagemods, " +
			"cr.chattype AS room_chattype, cr.user1 AS room_user1, cr.user2 AS room_user2, " +
			"COALESCE(cr.groupid, 0) AS room_groupid, " +
			"1 AS widerchatreview, " +
			"COALESCE(cmh.userid, 0) AS held_by, cmh.timestamp AS held_timestamp, " +
			"cme.msgid, " +
			"m1.groupid AS groupid, " +
			"COALESCE(m2.groupid, 0) AS groupidfrom " +
			"FROM chat_messages cm " +
			"INNER JOIN chat_rooms cr ON cr.id = cm.chatid AND cm.reviewrequired = 1 AND cm.reviewrejected = 0 " +
			"INNER JOIN memberships m1 ON m1.userid = " + recipientExpr + " " +
			"INNER JOIN `groups` g ON m1.groupid = g.id AND g.type = 'Freegle' " +
			"INNER JOIN users ON users.id = cm.userid AND users.deleted IS NULL " +
			"LEFT JOIN memberships m2 ON m2.userid = cm.userid " +
			"LEFT JOIN chat_images ci ON ci.chatmsgid = cm.id " +
			"LEFT JOIN chat_messages_held cmh ON cmh.msgid = cm.id " +
			"LEFT JOIN chat_messages_byemail cme ON cme.chatmsgid = cm.id " +
			"WHERE JSON_EXTRACT(g.settings, '$.widerchatreview') = 1 " +
			"AND cmh.id IS NULL " +
			"AND (cm.reportreason IS NULL OR cm.reportreason != 'User') " +
			"AND NOT EXISTS (SELECT 1 FROM memberships m_check WHERE m_check.userid = " + recipientExpr + " AND m_check.groupid IN (" + groupIDList + "))" + ctxq

		result := db.Raw("SELECT * FROM ("+baseQuery+widerQuery+") combined GROUP BY id ORDER BY widerchatreview ASC, id ASC LIMIT ?",
			utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit).Scan(&msgs)
		if result.Error != nil {
			stdlog.Printf("Failed to query wider chat review queue for user %d: %v", myid, result.Error)
		}
	} else {
		result := db.Raw(baseQuery+" GROUP BY cm.id ORDER BY cm.id ASC LIMIT ?",
			utils.CHAT_TYPE_USER2MOD, utils.CHAT_TYPE_USER2USER, utils.CHAT_TYPE_USER2USER, limit).Scan(&msgs)
		if result.Error != nil {
			stdlog.Printf("Failed to query chat review queue for user %d: %v", myid, result.Error)
		}
	}

	if msgs == nil {
		msgs = []reviewRow{}
	}

	// Collect held-by user IDs for batch fetching.
	heldByUserIDs := make(map[uint64]bool)
	for _, m := range msgs {
		if m.HeldBy > 0 {
			heldByUserIDs[m.HeldBy] = true
		}
	}

	// Fetch held-by user details (name, email) if any.
	type heldUserInfo struct {
		ID    uint64
		Name  string
		Email string
	}
	heldUsers := make(map[uint64]heldUserInfo)
	if len(heldByUserIDs) > 0 {
		ids := make([]string, 0, len(heldByUserIDs))
		for id := range heldByUserIDs {
			ids = append(ids, strconv.FormatUint(id, 10))
		}
		var heldInfos []heldUserInfo
		db.Raw("SELECT u.id, u.fullname AS name, " +
			"(SELECT e.email FROM users_emails e WHERE e.userid = u.id AND e.preferred = 1 LIMIT 1) AS email " +
			"FROM users u WHERE u.id IN (" + strings.Join(ids, ",") + ")").Scan(&heldInfos)
		for _, h := range heldInfos {
			heldUsers[h.ID] = h
		}
	}

	// Build response with inline chatroom info.
	result := make([]fiber.Map, 0, len(msgs))
	for _, m := range msgs {
		name := getChatName(db, m.RoomChattype, m.RoomGroupid, m.RoomUser1, m.RoomUser2, myid)

		// Determine fromuser (sender) and touser (other participant).
		fromuserid := m.Userid
		var touserid uint64
		if m.Userid == m.RoomUser1 {
			touserid = m.RoomUser2
		} else {
			touserid = m.RoomUser1
		}

		msg := fiber.Map{
			"id":              m.ID,
			"chatid":          m.Chatid,
			"userid":          m.Userid,
			"fromuserid":      fromuserid,
			"touserid":        touserid,
			"type":            m.Type,
			"message":         m.Message,
			"date":            m.Date,
			"refmsgid":        m.Refmsgid,
			"reviewreason":    enrichReviewReason(db, m.Message, m.Reportreason),
			"widerchatreview": m.Widerchatreview > 0,
			"groupid":         m.Groupid,
			"groupidfrom":     m.Groupidfrom,
			"chatroom": fiber.Map{
				"id":       m.Chatid,
				"chattype": m.RoomChattype,
				"user1":    m.RoomUser1,
				"user2":    m.RoomUser2,
				"groupid":  m.RoomGroupid,
				"name":     name,
			},
		}

		// Add image if the message has one.
		if m.Imageid != nil {
			path, paththumb := misc.BuildChatImageUrl(*m.Imageid, m.Imageuid, string(m.Imagemods), m.ImageArchived)
			image := &ChatAttachment{
				ID:           *m.Imageid,
				Ouruid:       m.Imageuid,
				Externalmods: m.Imagemods,
				Path:         path,
				Paththumb:    paththumb,
			}
			msg["image"] = image
			msg["imageid"] = *m.Imageid
		}

		// Add msgid if the message came via email.
		if m.Msgid != nil {
			msg["msgid"] = *m.Msgid
		}

		// Add held info if message is held by a moderator.
		if m.HeldBy > 0 {
			held := fiber.Map{
				"id": m.HeldBy,
			}
			if m.HeldTimestamp != nil {
				held["timestamp"] = m.HeldTimestamp
			}
			if h, ok := heldUsers[m.HeldBy]; ok {
				held["name"] = h.Name
				held["email"] = h.Email
			}
			msg["held"] = held
		}

		result = append(result, msg)
	}

	// Build context for pagination.
	newCtx := fiber.Map{}
	if len(msgs) > 0 {
		newCtx["id"] = msgs[len(msgs)-1].ID
	}

	return c.JSON(fiber.Map{
		"ret":          0,
		"status":       "Success",
		"chatmessages": result,
		"chatreports":  make([]interface{}, 0),
		"context":      newCtx,
	})
}

// =============================================================================
// Moderation internal helpers
// =============================================================================

type reviewMessage struct {
	ID      uint64 `gorm:"column:id"`
	Chatid  uint64 `gorm:"column:chatid"`
	Userid  uint64 `gorm:"column:userid"`
	Message string `gorm:"column:message"`
	HeldBy  uint64 `gorm:"column:heldbyuser"`
}

func fetchReviewMessage(db *gorm.DB, msgID uint64) *reviewMessage {
	var msg reviewMessage
	result := db.Raw("SELECT chat_messages.id, chat_messages.chatid, chat_messages.userid, chat_messages.message, "+
		"COALESCE(chat_messages_held.userid, 0) AS heldbyuser "+
		"FROM chat_messages "+
		"LEFT JOIN chat_messages_held ON chat_messages_held.msgid = chat_messages.id "+
		"INNER JOIN chat_rooms ON chat_rooms.id = chat_messages.chatid "+
		"WHERE chat_messages.id = ? AND chat_messages.reviewrequired = 1",
		msgID).Scan(&msg)
	if result.Error != nil {
		stdlog.Printf("Failed to fetch review message %d: %v", msgID, result.Error)
	}

	if msg.ID == 0 {
		return nil
	}
	return &msg
}

// checkHoldConflict returns true if the message is held by a different moderator.
func checkHoldConflict(msg *reviewMessage, myid uint64) bool {
	if msg == nil {
		return false
	}
	return msg.HeldBy != 0 && msg.HeldBy != myid
}

// autoApproveModmails approves any ModMail messages after the given message in the same chat.
func autoApproveModmails(db *gorm.DB, myid uint64, chatID uint64, afterMsgID uint64) {
	var modmailIDs []uint64
	db.Raw("SELECT id FROM chat_messages WHERE chatid = ? AND id > ? AND reviewrequired = 1 AND type = 'ModMail'",
		chatID, afterMsgID).Scan(&modmailIDs)

	for _, id := range modmailIDs {
		db.Exec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ? WHERE id = ?", myid, id)
	}
}

// updateMessageCounts recalculates valid/invalid message counts for a chat room.
func updateMessageCounts(db *gorm.DB, chatID uint64) {
	type countRow struct {
		Valid int
		Count int64
	}

	var counts []countRow
	// Pin to the write host: callers invoke this immediately after UPDATEing
	// chat_messages.reviewrequired/reviewrejected on the source, and these recounted
	// totals are written back to chat_rooms. A lagging replica read would persist
	// stale valid/invalid counts that survive until the next approve/reject.
	db.Clauses(dbresolver.Write).Raw("SELECT CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END AS valid, "+
		"COUNT(*) AS count FROM chat_messages WHERE chatid = ? "+
		"GROUP BY CASE WHEN reviewrequired = 0 AND reviewrejected = 0 AND processingsuccessful = 1 THEN 1 ELSE 0 END",
		chatID).Scan(&counts)

	var msgValid, msgInvalid int64
	for _, c := range counts {
		if c.Valid == 1 {
			msgValid = c.Count
		} else {
			msgInvalid = c.Count
		}
	}

	// For Mod2Mod chats, force msginvalid to 0
	var chattype string
	db.Raw("SELECT chattype FROM chat_rooms WHERE id = ?", chatID).Scan(&chattype)
	if chattype == "Mod2Mod" {
		msgInvalid = 0
	}

	db.Exec("UPDATE chat_rooms SET msgvalid = ?, msginvalid = ?, latestmessage = ? WHERE id = ?",
		msgValid, msgInvalid, time.Now(), chatID)
}

func approveChatMessage(c *fiber.Ctx, db *gorm.DB, myid uint64, msgID uint64, approveAllFuture bool) error {
	msg := fetchReviewMessage(db, msgID)
	if msg == nil {
		return fiber.NewError(fiber.StatusNotFound, "Message not found or not requiring review")
	}

	if checkHoldConflict(msg, myid) {
		return fiber.NewError(fiber.StatusConflict, "Message is held by another moderator")
	}

	// Approve the message
	if result := db.Exec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ? WHERE id = ?", myid, msgID); result.Error != nil {
		stdlog.Printf("Failed to approve chat message %d: %v", msgID, result.Error)
	}

	// Log the approve action
	db.Exec("INSERT INTO logs (timestamp, type, subtype, user, byuser, text) VALUES (NOW(), ?, ?, ?, ?, ?)",
		log.LOG_TYPE_CHAT, log.LOG_SUBTYPE_APPROVED, msg.Userid, myid, fmt.Sprintf("Chat message %d approved", msgID))

	// Auto-approve any ModMail messages after this one in the same chat
	autoApproveModmails(db, myid, msg.Chatid, msgID)

	// Update message counts
	updateMessageCounts(db, msg.Chatid)

	// Remove hold if it exists
	db.Exec("DELETE FROM chat_messages_held WHERE msgid = ?", msgID)

	// Whitelist the message text so similar messages aren't flagged again
	//.
	if msg.Message != "" {
		db.Exec("INSERT IGNORE INTO spam_whitelist_subjects (subject, comment) VALUES (?, 'Marked as not spam')", msg.Message)
	}

	if approveAllFuture {
		// Set user's chatmodstatus to Unmoderated
		db.Exec("UPDATE users SET chatmodstatus = 'Unmoderated' WHERE id = ?", msg.Userid)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func rejectChatMessage(c *fiber.Ctx, db *gorm.DB, myid uint64, msgID uint64) error {
	msg := fetchReviewMessage(db, msgID)
	if msg == nil {
		return fiber.NewError(fiber.StatusNotFound, "Message not found or not requiring review")
	}

	// Reject is as destructive as Approve, so it gets the same hold check - it was
	// missing here, letting a mod reject a message another mod was reviewing.
	if checkHoldConflict(msg, myid) {
		return fiber.NewError(fiber.StatusConflict, "Message is held by another moderator")
	}

	// Reject the message
	if result := db.Exec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ?, reviewrejected = 1 WHERE id = ?", myid, msgID); result.Error != nil {
		stdlog.Printf("Failed to reject chat message %d: %v", msgID, result.Error)
	}

	// Log the reject action
	db.Exec("INSERT INTO logs (timestamp, type, subtype, user, byuser, text) VALUES (NOW(), ?, ?, ?, ?, ?)",
		log.LOG_TYPE_CHAT, log.LOG_SUBTYPE_REJECTED, msg.Userid, myid, fmt.Sprintf("Chat message %d rejected", msgID))

	// Auto-approve any ModMail messages after this one
	autoApproveModmails(db, myid, msg.Chatid, msgID)

	// Find and reject all identical pending messages from last 24 hours (spam flood prevention)
	cutoff := time.Now().Add(-24 * time.Hour).Format("2006-01-02 15:04:05")

	type dupMsg struct {
		ID     uint64
		Chatid uint64
	}
	var dups []dupMsg
	db.Raw("SELECT id, chatid FROM chat_messages WHERE date >= ? AND reviewrequired = 1 AND message = ? AND id != ?",
		cutoff, msg.Message, msgID).Scan(&dups)

	// Track affected chat IDs for count updates
	affectedChats := map[uint64]bool{msg.Chatid: true}

	for _, dup := range dups {
		db.Exec("UPDATE chat_messages SET reviewrequired = 0, reviewedby = ?, reviewrejected = 1 WHERE id = ?", myid, dup.ID)
		affectedChats[dup.Chatid] = true
	}

	// Update message counts for all affected chats
	for chatID := range affectedChats {
		updateMessageCounts(db, chatID)
	}

	// Remove hold if it exists
	db.Exec("DELETE FROM chat_messages_held WHERE msgid = ?", msgID)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func holdChatMessage(c *fiber.Ctx, db *gorm.DB, myid uint64, msgID uint64) error {
	// Verify the message exists and requires review
	var reviewRequired int
	db.Raw("SELECT reviewrequired FROM chat_messages WHERE id = ?", msgID).Scan(&reviewRequired)
	if reviewRequired != 1 {
		return fiber.NewError(fiber.StatusNotFound, "Message not found or not requiring review")
	}

	// A hold is an exclusive claim, so don't let one mod take another's. REPLACE
	// INTO used to overwrite the holder silently, which meant a stale screen could
	// steal a hold rather than being told about it. Release is the deliberate way
	// to break someone else's hold.
	var currentHolder uint64
	db.Raw("SELECT userid FROM chat_messages_held WHERE msgid = ?", msgID).Scan(&currentHolder)
	if currentHolder != 0 && currentHolder != myid {
		return fiber.NewError(fiber.StatusConflict, "Message is held by another moderator")
	}

	// REPLACE INTO handles re-holding your own hold, which is a harmless no-op.
	db.Exec("REPLACE INTO chat_messages_held (msgid, userid) VALUES (?, ?)", msgID, myid)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

func releaseChatMessage(c *fiber.Ctx, db *gorm.DB, myid uint64, msgID uint64) error {
	// Delete the hold record
	db.Exec("DELETE FROM chat_messages_held WHERE msgid = ?", msgID)

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}

// Email regex pattern for detecting email addresses in chat messages.
var emailRegexp = regexp.MustCompile(`[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}`)

// URL regex pattern for detecting URLs in chat messages.
var urlRegexp = regexp.MustCompile(`(?i)\b(?:(?:https?):(?:/{1,3}|[a-z0-9%])|www\d{0,3}[.]|[a-z0-9.\-]+[.][a-z]{2,4}/)(?:[^\s()<>]+|\((?:[^\s()<>]+|(?:\([^\s()<>]+\)))*\))+`)

// Freegle-related domains excluded from email spam checks.
var freegleDomains = []string{"ilovefreegle.org", "trashnothing", "yahoogroups"}

// enrichReviewReason re-checks message content when reportreason is 'Spam' to provide
// a more specific reason (Money, Email, Link, etc.), matching V1 PHP behaviour.
func enrichReviewReason(db *gorm.DB, message string, reportreason *string) string {
	if reportreason == nil {
		return ""
	}
	reason := *reportreason
	if reason != "Spam" {
		return reason
	}

	// Spammer trick: encoded dot in URLs.
	msg := strings.ReplaceAll(message, "&#12290;", ".")

	if len(msg) == 0 {
		return reason
	}

	// Step 1: Check concern_keywords with literal/regex match modes.
	//
	// Mirror PHP ContentCheckService::checkConcernKeywords filters:
	//   - scope='global' only (per-group worry words are scoped to a specific
	//     group_id and must not match chat messages from other groups; chat
	//     messages have no group context here, so per-group keywords are
	//     dropped entirely)
	//   - category != 'allowed' (allowed-category place names like 'road' /
	//     'Butt Road' / 'Cock Lane' are tracked for context, not flagging)
	//
	// Without these filters, every chat message containing common place-name
	// or per-group worry tokens (e.g. 'road', 'donate', 'charity') was
	// labelled "Known spam keyword" in MT chat review even when the original
	// flag came from a URL or other content check.
	type spamWord struct {
		Word    string  `gorm:"column:word"`
		Type    string  `gorm:"column:type"`
		Action  string  `gorm:"column:action"`
		Exclude *string `gorm:"column:exclude"`
	}
	var keywords []spamWord
	db.Raw("SELECT keyword AS word, match_mode AS type, action, exclude FROM concern_keywords WHERE match_mode IN ('literal', 'regex') AND action IN ('block', 'flag') AND scope = 'global' AND category != 'allowed' AND LENGTH(TRIM(keyword)) > 0").Scan(&keywords)

	for _, kw := range keywords {
		word := strings.TrimSpace(kw.Word)
		if len(word) == 0 {
			continue
		}
		pattern := `(?i)\b` + regexp.QuoteMeta(word) + `\b`
		re, err := regexp.Compile(pattern)
		if err != nil {
			continue
		}
		if re.MatchString(msg) {
			if kw.Exclude != nil && *kw.Exclude != "" {
				exRe, exErr := regexp.Compile(`(?i)` + *kw.Exclude)
				if exErr == nil && exRe.MatchString(msg) {
					continue
				}
			}
			return "Known spam keyword"
		}
	}

	// Step 2: checkReview-style pattern checks (matching PHP Spam::checkReview order).

	// Script tags.
	if strings.Contains(strings.ToLower(msg), "<script") {
		return "Script"
	}

	// URL removed marker.
	if strings.Contains(msg, "(URL removed)") {
		return "Link"
	}

	// URLs — check against whitelisted domains.
	urls := urlRegexp.FindAllString(msg, -1)
	if len(urls) > 0 {
		var whitelist []string
		db.Raw("SELECT domain FROM spam_whitelist_links WHERE count >= 3 AND LENGTH(domain) > 5 AND domain NOT LIKE '%linkedin%' AND domain NOT LIKE '%goo.gl%' AND domain NOT LIKE '%bit.ly%' AND domain NOT LIKE '%tinyurl%'").Scan(&whitelist)

		untrustedCount := 0
		for _, u := range urls {
			// Strip protocol.
			stripped := u
			if idx := strings.Index(u, "://"); idx >= 0 {
				stripped = u[idx+3:]
			}
			trusted := false
			for _, domain := range whitelist {
				if strings.HasPrefix(strings.ToLower(stripped), strings.ToLower(domain)) {
					trusted = true
					break
				}
			}
			if !trusted {
				untrustedCount++
			}
		}
		if untrustedCount > 0 {
			return "Link"
		}
	}

	// Money symbols.
	if strings.ContainsAny(msg, "$£") || strings.Contains(msg, "(a)") {
		return "Money"
	}

	// Email addresses (excluding Freegle-related domains).
	emails := emailRegexp.FindAllString(msg, -1)
	for _, email := range emails {
		emailLower := strings.ToLower(email)

		// Exclude noreply@ on our domain.
		if strings.HasPrefix(emailLower, "noreply@") && strings.Contains(emailLower, "ilovefreegle.org") {
			continue
		}

		excluded := false
		for _, domain := range freegleDomains {
			if strings.Contains(emailLower, domain) {
				excluded = true
				break
			}
		}
		if !excluded {
			return "Email"
		}
	}

	return reason
}

func redactChatMessage(c *fiber.Ctx, db *gorm.DB, myid uint64, msgID uint64) error {
	msg := fetchReviewMessage(db, msgID)
	if msg == nil {
		return fiber.NewError(fiber.StatusNotFound, "Message not found or not requiring review")
	}

	if checkHoldConflict(msg, myid) {
		return fiber.NewError(fiber.StatusConflict, "Message is held by another moderator")
	}

	// Replace email addresses with placeholder
	cleaned := emailRegexp.ReplaceAllString(msg.Message, "(email removed)")

	if cleaned != msg.Message {
		db.Exec("UPDATE chat_messages SET message = ? WHERE id = ?", cleaned, msgID)
	}

	return c.JSON(fiber.Map{"ret": 0, "status": "Success"})
}
